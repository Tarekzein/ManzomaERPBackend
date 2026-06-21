<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use Illuminate\Support\Collection;

class EffectiveAccessService
{
    public const MODULE_FEATURES = [
        'finance' => 'core.finance',
        'inventory' => 'core.inventory',
        'hr' => 'core.hr',
        'sales' => 'core.sales',
        'crm' => 'core.crm',
        'projects' => 'core.projects',
        'reporting' => 'reporting.prebuilt',
        'custom-modules' => 'custom_modules.marketplace',
    ];

    public const MODULE_PERMISSION_PREFIXES = [
        'custom-modules' => 'custom_modules',
    ];

    public function effectiveAccess(User $user): array
    {
        $permissions = $this->permissions($user);
        $features = $this->features($user);

        return [
            'modules' => collect(self::MODULE_FEATURES)->mapWithKeys(function (string $feature, string $module) use ($user, $permissions, $features) {
                $prefix = $this->permissionPrefix($module);
                $modulePermissions = $permissions
                    ->filter(fn (string $permission) => str_starts_with($permission, "{$prefix}."))
                    ->map(fn (string $permission) => substr($permission, strlen($prefix) + 1))
                    ->values();

                return [$module => [
                    'enabled' => $user->isSuperAdmin() || ($features->contains($feature) && $permissions->contains("{$module}.view")),
                    'permissions' => $user->isSuperAdmin() ? ['*'] : $modulePermissions->all(),
                ]];
            })->all(),
            'features' => $features->values()->all(),
            'permissions' => $permissions->values()->all(),
        ];
    }

    public function canAccessModule(User $user, string $module): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $feature = self::MODULE_FEATURES[$module] ?? null;

        return $feature !== null
            && $this->features($user)->contains($feature)
            && $this->permissions($user)->contains($this->permissionPrefix($module).'.view');
    }

    public function can(User $user, string $permission, ?string $module = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $module ??= explode('.', $permission)[0];

        return $this->canAccessModule($user, $module) && $this->permissions($user)->contains($permission);
    }

    public function hasFeature(User $user, string $feature): bool
    {
        return $user->isSuperAdmin() || $this->features($user)->contains($feature);
    }

    public function featureForPath(string $path): ?string
    {
        $segment = explode('/', trim($path, '/'))[1] ?? null;

        return self::MODULE_FEATURES[$segment] ?? null;
    }

    public function moduleForPath(string $path): ?string
    {
        $segment = explode('/', trim($path, '/'))[1] ?? null;

        return array_key_exists($segment, self::MODULE_FEATURES) ? $segment : null;
    }

    public function permissionForAction(string $module, string $action): string
    {
        return $this->permissionPrefix($module).'.'.$action;
    }

    private function permissions(User $user): Collection
    {
        return $user->getAllPermissions()->pluck('name')->unique()->sort()->values();
    }

    private function permissionPrefix(string $module): string
    {
        return self::MODULE_PERMISSION_PREFIXES[$module] ?? $module;
    }

    private function features(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return collect();
        }

        $user->loadMissing('company.subscription.plan.features');

        return collect($user->company?->subscription?->plan?->features)
            ->filter(fn ($feature) => (bool) $feature->pivot?->enabled)
            ->pluck('slug')
            ->unique()
            ->sort()
            ->values();
    }
}
