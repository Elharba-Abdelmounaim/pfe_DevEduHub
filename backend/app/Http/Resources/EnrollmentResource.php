<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'course_id'       => $this->course_id,
            'student_id'      => $this->student_id,
            'enrollment_date' => $this->enrollment_date->toIso8601String(),
            'status'          => $this->status,
            'final_grade'     => $this->final_grade,
            'course'          => new CourseResource($this->whenLoaded('course')),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
