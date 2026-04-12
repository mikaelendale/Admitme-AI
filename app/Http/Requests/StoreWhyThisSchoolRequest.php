<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhyThisSchoolRequest extends FormRequest
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
        ];
    }
}
