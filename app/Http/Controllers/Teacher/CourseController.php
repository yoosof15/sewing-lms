<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Course::class);
        $courses = request()->user()->taughtCourses()->with(['category:id,name', 'teachers:id,first_name,last_name'])->latest('courses.updated_at')->paginate(15);

        return Inertia::render('Teacher/Courses/Index', ['courses' => $courses]);
    }

    public function show(Course $course): Response
    {
        $this->authorize('view', $course);

        return Inertia::render('Teacher/Courses/Show', ['course' => $course->load(['category:id,name', 'teachers:id,first_name,last_name', 'lessons.creator:id,first_name,last_name'])]);
    }
}
