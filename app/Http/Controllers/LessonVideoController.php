<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class LessonVideoController extends Controller
{
    public function destroy(Lesson $lesson, LessonVideo $video): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('delete', $video);
        DB::transaction(function () use ($lesson, $video) {
            $video->update(['sort_order' => 4000000000 + $video->id]);
            $video->delete();
            $this->normalize($lesson);
        });

        return back()->with('success', 'ویدئو بایگانی شد.');
    }

    public function move(Lesson $lesson, LessonVideo $video, string $direction): RedirectResponse
    {
        $this->bound($lesson, $video);
        $this->authorize('update', $video);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);
        $op = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $other = $lesson->videos()->where('sort_order', $op, $video->sort_order)->orderBy('sort_order', $order)->first();
        if ($other) {
            DB::transaction(function () use ($video, $other) {
                $a = $video->sort_order;
                $b = $other->sort_order;
                $video->update(['sort_order' => 0]);
                $other->update(['sort_order' => $a]);
                $video->update(['sort_order' => $b]);
            });
        }

        return back();
    }

    private function bound(Lesson $lesson, LessonVideo $video): void
    {
        abort_unless($video->lesson_id === $lesson->id, 404);
    }

    private function normalize(Lesson $lesson): void
    {
        $videos = $lesson->videos()->orderBy('sort_order')->get();
        $lesson->videos()->update(['sort_order' => DB::raw('sort_order + 1000000')]);
        $videos->each(fn ($v, $i) => $v->update(['sort_order' => $i + 1]));
    }
}
