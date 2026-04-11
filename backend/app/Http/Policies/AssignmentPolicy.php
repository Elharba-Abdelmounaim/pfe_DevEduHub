<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy
{
    /**
     * Any authenticated user can view the list of published assignments
     * for a course they have access to. Filtering (published-only for students)
     * is done at the query level in AssignmentController@index.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Students can only view published assignments.
     * Teachers can view any assignment in their own courses.
     */
    public function view(User $user, Assignment $assignment): bool
    {
        if ($user->isTeacher()) {
            return $assignment->course->instructor_id === $user->id;
        }

        // Student: must be published
        return $assignment->is_published;
    }

    /**
     * Only the instructor of the course can create assignments for it.
     * The course_id is validated in StoreAssignmentRequest, but the
     * ownership check must be done here against the resolved course.
     */
    public function create(User $user, Assignment $assignment): bool
    {
        return $user->isTeacher()
            && $assignment->course->instructor_id === $user->id;
    }

    /**
     * Only the course instructor can update an assignment.
     */
    public function update(User $user, Assignment $assignment): bool
    {
        return $user->isTeacher()
            && $assignment->course->instructor_id === $user->id;
    }

    /**
     * Only the course instructor can delete an assignment,
     * and only if no submissions have been made against it.
     */
    public function delete(User $user, Assignment $assignment): bool
    {
        if (! $user->isTeacher() || $assignment->course->instructor_id !== $user->id) {
            return false;
        }

        return ! $assignment->submissions()->exists();
    }

    /**
     * Only teachers (course instructor) can publish/unpublish assignments.
     */
    public function publish(User $user, Assignment $assignment): bool
    {
        return $user->isTeacher()
            && $assignment->course->instructor_id === $user->id;
    }
}
