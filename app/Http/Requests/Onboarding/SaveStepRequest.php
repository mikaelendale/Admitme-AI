<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStepRequest extends FormRequest
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
        $step = (int) $this->input('step', -1);

        return match ($step) {
            0 => [
                'step' => ['required', 'integer', 'in:0'],
                'age_range' => ['required', Rule::in(['13_14', '15_16', '17_18', '19plus'])],
                'legal_accepted' => ['required', 'accepted'],
            ],
            1 => [
                'step' => ['required', 'integer', 'in:1'],
            ],
            2 => [
                'step' => ['required', 'integer', 'in:2'],
                'profile_text' => [
                    'required',
                    'string',
                    'max:65535',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_string($value)) {
                            return;
                        }
                        $max = config('admissions.max_profile_words', 2000);
                        $words = preg_split('/\s+/u', trim(strip_tags($value)), -1, PREG_SPLIT_NO_EMPTY);
                        $count = is_array($words) ? count($words) : 0;
                        if ($count > $max) {
                            $fail("The profile text must not exceed {$max} words.");
                        }
                    },
                ],
            ],
            3 => [
                'step' => ['required', 'integer', 'in:3'],
                'report_acknowledged' => ['sometimes', 'boolean'],
            ],
            4 => [
                'step' => ['required', 'integer', 'in:4'],
            ],
            default => [
                'step' => ['required', 'integer', 'in:0,1,2,3,4'],
            ],
        };
    }
}
