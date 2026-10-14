<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'actor' => ['nullable', 'integer'], 'action' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:160'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $logs = AuditLog::query()->with('actor:id,first_name,last_name')
            ->when($filters['actor'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('description', 'like', "%{$v}%"))
            ->latest()->paginate(25)->withQueryString();

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs, 'filters' => $filters,
            'actors' => User::query()->whereIn('id', AuditLog::query()->whereNotNull('actor_user_id')->select('actor_user_id'))->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'types' => AuditLog::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
        ]);
    }
}
