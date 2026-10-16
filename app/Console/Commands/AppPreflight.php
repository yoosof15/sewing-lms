<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class AppPreflight extends Command
{
    protected $signature = 'app:preflight {--production : Enforce production-only requirements}';

    protected $description = 'Read-only deployment preflight with redacted PASS/WARN/FAIL output';

    /** @var array<int, array{level:string,label:string,detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');

        $this->check(PHP_VERSION_ID >= 80200, 'PHP version', PHP_VERSION, true);
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml'] as $extension) {
            $this->check(extension_loaded($extension), "PHP extension {$extension}", extension_loaded($extension) ? 'loaded' : 'missing', true);
        }
        $this->check((bool) config('app.key'), 'Application key', config('app.key') ? 'configured (redacted)' : 'missing', true);
        $this->check(! $production || config('app.env') === 'production', 'Environment', (string) config('app.env'), $production);
        $this->check(! $production || config('app.debug') === false, 'Debug mode', config('app.debug') ? 'enabled' : 'disabled', $production);
        $url = (string) config('app.url');
        $this->check(! $production || str_starts_with($url, 'https://'), 'HTTPS application URL', $this->safeUrl($url), $production);
        $this->check(! $production || config('session.secure'), 'Secure session cookie', config('session.secure') ? 'enabled' : 'disabled', $production);
        $this->check((bool) config('session.http_only'), 'HttpOnly session cookie', config('session.http_only') ? 'enabled' : 'disabled', true);
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'SameSite session cookie', (string) config('session.same_site'), true);
        $this->check(is_writable(storage_path()), 'Storage writable', storage_path(), true);
        $this->check(is_writable(base_path('bootstrap/cache')), 'Bootstrap cache writable', base_path('bootstrap/cache'), true);
        $this->check(is_file(public_path('build/manifest.json')), 'Frontend manifest', 'public/build/manifest.json', true);

        try {
            DB::select('select 1');
            $this->recordPass('Database connection', (string) config('database.default'));
            $versionQuery = config('database.default') === 'sqlite' ? 'select sqlite_version() as version' : 'select version() as version';
            $version = (string) (array_values((array) (DB::selectOne($versionQuery) ?? []))[0] ?? 'unknown');
            $this->recordPass('Database version', mb_substr($version, 0, 100));
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()->diff(app('migrator')->getRepository()->getRan())->count();
            $this->check($pending === 0, 'Database migrations', "{$pending} pending", true);
            foreach ($this->requiredTables() as $table) {
                $this->check(Schema::hasTable($table), "Table {$table}", Schema::hasTable($table) ? 'present' : 'missing', true);
            }
            if (Schema::hasTable('jobs')) {
                $this->recordPass('Pending queue jobs', (string) DB::table('jobs')->count());
            }
            if (Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->count();
                $failed === 0 ? $this->recordPass('Failed queue jobs', '0') : $this->recordWarn('Failed queue jobs', "{$failed}; operator review required");
            }
        } catch (Throwable $e) {
            $this->recordFail('Database connection', $this->safeError($e));
        }

        $this->check(config('queue.default') !== 'sync' || ! $production, 'Queue driver', (string) config('queue.default'), $production);
        $this->check(config('session.driver') !== 'array' || ! $production, 'Session driver', (string) config('session.driver'), $production);
        $this->check(config('logging.default') !== 'single' || ! $production, 'Rotating logs', (string) config('logging.default'), false);
        $publicLink = public_path('storage');
        $this->check(file_exists($publicLink), 'Public storage link', file_exists($publicLink) ? 'present' : 'missing; run storage:link when public assets are used', false);
        $mailer = (string) config('mail.default');
        $mailConfigured = $mailer !== 'smtp' || ((string) config('mail.mailers.smtp.host') !== '' && (string) config('mail.from.address') !== '');
        $this->check($mailConfigured, 'Mail configuration', $mailer === 'log' ? 'log-only; provider required before delivery' : $mailer, false);
        $this->check(in_array(config('video.storage_driver'), ['local'], true), 'Video storage adapter', (string) config('video.storage_driver'), true);

        if (config('video.processor_driver') === 'ffmpeg') {
            $this->binary((string) config('video.ffmpeg_binary'), 'FFmpeg', false);
            $this->binary((string) config('video.ffprobe_binary'), 'FFprobe', false);
        } else {
            $this->recordWarn('Video processor', 'disabled or external; verify the replacement operationally');
        }

        try {
            $free = disk_free_space(storage_path());
            $this->check($free === false || $free >= 1024 * 1024 * 1024, 'Free application storage', $free === false ? 'unknown' : $this->bytes((int) $free), false);
        } catch (Throwable $e) {
            $this->recordWarn('Free application storage', $this->safeError($e));
        }
        $this->recordWarn('Scheduler cron', 'configure `php artisan schedule:run` every minute in production');

        foreach ($this->results as $result) {
            $method = $result['level'] === 'FAIL' ? 'error' : ($result['level'] === 'WARN' ? 'warn' : 'line');
            $this->{$method}(sprintf('%-4s  %-30s %s', $result['level'], $result['label'], $result['detail']));
        }
        $fails = collect($this->results)->where('level', 'FAIL')->count();
        $warnings = collect($this->results)->where('level', 'WARN')->count();
        $this->newLine();
        $this->line("Summary: {$fails} failure(s), {$warnings} warning(s). No values were changed.");

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function requiredTables(): array
    {
        return ['users', 'roles', 'courses', 'lessons', 'course_enrollments', 'jobs', 'failed_jobs', 'sessions', 'system_settings'];
    }

    private function binary(string $binary, string $label, bool $required): void
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(10);
            $process->run();
            $firstLine = strtok($process->getOutput(), "\r\n") ?: 'available';
            $this->check($process->isSuccessful(), $label, mb_substr($firstLine, 0, 160), $required);
        } catch (Throwable $e) {
            $this->check(false, $label, $this->safeError($e), $required);
        }
    }

    private function check(bool $condition, string $label, string $detail, bool $required): void
    {
        $condition ? $this->recordPass($label, $detail) : ($required ? $this->recordFail($label, $detail) : $this->recordWarn($label, $detail));
    }

    private function recordPass(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'PASS', 'label' => $label, 'detail' => $detail];
    }

    private function recordWarn(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'WARN', 'label' => $label, 'detail' => $detail];
    }

    private function recordFail(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'FAIL', 'label' => $label, 'detail' => $detail];
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : 'invalid URL';
    }

    private function safeError(Throwable $e): string
    {
        return class_basename($e).' (details redacted; inspect protected logs)';
    }

    private function bytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024 / 1024, 1).' GiB';
    }
}
