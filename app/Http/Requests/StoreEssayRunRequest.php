<?php

namespace App\Http\Requests;

use App\Models\EssayRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEssayRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function withValidator($validator): void
    {
        $validator->sometimes(
            'message',
            ['required', 'string', 'min:20', 'max:'.(int) config('essay_suite.max_prompt_length', 8000)],
            fn () => $this->string('run_type')->toString() === 'draft',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('essay_suite.max_prompt_length', 8000);

        return [
            'college_list_school_id' => [
                'required',
                'string',
                'max:36',
                Rule::exists('college_list_schools', 'id')->where(
                    fn ($q) => $q
                        ->where('user_id', $this->user()->id)
                        ->where('is_removed', false),
                ),
            ],
            'run_type' => ['required', 'string', Rule::in(['brainstorm', 'draft'])],
            'essay_type' => ['required', 'string', Rule::in(EssayRun::essayTypes())],
            'essay_prompt' => ['required', 'string', 'min:10', 'max:'.$max],
            'message' => ['nullable', 'string', 'max:'.$max],
            'word_limit' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'tone' => ['nullable', 'string', Rule::in(['brutally_honest', 'balanced', 'supportive'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function essayOptions(): array
    {
        $v = $this->validated();

        return [
            'essay_type' => (string) $v['essay_type'],
            'essay_prompt' => trim((string) $v['essay_prompt']),
            'word_limit' => isset($v['word_limit']) ? (int) $v['word_limit'] : null,
            'tone' => isset($v['tone']) && is_string($v['tone']) && $v['tone'] !== '' ? $v['tone'] : null,
        ];
    }

    public function studentMessage(): string
    {
        $v = $this->validated();
        $raw = isset($v['message']) ? trim((string) $v['message']) : '';

        return $raw;
    }
}
