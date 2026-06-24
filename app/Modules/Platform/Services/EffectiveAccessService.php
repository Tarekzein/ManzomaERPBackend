<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserPermissionOverride;
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
        $permissions = $this->effectivePermissionNames($user);
        $features = $this->features($user);
        $metadataFilter = fn (string $permission) => $user->isSuperAdmin() || $this->permissionAllowedBySubscription($user, $permission);
        $rolePermissions = $this->rolePermissionNames($user)->filter($metadataFilter)->values();
        $allowedPermissions = $this->allowedPermissionNames($user)->filter($metadataFilter)->values();
        $deniedPermissions = $this->deniedPermissionNames($user)->filter($metadataFilter)->values();

        return [
            'modules' => collect(self::MODULE_FEATURES)->mapWithKeys(function (string $feature, string $module) use ($user, $permissions, $features) {
                $prefix = $this->permissionPrefix($module);
                $modulePermissions = $permissions
                    ->filter(fn (string $permission) => str_starts_with($permission, "{$prefix}."))
                    ->map(fn (string $permission) => substr($permission, strlen($prefix) + 1))
                    ->values();

                return [$module => [
                    'enabled' => $user->isSuperAdmin() || ($features->contains($feature) && $permissions->contains("{$prefix}.view")),
                    'permissions' => $user->isSuperAdmin() ? ['*'] : $modulePermissions->all(),
                ]];
            })->all(),
            'features' => $features->values()->all(),
            'permissions' => $permissions->values()->all(),
            'role_permissions' => $rolePermissions->values()->all(),
            'allowed_permissions' => $allowedPermissions->values()->all(),
            'denied_permissions' => $deniedPermissions->values()->all(),
        ];
    }

    public function canAccessModule(User $user, string $module): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $feature = self::MODULE_FEATURES[$module] ?? null;
        $prefix = $this->permissionPrefix($module);

        return $feature !== null
            && $this->features($user)->contains($feature)
            && $this->effectivePermissionNames($user)->contains($prefix.'.view');
    }

    public function can(User $user, string $permission, ?string $module = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $module ??= explode('.', $permission)[0];

        if (! $this->permissionAllowedBySubscription($user, $permission)) {
            return false;
        }

        if (array_key_exists($module, self::MODULE_FEATURES)) {
            return $this->canAccessModule($user, $module) && $this->effectivePermissionNames($user)->contains($permission);
        }

        return $this->effectivePermissionNames($user)->contains($permission);
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

    public function effectivePermissionNames(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return $user->getAllPermissions()->pluck('name')->unique()->sort()->values();
        }

        return $this->rolePermissionNames($user)
            ->merge($this->legacyDirectPermissionNames($user))
            ->merge($this->allowedPermissionNames($user))
            ->reject(fn (string $permission) => $this->deniedPermissionNames($user)->contains($permission))
            ->filter(fn (string $permission) => $this->permissionAllowedBySubscription($user, $permission))
            ->unique()
            ->sort()
            ->values();
    }

    public function rolePermissionNames(User $user): Collection
    {
        $user->loadMissing('roles.permissions');

        return $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values();
    }

    public function allowedPermissionNames(User $user): Collection
    {
        return $this->overrideNames($user, UserPermissionOverride::EFFECT_ALLOW);
    }

    public function deniedPermissionNames(User $user): Collection
    {
        return $this->overrideNames($user, UserPermissionOverride::EFFECT_DENY);
    }

    public function permissionAllowedBySubscription(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $module = $this->moduleForPermission($permission);
        if ($module === null) {
            return true;
        }

        $feature = self::MODULE_FEATURES[$module] ?? null;

        return $feature === null || $this->features($user)->contains($feature);
    }

    public function permissionModule(string $permission): ?string
    {
        return $this->moduleForPermission($permission);
    }

    public function permissionAction(string $permission): string
    {
        return explode('.', $permission, 2)[1] ?? $permission;
    }

    private function legacyDirectPermissionNames(User $user): Collection
    {
        $user->loadMissing('permissions');

        return $user->permissions->pluck('name')->unique()->sort()->values();
    }

    private function overrideNames(User $user, string $effect): Collection
    {
        $user->loadMissing('permissionOverrides');

        return $user->permissionOverrides
            ->where('effect', $effect)
            ->pluck('permission_name')
            ->unique()
            ->sort()
            ->values();
    }

    public function permissionPrefix(string $module): string
    {
        return self::MODULE_PERMISSION_PREFIXES[$module] ?? $module;
    }

    public function features(User $user): Collection
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

    private function moduleForPermission(string $permission): ?string
    {
        $prefix = explode('.', $permission, 2)[0] ?? null;
        if ($prefix === null) {
            return null;
        }

        foreach (self::MODULE_PERMISSION_PREFIXES as $module => $permissionPrefix) {
            if ($prefix === $permissionPrefix) {
                return $module;
            }
        }

        return array_key_exists($prefix, self::MODULE_FEATURES) ? $prefix : null;
    }
}
