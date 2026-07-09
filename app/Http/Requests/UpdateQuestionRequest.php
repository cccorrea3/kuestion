<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:50|regex:/^[a-z0-9áéíóúüñ\-]+$/u',
            'is_starred' => 'nullable|boolean',
            'status' => 'nullable|in:active,archived',
            'review_frequency' => 'nullable|in:weekly,monthly,quarterly',
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
