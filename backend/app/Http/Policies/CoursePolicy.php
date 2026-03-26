<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /** Any authenticated user can view the list */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Any authenticated user can view a single course */
    public function view(User $user, Course $course): bool
    {
        return true;
    }

    /** Only teachers can create courses */
    public function create(User $user): bool
    {
        return $user->isTeacher();
    }

    /** Only the instructor who owns the course can update it */
    public function update(User $user, Course $course): bool
    {
        return $user->isTeacher() && $user->id === $course->instructor_id;
    }

    /** Only the owner can delete, and only if no active enrollments */
    public function delete(User $user, Course $course): bool
    {
        if (! $user->isTeacher() || $user->id !== $course->instructor_id) {
            return false;
        }

        return ! $course->enrollments()->where('status', 'active')->exists();
    }
}
