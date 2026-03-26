<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

    // ── No auto-increment — UUIDs from DB ────────────────────────────────
    public $incrementing = false;
    protected $keyType   = 'string';

    // ── Password field name matches ERD ──────────────────────────────────
    protected $authPasswordName = 'password_hash';

    protected $fillable = [
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'role',                      // 'teacher' | 'student'
        'avatar_url',
        'phone',
        'bio',
        'github_username',
        'github_token_encrypted',    // Phase 2
        'is_active',
        'is_verified',
        'email_verification_token',
        'password_reset_token',
        'password_reset_expires',
        'last_login',
    ];

    protected $hidden = [
        'password_hash',
        'github_token_encrypted',
        'email_verification_token',
        'password_reset_token',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'is_verified'             => 'boolean',
        'password_reset_expires'  => 'datetime',
        'last_login'              => 'datetime',
        'created_at'              => 'datetime',
        'updated_at'              => 'datetime',
    ];

    // ── Role helpers ─────────────────────────────────────────────────────
    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    // ── Relationships ─────────────────────────────────────────────────────

    /** Courses this teacher created */
    public function taughtCourses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    /** Enrollments this student has */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    /** Courses this student is enrolled in (via enrollments) */
    public function enrolledCourses()
    {
        return $this->belongsToMany(Course::class, 'enrollments', 'student_id', 'course_id')
                    ->withPivot(['status', 'final_grade', 'enrollment_date'])
                    ->withTimestamps();
    }

    /** All submissions by this student */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'student_id');
    }

    // ── Computed ─────────────────────────────────────────────────────────
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}