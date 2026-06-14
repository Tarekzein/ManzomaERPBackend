# Human Resources Module

Company-scoped HR and payroll module covering employee profiles, organization trees, leave approval workflow,
manual/extensible attendance, configurable payroll calculations, PDF/email payslips, versioned employee documents,
self-service endpoints, basic recruitment, and exportable HR reports.

All endpoints are under `/api/hr` and require Sanctum authentication. Management operations use `hr.create`,
`hr.edit`, and `hr.export`; linked employees use `hr.view` for self-service.

Endpoint groups:

- Organization: `/departments`, `/teams`, `/employees`
- Leave and attendance: `/leave-types`, `/leave-requests`, `/attendance`
- Payroll: `/payroll-runs`, `/payslips`, `/me/payslips`
- Documents and recruitment: `/documents/versions/{version}/download`, `/jobs`, `/applicants`
- Self-service and reports: `/me`, `/me/leave-requests`, `/reports/{report}`
