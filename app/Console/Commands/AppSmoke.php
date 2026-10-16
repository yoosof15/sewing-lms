<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AppSmoke extends Command
{
    protected $signature = 'app:smoke {--write-storage : Perform a temporary private-storage write/delete check}';

    protected $description = 'Run safe application, database, cache, and optional storage smoke checks';

    public function handle(): int
    {
        $failures = 0;
        $checks = [
            'database' => fn () => DB::select('select 1'),
            'cache' => function (): void {
                $key = 'smoke:'.bin2hex(random_bytes(8));
                Cache::put($key, 'ok', 10);
                if (Cache::pull($key) !== 'ok') {
                    throw new \RuntimeException('Cache round trip failed.');
                }
            },
        ];
        if ($this->option('write-storage')) {
            $checks['private storage'] = function (): void {
                $path = 'smoke/'.bin2hex(random_bytes(8)).'.txt';
                try {
                    if (! Storage::disk('local')->put($path, 'smoke') || Storage::disk('local')->get($path) !== 'smoke') {
                        throw new \RuntimeException('Storage round trip failed.');
                    }
                } finally {
                    Storage::disk('local')->delete($path ?? '');
                }
            };
        }
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->line("PASS  {$name}");
            } catch (Throwable $e) {
                $failures++;
                $this->error("FAIL  {$name}: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
