<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCloudServerRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'api_base_url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer'],
            'branch_name' => ['nullable', 'string', 'max:255'],
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
