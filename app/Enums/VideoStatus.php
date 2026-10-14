<?php

namespace App\Enums;

enum VideoStatus: string
{
    case Uploading = 'UPLOADING';
    case Stored = 'STORED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
}
