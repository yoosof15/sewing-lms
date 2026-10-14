<?php

namespace App\Contracts;

use App\Models\LessonVideo;
use Symfony\Component\HttpFoundation\Response;

interface VideoDelivery
{
    public function deliver(LessonVideo $video, ?string $range): Response;
}
