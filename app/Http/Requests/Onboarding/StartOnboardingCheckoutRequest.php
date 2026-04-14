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
        $trialOnly = (bool) config('billing.trial_only_mode', false);
        $allowedPlans = $trialOnly ? ['trial'] : ['trial', 'sprint', 'pro'];

        return [
            'plan' => ['required', Rule::in($allowedPlans)],
            'billing_cycle' => [
                Rule::requiredIf(fn () => $this->input('plan') === 'pro'),
                'nullable',
                Rule::in(['monthly', 'annual']),
            ],
        ];
    }
}
