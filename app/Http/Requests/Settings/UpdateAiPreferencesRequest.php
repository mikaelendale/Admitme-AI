<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mentor_tone' => [
                'required',
                'string',
                Rule::in(['brutally_honest', 'supportive', 'balanced']),
            ],
            'essay_feedback_style' => [
                'required',
                'string',
                Rule::in(['detailed_constructive', 'concise', 'line_by_line']),
            ],
        ];
    }
}
