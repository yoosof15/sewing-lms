<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseCategoryRequest;
use App\Http\Requests\Admin\UpdateCourseCategoryRequest;
use App\Models\CourseCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CourseCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CourseCategory::class);

        return Inertia::render('Admin/Categories/Index', ['categories' => CourseCategory::query()->withCount('courses')->orderBy('sort_order')->orderBy('name')->paginate(15)]);
    }

    public function create(): Response
    {
        $this->authorize('create', CourseCategory::class);

        return Inertia::render('Admin/Categories/Form', ['category' => null]);
    }

    public function store(StoreCourseCategoryRequest $request): RedirectResponse
    {
        CourseCategory::create($request->validated());

        return redirect()->route('admin.categories.index')->with('success', 'دسته‌بندی ایجاد شد.');
    }

    public function edit(CourseCategory $category): Response
    {
        $this->authorize('update', $category);

        return Inertia::render('Admin/Categories/Form', ['category' => $category]);
    }

    public function update(UpdateCourseCategoryRequest $request, CourseCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('admin.categories.index')->with('success', 'دسته‌بندی به‌روزرسانی شد.');
    }
}
