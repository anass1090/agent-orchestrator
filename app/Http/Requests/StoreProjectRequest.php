<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:projects,name'],
            'path' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_dir(rtrim((string) $value, '/').'/.git')) {
                    $fail('Dit pad is geen git-repository.');
                }
            }],
            'branch' => ['required', 'string', 'max:200'],
            'remote' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Gebruik kleine letters, cijfers, punten, streepjes en underscores.',
            'name.unique' => 'Er is al een project met deze naam.',
        ];
    }
}
