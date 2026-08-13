<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Substring matches, applied at every depth. Deliberately broad: it is
     * better to redact a harmless `sort_token` than to log a card token.
     */
    private const HIDDEN = [
        'password',
        'remember_token',
        'secret',
        'token',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'credential',
        'authorization',
        'signature',
        'card_number',
        'pan',
        'cvv',
        'cvc',
        'secur',
    ];

    private const REDACTED = '[redacted]';

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

    /**
     * Strip secrets at every depth, not just the top level.
     *
     * Audited models carry JSON columns — integration `settings`, provider
     * payloads, POS tender metadata — and a flat `Arr::except` writes the
     * nested contents of those straight into the audit log. Card tokens and
     * provider credentials must never land there.
     */
    private function sanitize(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = $this->sanitizeValue($value);
        }

        return $redacted;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitize($value);
        }

        // A JSON column read straight off the model is still a string here.
        if (is_string($value) && $this->looksLikeJsonObject($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return json_encode($this->sanitize($decoded));
            }
        }

        return $value;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = Str::lower($key);

        foreach (self::HIDDEN as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeJsonObject(string $value): bool
    {
        $trimmed = ltrim($value);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
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
