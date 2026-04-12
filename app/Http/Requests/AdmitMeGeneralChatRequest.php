<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdmitMeGeneralChatRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:16000'],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }
}
