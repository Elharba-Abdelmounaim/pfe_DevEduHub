<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Submission extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType   = 'string';

    // No updated_at — submissions are immutable after creation; retries create new records
    public $timestamps = false;

    protected $fillable = [
        'assignment_id',
        'student_id',

        // ── Submission data ──────────────────────────────────────────────
        'github_repo_url',
        'github_commit_sha',           // Set by student or auto-fetched in Phase 2
        'github_branch',
        'submission_type',             // 'github' | 'file_upload'
        'file_path',

        // ── Status & timing ──────────────────────────────────────────────
        'submission_status',           // 'pending'|'queued'|'grading'|'graded'|'failed'
        'submitted_at',
        'is_late',                     // Computed at store() from due_date comparison

        // ── Phase 2: scoring (nullable — grader fills these) ─────────────
        'auto_grade_score',
        'manual_grade_score',
        'final_score',

        // ── Feedback ─────────────────────────────────────────────────────
        'teacher_feedback',
        'student_notes',

        // ── Phase 2: retry ────────────────────────────────────────────────
        'retry_count',
        'last_retry_at',
    ];

    protected $casts = [
        'submitted_at'       => 'datetime',
        'last_retry_at'      => 'datetime',
        'is_late'            => 'boolean',
        'auto_grade_score'   => 'decimal:2',
        'manual_grade_score' => 'decimal:2',
        'final_score'        => 'decimal:2',
        'retry_count'        => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('submission_status', 'pending');
    }

    public function scopeGraded($query)
    {
        return $query->where('submission_status', 'graded');
    }

    // ── Status helpers ────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->submission_status === 'pending'; }
    public function isGrading(): bool   { return $this->submission_status === 'grading'; }
    public function isGraded(): bool    { return $this->submission_status === 'graded'; }
    public function isFailed(): bool    { return $this->submission_status === 'failed'; }
    public function canRetry(): bool    { return $this->isFailed(); }

    /**
     * Phase 2: called by the Python grader webhook.
     * Sets auto_grade_score and transitions status to 'graded'.
     */
    public function applyAutoGrade(float $score): void
    {
        $this->update([
            'auto_grade_score'   => $score,
            'final_score'        => $score,
            'submission_status'  => 'graded',
        ]);
    }
}