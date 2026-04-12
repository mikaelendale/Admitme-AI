<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEssayRoastRequest extends FormRequest
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
        return [
            'essay_text' => ['required', 'string', 'min:100', 'max:8000'],
            'essay_run_id' => [
                'nullable',
                'string',
                'max:36',
                Rule::exists('essay_runs', 'id')->where(
                    fn ($q) => $q->where('user_id', $this->user()->id),
                ),
            ],
            'essay_type' => ['nullable', 'string', 'max:64'],
        ];
    }
}
