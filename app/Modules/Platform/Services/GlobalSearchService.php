<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Projects\Models\Project;

class GlobalSearchService
{
    public function __construct(private readonly EffectiveAccessService $access) {}

    public function search(User $user, string $term, int $limit = 8): array
    {
        $companyId = $user->company_id;
        abort_unless($companyId, 422, 'Global operational search requires a company.');
        $like = '%'.addcslashes($term, '%_').'%';

        $results = [];

        if ($this->access->canAccessModule($user, 'inventory')) {
            $results['products'] = Product::where('company_id', $companyId)
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like))
                ->limit($limit)->get(['id', 'name', 'sku', 'barcode']);
        }
        if ($this->access->canAccessModule($user, 'projects')) {
            $results['projects'] = Project::where('company_id', $companyId)
                ->where('name', 'like', $like)->limit($limit)->get(['id', 'name', 'status']);
        }
        if ($this->access->canAccessModule($user, 'crm')) {
            $results['crm_contacts'] = CRMContact::where('company_id', $companyId)
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('company_name', 'like', $like))
                ->limit($limit)->get(['id', 'name', 'email', 'company_name', 'type']);
        }
        if ($this->access->canAccessModule($user, 'hr')) {
            $results['employees'] = Employee::where('company_id', $companyId)
                ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('employee_number', 'like', $like))
                ->limit($limit)->get(['id', 'name', 'email', 'employee_number', 'position']);
        }

        return $results;
    }
}
