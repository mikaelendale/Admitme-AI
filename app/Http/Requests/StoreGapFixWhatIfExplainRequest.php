<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGapFixWhatIfExplainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uid = $this->user()->id;

        return [
            'college_list_school_ids' => ['required', 'array', 'min:1', 'max:12'],
            'college_list_school_ids.*' => [
                'string',
                'max:32',
                Rule::exists('college_list_schools', 'id')->where(
                    fn ($q) => $q->where('user_id', $uid)->where('is_removed', false),
                ),
            ],
            'sat_delta' => ['required', 'integer', 'min:-200', 'max:200'],
            'gpa_delta' => ['required', 'numeric', 'min:-0.5', 'max:0.5'],
        ];
    }
}
