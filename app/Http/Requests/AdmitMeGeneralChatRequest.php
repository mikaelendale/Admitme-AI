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
            // Applies to this request only; the server decides the tools from it.
            'mode' => ['nullable', 'string', 'in:ask,agent'],
            // Documents from the student's own uploads attached to this message (ownership is checked when resolved).
            'document_ids' => ['sometimes', 'array', 'max:4'],
            'document_ids.*' => ['string', 'ulid'],
        ];
    }
}
