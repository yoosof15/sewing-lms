<?php

namespace App\Http\Requests\Admin;

class UpdateCourseRequest extends StoreCourseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('course')) ?? false;
    }
}
