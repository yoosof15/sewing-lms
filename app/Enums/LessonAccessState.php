<?php

namespace App\Enums;

enum LessonAccessState: string
{
    case Available = 'AVAILABLE';
    case LockedSequence = 'LOCKED_SEQUENCE';
    case LockedPrerequisite = 'LOCKED_PREREQUISITE';
    case Scheduled = 'SCHEDULED';
    case Unavailable = 'UNAVAILABLE';
}
