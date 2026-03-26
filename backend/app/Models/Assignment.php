<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Assignment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'assignment_type',             // 'code' | 'quiz' | 'project'
        'max_score',
        'due_date',
        'late_submission_allowed',
        'late_penalty_percentage',
        // ── Phase 2 fields (accepted now, populated later) ─────────────
        'test_cases',                  // jsonb: [{id, name, input, expected_output, weight}]
        'docker_config',               // jsonb: {image, memory_limit, cpu_limit, timeout}
        'language',                    // 'python' | 'node' | 'java'
        'requirements',                // jsonb: dependency manifest
        // ──────────────────────────────────────────────────────────────
        'is_published',
    ];

    protected $casts = [
        'due_date'                 => 'datetime',
        'late_submission_allowed'  => 'boolean',
        'late_penalty_percentage'  => 'decimal:2',
        'max_score'                => 'decimal:2',
        'is_published'             => 'boolean',
        // Phase 2 jsonb columns cast to arrays automatically
        'test_cases'               => 'array',
        'docker_config'            => 'array',
        'requirements'             => 'array',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Check if a given datetime is past the due date.
     * Used by SubmissionController to set is_late automatically.
     */
    public function isPastDue(?Carbon $at = null): bool
    {
        return ($at ?? now())->gt($this->due_date);
    }

    /**
     * Phase 2: returns true if test_cases and docker_config are configured.
     */
    public function isAutoGradable(): bool
    {
        return ! empty($this->test_cases) && ! empty($this->docker_config);
    }
}