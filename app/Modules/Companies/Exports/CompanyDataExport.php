<?php

namespace App\Modules\Companies\Exports;

use App\Modules\Companies\Models\Company;
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
        $company = $this->company->loadMissing('subscription.plan.features');
        $settings = $company->settings ?? [];

        return [
            ['Company ID', $company->id],
            ['Name', $company->name],
            ['Display Name', data_get($settings, 'display_name', '-')],
            ['Plan', $company->subscription?->plan?->name ?? '-'],
            ['Subscription Status', $company->subscription?->status ?? '-'],
            ['Billing Cycle', $company->subscription?->billing_cycle ?? '-'],
            ['Currency', $company->currency],
            ['Locale', $company->locale],
            ['Timezone', $company->timezone],
            ['Active', $company->is_active ? 'Yes' : 'No'],
            ['Users', $company->users()->count()],
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
        return $this->company->users()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                $user->id,
                $user->name,
                $user->email,
                $user->roles->pluck('name')->implode(', '),
                $user->is_active ? 'Yes' : 'No',
                $this->formatValue($user->created_at),
                $this->formatValue($user->last_login_at),
            ])
            ->all();
    }

    private function subscriptionHeadings(): array
    {
        return ['ID', 'Plan', 'Status', 'Billing Cycle', 'Starts At', 'Ends At', 'Trial Ends At', 'Cancelled At'];
    }

    private function subscriptionRows(): array
    {
        return $this->company->subscriptions()
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
