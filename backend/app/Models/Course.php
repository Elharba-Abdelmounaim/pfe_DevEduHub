<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'instructor_id',
        'code',
        'title',
        'description',
        'academic_year',
        'semester',
        'credits',
        'max_students',
        'is_active',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'academic_year' => 'integer',
        'credits'       => 'integer',
        'max_students'  => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** Students enrolled in this course */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments', 'course_id', 'student_id')
                    ->withPivot(['status', 'final_grade', 'enrollment_date'])
                    ->withTimestamps();
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function hasCapacity(): bool
    {
        return $this->enrollments()->where('status', 'active')->count() < $this->max_students;
    }
}