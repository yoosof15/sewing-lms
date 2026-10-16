<?php

declare(strict_types=1);
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sewing-lms-backup-'.bin2hex(random_bytes(6));
mkdir($root, 0700, true);

try {
    $database = $root.DIRECTORY_SEPARATOR.'source.sqlite';
    touch($database);
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$database);
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
    $migrationCount = DB::table('migrations')->count();
    $roleCount = DB::table('roles')->count();
    if ($migrationCount < 1 || $roleCount !== 3) {
        throw new RuntimeException('Controlled migration/seed verification failed.');
    }
    DB::disconnect();

    $databaseBackup = $root.DIRECTORY_SEPARATOR.'database.backup.sqlite';
    copy($database, $databaseBackup);
    if (filesize($databaseBackup) < 1) {
        throw new RuntimeException('Database backup is empty.');
    }
    unlink($database);
    $restoredDatabase = $root.DIRECTORY_SEPARATOR.'restored.sqlite';
    copy($databaseBackup, $restoredDatabase);
    $restored = new PDO('sqlite:'.$restoredDatabase);
    if ((int) $restored->query('SELECT COUNT(*) FROM migrations')->fetchColumn() !== $migrationCount || (int) $restored->query('SELECT COUNT(*) FROM roles')->fetchColumn() !== $roleCount) {
        throw new RuntimeException('Database restore content mismatch.');
    }
    unset($restored);
    echo "PASS database backup and restore ({$migrationCount} migrations, {$roleCount} seeded roles)\n";

    $files = $root.DIRECTORY_SEPARATOR.'private-files';
    mkdir($files);
    file_put_contents($files.DIRECTORY_SEPARATOR.'attachment.bin', random_bytes(4096));
    $backup = $root.DIRECTORY_SEPARATOR.'private-files.backup';
    mkdir($backup);
    copy($files.DIRECTORY_SEPARATOR.'attachment.bin', $backup.DIRECTORY_SEPARATOR.'attachment.bin');
    $restoredFiles = $root.DIRECTORY_SEPARATOR.'private-files.restored';
    mkdir($restoredFiles);
    copy($backup.DIRECTORY_SEPARATOR.'attachment.bin', $restoredFiles.DIRECTORY_SEPARATOR.'attachment.bin');
    if (hash_file('sha256', $files.DIRECTORY_SEPARATOR.'attachment.bin') !== hash_file('sha256', $restoredFiles.DIRECTORY_SEPARATOR.'attachment.bin')) {
        throw new RuntimeException('File restore checksum mismatch.');
    }
    echo "PASS private-file backup and restore (SHA-256 verified)\n";
    echo "PASS rehearsal used only temporary synthetic data\n";
} finally {
    $delete = function (string $path) use (&$delete): void {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
                $delete($path.DIRECTORY_SEPARATOR.$item);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    };
    if (is_dir($root)) {
        $delete($root);
    }
}
