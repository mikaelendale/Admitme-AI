<?php

namespace App\Http\Requests;

use App\Models\SupplementalEssay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplementalEssayRequest extends FormRequest
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
            'content' => ['nullable', 'string', 'max:50000'],
            'status' => ['sometimes', 'string', Rule::in(SupplementalEssay::statuses())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'word_limit' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
