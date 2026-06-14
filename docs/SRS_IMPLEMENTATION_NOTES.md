# SRS Implementation Notes

Source: `D:\programming\Manzomatech\Documents\ManzomaTech_ERP_SRS_v1.0.pdf`

## Backend Stack

- Laravel 11 REST API.
- PHP target in SRS: 8.3. Local machine currently runs PHP 8.2.12, which Laravel 11 supports for development.
- Database target: MySQL 8 or PostgreSQL 14. Local scaffold currently uses SQLite until environment services are configured.
- Redis-backed queues/cache with Laravel Horizon. Horizon was installed with Composer platform ignores because Windows/XAMPP does not expose `pcntl` and `posix`; production should run this on Linux.
- Simplified company-scoped data model. Users belong to one company and module data will use `company_id` scoping instead of database-per-tenant switching.
- Auth stack: Laravel Sanctum + Fortify.
- RBAC: Spatie Laravel Permission.
- API docs: Scribe.
- Export/search stack: DOMPDF, Laravel Excel, Laravel Scout, Meilisearch client.

## API Contract

All API responses should use this envelope:

```json
{
  "success": true,
  "data": {},
  "message": "OK",
  "errors": null,
  "meta": {}
}
```

The API namespace is `/api`.

## First Implementation Priorities

1. Add `company_id` ownership to each module table as modules are built.
2. Create company subscription settings and seed plan feature flags.
3. Expand Fortify/Sanctum auth with MFA endpoints when the frontend flow is ready.
4. Seed default roles: Super Admin, Company Admin, Manager, Employee, Viewer.
5. Add immutable audit logging for create/update/delete and RBAC changes.
6. Build CRUD foundations for Sales, CRM, Reporting, Notifications, and Custom Modules.

## Implemented HR Scope

- Company-scoped employee profiles, departments, teams, reporting lines, and self-service profile updates.
- Leave requests with overlap/allowance validation, manager approval workflow, and employee notifications.
- Manual/extensible attendance entries, configurable payroll formulas, PDF/email payslips, and payroll summaries.
- Versioned employee documents, authorized downloads, basic recruitment/applicant tracking, and CSV HR reports.
