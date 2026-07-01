# Human Resources Module

Company-scoped HR and payroll module covering employee profiles, organization trees, positions, contracts,
personal details, emergency contacts, leave approval workflow, leave balances, holidays, manual/extensible
attendance, benefits, configurable payroll calculations, PDF/email payslips, versioned employee documents,
onboarding/offboarding tasks, performance reviews, disciplinary actions, training records, self-service
endpoints, basic recruitment, and exportable HR reports.

All endpoints are under `/api/hr` and require Sanctum authentication. Management operations use `hr.create`,
`hr.edit`, and `hr.export`; linked employees use `hr.view` for self-service. Sensitive areas are separated
with `hr.payroll.view`, `hr.payroll.edit`, `hr.documents.view`, `hr.documents.edit`, `hr.leave.approve`,
`hr.recruitment.manage`, `hr.performance.manage`, and `hr.disciplinary.manage`.

Endpoint groups:

- Organization: `/departments`, `/teams`, `/positions`, `/employees`
- Employee records: `/personal-details`, `/emergency-contacts`, `/contracts`, `/benefits`, `/employee-benefits`
- Leave and attendance: `/leave-types`, `/leave-balances`, `/leave-balances/adjustments`, `/leave-requests`, `/holidays`, `/attendance`
- Payroll: `/payroll-runs`, `/payslips`, `/me/payslips`
- Lifecycle and talent: `/onboarding-tasks`, `/offboarding-tasks`, `/performance-reviews`, `/disciplinary-actions`, `/training-records`
- Documents and recruitment: `/employees/{employee}/documents`, `/documents/versions/{version}/download`, `/jobs`, `/applicants`
- Self-service and reports: `/me`, `/me/leave-balances`, `/me/attendance-summary`, `/me/training`, `/me/performance`, `/me/leave-requests`, `/reports/{report}`

List endpoints accept `search`, `page`, `per_page`, `sort`, `direction`, and relevant filters such as
`employee_id`, `status`, `department_id`, `team_id`, `leave_type_id`, and `year`.
