<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Projects\Models\Project;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GlobalSearchService
{
    private const MAX_LIMIT = 25;

    private const MAX_TOKENS = 8;

    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly WorkScopeService $scope,
        private readonly CompanyContext $context,
    ) {}

    public function search(User $user, string $term, int $limit = 8): array
    {
        $companyId = $this->context->companyIdFor($user);
        abort_unless($companyId, 422, 'Global operational search requires a company.');

        $term = $this->normalizeTerm($term);
        if (Str::length($term) < 2) {
            throw ValidationException::withMessages([
                'q' => ['The search query must be at least 2 characters.'],
            ]);
        }

        $limit = min(max($limit, 1), self::MAX_LIMIT);
        $tokens = array_slice(array_values(array_unique(
            preg_split('/\s+/u', Str::lower($term), flags: PREG_SPLIT_NO_EMPTY) ?: []
        )), 0, self::MAX_TOKENS);
        if ($tokens === []) {
            throw ValidationException::withMessages([
                'q' => ['The search query must contain searchable characters.'],
            ]);
        }

        $groups = [];

        if ($this->access->canAccessModule($user, 'inventory')) {
            $groups['products'] = $this->products($companyId, $term, $tokens, $limit);
        }

        if ($this->access->canAccessModule($user, 'projects')) {
            $groups['projects'] = $this->projects($user, $companyId, $term, $tokens, $limit);
        }

        if ($this->access->canAccessModule($user, 'crm')) {
            $groups['crm_contacts'] = $this->contacts($companyId, $term, $tokens, $limit);
        }

        if ($this->access->canAccessModule($user, 'hr')) {
            $groups['employees'] = $this->employees($user, $companyId, $term, $tokens, $limit);
        }

        return $this->globallyLimited($groups, $limit);
    }

    public function normalizeTerm(string $term): string
    {
        return trim(preg_replace('/\s+/u', ' ', trim($term)) ?? trim($term));
    }

    private function products(int $companyId, string $term, array $tokens, int $limit): array
    {
        $columns = ['name', 'sku', 'barcode'];
        $query = Product::query()->where('company_id', $companyId);
        $this->applySearch($query, $columns, $term, $tokens);

        return $query->limit($limit)
            ->get(['id', 'name', 'sku', 'barcode'])
            ->map(fn (Product $product) => $this->result(
                id: $product->id,
                type: 'product',
                module: 'inventory',
                title: $product->name,
                subtitle: $product->sku,
                target: "/inventory/products?product_id={$product->id}",
                meta: [
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                ],
                term: $term,
                matchValues: [$product->name, $product->sku, $product->barcode],
            ))
            ->all();
    }

    private function projects(User $user, int $companyId, string $term, array $tokens, int $limit): array
    {
        $columns = ['name', 'description'];
        $query = Project::query()->where('company_id', $companyId);
        $this->scope->applyProjectScope($query, $user);
        $this->applySearch($query, $columns, $term, $tokens);

        return $query->limit($limit)
            ->get(['id', 'name', 'description', 'status'])
            ->map(function (Project $project) use ($term) {
                $status = $project->status instanceof BackedEnum
                    ? (string) $project->status->value
                    : (string) $project->status;

                return $this->result(
                    id: $project->id,
                    type: 'project',
                    module: 'projects',
                    title: $project->name,
                    subtitle: $status,
                    target: "/projects/{$project->id}",
                    meta: ['status' => $status],
                    term: $term,
                    matchValues: [$project->name, $project->description],
                );
            })
            ->all();
    }

    private function contacts(int $companyId, string $term, array $tokens, int $limit): array
    {
        $columns = ['name', 'email', 'company_name', 'phone'];
        $query = CRMContact::query()->where('company_id', $companyId);
        $this->applySearch($query, $columns, $term, $tokens);

        return $query->limit($limit)
            ->get(['id', 'name', 'email', 'company_name', 'phone', 'type'])
            ->map(fn (CRMContact $contact) => $this->result(
                id: $contact->id,
                type: 'crm_contact',
                module: 'crm',
                title: $contact->name,
                subtitle: $contact->company_name ?: $contact->email,
                target: "/crm/contacts?contact_id={$contact->id}",
                meta: [
                    'contact_type' => $contact->type,
                    'company_name' => $contact->company_name,
                    'email' => $contact->email,
                ],
                term: $term,
                matchValues: [$contact->name, $contact->email, $contact->company_name, $contact->phone],
            ))
            ->all();
    }

    private function employees(User $user, int $companyId, string $term, array $tokens, int $limit): array
    {
        $columns = ['name', 'email', 'employee_number', 'position', 'phone'];
        $query = Employee::query()->where('company_id', $companyId);

        if (! $this->scope->isCompanyWide($user)) {
            $employeeIds = $this->scope->scopedEmployeeIds($user);
            $employeeIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('id', $employeeIds);
        }

        $this->applySearch($query, $columns, $term, $tokens);

        return $query->limit($limit)
            ->get(['id', 'name', 'email', 'employee_number', 'position', 'phone', 'status'])
            ->map(fn (Employee $employee) => $this->result(
                id: $employee->id,
                type: 'employee',
                module: 'hr',
                title: $employee->name,
                subtitle: $employee->position ?: $employee->employee_number,
                target: "/hr/employees?employee_id={$employee->id}",
                meta: [
                    'employee_number' => $employee->employee_number,
                    'position' => $employee->position,
                    'status' => $employee->status,
                ],
                term: $term,
                matchValues: [$employee->name, $employee->email, $employee->employee_number, $employee->position, $employee->phone],
            ))
            ->all();
    }

    private function applySearch(Builder $query, array $columns, string $term, array $tokens): void
    {
        foreach ($tokens as $token) {
            $pattern = '%'.$this->escapeLike($token).'%';

            $query->where(function (Builder $query) use ($columns, $pattern) {
                foreach ($columns as $column) {
                    $query->orWhereRaw(
                        'LOWER('.$this->wrap($query, $column).") LIKE ? ESCAPE '!'",
                        [$pattern]
                    );
                }
            });
        }

        $normalizedTerm = Str::lower($term);
        $exact = [];
        $prefix = [];
        $bindings = [];

        foreach ($columns as $column) {
            $wrapped = $this->wrap($query, $column);
            $exact[] = "LOWER({$wrapped}) = ?";
            $bindings[] = $normalizedTerm;
        }

        foreach ($columns as $column) {
            $wrapped = $this->wrap($query, $column);
            $prefix[] = "LOWER({$wrapped}) LIKE ? ESCAPE '!'";
            $bindings[] = $this->escapeLike($normalizedTerm).'%';
        }

        $query->orderByRaw(
            'CASE WHEN ('.implode(' OR ', $exact).') THEN 0 '
            .'WHEN ('.implode(' OR ', $prefix).') THEN 1 ELSE 2 END',
            $bindings
        )->orderBy($columns[0])->orderBy('id');
    }

    private function wrap(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($column);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function result(
        int $id,
        string $type,
        string $module,
        string $title,
        ?string $subtitle,
        string $target,
        array $meta,
        string $term,
        array $matchValues,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'module' => $module,
            'title' => $title,
            'subtitle' => filled($subtitle) ? $subtitle : null,
            'target' => $target,
            'meta' => collect($meta)->reject(fn ($value) => $value === null || $value === '')->all(),
            '_rank' => $this->rank($term, $matchValues),
        ];
    }

    private function rank(string $term, array $values): int
    {
        $term = Str::lower($term);
        $values = collect($values)
            ->filter(fn ($value) => is_scalar($value) && filled((string) $value))
            ->map(fn ($value) => Str::lower($this->normalizeTerm((string) $value)));

        if ($values->contains($term)) {
            return 0;
        }

        if ($values->contains(fn (string $value) => str_starts_with($value, $term))) {
            return 1;
        }

        if ($values->contains(fn (string $value) => str_contains($value, $term))) {
            return 2;
        }

        return 3;
    }

    private function globallyLimited(array $groups, int $limit): array
    {
        $results = collect($groups)
            ->flatMap(fn (array $items, string $group) => collect($items)->map(fn (array $item) => [
                'group' => $group,
                'item' => $item,
            ]))
            ->sort(function (array $left, array $right) {
                $rank = $left['item']['_rank'] <=> $right['item']['_rank'];

                return $rank !== 0
                    ? $rank
                    : strnatcasecmp($left['item']['title'], $right['item']['title']);
            })
            ->take($limit)
            ->values();

        return collect(array_keys($groups))->mapWithKeys(function (string $group) use ($results) {
            return [$group => $results
                ->where('group', $group)
                ->pluck('item')
                ->map(fn (array $item) => collect($item)->except('_rank')->all())
                ->values()
                ->all()];
        })->all();
    }
}
