<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubmissionController extends Controller
{
    // ── GET /api/submissions ──────────────────────────────────────────────
    // Student sees own submissions; teacher sees all for their assignments
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Submission::with(['assignment:id,title,course_id,due_date', 'student:id,first_name,last_name,email'])
                           ->latest('submitted_at');

        if ($user->isStudent()) {
            $query->where('student_id', $user->id);
        } else {
            // Teacher sees submissions for assignments in their courses
            $query->whereHas('assignment.course', fn($q) => $q->where('instructor_id', $user->id));
        }

        return SubmissionResource::collection($query->paginate(20));
    }

    // ── POST /api/submissions ─────────────────────────────────────────────
    public function store(StoreSubmissionRequest $request): JsonResponse
    {
        $user       = $request->user();
        $assignment = Assignment::with('course')->findOrFail($request->assignment_id);

        // ── Guard 1: assignment must be published ─────────────────────────
        abort_unless($assignment->is_published, 404, 'Assignment not found or not published.');

        // ── Guard 2: student must be enrolled in this course ──────────────
        $enrolled = Enrollment::where([
            'student_id' => $user->id,
            'course_id'  => $assignment->course_id,
            'status'     => 'active',
        ])->exists();

        abort_unless($enrolled, 403, 'You are not enrolled in this course.');

        // ── Guard 3: prevent duplicate (unless last attempt failed) ───────
        $existing = Submission::where([
            'student_id'    => $user->id,
            'assignment_id' => $assignment->id,
        ])->latest('submitted_at')->first();

        if ($existing && ! $existing->isFailed()) {
            return response()->json([
                'message'       => 'You have already submitted this assignment.',
                'submission_id' => $existing->id,
                'status'        => $existing->submission_status,
            ], 422);
        }

        // ── Guard 4: late submission policy ───────────────────────────────
        $isLate = $assignment->isPastDue();

        if ($isLate && ! $assignment->late_submission_allowed) {
            return response()->json([
                'message'  => 'This assignment is past its due date and does not accept late submissions.',
                'due_date' => $assignment->due_date->toIso8601String(),
            ], 422);
        }

        // ── Create submission ─────────────────────────────────────────────
        $submission = Submission::create([
            'assignment_id'     => $assignment->id,
            'student_id'        => $user->id,
            'github_repo_url'   => $request->github_repo_url,
            'github_commit_sha' => $request->github_commit_sha,
            'github_branch'     => $request->github_branch ?? 'main',
            'submission_type'   => 'github',
            'submission_status' => 'pending',
            'submitted_at'      => now(),
            'is_late'           => $isLate,
            'student_notes'     => $request->student_notes,
            'retry_count'       => $existing ? $existing->retry_count + 1 : 0,
            'last_retry_at'     => $existing ? now() : null,
        ]);

        // ── Phase 2 hook: dispatch grading job ────────────────────────────
        // if ($assignment->isAutoGradable()) {
        //     \App\Jobs\GradeSubmissionJob::dispatch($submission);
        //     $submission->update(['submission_status' => 'queued']);
        // }

        return response()->json(new SubmissionResource($submission->load('assignment')), 201);
    }

    // ── GET /api/submissions/{submission} ─────────────────────────────────
    public function show(Request $request, Submission $submission): JsonResponse
    {
        $this->authorizeSubmissionAccess($request, $submission);

        $submission->load(['assignment.course:id,title,code', 'student:id,first_name,last_name']);

        return response()->json(new SubmissionResource($submission));
    }

    // ── PATCH /api/submissions/{submission} ───────────────────────────────
    // Teachers can add manual_grade_score and feedback
    public function update(Request $request, Submission $submission): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isTeacher(), 403, 'Only teachers can grade submissions.');

        // Verify this submission belongs to one of the teacher's courses
        abort_unless(
            $submission->assignment->course->instructor_id === $user->id,
            403,
            'You do not own this course.'
        );

        $data = $request->validate([
            'manual_grade_score' => ['nullable', 'numeric', 'min:0'],
            'teacher_feedback'   => ['nullable', 'string', 'max:5000'],
        ]);

        // final_score = manual override if provided, otherwise auto_grade_score
        if (isset($data['manual_grade_score'])) {
            $data['final_score']        = $data['manual_grade_score'];
            $data['submission_status']  = 'graded';
        }

        $submission->update($data);

        return response()->json(new SubmissionResource($submission));
    }

    // ── GET /api/assignments/{assignment}/submissions ──────────────────────
    // Teacher views all submissions for one assignment
    public function byAssignment(Request $request, Assignment $assignment): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user->isTeacher(), 403, 'Only teachers can view all submissions.');
        abort_unless(
            $assignment->course->instructor_id === $user->id,
            403,
            'You do not own this assignment.'
        );

        $submissions = $assignment->submissions()
            ->with('student:id,first_name,last_name,email,github_username')
            ->latest('submitted_at')
            ->paginate(30);

        return SubmissionResource::collection($submissions);
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function authorizeSubmissionAccess(Request $request, Submission $submission): void
    {
        $user = $request->user();

        $isOwner    = $submission->student_id === $user->id;
        $isInstructor = $submission->assignment->course->instructor_id === $user->id;

        abort_unless($isOwner || $isInstructor, 403, 'Access denied.');
    }
}
