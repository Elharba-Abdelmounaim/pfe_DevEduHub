<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'course_id'               => ['required', 'uuid', 'exists:courses,id'],
            'title'                   => ['required', 'string', 'max:255'],
            'description'             => ['nullable', 'string'],
            'assignment_type'         => ['required', 'in:code,quiz,project'],
            'max_score'               => ['numeric', 'min:0', 'max:1000'],
            'due_date'                => ['required', 'date', 'after:now'],
            'late_submission_allowed' => ['boolean'],
            'late_penalty_percentage' => ['numeric', 'min:0', 'max:100'],
            'language'                => ['nullable', 'in:python,node,java,php,go,rust'],
            'is_published'            => ['boolean'],

            // Phase 2 fields: accepted but not processed in Phase 1
            'test_cases'              => ['nullable', 'array'],
            'test_cases.*.id'         => ['string'],
            'test_cases.*.name'       => ['string'],
            'test_cases.*.input'      => ['nullable', 'string'],
            'test_cases.*.expected_output' => ['nullable', 'string'],
            'test_cases.*.weight'     => ['numeric', 'min:0'],
            'docker_config'           => ['nullable', 'array'],
            'requirements'            => ['nullable', 'array'],
        ];
    }
}
