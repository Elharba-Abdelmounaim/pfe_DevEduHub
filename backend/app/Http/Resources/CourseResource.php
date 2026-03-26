<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'code'              => $this->code,
            'title'             => $this->title,
            'description'       => $this->description,
            'academic_year'     => $this->academic_year,
            'semester'          => $this->semester,
            'credits'           => $this->credits,
            'max_students'      => $this->max_students,
            'is_active'         => $this->is_active,
            'enrollments_count' => $this->whenCounted('enrollments'),
            'instructor'        => new UserResource($this->whenLoaded('instructor')),
            'assignments'       => AssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at'        => $this->created_at->toIso8601String(),
            'updated_at'        => $this->updated_at->toIso8601String(),
        ];
    }
}
