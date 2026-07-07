<?php

namespace App\Modules\MetaIntegration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MetaRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'meta.oauth.callback' => [
                'code' => ['required', 'string'],
                'state' => ['required', 'string'],
            ],
            'meta.connection.manual' => [
                'access_token' => ['required', 'string'],
                'app_id' => ['nullable', 'string', 'max:60'],
                'app_secret' => ['nullable', 'string', 'max:255'],
                'business_id' => ['nullable', 'string', 'max:60'],
                'ad_account_id' => ['nullable', 'string', 'max:60'],
                'pixel_id' => ['nullable', 'string', 'max:60'],
            ],
            'meta.connection.app-credentials' => [
                'app_id' => ['required', 'string', 'max:60'],
                'app_secret' => ['required', 'string', 'max:255'],
                'config_id' => ['nullable', 'string', 'max:60'],
            ],
            'meta.connection.assets' => [
                'business_id' => ['nullable', 'string', 'max:60'],
                'ad_account_id' => ['nullable', 'string', 'max:60'],
                'pixel_id' => ['nullable', 'string', 'max:60'],
                'page_ids' => ['nullable', 'array'],
                'page_ids.*' => ['string'],
                'default_page_id' => ['nullable', 'string', 'max:60'],
            ],
            'meta.connection.compliance' => [
                'require_consent' => ['nullable', 'boolean'],
                'ldu_enabled' => ['nullable', 'boolean'],
                'ldu_country' => ['nullable', 'integer'],
                'ldu_state' => ['nullable', 'integer'],
                'test_event_code' => ['nullable', 'string', 'max:60'],
            ],
            'meta.test-event' => [
                'test_event_code' => ['nullable', 'string', 'max:60'],
            ],
            'meta.event-mappings.store', 'meta.event-mappings.update' => [
                'trigger_source' => ['required', Rule::in(['crm_lead_created', 'crm_opportunity_won', 'invoice_paid'])],
                'meta_event_name' => ['required', 'string', 'max:80'],
                'is_active' => ['nullable', 'boolean'],
                'value_field' => ['nullable', 'string', 'max:100'],
                'currency_field' => ['nullable', 'string', 'max:100'],
                'extra_params' => ['nullable', 'array'],
            ],
            'meta.lead-form-mappings.store', 'meta.lead-form-mappings.update' => [
                'page_id' => ['required', 'string', 'max:60'],
                'form_id' => ['required', 'string', 'max:60'],
                'form_name' => ['nullable', 'string', 'max:150'],
                'field_mapping' => ['required', 'array'],
                'default_owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'default_source' => ['nullable', 'string', 'max:60'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'meta.whatsapp.settings' => [
                'whatsapp_enabled' => ['nullable', 'boolean'],
                'whatsapp_business_account_id' => ['nullable', 'string', 'max:60'],
                'whatsapp_phone_number_id' => ['nullable', 'string', 'max:60'],
                'whatsapp_phone_number' => ['nullable', 'string', 'max:30'],
            ],
            'meta.whatsapp.send' => [
                'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id'],
                'to_phone' => ['nullable', 'string', 'max:30'],
                'template_name' => ['required', 'string', 'max:120'],
                'language' => ['nullable', 'string', 'max:15'],
                'components' => ['nullable', 'array'],
            ],
            'meta.audiences.store', 'meta.audiences.update' => [
                'crm_segment_id' => ['required', 'integer', 'exists:crm_segments,id'],
                'audience_name' => ['required', 'string', 'max:150'],
                'sync_mode' => ['nullable', Rule::in(['manual', 'scheduled'])],
                'schedule_frequency' => ['nullable', Rule::in(['hourly', 'daily', 'weekly'])],
            ],
            default => [],
        };
    }
}
