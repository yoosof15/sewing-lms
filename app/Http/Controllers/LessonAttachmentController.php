<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLessonAttachmentRequest;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonAttachmentController extends Controller
{
    public function store(StoreLessonAttachmentRequest $request, Lesson $lesson): RedirectResponse
    {
        $file = $request->file('attachment');
        $path = $file->store('lesson-attachments', 'local');
        try {
            $lesson->attachments()->create(['uploaded_by' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'فایل ضمیمه شد.');
    }

    public function download(LessonAttachment $attachment): StreamedResponse
    {
        $this->authorize('download', $attachment);
        abort_unless(str_starts_with($attachment->storage_path, 'lesson-attachments/') && ! str_contains($attachment->storage_path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404);

        return Storage::disk('local')->download($attachment->storage_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }
}
