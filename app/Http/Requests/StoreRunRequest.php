<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'prompt' => ['required', 'string', 'min:10'],
            'until' => ['nullable', 'in:plan,build'],
            'keep_worktree' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Kies een project.',
            'prompt.required' => 'Beschrijf de feature die gebouwd moet worden.',
            'prompt.min' => 'Beschrijf de feature in minstens 10 tekens.',
        ];
    }
}
