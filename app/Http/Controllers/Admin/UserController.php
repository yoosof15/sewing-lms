<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', ['users' => User::query()->with('roles:id,name')->latest()->paginate(15)->through(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'mobile' => $u->mobile,
            'email' => $u->email, 'is_active' => $u->is_active, 'role' => $u->roles->first()?->name,
        ])]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', ['managedUser' => null]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $role = $request->validated('role');
            $data = $request->safe()->except(['role', 'password_confirmation']);
            $user = User::create($data);
            $user->assignRole($role);
        });

        return redirect()->route('admin.users.index')->with('success', 'کاربر با موفقیت ایجاد شد.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', ['managedUser' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'mobile' => $user->mobile, 'email' => $user->email, 'is_active' => $user->is_active, 'role' => $user->getRoleNames()->first()]]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->hasRole('ADMIN') && ! $request->boolean('is_active') && User::role('ADMIN')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['is_active' => 'آخرین مدیر فعال را نمی‌توان غیرفعال کرد.']);
        }
        DB::transaction(function () use ($request, $user) {
            $role = $request->validated('role');
            $data = $request->safe()->except(['role', 'password_confirmation']);
            if (blank($data['password'] ?? null)) {
                unset($data['password']);
            } $user->update($data);
            if (! $user->hasRole('ADMIN')) {
                $user->syncRoles([$role]);
            }
        });

        return redirect()->route('admin.users.index')->with('success', 'اطلاعات کاربر به‌روزرسانی شد.');
    }
}
