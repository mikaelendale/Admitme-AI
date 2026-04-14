<?php

namespace App\Http\Requests;

use App\Models\CollegeListSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollegeListSchoolRequest extends FormRequest
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
            'application_status' => ['sometimes', 'nullable', 'string', Rule::in(CollegeListSchool::APPLICATION_STATUSES)],
            'student_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
