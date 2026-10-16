<?php

namespace App\Console\Commands;

use App\Models\LessonVideo;
use Illuminate\Console\Command;

class StorageEstimate extends Command
{
    protected $signature = 'storage:estimate {--students=0} {--monthly-uploads-gb=0} {--months=12}';

    protected $description = 'Estimate storage growth from database metadata and planning inputs';

    public function handle(): int
    {
        $source = (int) LessonVideo::withTrashed()->sum('size_bytes');
        $processed = (int) LessonVideo::withTrashed()->sum('processed_size_bytes');
        $monthly = max(0, (float) $this->option('monthly-uploads-gb'));
        $months = max(1, (int) $this->option('months'));
        $students = max(0, (int) $this->option('students'));
        $projected = ($source + $processed) / 1073741824 + $monthly * $months;
        $this->table(['Metric', 'Estimate'], [
            ['Current source videos', $this->gb($source)],
            ['Current processed videos', $this->gb($processed)],
            ['Planning students', number_format($students)],
            ["Projected video footprint after {$months} month(s)", number_format($projected, 2).' GiB'],
            ['Temporary processing headroom', 'at least 1.25x the largest source, preferably 2x'],
        ]);
        $this->warn('This is a planning estimate. Attachments, backups, logs, database growth, and provider versioning must be budgeted separately.');

        return self::SUCCESS;
    }

    private function gb(int $bytes): string
    {
        return number_format($bytes / 1073741824, 2).' GiB';
    }
}
