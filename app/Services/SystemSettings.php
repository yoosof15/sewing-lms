<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class SystemSettings
{
    private const CACHE_KEY = 'system-settings:overrides:v1';

    public function definitions(): array
    {
        return config('system_settings', []);
    }

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $raw = $this->overrides()[$key] ?? null;
        if ($raw === null) {
            return $definition['default'];
        }

        return match ($definition['type']) {
            'integer' => (int) $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOL),
            default => $raw,
        };
    }

    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return $value === null ? null : (string) $value;
    }

    public function integer(string $key): int
    {
        return (int) $this->get($key);
    }

    public function boolean(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function group(string $group): array
    {
        return collect($this->definitions())->filter(fn ($d) => $d['group'] === $group)->mapWithKeys(fn ($d, $key) => [$key => $this->get($key)])->all();
    }

    public function setGroup(string $group, array $values, int $actor): void
    {
        DB::transaction(function () use ($group, $values, $actor) {
            foreach ($values as $key => $value) {
                $definition = $this->definition($key);
                if ($definition['group'] !== $group) {
                    throw new InvalidArgumentException('Setting group mismatch.');
                }
                SystemSetting::updateOrCreate(['key' => $key], ['value' => $value === null ? null : ($definition['type'] === 'boolean' ? ($value ? '1' : '0') : (string) $value), 'type' => strtoupper($definition['type']), 'group' => $group, 'updated_by' => $actor]);
            }
        });
        $this->forget();
    }

    public function resetGroup(string $group): void
    {
        SystemSetting::where('group', $group)->delete();
        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function videoMaxUploadMb(): int
    {
        return min((int) config('video.max_upload_mb'), $this->integer('video_max_upload_mb'));
    }

    public function assignmentMaxFiles(): int
    {
        return min((int) config('assignments.max_files'), $this->integer('assignment_max_files'));
    }

    public function assignmentMaxFileKb(): int
    {
        return min((int) config('assignments.max_file_kb'), $this->integer('assignment_max_file_mb') * 1024);
    }

    public function qaMaxImages(): int
    {
        return min((int) config('q_and_a.max_images'), $this->integer('qa_max_images'));
    }

    public function qaMaxImageKb(): int
    {
        return min((int) config('q_and_a.max_image_kb'), $this->integer('qa_max_image_mb') * 1024);
    }

    private function overrides(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()->pluck('value', 'key')->all());
    }

    private function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown system setting: $key");
    }
}
