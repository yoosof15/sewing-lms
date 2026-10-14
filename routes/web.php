<?php

use App\Http\Controllers\ActiveSessionController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CourseCategoryController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PurgeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\LessonAttachmentController;
use App\Http\Controllers\LessonCompletionController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\LessonVideoController;
use App\Http\Controllers\Student\CourseController as StudentCourseController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
use App\Http\Controllers\VideoPlaybackController;
use App\Http\Controllers\VideoProgressController;
use App\Http\Controllers\VideoUploadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
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
            Route::delete('courses/{course}', [ArchiveController::class, 'course'])->name('courses.destroy');
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('operations', [OperationsController::class, 'index'])->name('operations.index');
            Route::get('archive', [ArchiveController::class, 'index'])->name('archive.index');
            Route::delete('categories/{category}/archive', [ArchiveController::class, 'category'])->name('categories.archive');
            Route::post('archive/categories/{category}/restore', [ArchiveController::class, 'restoreCategory'])->name('archive.categories.restore');
            Route::post('archive/courses/{course}/restore', [ArchiveController::class, 'restoreCourse'])->name('archive.courses.restore');
            Route::post('courses/{course}/lessons/{lesson}/restore', [ArchiveController::class, 'restoreLesson'])->name('lessons.restore');
            Route::post('lessons/{lesson}/videos/{video}/restore', [ArchiveController::class, 'restoreVideo'])->name('videos.restore');
            Route::delete('purge/courses/{course}', [PurgeController::class, 'course'])->name('purge.courses');
            Route::delete('purge/attachments/{attachment}', [PurgeController::class, 'attachment'])->name('purge.attachments');
            Route::delete('purge/videos/{video}', [PurgeController::class, 'video'])->name('purge.videos');
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
        Route::delete('attachments/{attachment}', [LessonAttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::get('attachments/{attachment}/download', [LessonAttachmentController::class, 'download'])->name('attachments.download');
        Route::post('lessons/{lesson}/video-uploads', [VideoUploadController::class, 'initiate'])->middleware('throttle:20,1')->name('video-uploads.initiate');
        Route::get('video-uploads/{uploadSession}', [VideoUploadController::class, 'show'])->name('video-uploads.show');
        Route::patch('video-uploads/{uploadSession}/chunk', [VideoUploadController::class, 'chunk'])->name('video-uploads.chunk');
        Route::post('video-uploads/{uploadSession}/finalize', [VideoUploadController::class, 'finalize'])->name('video-uploads.finalize');
        Route::delete('video-uploads/{uploadSession}', [VideoUploadController::class, 'cancel'])->name('video-uploads.cancel');
        Route::post('lessons/{lesson}/videos/{video}/move/{direction}', [LessonVideoController::class, 'move'])->name('videos.move');
        Route::post('lessons/{lesson}/videos/{video}/retry', [LessonVideoController::class, 'retry'])->middleware('throttle:10,1')->name('videos.retry');
        Route::patch('lessons/{lesson}/videos/{video}/publication', [LessonVideoController::class, 'publication'])->name('videos.publication');
        Route::get('student/courses/{course}/lessons/{lesson}/videos/{video}/stream', [VideoPlaybackController::class, 'student'])->name('student.videos.stream');
        Route::get('videos/{video}/preview', [VideoPlaybackController::class, 'preview'])->name('videos.preview');
        Route::post('student/courses/{course}/lessons/{lesson}/videos/{video}/progress', [VideoProgressController::class, 'update'])->middleware('throttle:120,1')->name('student.videos.progress');
        Route::post('student/courses/{course}/lessons/{lesson}/complete', [LessonCompletionController::class, 'store'])->name('student.lessons.complete');
        Route::get('account/sessions', [ActiveSessionController::class, 'index'])->name('sessions.index');
        Route::delete('account/sessions/{token}', [ActiveSessionController::class, 'destroy'])->middleware('throttle:20,1')->name('sessions.destroy');
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('admin/users/{user}/sessions', [ActiveSessionController::class, 'adminIndex'])->name('admin.users.sessions');
            Route::delete('admin/users/{user}/sessions/{token}', [ActiveSessionController::class, 'adminDestroy'])->name('admin.users.sessions.destroy');
        });
        Route::delete('lessons/{lesson}/videos/{video}', [LessonVideoController::class, 'destroy'])->name('videos.destroy');
    });
});
