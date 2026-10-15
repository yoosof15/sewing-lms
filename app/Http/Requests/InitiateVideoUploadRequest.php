<?php

namespace App\Http\Requests;

use App\Services\SystemSettings;
use Illuminate\Foundation\Http\FormRequest;

class InitiateVideoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('lesson')) ?? false;
    }

    public function rules(): array
    {
        $max = app(SystemSettings::class)->videoMaxUploadMb() * 1024 * 1024;

        return ['title' => ['required', 'string', 'max:180'], 'original_filename' => ['required', 'string', 'max:255'], 'declared_mime_type' => ['nullable', 'string', 'max:120'], 'total_bytes' => ['required', 'integer', 'min:1', "max:$max"]];
    }
}
