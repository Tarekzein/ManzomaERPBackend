<?php

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CRMRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'crm.contacts.store', 'crm.contacts.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'sales_contact_id' => ['nullable', 'integer', 'exists:sales_contacts,id'],
                'type' => ['required', Rule::in(['lead', 'prospect', 'customer'])],
                'status' => ['nullable', 'string', 'max:50'],
                'name' => ['required', 'string', 'max:255'],
                'company_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:50'],
                'region' => ['nullable', 'string', 'max:100'],
                'source' => ['nullable', 'string', 'max:100'],
                'currency' => ['nullable', 'string', 'size:3'],
                'address' => ['nullable', 'array'],
                'custom_attributes' => ['nullable', 'array'],
                'tag_ids' => ['nullable', 'array'],
                'tag_ids.*' => ['integer', 'exists:crm_tags,id'],
            ],
            'crm.contacts.convert' => [
                'sales_contact_id' => ['nullable', 'integer', 'exists:sales_contacts,id'],
                'type' => ['nullable', Rule::in(['customer', 'vendor', 'both'])],
                'currency' => ['nullable', 'string', 'size:3'],
            ],
            'crm.tags.store', 'crm.tags.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'name' => ['required', 'string', 'max:80'],
                'color' => ['nullable', 'string', 'max:30'],
            ],
            'crm.stages.store', 'crm.stages.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'name' => ['required', 'string', 'max:100'],
                'key' => ['nullable', 'string', 'max:100'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
                'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
                'is_won' => ['nullable', 'boolean'],
                'is_lost' => ['nullable', 'boolean'],
            ],
            'crm.stages.reorder' => [
                'stages' => ['required', 'array', 'min:1'],
                'stages.*.id' => ['required', 'integer', 'exists:crm_pipeline_stages,id'],
                'stages.*.sort_order' => ['required', 'integer', 'min:0'],
            ],
            'crm.opportunities.store', 'crm.opportunities.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['required', 'integer', 'exists:crm_contacts,id'],
                'stage_id' => ['required', 'integer', 'exists:crm_pipeline_stages,id'],
                'owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'title' => ['required', 'string', 'max:255'],
                'value' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'size:3'],
                'expected_close_date' => ['nullable', 'date'],
                'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
                'status' => ['nullable', Rule::in(['open', 'won', 'lost'])],
                'notes' => ['nullable', 'string'],
            ],
            'crm.opportunities.move' => [
                'stage_id' => ['required', 'integer', 'exists:crm_pipeline_stages,id'],
            ],
            'crm.activities.store' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id'],
                'opportunity_id' => ['nullable', 'integer', 'exists:crm_opportunities,id'],
                'type' => ['required', Rule::in(['call', 'email', 'meeting', 'note'])],
                'subject' => ['required', 'string', 'max:255'],
                'body' => ['nullable', 'string'],
                'occurred_at' => ['nullable', 'date'],
            ],
            'crm.tasks.store', 'crm.tasks.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id'],
                'opportunity_id' => ['nullable', 'integer', 'exists:crm_opportunities,id'],
                'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
                'title' => ['required', 'string', 'max:255'],
                'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
                'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
                'due_at' => ['nullable', 'date'],
                'reminder_at' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ],
            'crm.segments.store', 'crm.segments.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'name' => ['required', 'string', 'max:120'],
                'criteria' => ['required', 'array'],
                'criteria.types' => ['nullable', 'array'],
                'criteria.types.*' => [Rule::in(['lead', 'prospect', 'customer'])],
                'criteria.regions' => ['nullable', 'array'],
                'criteria.regions.*' => ['string'],
                'criteria.tag_ids' => ['nullable', 'array'],
                'criteria.tag_ids.*' => ['integer', 'exists:crm_tags,id'],
                'criteria.owner_id' => ['nullable', 'integer', 'exists:users,id'],
                'criteria.custom_attributes' => ['nullable', 'array'],
            ],
            'crm.campaigns.store', 'crm.campaigns.update' => [
                'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'segment_id' => ['nullable', 'integer', 'exists:crm_segments,id'],
                'provider' => ['nullable', Rule::in(['manual', 'sendgrid', 'mailchimp'])],
                'external_id' => ['nullable', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:150'],
                'subject' => ['nullable', 'string', 'max:255'],
                'content' => ['nullable', 'string'],
                'status' => ['nullable', Rule::in(['draft', 'scheduled', 'sent', 'paused', 'archived'])],
                'scheduled_at' => ['nullable', 'date'],
                'sent_at' => ['nullable', 'date'],
            ],
            'crm.campaign-webhooks.store' => [
                'campaign_id' => ['nullable', 'integer', 'exists:crm_campaigns,id'],
                'external_id' => ['nullable', 'string'],
                'contact_id' => ['nullable', 'integer', 'exists:crm_contacts,id'],
                'email' => ['nullable', 'email'],
                'event_type' => ['required', 'string', 'max:80'],
                'occurred_at' => ['nullable', 'date'],
                'payload' => ['nullable', 'array'],
            ],
            default => [],
        };
    }
}
