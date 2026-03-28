<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'required', 'ip'],
            'port' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['sometimes', 'required', 'string', 'in:tcp,udp'],
            'poll_method' => ['sometimes', 'required', 'string', 'in:realtime,bulk'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip_address.ip' => 'Please enter a valid IP address.',
            'port.min' => 'Port must be between 1 and 65535.',
            'port.max' => 'Port must be between 1 and 65535.',
        ];
    }
}
