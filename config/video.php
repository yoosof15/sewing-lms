<?php

return [
    'storage_driver' => env('VIDEO_STORAGE_DRIVER', 'local'),
    'max_upload_mb' => (int) env('VIDEO_MAX_UPLOAD_MB', 10240),
    'chunk_mb' => (int) env('VIDEO_UPLOAD_CHUNK_MB', 5),
    'session_ttl_hours' => (int) env('VIDEO_UPLOAD_SESSION_TTL_HOURS', 24),
    'processor_driver' => env('VIDEO_PROCESSOR_DRIVER', 'ffmpeg'),
    'processing_queue' => env('VIDEO_PROCESSING_QUEUE', 'video'),
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'target_max_width' => (int) env('VIDEO_TARGET_MAX_WIDTH', 1920),
    'target_max_height' => (int) env('VIDEO_TARGET_MAX_HEIGHT', 1080),
    'target_max_fps' => (float) env('VIDEO_TARGET_MAX_FPS', 30),
    'video_codec' => env('VIDEO_VIDEO_CODEC', 'libx264'),
    'audio_codec' => env('VIDEO_AUDIO_CODEC', 'aac'),
    'crf' => (int) env('VIDEO_CRF', 22),
    'preset' => env('VIDEO_PRESET', 'medium'),
    'keep_original' => filter_var(env('VIDEO_KEEP_ORIGINAL', true), FILTER_VALIDATE_BOOL),
    'processing_max_attempts' => (int) env('VIDEO_PROCESSING_MAX_ATTEMPTS', 3),
    'processing_timeout' => (int) env('VIDEO_PROCESSING_TIMEOUT', 7200),
    'processing_temp_path' => env('VIDEO_PROCESSING_TEMP_PATH') ?: storage_path('app/private/video-processing'),
    'processing_disk_overhead' => (float) env('VIDEO_PROCESSING_DISK_OVERHEAD', 1.25),
];
