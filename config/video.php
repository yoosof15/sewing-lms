<?php

return [
    'storage_driver' => env('VIDEO_STORAGE_DRIVER', 'local'),
    'max_upload_mb' => (int) env('VIDEO_MAX_UPLOAD_MB', 10240),
    'chunk_mb' => (int) env('VIDEO_UPLOAD_CHUNK_MB', 5),
    'session_ttl_hours' => (int) env('VIDEO_UPLOAD_SESSION_TTL_HOURS', 24),
];
