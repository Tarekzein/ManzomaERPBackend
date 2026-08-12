<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditService
{
    private const HIDDEN = ['password', 'remember_token', 'secret', 'token', 'api_key'];

    public function __construct(private readonly CompanyContext $context) {}

    public function recordModel(Model $model, string $event): void
    {
        if ($model instanceof AuditLog || ! app()->bound('request')) {
            return;
        }

        /** @var Request $request */
        $request = request();
        /** @var User|null $actor */
        $actor = $request->user();
        $companyId = $model->getAttribute('company_id') ?? $this->context->companyId() ?? $actor?->company_id;
        $organizationId = $this->organizationId($model, $companyId);

        AuditLog::query()->create([
            'company_id' => $companyId,
            'organization_id' => $organizationId,
            'user_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $event === 'created' ? null : $this->sanitize($model->getOriginal()),
            'new_values' => $event === 'deleted' ? null : $this->sanitize($model->getAttributes()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    public function record(string $event, ?Model $subject = null, array $old = [], array $new = []): void
    {
        /** @var User|null $actor */
        $actor = request()->user();
        $companyId = $subject?->getAttribute('company_id') ?? $this->context->companyId() ?? $actor?->company_id;

        AuditLog::query()->create([
            'company_id' => $companyId,
            'organization_id' => $this->organizationId($subject, $companyId),
            'user_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $this->sanitize($old),
            'new_values' => $this->sanitize($new),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->header('X-Request-Id') ?: (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    private function sanitize(array $values): array
    {
        return Arr::except($values, self::HIDDEN);
    }

    private function organizationId(?Model $subject, ?int $companyId): ?int
    {
        if ($subject instanceof Organization) {
            return (int) $subject->getKey();
        }

        $organizationId = $subject?->getAttribute('organization_id')
            ?? $this->context->organization()?->getKey();

        if ($organizationId !== null || $companyId === null) {
            return $organizationId === null ? null : (int) $organizationId;
        }

        return Company::query()->whereKey($companyId)->value('organization_id');
    }
}
