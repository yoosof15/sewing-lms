<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonManualCompletion;
use App\Services\LearningProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LessonCompletionController extends Controller
{
    public function store(Request $request, Course $course, Lesson $lesson, LearningProgressService $progress): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);
        $this->authorize('view', $lesson);
        abort_unless($request->user()->hasRole('STUDENT'), 403);
        abort_unless($progress->lesson($request->user(), $lesson)['manual_allowed'], 409, 'Video lessons cannot be completed manually.');
        LessonManualCompletion::updateOrCreate(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id], ['completed_at' => now()]);

        return back()->with('success', 'درس به‌عنوان تکمیل‌شده ثبت شد.');
    }
}
