<?php

return [
    'progress_heartbeat_seconds' => (int) env('VIDEO_PROGRESS_HEARTBEAT_SECONDS', 12),
    'max_playback_rate_credit' => (float) env('VIDEO_PROGRESS_MAX_RATE', 2),
    'progress_credit_tolerance_seconds' => (int) env('VIDEO_PROGRESS_CREDIT_TOLERANCE_SECONDS', 3),
    'watermark_enabled' => filter_var(env('VIDEO_WATERMARK_ENABLED', true), FILTER_VALIDATE_BOOL),
    'watermark_move_seconds' => (int) env('VIDEO_WATERMARK_MOVE_SECONDS', 30),
    'max_active_sessions' => (int) env('AUTH_MAX_ACTIVE_SESSIONS', 2),
];
