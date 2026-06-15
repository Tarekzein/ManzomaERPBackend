# Reporting & Business Intelligence

Company-scoped reporting module covering configurable KPI widgets, module report catalog, guarded custom report definitions, PDF/XLSX/CSV exports, scheduled email delivery, and private Pusher-compatible refresh events.

## Scheduler

Run Laravel's scheduler in production so due reports are generated:

```bash
php artisan schedule:work
```

The `reporting:run-schedules` command processes due schedules and records every completed or failed run.

## Custom report safety

The report engine only permits fields, dimensions, metrics, filters, and aggregate functions declared in `ReportEngine::sources()`. It never accepts a table name or raw SQL from API clients.
