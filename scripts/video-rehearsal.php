<?php

declare(strict_types=1);

use App\Contracts\VideoProcessor;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sewing-lms-video-'.bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$source = $directory.DIRECTORY_SEPARATOR.'source.mp4';
$output = $directory.DIRECTORY_SEPARATOR.'processed.mp4';

try {
    $generator = new Process([
        (string) config('video.ffmpeg_binary'), '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=15',
        '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '1', '-pix_fmt', 'yuv420p',
        '-c:v', 'libx264', '-c:a', 'aac', $source,
    ]);
    $generator->setTimeout(30);
    $generator->run();
    if (! $generator->isSuccessful()) {
        throw new RuntimeException('Could not generate the synthetic source video.');
    }

    /** @var VideoProcessor $processor */
    $processor = $app->make(VideoProcessor::class);
    $input = $processor->inspect($source);
    $processor->process($source, $output, $input);
    $result = $processor->inspect($output);
    if ($result->duration <= 0 || $result->videoCodec !== 'h264' || ! is_file($output) || filesize($output) < 1) {
        throw new RuntimeException('Processed output validation failed.');
    }
    echo sprintf("PASS configured %s processor: %.2fs, %dx%d, %s, %d bytes\n", $processor->name(), $result->duration, $result->width, $result->height, $result->videoCodec, filesize($output));
    echo "PASS temporary synthetic media cleaned after rehearsal\n";
} finally {
    foreach ([$source, $output] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
