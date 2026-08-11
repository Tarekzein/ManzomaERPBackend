# Module-by-module audit — 6 Aug 2026

Scope: Finance, HR, Inventory, Sales, Projects, and Reporting, with tenant
isolation regression coverage across these modules and CRM.

## Findings fixed

| Module | Finding | Resolution |
| --- | --- | --- |
| Finance | Seeded demo transactions made tests assume the wrong starting balance and journal count. | Assertions now use company-scoped deltas and locate the intended aging row. |
| Finance | Payable schedules ignored credit notes. | Schedulable outstanding now subtracts credited totals. |
| Finance/Sales/Inventory | Generated document numbers could collide across simultaneous requests or existing deployments. | Added transactional, per-company/year/type sequences and initializes after the highest existing formatted number. Credit notes now use the same service. |
| HR | A non-company-wide user with no linked employee record received an empty scope that was interpreted as unrestricted. | Such users now receive an explicit empty result for employee-scoped resources. |
| HR | Arbitrary sort fields could reach SQL and cause a 500 response. | Added model-specific sort allowlists. |
| Inventory | Stock sufficiency was checked without locking the balance row. | Issue operations now lock the balance before validation and mutation. |
| Sales | Two simultaneous order confirmations could both create stock and cost-of-goods side effects. | Confirmation now refetches and locks the order inside its transaction. |
| Projects | List page size was not bounded, and the module lacked normal flow coverage. | Project/task pagination is clamped to 100 and feature tests cover tasks, time, comments, expenses, reports, and foreign owners. |
| Reporting | PHP array union allowed a caller-supplied company ID to move reports/widgets/schedules between tenants. | Tenant and creator identifiers now always win; cross-company references are rejected. |
| Reporting | Dashboard widgets were visible and mutable by colleagues in the same company. | Widgets are private to their creator, including update, delete, and reorder operations. |
| Reporting | A platform admin without an explicit company silently used the first tenant. | Platform admins must provide the target company. |

## Verification coverage

- Finance: balanced/unbalanced journals, locked periods, receivables, receipts,
  credit notes, overpayments, budgets, tax, exchange rates, payables, aging, and
  statements.
- Inventory: receipts, transfers, FIFO/LIFO/average issues, reorder alerts,
  write-offs, insufficient stock, and tenant boundaries.
- Sales: quotation-to-order-to-invoice flow, inventory/COGS effects, purchase
  receipt, three-way match, and PDF generation.
- HR: organization, employee self-service, leave, payroll, recruitment,
  documents, professional endpoints, and the unlinked-user scope regression.
- Projects: project/task lifecycle, time logs, comments, expenses, reporting,
  owner validation, and tenant boundaries.
- Reporting: catalogs, custom reports, exports, schedules, safe fields,
  immutable tenant ownership, private widgets, and tenant boundaries.

The focused audited-module and integration run completed with 126 passing
tests. The complete backend run completed with 214 passing tests and one known,
unrelated Platform compliance test failure.

## Remaining hardening backlog

1. Finance payment/posting and Sales receiving/invoicing should use the same
   row-lock pattern for every state-changing transition to eliminate remaining
   double-submit windows.
2. Inventory's nullable `location_id` means the database unique key may permit
   duplicate no-location balance rows under a concurrent first receipt on
   MySQL. A safe production migration needs to merge any existing duplicates
   before replacing that uniqueness strategy.
3. Several legacy list endpoints outside Projects still return unbounded data.
   Add consistent cursor or page-based pagination before high-volume tenants.
4. Run the concurrency scenarios against the production database engine, not
   only the test configuration.

No destructive data migration was introduced for the remaining items because
deduplicating live financial or stock records requires an explicit rollout and
reconciliation plan.
