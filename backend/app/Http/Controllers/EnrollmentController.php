<?php

namespace App\Http\Controllers;

use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EnrollmentController extends Controller
{
    // ── GET /api/enrollments ──────────────────────────────────────────────
    // Student sees their own enrollments
    public function index(Request $request): AnonymousResourceCollection
    {
        $enrollments = Enrollment::with('course:id,code,title,academic_year,semester,credits')
            ->where('student_id', $request->user()->id)
            ->where('status', 'active')
            ->latest()
            ->get();

        return EnrollmentResource::collection($enrollments);
    }

    // ── POST /api/enrollments ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => ['required', 'uuid', 'exists:courses,id'],
        ]);

        $user   = $request->user();
        $course = Course::findOrFail($request->course_id);

        // Guard: only students can enroll
        abort_unless($user->isStudent(), 403, 'Only students can enroll in courses.');

        // Guard: course must be active
        abort_unless($course->is_active, 422, 'This course is not currently active.');

        // Guard: check capacity
        abort_unless($course->hasCapacity(), 422, 'This course has reached its maximum capacity.');

        // Guard: prevent double enrollment
        $existing = Enrollment::where([
            'student_id' => $user->id,
            'course_id'  => $course->id,
        ])->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return response()->json(['message' => 'You are already enrolled in this course.'], 422);
            }
            // Re-activate a dropped enrollment
            $existing->update(['status' => 'active', 'enrollment_date' => now()]);
            return response()->json(new EnrollmentResource($existing->load('course')), 200);
        }

        $enrollment = Enrollment::create([
            'student_id'      => $user->id,
            'course_id'       => $course->id,
            'enrollment_date' => now(),
            'status'          => 'active',
        ]);

        return response()->json(new EnrollmentResource($enrollment->load('course')), 201);
    }

    // ── DELETE /api/enrollments/{enrollment} ──────────────────────────────
    public function destroy(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->student_id === $request->user()->id, 403, 'Access denied.');

        $enrollment->update(['status' => 'dropped']);

        return response()->json(['message' => 'Successfully unenrolled from the course.']);
    }
}
