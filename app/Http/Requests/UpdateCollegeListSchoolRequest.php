<?php

namespace App\Http\Requests;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\CollegeListSchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

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
            'choice_status' => ['sometimes', new Enum(CollegeChoiceStatus::class)],
            'tier' => ['sometimes', Rule::in(['reach', 'match', 'safety'])],
            // Saved deadlines (shown in the deadline tracker), portal link and cycle; null clears them.
            'ea_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ed_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'rd_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Rolling admission: decisions as applications arrive, so no fixed deadline is needed.
            'rolling_admission' => ['sometimes', 'boolean'],
            'application_portal_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'admissions_cycle_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The revision the student's screen was showing; a mismatch means someone else changed it first.
            'expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
