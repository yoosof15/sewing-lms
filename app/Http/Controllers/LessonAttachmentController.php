<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLessonAttachmentRequest;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonAttachmentController extends Controller
{
    public function store(StoreLessonAttachmentRequest $request, Lesson $lesson, AuditService $audit): RedirectResponse
    {
        $file = $request->file('attachment');
        $path = $file->store('lesson-attachments', 'local');
        try {
            $attachment = $lesson->attachments()->create(['uploaded_by' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $audit->record('attachment.uploaded', $attachment, ['lesson_id' => $lesson->id, 'mime_type' => $attachment->mime_type, 'size' => $attachment->file_size]);

        return back()->with('success', 'فایل ضمیمه شد.');
    }

    public function destroy(Request $request, LessonAttachment $attachment, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $attachment);
        $attachment->delete();
        $audit->record('attachment.archived', $attachment, ['lesson_id' => $attachment->lesson_id]);

        return back()->with('success', 'فایل بایگانی شد.');
    }

    public function download(LessonAttachment $attachment): StreamedResponse
    {
        $this->authorize('download', $attachment);
        abort_unless(str_starts_with($attachment->storage_path, 'lesson-attachments/') && ! str_contains($attachment->storage_path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404);

        return Storage::disk('local')->download($attachment->storage_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }
}
