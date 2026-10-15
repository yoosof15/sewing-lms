<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LessonQuestion;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\AuditService;
use App\Services\LearningReportService;
use App\Support\IranianMobile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'role' => ['nullable', 'in:ADMIN,TEACHER,STUDENT'], 'active' => ['nullable', 'in:0,1'], 'sort' => ['nullable', 'in:newest,oldest,name']]);
        $search = trim((string) ($filters['search'] ?? ''));
        $normalized = null;
        if ($search !== '') {
            try {
                $normalized = IranianMobile::normalize($search);
            } catch (\InvalidArgumentException) {
            }
        }
        $query = User::query()->with(['roles:id,name', 'profile:user_id,avatar_path'])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$search%")
                ->orWhere('last_name', 'like', "%$search%")
                ->orWhere('name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->when($normalized, fn ($q) => $q->orWhere('mobile', $normalized))))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when(isset($filters['active']) && $filters['active'] !== '', fn ($q) => $q->where('is_active', (bool) $filters['active']));
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest(),
            'name' => $query->orderBy('first_name')->orderBy('last_name'),
            default => $query->latest(),
        };

        return Inertia::render('Admin/Users/Index', ['filters' => $filters, 'courses' => Course::query()->orderBy('title')->get(['id', 'title']), 'users' => $query->paginate(15)->withQueryString()->through(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'mobile' => $u->mobile,
            'email' => $u->email, 'is_active' => $u->is_active, 'role' => $u->roles->first()?->name,
            'avatar_url' => $u->profile?->avatar_path ? Storage::disk('public')->url($u->profile->avatar_path) : null,
        ])]);
    }

    public function show(Request $request, User $user, LearningReportService $reports, ActiveSessionService $sessions): Response
    {
        $user->load('profile', 'roles:id,name');
        $role = $user->roles->first()?->name;
        $studentReport = $role === 'STUDENT' ? $reports->student($request->user(), $user) : null;
        $teacher = null;
        if ($role === 'TEACHER') {
            $courseIds = $user->taughtCourses()->pluck('courses.id');
            $teacher = [
                'courses' => $user->taughtCourses()->withCount('enrollments')->orderBy('title')->get(['courses.id', 'title']),
                'active_students' => CourseEnrollment::whereIn('course_id', $courseIds)->where('status', 'ACTIVE')->distinct('student_id')->count('student_id'),
                'pending_submissions' => AssignmentSubmission::whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])->whereHas('assignment.lesson', fn ($q) => $q->whereIn('course_id', $courseIds))->count(),
                'unanswered_questions' => LessonQuestion::where('status', 'OPEN')->whereHas('lesson', fn ($q) => $q->whereIn('course_id', $courseIds))->count(),
            ];
        }

        return Inertia::render('Admin/Users/Show', [
            'managedUser' => [
                'id' => $user->id, 'name' => $user->name, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
                'mobile' => $user->mobile, 'email' => $user->email, 'role' => $role, 'is_active' => $user->is_active,
                'created_at' => $user->created_at, 'avatar_url' => $user->profile?->avatar_path ? Storage::disk('public')->url($user->profile->avatar_path) : null,
                'bio' => $user->profile?->bio, 'teacher_specialty' => $user->profile?->teacher_specialty,
                'teacher_experience_text' => $user->profile?->teacher_experience_text,
            ],
            'studentReport' => $studentReport, 'teacherSummary' => $teacher,
            'sessions' => $sessions->descriptors($user, $request->session()->getId()),
            'summaries' => [
                'assignments' => AssignmentSubmission::where('student_id', $user->id)->count(),
                'questions' => LessonQuestion::where('student_id', $user->id)->count(),
                'notifications' => $user->notifications()->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', ['managedUser' => null]);
    }

    public function store(StoreUserRequest $request, AuditService $audit): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $role = $request->validated('role');
            $data = $request->safe()->except(['role', 'password_confirmation']);
            $user = User::create($data);
            $user->assignRole($role);

            return $user;
        });
        $audit->record('user.created', $user, ['role' => $user->getRoleNames()->first(), 'active' => $user->is_active]);

        return redirect()->route('admin.users.index')->with('success', 'کاربر با موفقیت ایجاد شد.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', ['managedUser' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'mobile' => $user->mobile, 'email' => $user->email, 'is_active' => $user->is_active, 'role' => $user->getRoleNames()->first()]]);
    }

    public function update(UpdateUserRequest $request, User $user, AuditService $audit): RedirectResponse
    {
        if ($user->hasRole('ADMIN') && ! $request->boolean('is_active') && User::role('ADMIN')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['is_active' => 'آخرین مدیر فعال را نمی‌توان غیرفعال کرد.']);
        }
        $beforeRole = $user->getRoleNames()->first();
        $beforeActive = $user->is_active;
        $passwordChanged = filled($request->validated('password'));
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
        $user->refresh();
        $afterRole = $user->getRoleNames()->first();
        $audit->record('user.updated', $user, ['changed_fields' => array_values(array_diff(array_keys($user->getChanges()), ['password']))]);
        if ($beforeRole !== $afterRole) {
            $audit->record('user.role_changed', $user, ['from' => $beforeRole, 'to' => $afterRole]);
        }
        if ($beforeActive !== $user->is_active) {
            $audit->record($user->is_active ? 'user.activated' : 'user.deactivated', $user);
        }
        if ($passwordChanged) {
            $audit->record('user.password_changed_by_admin', $user);
        }

        return redirect()->route('admin.users.index')->with('success', 'اطلاعات کاربر به‌روزرسانی شد.');
    }
}
