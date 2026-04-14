<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualCollegeListSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_name' => ['required', 'string', 'min:2', 'max:100'],
            'tier' => ['required', 'string', Rule::in(['reach', 'match', 'safety'])],
            'student_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
