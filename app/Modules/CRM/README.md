# CRM Module

The CRM module manages company-scoped contacts, tags, opportunity pipeline stages, opportunities, sales activities, follow-up tasks, segments, webhook-ready email campaign events, and CRM reports.

## Main Endpoints

- `GET /api/crm/contacts`
- `POST /api/crm/contacts`
- `POST /api/crm/contacts/{contact}/convert`
- `GET /api/crm/pipeline-stages`
- `POST /api/crm/opportunities/{opportunity}/move`
- `GET /api/crm/activities`
- `GET /api/crm/tasks`
- `GET /api/crm/segments/{segment}/contacts`
- `POST /api/crm/campaign-webhooks/{provider}`
- `GET /api/crm/reports/{conversion-rate|pipeline-value|rep-performance}`

CRM contacts are independent from Sales contacts, but they can be linked or converted to Sales contacts when a lead becomes commercial.
