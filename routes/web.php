<?php

use App\Http\Controllers\Admin\CourseCategoryController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
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
        });
        Route::prefix('teacher')->name('teacher.')->middleware('role:TEACHER')->group(function () {
            Route::get('/', fn () => Inertia::render('Dashboard', ['title' => 'داشبورد مدرس']))->name('dashboard');
            Route::resource('courses', TeacherCourseController::class)->only(['index', 'show']);
        });
        Route::prefix('student')->name('student.')->middleware('role:STUDENT')->group(function () {
            Route::get('/', fn () => Inertia::render('Dashboard', ['title' => 'داشبورد هنرجو']))->name('dashboard');
        });
    });
});
