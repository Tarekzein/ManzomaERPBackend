<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\ReorderAlert;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;

class DashboardService
{
    public function summary(User $user): array
    {
        return $user->isSuperAdmin() ? $this->platformSummary() : $this->companySummary($user);
    }

    private function platformSummary(): array
    {
        return [
            'scope' => 'platform',
            'metrics' => [
                'companies' => Company::count(),
                'active_companies' => Company::where('is_active', true)->count(),
                'users' => User::count(),
                'plans' => SubscriptionPlan::where('is_active', true)->count(),
                'active_subscriptions' => CompanySubscription::whereIn('status', ['active', 'trialing'])->count(),
            ],
            'recent_companies' => Company::query()
                ->with('subscription.plan')
                ->withCount('users')
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }

    private function companySummary(User $user): array
    {
        $companyId = $user->company_id;

        if ($companyId === null) {
            return ['scope' => 'company', 'metrics' => [], 'recent_projects' => []];
        }

        return [
            'scope' => 'company',
            'metrics' => [
                'active_projects' => Project::where('company_id', $companyId)->where('status', ProjectStatus::Active->value)->count(),
                'open_tasks' => ProjectTask::whereHas('project', fn ($query) => $query->where('company_id', $companyId))
                    ->where('status', '!=', TaskStatus::Done->value)
                    ->count(),
                'reorder_alerts' => ReorderAlert::where('company_id', $companyId)->whereNull('resolved_at')->count(),
                'draft_journals' => JournalEntry::where('company_id', $companyId)->whereNull('posted_at')->count(),
                'open_invoices' => Invoice::where('company_id', $companyId)->whereNotIn('status', ['paid', 'cancelled'])->count(),
            ],
            'recent_projects' => Project::query()
                ->where('company_id', $companyId)
                ->withCount('tasks')
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }
}
