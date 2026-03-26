<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code'          => ['required', 'string', 'max:20', 'unique:courses,code'],
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'academic_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'semester'      => ['required', 'in:Fall,Spring,Summer'],
            'credits'       => ['integer', 'min:1', 'max:6'],
            'max_students'  => ['integer', 'min:1', 'max:500'],
            'is_active'     => ['boolean'],
        ];
    }
}
