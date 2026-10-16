<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneSessions extends Command
{
    protected $signature = 'sessions:prune {--dry-run : Count expired database sessions without deleting them}';

    protected $description = 'Prune database sessions older than the configured session lifetime';

    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            $this->components->warn('Session driver is not database; nothing to prune.');

            return self::SUCCESS;
        }

        $table = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            $this->components->error("Session table [{$table}] does not exist.");

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $query = DB::table($table)->where('last_activity', '<', $cutoff);
        $count = $this->option('dry-run') ? $query->count() : $query->delete();
        $verb = $this->option('dry-run') ? 'would be pruned' : 'pruned';
        $this->components->info("{$count} expired session(s) {$verb}.");

        return self::SUCCESS;
    }
}
