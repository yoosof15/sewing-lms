<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentImportStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentImportBatch;
use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class StudentImportService
{
    private const HEADERS = ['first_name', 'last_name', 'mobile', 'email', 'course_slug', 'enrollment_starts_at', 'enrollment_ends_at'];

    public function preview(UploadedFile $file, User $actor, array $options): StudentImportBatch
    {
        $records = $this->parse($file);
        if (count($records) > config('student_imports.max_rows')) {
            throw new RuntimeException('تعداد ردیف‌ها از سقف مجاز بیشتر است.');
        }
        $mobiles = [];
        foreach ($records as $record) {
            try {
                $mobiles[] = IranianMobile::normalize((string) ($record['mobile'] ?? ''));
            } catch (\InvalidArgumentException) {
            }
        }
        $existing = User::with('roles:id,name')->whereIn('mobile', array_unique($mobiles))->get()->keyBy('mobile');
        $emails = collect($records)->pluck('email')->filter()->map(fn ($email) => strtolower(trim((string) $email)))->unique();
        $existingEmails = User::whereIn('email', $emails)->pluck('email')->mapWithKeys(fn ($email) => [strtolower($email) => true]);
        $courses = Course::query()->get(['id', 'slug', 'title'])->keyBy('slug');
        $selectedCourse = ($options['course_mode'] ?? 'selected') === 'selected' && filled($options['course_id'] ?? null)
            ? Course::find($options['course_id']) : null;
        $seen = [];
        $prepared = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'existing' => 0];
        foreach ($records as $index => $record) {
            [$data, $status, $codes, $message] = $this->validateRow($record, $seen, $existing, $existingEmails, $courses, $selectedCourse, $options);
            $counts[$status === 'VALID_NEW' ? 'valid' : ($status === 'DUPLICATE_IN_FILE' ? 'duplicate' : (str_starts_with($status, 'EXISTING_') ? 'existing' : 'invalid'))]++;
            $prepared[] = ['row_number' => $index + 2, 'data' => $data, 'status' => $status, 'error_codes' => $codes ?: null, 'error_message' => $message];
        }

        return DB::transaction(function () use ($file, $actor, $options, $records, $counts, $prepared) {
            $batch = StudentImportBatch::create(['created_by' => $actor->id, 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'status' => StudentImportStatus::Validated, 'total_rows' => count($records), 'valid_rows' => $counts['valid'], 'invalid_rows' => $counts['invalid'], 'duplicate_rows' => $counts['duplicate'], 'matched_existing_users' => $counts['existing'], 'options' => $options]);
            $batch->rows()->createMany($prepared);

            return $batch;
        });
    }

    public function execute(StudentImportBatch $batch, User $actor, AuditService $audit, NotificationEvents $notifications): void
    {
        if ($batch->status !== StudentImportStatus::Validated) {
            return;
        }
        $batch->update(['status' => StudentImportStatus::Processing, 'started_at' => now()]);
        $created = $enrolled = $failed = 0;
        $statuses = ($batch->options['existing_policy'] ?? 'SKIP_EXISTING') === 'ENROLL_EXISTING_ONLY' ? ['VALID_NEW', 'EXISTING_STUDENT'] : ['VALID_NEW'];
        $batch->rows()->whereIn('status', $statuses)->orderBy('id')->chunkById(100, function ($rows) use ($actor, $notifications, &$created, &$enrolled, &$failed) {
            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($row, $actor, $notifications, &$created, &$enrolled) {
                        $data = $row->data;
                        $user = User::where('mobile', $data['mobile'])->first();
                        if (! $user) {
                            $user = User::create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'mobile' => $data['mobile'], 'email' => $data['email'] ?: null, 'password' => Str::password(20), 'is_active' => true]);
                            $user->assignRole('STUDENT');
                            $created++;
                        }
                        if (! $user->hasRole('STUDENT')) {
                            $row->update(['status' => 'EXISTING_NON_STUDENT', 'user_id' => $user->id]);

                            return;
                        }
                        $courseId = $data['course_id'] ?? null;
                        if ($courseId && ! CourseEnrollment::where('course_id', $courseId)->where('student_id', $user->id)->exists()) {
                            $enrollment = CourseEnrollment::create(['course_id' => $courseId, 'student_id' => $user->id, 'status' => EnrollmentStatus::Active, 'enrolled_at' => now(), 'starts_at' => $data['starts_at'] ?: null, 'ends_at' => $data['ends_at'] ?: null, 'created_by' => $actor->id]);
                            $notifications->enrollment($user, $enrollment->course, true, $enrollment->id);
                            $enrolled++;
                        }
                        $row->update(['status' => 'IMPORTED', 'user_id' => $user->id]);
                    });
                } catch (\Throwable) {
                    $failed++;
                    $row->update(['status' => 'FAILED', 'error_codes' => ['EXECUTION_FAILED'], 'error_message' => 'اجرای این ردیف ناموفق بود.']);
                }
            }
        });
        $batch->update(['status' => $failed || $batch->invalid_rows || $batch->duplicate_rows ? StudentImportStatus::CompletedWithErrors : StudentImportStatus::Completed, 'created_users' => $created, 'created_enrollments' => $enrolled, 'failed_rows' => $failed, 'completed_at' => now()]);
        $audit->record('student_import.completed', $batch, ['batch_id' => $batch->id, 'total_rows' => $batch->total_rows, 'created_users' => $created, 'matched_existing_users' => $batch->matched_existing_users, 'created_enrollments' => $enrolled, 'invalid_rows' => $batch->invalid_rows]);
    }

    private function validateRow(array $record, array &$seen, $existing, $existingEmails, $courses, ?Course $selectedCourse, array $options): array
    {
        $data = ['first_name' => trim((string) ($record['first_name'] ?? '')), 'last_name' => trim((string) ($record['last_name'] ?? '')), 'email' => strtolower(trim((string) ($record['email'] ?? ''))), 'starts_at' => trim((string) ($record['enrollment_starts_at'] ?? '')), 'ends_at' => trim((string) ($record['enrollment_ends_at'] ?? ''))];
        try {
            $data['mobile'] = IranianMobile::normalize((string) ($record['mobile'] ?? ''));
        } catch (\InvalidArgumentException) {
            $data['mobile'] = trim((string) ($record['mobile'] ?? ''));
        }
        $validator = Validator::make($data, ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'mobile' => ['required', 'regex:/^\+989\d{9}$/'], 'email' => ['nullable', 'email', 'max:255'], 'starts_at' => ['nullable', 'date_format:Y-m-d'], 'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at']]);
        if ($validator->fails()) {
            return [$data, 'INVALID', array_keys($validator->errors()->toArray()), $validator->errors()->first()];
        }
        if (isset($seen[$data['mobile']])) {
            return [$data, 'DUPLICATE_IN_FILE', ['DUPLICATE_IN_FILE'], 'شماره موبایل در فایل تکراری است.'];
        }
        $seen[$data['mobile']] = true;
        $course = ($options['course_mode'] ?? 'selected') === 'selected' ? $selectedCourse : $courses->get(trim((string) ($record['course_slug'] ?? '')));
        if (($options['course_mode'] ?? 'selected') === 'slug' && filled($record['course_slug'] ?? null) && ! $course) {
            return [$data, 'INVALID', ['COURSE_NOT_FOUND'], 'دوره پیدا نشد.'];
        }
        $data['course_id'] = $course?->id;
        $user = $existing->get($data['mobile']);
        if ($user && ! $user->hasRole('STUDENT')) {
            return [$data, 'EXISTING_NON_STUDENT', ['EXISTING_NON_STUDENT_USER'], 'این موبایل متعلق به حساب غیرهنرجو است.'];
        }
        if ($user) {
            return [$data, 'EXISTING_STUDENT', [], null];
        }
        if ($data['email'] && $existingEmails->has($data['email'])) {
            return [$data, 'INVALID', ['EMAIL_ALREADY_EXISTS'], 'ایمیل قبلاً استفاده شده است.'];
        }

        return [$data, 'VALID_NEW', [], null];
    }

    private function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new RuntimeException('فقط CSV و XLSX مجاز است.');
        }
        $rows = $extension === 'csv' ? $this->csv($file->getRealPath()) : $this->xlsx($file->getRealPath());
        if (! $rows) {
            throw new RuntimeException('فایل خالی یا نامعتبر است.');
        }
        $headers = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($rows));
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        foreach (['first_name', 'last_name', 'mobile'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new RuntimeException("ستون الزامی $required وجود ندارد.");
            }
        }

        return array_values(array_filter(array_map(fn ($row) => array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null)), $rows), fn ($row) => collect($row)->filter(fn ($v) => filled($v))->isNotEmpty()));
    }

    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('فایل قابل خواندن نیست.');
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $row;
        } fclose($handle);

        return $rows;
    }

    private function xlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('پشتیبانی ZIP برای XLSX روی سرور فعال نیست.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('ساختار XLSX نامعتبر است.');
        }
        $shared = [];
        if ($xml = $zip->getFromName('xl/sharedStrings.xml')) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $item) {
                $shared[] = (string) ($item->t ?? $item->r->t);
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! $sheet) {
            throw new RuntimeException('برگه اول XLSX پیدا نشد.');
        }
        $doc = simplexml_load_string($sheet);
        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $ref, $m);
                $index = 0;
                foreach (str_split($m[0]) as $char) {
                    $index = $index * 26 + ord($char) - 64;
                } $value = (string) ($cell->v ?? $cell->is->t);
                if ((string) $cell['t'] === 's') {
                    $value = $shared[(int) $value] ?? '';
                } $values[$index - 1] = $value;
            } ksort($values);
            $rows[] = array_replace(array_fill(0, $values ? max(array_keys($values)) + 1 : 0, ''), $values);
        }

        return $rows;
    }
}
