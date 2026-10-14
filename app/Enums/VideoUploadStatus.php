<?php

namespace App\Enums;

enum VideoUploadStatus: string
{
    case Uploading = 'UPLOADING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
}
