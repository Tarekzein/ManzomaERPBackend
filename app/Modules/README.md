# Manzoma ERP Backend Modules

This directory keeps bounded backend modules aligned with the SRS.

Each module starts with the same shape:

- `Http/Controllers` for API controllers.
- `Models` for Eloquent models owned by the module.
- `Services` for business workflows and integration logic.

Initial modules:

- `Authentication`: login, registration, tokens, users, roles, permissions, and login auditing.
- `Platform`: cross-cutting API conventions, audit, feature flags, and shared services.
- `Companies`: onboarding, company profile, subscriptions, usage metering, and export workflows.
- `HR`: employees, org structure, leave, attendance, payroll, payslips, and HR reports.
- `Finance`: chart of accounts, ledger, AP/AR, bank reconciliation, budgets, tax, and financial periods.
- `Inventory`: products, warehouses, stock movements, reorder alerts, barcode support, and valuation.
- `Sales`: quotations, sales orders, purchase orders, invoicing, delivery notes, and finance/inventory events.
- `CRM`: contacts, opportunities, activities, tasks, campaigns, segmentation, and CRM reports.
- `Projects`: projects, tasks, time logs, files, comments, budgets, and project reports.
- `Reporting`: KPI dashboards, prebuilt reports, custom reports, exports, and scheduled reports.
- `Notifications`: in-app, email, SMS, and user notification preferences.
- `CustomModules`: module marketplace, company feature flags, custom APIs, and internal SDK workflows.
