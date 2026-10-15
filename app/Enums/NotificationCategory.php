<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case CourseAccess = 'COURSE_ACCESS';
    case NewLesson = 'NEW_LESSON';
    case NewVideo = 'NEW_VIDEO';
    case TeacherAssignment = 'TEACHER_ASSIGNMENT';
    case LessonQa = 'LESSON_QA';
    case Assignment = 'ASSIGNMENT';

    public function mandatory(): bool
    {
        return in_array($this, [self::CourseAccess, self::TeacherAssignment], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CourseAccess => 'دسترسی دوره', self::NewLesson => 'درس جدید', self::NewVideo => 'ویدیوی جدید', self::TeacherAssignment => 'تخصیص تدریس', self::LessonQa => 'پرسش و پاسخ', self::Assignment => 'تکالیف'
        };
    }
}
