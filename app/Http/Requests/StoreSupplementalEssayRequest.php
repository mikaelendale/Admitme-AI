<?php

namespace App\Http\Requests;

use App\Models\SupplementalEssay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplementalEssayRequest extends FormRequest
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
            'school_name' => ['required', 'string', 'max:255'],
            'college_list_school_id' => [
                'nullable',
                'string',
                'max:36',
                Rule::exists('college_list_schools', 'id')->where(
                    fn ($q) => $q
                        ->where('user_id', $this->user()->id)
                        ->where('is_removed', false),
                ),
            ],
            'prompt_text' => ['required', 'string', 'min:10', 'max:2000'],
            'word_limit' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'essay_type' => ['required', 'string', Rule::in(SupplementalEssay::essayTypes())],
            'status' => ['required', 'string', Rule::in(SupplementalEssay::statuses())],
            'due_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
