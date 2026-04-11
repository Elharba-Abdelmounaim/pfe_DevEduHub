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
        $this->authorize('viewAny', Assignment::class);

        $query = $course->assignments()->orderBy('due_date');

        // Students only see published assignments
        if ($request->user()->isStudent()) {
            $query->published();
        }

        return AssignmentResource::collection($query->get());
    }

    // ── POST /api/assignments ─────────────────────────────────────────────
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $course     = Course::findOrFail($request->course_id);
        $assignment = new Assignment(['course_id' => $course->id]);
        $assignment->setRelation('course', $course);

        $this->authorize('create', $assignment);

        $assignment = $course->assignments()->create($request->validated());

        return response()->json(new AssignmentResource($assignment), 201);
    }

    // ── GET /api/assignments/{assignment} ─────────────────────────────────
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        $assignment->load('course:id,title,code,instructor_id');

        return response()->json(new AssignmentResource($assignment));
    }

    // ── PUT /api/assignments/{assignment} ─────────────────────────────────
    public function update(StoreAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $assignment->update($request->validated());

        return response()->json(new AssignmentResource($assignment));
    }

    // ── DELETE /api/assignments/{assignment} ──────────────────────────────
    public function destroy(Assignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    // ── PATCH /api/assignments/{assignment}/publish ────────────────────────
    // Dedicated publish / unpublish toggle
    public function togglePublish(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('publish', $assignment);

        $assignment->update(['is_published' => ! $assignment->is_published]);

        return response()->json([
            'message'      => $assignment->is_published ? 'Assignment published.' : 'Assignment unpublished.',
            'is_published' => $assignment->is_published,
        ]);
    }
}
