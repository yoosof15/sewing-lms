<?php

use App\Http\Controllers\Admin\CourseCategoryController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\LessonAttachmentController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\LessonVideoController;
use App\Http\Controllers\Student\CourseController as StudentCourseController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
use App\Http\Controllers\VideoUploadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::middleware('active')->group(function () {
        Route::get('/dashboard', function () {
            $role = strtolower(request()->user()->getRoleNames()->first());

            return redirect()->route($role.'.dashboard');
        })->name('dashboard');
        Route::prefix('admin')->name('admin.')->middleware('role:ADMIN')->group(function () {
            Route::get('/', fn () => Inertia::render('Dashboard', ['title' => 'داشبورد مدیر']))->name('dashboard');
            Route::resource('users', UserController::class)->except(['show', 'destroy']);
            Route::resource('categories', CourseCategoryController::class)->except(['show', 'destroy']);
            Route::resource('courses', AdminCourseController::class)->except(['destroy']);
            Route::get('courses/{course}/enrollments', [EnrollmentController::class, 'index'])->name('courses.enrollments.index');
            Route::post('courses/{course}/enrollments', [EnrollmentController::class, 'store'])->name('courses.enrollments.store');
            Route::put('courses/{course}/enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('courses.enrollments.update');
            Route::get('courses/{course}/lessons/create', [LessonController::class, 'create'])->name('lessons.create');
            Route::post('courses/{course}/lessons', [LessonController::class, 'store'])->name('lessons.store');
            Route::get('courses/{course}/lessons/{lesson}/edit', [LessonController::class, 'edit'])->name('lessons.edit');
            Route::put('courses/{course}/lessons/{lesson}', [LessonController::class, 'update'])->name('lessons.update');
            Route::delete('courses/{course}/lessons/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');
            Route::post('courses/{course}/lessons/{lesson}/move/{direction}', [LessonController::class, 'move'])->name('lessons.move');
        });
        Route::prefix('teacher')->name('teacher.')->middleware('role:TEACHER')->group(function () {
            Route::get('/', fn () => Inertia::render('Dashboard', ['title' => 'داشبورد مدرس']))->name('dashboard');
            Route::resource('courses', TeacherCourseController::class)->only(['index', 'show']);
            Route::get('courses/{course}/lessons/create', [LessonController::class, 'create'])->name('lessons.create');
            Route::post('courses/{course}/lessons', [LessonController::class, 'store'])->name('lessons.store');
            Route::get('courses/{course}/lessons/{lesson}/edit', [LessonController::class, 'edit'])->name('lessons.edit');
            Route::put('courses/{course}/lessons/{lesson}', [LessonController::class, 'update'])->name('lessons.update');
            Route::delete('courses/{course}/lessons/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');
            Route::post('courses/{course}/lessons/{lesson}/move/{direction}', [LessonController::class, 'move'])->name('lessons.move');
        });
        Route::prefix('student')->name('student.')->middleware('role:STUDENT')->group(function () {
            Route::get('/', [StudentCourseController::class, 'index'])->name('dashboard');
            Route::get('courses', [StudentCourseController::class, 'index'])->name('courses.index');
            Route::get('courses/{course}', [StudentCourseController::class, 'show'])->name('courses.show');
            Route::get('courses/{course}/lessons/{lesson}', [StudentCourseController::class, 'lesson'])->name('lessons.show');
        });
        Route::post('lessons/{lesson}/attachments', [LessonAttachmentController::class, 'store'])->name('attachments.store');
        Route::get('attachments/{attachment}/download', [LessonAttachmentController::class, 'download'])->name('attachments.download');
        Route::post('lessons/{lesson}/video-uploads', [VideoUploadController::class, 'initiate'])->name('video-uploads.initiate');
        Route::get('video-uploads/{uploadSession}', [VideoUploadController::class, 'show'])->name('video-uploads.show');
        Route::patch('video-uploads/{uploadSession}/chunk', [VideoUploadController::class, 'chunk'])->name('video-uploads.chunk');
        Route::post('video-uploads/{uploadSession}/finalize', [VideoUploadController::class, 'finalize'])->name('video-uploads.finalize');
        Route::delete('video-uploads/{uploadSession}', [VideoUploadController::class, 'cancel'])->name('video-uploads.cancel');
        Route::post('lessons/{lesson}/videos/{video}/move/{direction}', [LessonVideoController::class, 'move'])->name('videos.move');
        Route::delete('lessons/{lesson}/videos/{video}', [LessonVideoController::class, 'destroy'])->name('videos.destroy');
    });
});
