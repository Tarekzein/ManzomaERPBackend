# Authentication Module

The Authentication module owns authentication, users, roles, tokens, login auditing, and user access management.

## Structure

- `Http/Controllers`: thin HTTP adapters that return API responses.
- `Http/Requests`: request validation.
- `DTOs`: immutable input objects passed into services.
- `Services`: authentication and user-management use cases.
- `Policies`: authorization and company-assignment rules.
- `Contracts`: repository interfaces used by services.
- `Repositories`: Eloquent and third-party persistence adapters.
- `Models`: authentication-owned Eloquent models.
- `Actions/Fortify`: Laravel Fortify integration adapters.
- `Providers`: dependency bindings, Fortify configuration, and module route registration.
- `Routes`: authentication-owned API routes.

Controllers depend on services, services depend on contracts, and repository implementations are selected by the module service provider.
