<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'student_id',
        'course_id',
        'enrollment_date',
        'status',         // 'active' | 'dropped' | 'completed'
        'final_grade',
    ];

    protected $casts = [
        'enrollment_date' => 'datetime',
        'final_grade'     => 'decimal:2',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}