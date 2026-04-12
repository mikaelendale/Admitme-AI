<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartOnboardingCheckoutRequest extends FormRequest
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
            'plan' => ['required', Rule::in(['trial', 'sprint', 'pro'])],
            'billing_cycle' => [
                Rule::requiredIf(fn () => $this->input('plan') === 'pro'),
                'nullable',
                Rule::in(['monthly', 'annual']),
            ],
        ];
    }
}
