<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

class StoreCloudServerRequest extends FormRequest
{
    /**
     * Setup wizard is unauthenticated — always authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_base_url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_base_url.required' => 'The cloud server URL is required.',
            'api_base_url.url' => 'Please enter a valid URL (e.g. https://api.example.com).',
            'api_key.required' => 'The API key is required.',
        ];
    }
}
