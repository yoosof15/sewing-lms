<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentImportBatch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class PhaseSixteenBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_upload_is_preview_only_and_confirm_creates_normalized_students(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("\xEF\xBB\xBFfirst_name,last_name,mobile,email,role,is_active\nAli,One,09121234567,ali@example.test,ADMIN,0\nSara,Two,+989121234568,sara@example.test,TEACHER,0"))->assertRedirect();
        $this->assertDatabaseMissing('users', ['mobile' => '+989121234567']);
        $batch = StudentImportBatch::firstOrFail();
        $this->assertSame(2, $batch->valid_rows);
        $this->actingAs($admin)->post("/admin/student-imports/{$batch->id}/execute")->assertRedirect();
        foreach (['+989121234567', '+989121234568'] as $mobile) {
            $user = User::where('mobile', $mobile)->firstOrFail();
            $this->assertTrue($user->hasRole('STUDENT'));
            $this->assertTrue($user->is_active);
            $this->assertFalse(Hash::check('', $user->password));
        }
        $this->actingAs($admin)->post("/admin/student-imports/{$batch->id}/execute")->assertStatus(409);
        $this->assertSame(2, User::role('STUDENT')->count());
    }

    public function test_invalid_headers_rows_and_normalized_duplicates_are_reported(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("first_name,mobile\nAli,09121234567"))->assertSessionHasErrors('file');
        $csv = "first_name,last_name,mobile,email\nAli,One,09121234567,ali@example.test\nAli,Two,989121234567,ok@example.test\nBad,Phone,123,x@example.test";
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload($csv))->assertRedirect();
        $batch = StudentImportBatch::firstOrFail();
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertDatabaseHas('student_import_rows', ['status' => 'DUPLICATE_IN_FILE']);
    }

    public function test_existing_student_can_enroll_but_existing_teacher_is_never_converted(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT', ['mobile' => '+989121111111']);
        $teacher = $this->user('TEACHER', ['mobile' => '+989122222222']);
        $course = Course::factory()->create();
        $csv = "first_name,last_name,mobile\nStudent,Old,09121111111\nTeacher,Old,09122222222";
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload($csv, ['course_mode' => 'selected', 'course_id' => $course->id]))->assertRedirect();
        $batch = StudentImportBatch::firstOrFail();
        $this->actingAs($admin)->post("/admin/student-imports/{$batch->id}/execute")->assertRedirect();
        $this->assertDatabaseHas('course_enrollments', ['student_id' => $student->id, 'course_id' => $course->id]);
        $this->assertFalse($teacher->fresh()->hasRole('STUDENT'));
        $this->assertDatabaseMissing('course_enrollments', ['student_id' => $teacher->id]);
    }

    public function test_existing_enrollment_is_not_duplicated_or_reactivated(): void
    {
        $admin = $this->user('ADMIN');
        $student = $this->user('STUDENT', ['mobile' => '+989123333333']);
        $course = Course::factory()->create();
        CourseEnrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => EnrollmentStatus::Inactive, 'enrolled_at' => now(), 'created_by' => $admin->id]);
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("first_name,last_name,mobile\nOld,Student,09123333333", ['course_mode' => 'selected', 'course_id' => $course->id]))->assertRedirect();
        $batch = StudentImportBatch::first();
        $this->actingAs($admin)->post("/admin/student-imports/{$batch->id}/execute");
        $this->assertSame(1, CourseEnrollment::count());
        $this->assertSame(EnrollmentStatus::Inactive, CourseEnrollment::first()->status);
    }

    public function test_import_routes_are_admin_only_and_bad_file_types_are_rejected(): void
    {
        $this->get('/admin/student-imports')->assertRedirect('/login');
        $this->actingAs($this->user('TEACHER'))->get('/admin/student-imports')->assertForbidden();
        $this->actingAs($this->user('STUDENT'))->get('/admin/student-imports')->assertForbidden();
        $this->actingAs($this->user('ADMIN'))->post('/admin/student-imports', ['file' => UploadedFile::fake()->create('payload.php', 2, 'application/x-php'), 'course_mode' => 'slug', 'existing_policy' => 'SKIP_EXISTING'])->assertSessionHasErrors('file');
    }

    public function test_max_rows_and_dates_are_enforced(): void
    {
        config(['student_imports.max_rows' => 1]);
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("first_name,last_name,mobile\nA,B,09121111111\nC,D,09122222222"))->assertSessionHasErrors('file');
        config(['student_imports.max_rows' => 5000]);
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("first_name,last_name,mobile,enrollment_starts_at,enrollment_ends_at\nA,B,09121111111,2026-12-31,2026-01-01"))->assertRedirect();
        $this->assertSame(1, StudentImportBatch::first()->invalid_rows);
    }

    public function test_error_csv_has_bom_and_no_credentials(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/student-imports', $this->upload("first_name,last_name,mobile\nBad,Row,invalid"));
        $batch = StudentImportBatch::first();
        $content = $this->actingAs($admin)->get("/admin/student-imports/{$batch->id}/errors")->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('row_number,mobile,name,error_code,error_message', $content);
        $this->assertStringNotContainsString('password', strtolower($content));
    }

    public function test_valid_xlsx_is_previewed_and_invalid_xlsx_is_rejected(): void
    {
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->post('/admin/student-imports', $this->xlsxUpload())->assertRedirect();
        $this->assertSame(1, StudentImportBatch::firstOrFail()->valid_rows);
        $this->assertDatabaseMissing('users', ['mobile' => '+989121234567']);

        $invalid = ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip'), 'course_mode' => 'slug', 'course_id' => null, 'existing_policy' => 'SKIP_EXISTING'];
        $this->actingAs($admin)->post('/admin/student-imports', $invalid)->assertSessionHasErrors('file');
    }

    public function test_bulk_status_and_enrollment_are_safe_and_audited(): void
    {
        $admin = $this->user('ADMIN');
        $a = $this->user('STUDENT');
        $b = $this->user('STUDENT');
        $course = Course::factory()->create();
        $this->actingAs($admin)->post('/admin/users/bulk/status', ['user_ids' => [$a->id, $b->id], 'active' => false])->assertRedirect();
        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->actingAs($admin)->post('/admin/users/bulk/enroll', ['user_ids' => [$a->id, $b->id], 'course_id' => $course->id])->assertRedirect();
        $this->assertSame(2, CourseEnrollment::count());
        $this->actingAs($admin)->post('/admin/users/bulk/status', ['user_ids' => [$admin->id], 'active' => false])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.bulk_enrolled']);
    }

    private function upload(string $content, array $extra = []): array
    {
        return array_merge(['file' => UploadedFile::fake()->createWithContent('students.csv', $content), 'course_mode' => 'slug', 'course_id' => null, 'existing_policy' => 'ENROLL_EXISTING_ONLY'], $extra);
    }

    private function xlsxUpload(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'phase16-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Students" sheetId="1"/></sheets></workbook>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>first_name</t></is></c><c r="B1" t="inlineStr"><is><t>last_name</t></is></c><c r="C1" t="inlineStr"><is><t>mobile</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Ali</t></is></c><c r="B2" t="inlineStr"><is><t>Ahmadi</t></is></c><c r="C2" t="inlineStr"><is><t>09121234567</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        return ['file' => new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), 'course_mode' => 'slug', 'course_id' => null, 'existing_policy' => 'SKIP_EXISTING'];
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
