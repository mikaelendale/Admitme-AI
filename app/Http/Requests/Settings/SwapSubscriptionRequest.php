<?php

namespace App\Http\Requests\Settings;

use App\Support\BillingPlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwapSubscriptionRequest extends FormRequest
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
            'price_id' => ['required', 'string', Rule::in(BillingPlanCatalog::allowedSwapPriceIds())],
        ];
    }
}
