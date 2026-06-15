<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'notifications.preferences.update' => [
                'preferences' => ['required', 'array'],
                'preferences.*.event_type' => ['required', 'string'],
                'preferences.*.in_app' => ['required', 'boolean'],
                'preferences.*.email' => ['required', 'boolean'],
                'preferences.*.sms' => ['required', 'boolean'],
            ],
            'notifications.settings.update' => [
                'email.enabled' => ['required', 'boolean'],
                'email.mailer' => ['required', Rule::in(['smtp', 'ses', 'log'])],
                'email.host' => ['nullable', 'string'],
                'email.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'email.username' => ['nullable', 'string'],
                'email.password' => ['nullable', 'string'],
                'email.encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
                'email.from_address' => ['nullable', 'email'],
                'email.from_name' => ['nullable', 'string'],
                'sms.enabled' => ['required', 'boolean'],
                'twilio.sid' => ['nullable', 'string'],
                'twilio.token' => ['nullable', 'string'],
                'twilio.from' => ['nullable', 'string'],
            ],
            'notifications.announce' => [
                'title' => ['required', 'string', 'max:150'],
                'message' => ['required', 'string', 'max:2000'],
                'severity' => ['nullable', Rule::in(['info', 'success', 'warning', 'critical'])],
                'user_ids' => ['nullable', 'array'],
                'user_ids.*' => ['integer', 'exists:users,id'],
            ],
            default => [],
        };
    }
}
