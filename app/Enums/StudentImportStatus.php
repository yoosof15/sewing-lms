<?php

namespace App\Enums;

enum StudentImportStatus: string
{
    case Validated = 'VALIDATED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case CompletedWithErrors = 'COMPLETED_WITH_ERRORS';
    case Failed = 'FAILED';
}
