<?php

namespace App\Modules\Companies\Exports;

use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CompanyDataExport implements WithMultipleSheets
{
    public function __construct(private readonly Company $company) {}

    public function sheets(): array
    {
        $usedTitles = [];
        $sheets = [
            new CompanyArraySheet($this->uniqueTitle('Company Summary', $usedTitles), ['Field', 'Value'], $this->companySummary()),
            new CompanyArraySheet($this->uniqueTitle('Users', $usedTitles), $this->userHeadings(), $this->userRows()),
            new CompanyArraySheet($this->uniqueTitle('Subscriptions', $usedTitles), $this->subscriptionHeadings(), $this->subscriptionRows()),
        ];

        foreach ($this->companyTables() as $table) {
            $columns = Schema::getColumnListing($table);
            $rows = DB::table($table)
                ->where('company_id', $this->company->id)
                ->get()
                ->map(fn ($row) => $this->normalizeRow((array) $row, $columns))
                ->all();

            $sheets[] = new CompanyArraySheet(
                $this->uniqueTitle(Str::headline($table), $usedTitles),
                collect($columns)->map(fn (string $column) => Str::headline($column))->all(),
                $rows,
            );
        }

        return $sheets;
    }

    private function companySummary(): array
    {
        $company = $this->company->loadMissing('organization');
        $subscription = CompanySubscription::query()
            ->with('plan.features')
            ->when(
                $company->organization_id,
                fn ($query) => $query->where('organization_id', $company->organization_id),
                fn ($query) => $query->where('company_id', $company->getKey()),
            )
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->latest('id')
            ->first();
        $settings = $company->settings ?? [];

        return [
            ['Company ID', $company->id],
            ['Name', $company->name],
            ['Display Name', data_get($settings, 'display_name', '-')],
            ['Plan', $subscription?->plan?->name ?? '-'],
            ['Subscription Status', $subscription?->status ?? '-'],
            ['Billing Cycle', $subscription?->billing_cycle ?? '-'],
            ['Currency', $company->currency],
            ['Locale', $company->locale],
            ['Timezone', $company->timezone],
            ['Active', $company->is_active ? 'Yes' : 'No'],
            ['Users', $this->activeUserCount()],
            ['Contact Email', data_get($settings, 'contact_email', '-')],
            ['Contact Phone', data_get($settings, 'contact_phone', '-')],
            ['Address', data_get($settings, 'address', '-')],
            ['Exported At', now()->toDateTimeString()],
        ];
    }

    private function userHeadings(): array
    {
        return ['ID', 'Name', 'Email', 'Roles', 'Active', 'Created At', 'Last Login At'];
    }

    private function userRows(): array
    {
        if (! $this->company->companyMemberships()->exists()) {
            return $this->company->users()
                ->where('is_active', true)
                ->with(['roles', 'customRole'])
                ->orderBy('id')
                ->get()
                ->map(fn ($user) => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->customRole?->name ?? ($user->roles->pluck('name')->join(', ') ?: '-'),
                    $user->is_active ? 'Yes' : 'No',
                    $this->formatValue($user->created_at),
                    $this->formatValue($user->last_login_at),
                ])
                ->all();
        }

        return $this->company->companyMemberships()
            ->where('status', CompanyMembership::STATUS_ACTIVE)
            ->with(['user', 'role', 'customRole'])
            ->orderBy('user_id')
            ->get()
            ->map(fn (CompanyMembership $membership) => [
                $membership->user->id,
                $membership->user->name,
                $membership->user->email,
                $membership->customRole?->name ?? $membership->role?->name ?? '-',
                $membership->user->is_active ? 'Yes' : 'No',
                $this->formatValue($membership->user->created_at),
                $this->formatValue($membership->user->last_login_at),
            ])
            ->all();
    }

    private function activeUserCount(): int
    {
        if ($this->company->companyMemberships()->exists()) {
            return $this->company->companyMemberships()
                ->where('status', CompanyMembership::STATUS_ACTIVE)
                ->count();
        }

        return $this->company->users()->where('is_active', true)->count();
    }

    private function subscriptionHeadings(): array
    {
        return ['ID', 'Plan', 'Status', 'Billing Cycle', 'Starts At', 'Ends At', 'Trial Ends At', 'Cancelled At'];
    }

    private function subscriptionRows(): array
    {
        return CompanySubscription::query()
            ->when(
                $this->company->organization_id,
                fn ($query) => $query->where('organization_id', $this->company->organization_id),
                fn ($query) => $query->where('company_id', $this->company->getKey()),
            )
            ->with('plan')
            ->latest()
            ->get()
            ->map(fn ($subscription) => [
                $subscription->id,
                $subscription->plan?->name ?? '-',
                $subscription->status,
                $subscription->billing_cycle,
                $this->formatValue($subscription->starts_at),
                $this->formatValue($subscription->ends_at),
                $this->formatValue($subscription->trial_ends_at),
                $this->formatValue($subscription->cancelled_at),
            ])
            ->all();
    }

    private function companyTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'company_id'))
            ->reject(fn (string $table) => in_array($table, ['companies', 'users', 'company_subscriptions'], true))
            ->values()
            ->all();
    }

    private function normalizeRow(array $row, array $columns): array
    {
        return collect($columns)
            ->map(fn (string $column) => $this->formatValue($row[$column] ?? null))
            ->all();
    }

    private function formatValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if (is_string($value) && $this->looksLikeJson($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        return $value;
    }

    private function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);

        return ($trimmed !== '') && (
            (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        );
    }

    private function uniqueTitle(string $title, array &$used): string
    {
        $clean = preg_replace('/[\[\]\*\/\\\\\?\:]/', ' ', $title) ?: 'Sheet';
        $clean = trim(preg_replace('/\s+/', ' ', $clean)) ?: 'Sheet';
        $base = mb_substr($clean, 0, 31);
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $used, true)) {
            $tail = ' '.$suffix++;
            $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }

        $used[] = $candidate;

        return $candidate;
    }
}
