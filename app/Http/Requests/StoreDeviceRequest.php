<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', 'string', 'in:tcp,udp'],
            'poll_method' => ['required', 'string', 'in:realtime,bulk'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip_address.required' => 'The device IP address is required.',
            'ip_address.ip' => 'Please enter a valid IP address.',
            'port.min' => 'Port must be between 1 and 65535.',
        ];
    }
}
