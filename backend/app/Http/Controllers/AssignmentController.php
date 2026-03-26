<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssignmentController extends Controller
{
    // ── GET /api/courses/{course}/assignments ─────────────────────────────
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = $course->assignments()->orderBy('due_date');

        // Students only see published assignments
        if ($user->isStudent()) {
            $query->published();
        }

        return AssignmentResource::collection($query->get());
    }

    // ── POST /api/assignments ─────────────────────────────────────────────
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $course = Course::findOrFail($request->course_id);

        // Only the course instructor can add assignments
        abort_unless(
            $course->instructor_id === $request->user()->id,
            403,
            'Only the course instructor can create assignments.'
        );

        $assignment = $course->assignments()->create($request->validated());

        return response()->json(new AssignmentResource($assignment), 201);
    }

    // ── GET /api/assignments/{assignment} ─────────────────────────────────
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();

        // Students only see published assignments they are enrolled in
        if ($user->isStudent()) {
            abort_unless($assignment->is_published, 404, 'Assignment not found.');
        }

        $assignment->load('course:id,title,code,instructor_id');

        return response()->json(new AssignmentResource($assignment));
    }

    // ── PUT /api/assignments/{assignment} ─────────────────────────────────
    public function update(StoreAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        abort_unless(
            $assignment->course->instructor_id === $request->user()->id,
            403,
            'Only the course instructor can update assignments.'
        );

        $assignment->update($request->validated());

        return response()->json(new AssignmentResource($assignment));
    }

    // ── DELETE /api/assignments/{assignment} ──────────────────────────────
    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless(
            $assignment->course->instructor_id === $request->user()->id,
            403,
            'Only the course instructor can delete assignments.'
        );

        // Prevent deletion if submissions exist
        if ($assignment->submissions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete assignment with existing submissions.',
            ], 422);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
