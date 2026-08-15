<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_text' => 'required|string|max:2000',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:50|regex:/^[a-z0-9áéíóúüñ\-]+$/u',
            'review_frequency' => 'nullable|in:weekly,monthly,quarterly',
            'conversation_id' => 'nullable|string|max:36',
            // D2 — repositorio activo opcional; si no llega, se usa el default del usuario.
            // La pertenencia y el estado active se validan en el controller.
            'repository_id' => 'nullable|string|size:36',
            'confirmed_relations' => 'nullable|array',
            'confirmed_relations.*' => 'string|size:36',
        ];
    }

    public function messages(): array
    {
        return [
            'tags.*.regex' => 'Cada tag solo puede contener letras, números y guiones.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tags')) {
            $this->merge([
                'tags' => array_map('strtolower', array_map('trim', $this->tags)),
            ]);
        }
    }
}
