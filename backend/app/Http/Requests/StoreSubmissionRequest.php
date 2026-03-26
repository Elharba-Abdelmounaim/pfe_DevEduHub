<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'assignment_id'     => ['required', 'uuid', 'exists:assignments,id'],

            // GitHub/GitLab HTTPS URL only — same regex used by Phase 2 repo_cloner.py
            'github_repo_url'   => [
                'required',
                'url',
                'regex:/^https:\/\/(github|gitlab)\.com\/[\w\-\.]+\/[\w\-\.]+\/?$/',
            ],

            // Optional: student can pin the exact commit they want graded
            'github_commit_sha' => ['nullable', 'string', 'regex:/^[0-9a-f]{40}$/i'],
            'github_branch'     => ['nullable', 'string', 'max:100'],
            'student_notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'github_repo_url.regex'    => 'Must be a valid public GitHub or GitLab HTTPS URL.',
            'github_commit_sha.regex'  => 'Commit SHA must be a 40-character hex string.',
        ];
    }
}
