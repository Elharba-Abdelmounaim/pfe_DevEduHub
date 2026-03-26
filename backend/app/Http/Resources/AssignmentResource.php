<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'                      => $this->id,
            'course_id'               => $this->course_id,
            'title'                   => $this->title,
            'description'             => $this->description,
            'assignment_type'         => $this->assignment_type,
            'max_score'               => $this->max_score,
            'due_date'                => $this->due_date->toIso8601String(),
            'is_past_due'             => $this->isPastDue(),
            'late_submission_allowed' => $this->late_submission_allowed,
            'late_penalty_percentage' => $this->late_penalty_percentage,
            'language'                => $this->language,
            'is_published'            => $this->is_published,
            'course'                  => new CourseResource($this->whenLoaded('course')),

            // Phase 2 fields: only shown to teachers
            $this->mergeWhen($user?->isTeacher(), [
                'test_cases'    => $this->test_cases,
                'docker_config' => $this->docker_config,
                'requirements'  => $this->requirements,
                'is_auto_gradable' => $this->isAutoGradable(),
            ]),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}