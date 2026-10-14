<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActiveSessionService
{
    public function rows(User $user): Collection
    {
        return DB::table(config('session.table'))->where('user_id', $user->id)
            ->where('last_activity', '>=', $this->cutoff())->orderByDesc('last_activity')->get();
    }

    public function count(User $user, ?string $exceptId = null): int
    {
        return $this->rows($user)->reject(fn ($row) => $exceptId !== null && hash_equals($row->id, $exceptId))->count();
    }

    public function descriptors(User $user, string $currentId): array
    {
        return $this->rows($user)->map(fn ($row) => [
            'token' => $this->token($row->id),
            'device' => $this->describe((string) $row->user_agent),
            'ip' => $this->maskIp($row->ip_address),
            'last_activity' => date(DATE_ATOM, $row->last_activity),
            'current' => hash_equals($row->id, $currentId),
        ])->values()->all();
    }

    public function revoke(User $user, string $token, ?string $protectedId = null): bool
    {
        foreach ($this->rows($user) as $row) {
            if (hash_equals($this->token($row->id), $token)) {
                if ($protectedId && hash_equals($row->id, $protectedId)) {
                    return false;
                }

                return DB::table(config('session.table'))->where('id', $row->id)->where('user_id', $user->id)->delete() === 1;
            }
        }

        return false;
    }

    private function cutoff(): int
    {
        return now()->subMinutes((int) config('session.lifetime'))->timestamp;
    }

    private function token(string $id): string
    {
        return hash_hmac('sha256', $id, (string) config('app.key'));
    }

    private function describe(string $agent): string
    {
        $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Safari/') ? 'Safari' : 'مرورگر')));
        $os = str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') ? 'iOS' : (str_contains($agent, 'Macintosh') ? 'macOS' : 'سیستم ناشناس')));

        return $browser.' — '.$os;
    }

    private function maskIp(?string $ip): string
    {
        if (! $ip) {
            return 'نامشخص';
        }
        if (str_contains($ip, ':')) {
            return substr($ip, 0, 4).'…';
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.*.*" : 'نامشخص';
    }
}
