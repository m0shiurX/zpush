<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sync_interval' => ['required', 'integer', 'min:1', 'max:1440'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'log_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'auto_sync_enabled' => ['required', 'boolean'],
            'poll_interval' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sync_interval.required' => 'The sync interval is required.',
            'sync_interval.min' => 'Sync interval must be at least 1 minute.',
            'sync_interval.max' => 'Sync interval cannot exceed 1440 minutes (24 hours).',
            'timezone.required' => 'The timezone is required.',
            'timezone.timezone' => 'Please select a valid timezone.',
            'log_retention_days.required' => 'The log retention period is required.',
            'log_retention_days.min' => 'Log retention must be at least 1 day.',
            'log_retention_days.max' => 'Log retention cannot exceed 365 days.',
            'poll_interval.required' => 'The device poll interval is required.',
            'poll_interval.min' => 'Poll interval must be at least 1 minute.',
            'poll_interval.max' => 'Poll interval cannot exceed 1440 minutes (24 hours).',
        ];
    }
}
