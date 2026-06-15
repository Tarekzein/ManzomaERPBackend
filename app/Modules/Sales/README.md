# Sales & Purchase Orders Module

Company-scoped sales and purchasing module covering quotations, sales order workflow, purchase orders,
three-way matching, customer price lists, PDF documents, inventory stock updates, finance invoice posting,
and sales pipeline reports.

All endpoints are under `/api/sales` and require Sanctum authentication. Management operations use
`sales.create`, `sales.edit`, and `sales.export`.
