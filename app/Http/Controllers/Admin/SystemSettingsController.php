<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\SystemSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function index(SystemSettings $settings): Response
    {
        $definitions = collect($settings->definitions())->reject(fn ($d, $key) => $key === 'branding_logo_path')->map(fn ($d, $key) => [...$d, 'key' => $key, 'value' => $settings->get($key)])->groupBy('group');

        return Inertia::render('Admin/Settings/Index', ['groups' => $definitions, 'branding' => ['logo_url' => $settings->string('branding_logo_path') ? Storage::disk('public')->url($settings->string('branding_logo_path')) : null]]);
    }

    public function update(Request $request, string $group, SystemSettings $settings, AuditService $audit): RedirectResponse
    {
        $definitions = collect($settings->definitions())->filter(fn ($d, $key) => $d['group'] === $group && $key !== 'branding_logo_path');
        abort_if($definitions->isEmpty(), 404);
        $unexpected = array_diff(array_keys($request->all()), array_merge($definitions->keys()->all(), ['_token', '_method']));
        if ($unexpected) {
            throw ValidationException::withMessages(['settings' => 'کلید تنظیم ناشناخته مجاز نیست.']);
        }
        $rules = $definitions->mapWithKeys(fn ($d, $key) => [$key => $d['rules']])->all();
        $data = $request->validate($rules);
        $before = $definitions->keys()->mapWithKeys(fn ($key) => [$key => $settings->get($key)])->all();
        $settings->setGroup($group, $data, $request->user()->id);
        foreach ($data as $key => $value) {
            $audit->record('system_setting.updated', SystemSetting::where('key', $key)->first(), ['group' => $group, 'key' => $key, 'from' => $before[$key], 'to' => $value]);
        }

        return back()->with('success', 'تنظیمات ذخیره شد.');
    }

    public function reset(Request $request, string $group, SystemSettings $settings, AuditService $audit): RedirectResponse
    {
        abort_unless(collect($settings->definitions())->contains(fn ($d) => $d['group'] === $group), 404);
        $settings->resetGroup($group);
        $audit->record('system_setting.group_reset', null, ['group' => $group]);

        return back()->with('success', 'تنظیمات این بخش به مقدار پیش‌فرض بازگشت.');
    }

    public function logo(Request $request, SystemSettings $settings, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', Rule::dimensions()->maxWidth(3000)->maxHeight(3000)]]);
        $new = $data['logo']->store('branding', 'public');
        $old = $settings->string('branding_logo_path');
        $settings->setGroup('branding', ['branding_logo_path' => $new], $request->user()->id);
        if ($old && str_starts_with($old, 'branding/')) {
            Storage::disk('public')->delete($old);
        }
        $audit->record('branding.logo_updated', SystemSetting::where('key', 'branding_logo_path')->first(), ['group' => 'branding']);

        return back()->with('success', 'لوگو به‌روزرسانی شد.');
    }
}
