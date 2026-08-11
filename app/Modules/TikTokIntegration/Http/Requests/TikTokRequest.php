<?php

namespace App\Modules\TikTokIntegration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TikTokRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'tiktok.connection.app-credentials' => [
                'app_id' => ['required', 'string', 'max:60'],
                'app_secret' => ['required', 'string', 'max:255'],
            ],
            'tiktok.oauth.callback' => [
                'auth_code' => ['required', 'string'],
                'state' => ['required', 'string'],
            ],
            'tiktok.settings' => [
                'default_advertiser_id' => ['nullable', 'string', 'max:60'],
                'pixel_code' => ['nullable', 'string', 'max:60'],
                'events_enabled' => ['nullable', 'boolean'],
            ],
            'tiktok.event-mappings.store', 'tiktok.event-mappings.update' => [
                'trigger_source' => ['required', Rule::in(['crm_lead_created', 'crm_opportunity_won', 'invoice_paid'])],
                'event_name' => ['required', 'string', 'max:80'],
                'is_active' => ['nullable', 'boolean'],
                'value_field' => ['nullable', 'string', 'max:100'],
                'currency_field' => ['nullable', 'string', 'max:100'],
                'extra_params' => ['nullable', 'array'],
            ],
            'tiktok.lead-forms.store', 'tiktok.lead-forms.update' => [
                'advertiser_id' => ['required', 'string', 'max:60'],
                'page_id' => ['required', 'string', 'max:60'],
                'page_name' => ['nullable', 'string', 'max:150'],
                'field_mapping' => ['required', 'array'],
                'default_owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'default_source' => ['nullable', 'string', 'max:60'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'tiktok.audiences.store', 'tiktok.audiences.update' => [
                'crm_segment_id' => ['required', 'integer', 'exists:crm_segments,id'],
                'audience_name' => ['required', 'string', 'max:150'],
                'advertiser_id' => ['nullable', 'string', 'max:60'],
                'calculate_type' => ['nullable', Rule::in(['EMAIL_SHA256', 'PHONE_SHA256'])],
                'sync_mode' => ['nullable', Rule::in(['manual', 'scheduled'])],
                'schedule_frequency' => ['nullable', Rule::in(['hourly', 'daily', 'weekly'])],
            ],
            'tiktok.reports.campaigns' => [
                'advertiser_id' => ['required', 'string', 'max:60'],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            ],
            default => [],
        };
    }
}
