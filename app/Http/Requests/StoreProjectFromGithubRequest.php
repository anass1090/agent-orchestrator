<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectFromGithubRequest extends FormRequest
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
            // GitHub's own allowed characters for a user/org and repo name.
            'full_name' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'branch' => ['required', 'string', 'max:200'],
            'remote' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * A project name derived from the repository, e.g. "octocat/hello-world"
     * becomes "hello-world".
     */
    public function projectName(): string
    {
        return (string) str($this->string('full_name')->afterLast('/'))->slug();
    }
}
