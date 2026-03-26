<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'                => $this->id,
            'assignment_id'     => $this->assignment_id,
            'student_id'        => $this->student_id,

            // ── Submission data ──────────────────────────────────────────
            'github_repo_url'   => $this->github_repo_url,
            'github_commit_sha' => $this->github_commit_sha,
            'github_branch'     => $this->github_branch,
            'submission_type'   => $this->submission_type,

            // ── Status ───────────────────────────────────────────────────
            'submission_status' => $this->submission_status,
            'submitted_at'      => $this->submitted_at->toIso8601String(),
            'is_late'           => $this->is_late,
            'retry_count'       => $this->retry_count,

            // ── Scores: visible to both student (own) and teacher ─────────
            'auto_grade_score'  => $this->auto_grade_score,
            'final_score'       => $this->final_score,
            'teacher_feedback'  => $this->teacher_feedback,
            'student_notes'     => $this->student_notes,

            // ── Teacher-only fields ───────────────────────────────────────
            $this->mergeWhen($user?->isTeacher(), [
                'manual_grade_score' => $this->manual_grade_score,
                'last_retry_at'      => $this->last_retry_at?->toIso8601String(),
            ]),

            // ── Relationships ─────────────────────────────────────────────
            'assignment' => new AssignmentResource($this->whenLoaded('assignment')),
            'student'    => new UserResource($this->whenLoaded('student')),
        ];
    }
}