<?php

namespace App\Exceptions;

use RuntimeException;

class VideoOffsetMismatch extends RuntimeException
{
    public function __construct(public readonly int $expectedOffset)
    {
        parent::__construct('Upload offset mismatch.');
    }
}
