<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\Inventory\Models\ReorderAlert;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private readonly EffectiveAccessService $access) {}

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
            'analytics' => [
                'company_growth' => $this->monthlyCount(Company::query(), 'created_at'),
                'user_growth' => $this->monthlyCount(User::query(), 'created_at'),
                'subscriptions_by_plan' => SubscriptionPlan::query()
                    ->withCount(['companySubscriptions as subscriptions' => fn ($query) => $query->whereIn('status', ['active', 'trialing'])])
                    ->orderBy('sort_order')
                    ->get(['id', 'name'])
                    ->map(fn (SubscriptionPlan $plan) => ['name' => $plan->name, 'value' => $plan->subscriptions])
                    ->values(),
                'subscriptions_by_status' => CompanySubscription::query()
                    ->selectRaw('status name, count(*) value')
                    ->groupBy('status')
                    ->orderByDesc('value')
                    ->get(),
            ],
        ];
    }

    private function companySummary(User $user): array
    {
        $companyId = $user->company_id;

        if ($companyId === null) {
            return ['scope' => 'company', 'metrics' => [], 'analytics' => [], 'access' => $this->access->effectiveAccess($user)];
        }

        $metrics = [];
        $analytics = [];
        $recentProjects = [];

        if ($this->access->canAccessModule($user, 'projects')) {
            $metrics['active_projects'] = Project::where('company_id', $companyId)->where('status', ProjectStatus::Active->value)->count();
            $metrics['open_tasks'] = ProjectTask::whereHas('project', fn ($query) => $query->where('company_id', $companyId))
                ->where('status', '!=', TaskStatus::Done->value)->count();
            $recentProjects = Project::query()->where('company_id', $companyId)->withCount('tasks')->latest()->limit(5)->get();
            $analytics['projects'] = [
                'projects_by_status' => $this->groupedCount(Project::where('company_id', $companyId), 'status'),
                'tasks_by_status' => ProjectTask::query()
                    ->whereHas('project', fn ($query) => $query->where('company_id', $companyId))
                    ->selectRaw('status name, count(*) value')->groupBy('status')->get(),
                'budget_total' => (float) Project::where('company_id', $companyId)->sum('budget'),
            ];
        }

        if ($this->access->canAccessModule($user, 'inventory')) {
            $metrics['reorder_alerts'] = ReorderAlert::where('company_id', $companyId)->whereNull('resolved_at')->count();
            $analytics['inventory'] = [
                'valuation_by_warehouse' => StockBalance::query()
                    ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
                    ->where('stock_balances.company_id', $companyId)
                    ->selectRaw('warehouses.name name, sum(stock_balances.quantity * stock_balances.average_cost) value, sum(stock_balances.quantity) quantity')
                    ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('value')->get(),
                'reorder_alerts' => ReorderAlert::query()->with('balance.product', 'balance.warehouse')
                    ->where('company_id', $companyId)->whereNull('resolved_at')->latest()->limit(5)->get(),
            ];
        }

        if ($this->access->canAccessModule($user, 'finance')) {
            $metrics['draft_journals'] = JournalEntry::where('company_id', $companyId)->whereNull('posted_at')->count();
            $metrics['open_invoices'] = Invoice::where('company_id', $companyId)->whereNotIn('status', ['paid', 'cancelled'])->count();
            $analytics['finance'] = [
                'invoice_trend' => $this->monthlyFinancials($companyId),
                'invoice_statuses' => $this->groupedCount(Invoice::where('company_id', $companyId), 'status'),
                'receivables_outstanding' => (float) Invoice::where('company_id', $companyId)->where('type', 'receivable')->sum(DB::raw('total - paid_total')),
                'payables_outstanding' => (float) Invoice::where('company_id', $companyId)->where('type', 'payable')->sum(DB::raw('total - paid_total')),
                'payments_total' => (float) Payment::where('company_id', $companyId)->sum('amount'),
            ];
        }

        if ($this->access->canAccessModule($user, 'sales')) {
            $metrics['open_sales_orders'] = SalesOrder::where('company_id', $companyId)->whereNotIn('status', ['closed'])->count();
            $metrics['open_purchase_orders'] = PurchaseOrder::where('company_id', $companyId)->whereNotIn('status', ['closed'])->count();
            $metrics['sales_revenue'] = (float) SalesOrder::where('company_id', $companyId)->whereIn('status', ['invoiced', 'closed'])->sum('total');
            $analytics['sales'] = [
                'order_trend' => $this->monthlySales($companyId),
                'sales_statuses' => $this->groupedCount(SalesOrder::where('company_id', $companyId), 'status'),
                'purchase_statuses' => $this->groupedCount(PurchaseOrder::where('company_id', $companyId), 'status'),
            ];
        }

        if ($this->access->canAccessModule($user, 'crm')) {
            $metrics['open_opportunities'] = CRMOpportunity::where('company_id', $companyId)->where('status', 'open')->count();
            $metrics['pipeline_value'] = (float) CRMOpportunity::where('company_id', $companyId)->where('status', 'open')->sum('value');
            $metrics['leads_this_month'] = CRMContact::where('company_id', $companyId)->where('type', 'lead')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
            $metrics['overdue_crm_followups'] = CRMTask::where('company_id', $companyId)->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<', now())->count();
            $analytics['crm'] = [
                'contacts_by_type' => $this->groupedCount(CRMContact::where('company_id', $companyId), 'type'),
                'pipeline_by_stage' => CRMOpportunity::query()
                    ->join('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_opportunities.stage_id')
                    ->where('crm_opportunities.company_id', $companyId)
                    ->selectRaw('crm_pipeline_stages.name name, count(*) opportunities, sum(crm_opportunities.value) value')
                    ->groupBy('crm_pipeline_stages.id', 'crm_pipeline_stages.name', 'crm_pipeline_stages.sort_order')
                    ->orderBy('crm_pipeline_stages.sort_order')->get(),
            ];
        }

        if ($this->access->canAccessModule($user, 'hr')) {
            $analytics['hr'] = [
                'headcount_by_department' => Department::query()
                    ->leftJoin('hr_employees', function ($join) {
                        $join->on('hr_employees.department_id', '=', 'hr_departments.id')->where('hr_employees.status', '=', 'active');
                    })
                    ->where('hr_departments.company_id', $companyId)
                    ->selectRaw('hr_departments.name name, count(hr_employees.id) value')
                    ->groupBy('hr_departments.id', 'hr_departments.name')->orderByDesc('value')->get(),
                'leave_by_status' => $this->groupedCount(LeaveRequest::where('company_id', $companyId), 'status'),
                'active_employees' => Employee::where('company_id', $companyId)->where('status', 'active')->count(),
                'payroll_total' => (float) PayrollItem::whereHas('employee', fn ($query) => $query->where('company_id', $companyId))->sum('net_salary'),
            ];
        }

        return [
            'scope' => 'company',
            'metrics' => $metrics,
            'recent_projects' => $recentProjects,
            'analytics' => $analytics,
            'access' => $this->access->effectiveAccess($user),
        ];
    }

    private function groupedCount($query, string $column)
    {
        return $query->selectRaw("{$column} name, count(*) value")->groupBy($column)->orderByDesc('value')->get();
    }

    private function monthlyCount($query, string $column)
    {
        $rows = $query->where($column, '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') period, count(*) value")
            ->groupBy('period')
            ->pluck('value', 'period');

        return $this->months()->map(fn (string $period) => ['period' => $period, 'value' => (int) ($rows[$period] ?? 0)]);
    }

    private function monthlyFinancials(int $companyId)
    {
        $rows = Invoice::query()
            ->where('company_id', $companyId)
            ->where('invoice_date', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') period, sum(case when type = 'receivable' then total else 0 end) receivable, sum(case when type = 'payable' then total else 0 end) payable")
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        return $this->months()->map(fn (string $period) => [
            'period' => $period,
            'receivable' => (float) ($rows[$period]?->receivable ?? 0),
            'payable' => (float) ($rows[$period]?->payable ?? 0),
        ]);
    }

    private function monthlySales(int $companyId)
    {
        $rows = SalesOrder::query()
            ->where('company_id', $companyId)
            ->where('order_date', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(order_date, '%Y-%m') period, count(*) orders, sum(total) revenue")
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        return $this->months()->map(fn (string $period) => [
            'period' => $period,
            'orders' => (int) ($rows[$period]?->orders ?? 0),
            'revenue' => (float) ($rows[$period]?->revenue ?? 0),
        ]);
    }

    private function months()
    {
        return collect(range(5, 0))->map(fn (int $monthsAgo) => now()->subMonths($monthsAgo)->format('Y-m'));
    }
}
