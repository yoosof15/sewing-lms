<?php

namespace App\Http\Requests\Admin;

class UpdateCourseCategoryRequest extends StoreCourseCategoryRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('category')) ?? false;
    }
}
