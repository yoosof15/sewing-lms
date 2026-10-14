<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditService
{
    private const SENSITIVE = ['password', 'password_confirmation', 'remember_token', 'token', 'payload', 'cookie', 'csrf', 'storage_key', 'processed_storage_key', 'temporary_key', 'secret', 'credentials'];

    public function record(string $action, ?Model $subject = null, array $metadata = [], ?string $description = null): void
    {
        try {
            AuditLog::query()->create([
                'actor_user_id' => auth()->id(),
                'action' => $action,
                'auditable_type' => $subject ? $subject->getMorphClass() : null,
                'auditable_id' => $subject?->getKey(),
                'description' => $description,
                'metadata' => $this->sanitize($metadata),
                'ip_address' => request()?->ip(),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 160),
            ]);
        } catch (Throwable $exception) {
            Log::error('Audit log write failed', ['action' => $action, 'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'exception' => $exception::class]);
        }
    }

    public function changes(Model $model, array $allowed): array
    {
        $changes = [];
        foreach (array_intersect($allowed, array_keys($model->getChanges())) as $field) {
            $changes[$field] = ['from' => $model->getOriginal($field), 'to' => $model->getAttribute($field)];
        }

        return $changes;
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (collect(self::SENSITIVE)->contains(fn ($word) => str_contains($lower, $word))) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $data[$key] = mb_substr($value, 0, 500);
            }
        }

        return $data;
    }
}
