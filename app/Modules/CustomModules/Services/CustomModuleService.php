<?php

namespace App\Modules\CustomModules\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CustomModules\Models\CustomModule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomModuleService
{
    public function catalog(User $actor)
    {
        return CustomModule::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('status', 'approved')->where('is_active', true))
            ->with(['companies' => fn ($query) => $query->where('companies.id', $actor->company_id)])
            ->orderBy('name')
            ->get();
    }

    public function save(User $actor, array $data, ?CustomModule $module = null): CustomModule
    {
        throw_unless($actor->isSuperAdmin(), AuthorizationException::class, 'Only a super admin can manage the module catalog.');

        $module ??= new CustomModule;
        $module->fill($data)->save();

        return $module->refresh();
    }

    public function install(User $actor, CustomModule $module, array $settings = []): CustomModule
    {
        $this->ensureCompanyAdmin($actor);

        if (! $module->is_active || $module->status !== 'approved') {
            throw ValidationException::withMessages(['module' => ['This module is not approved for installation.']]);
        }

        if ($module->minimum_erp_version && version_compare(config('erp.version'), $module->minimum_erp_version, '<')) {
            throw ValidationException::withMessages(['module' => ['This module requires a newer ERP version.']]);
        }

        DB::table('company_custom_modules')->updateOrInsert(
            ['company_id' => $actor->company_id, 'custom_module_id' => $module->id],
            [
                'installed_version' => $module->version,
                'status' => 'enabled',
                'settings' => json_encode($settings),
                'installed_by' => $actor->id,
                'installed_at' => now(),
                'disabled_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $module->load(['companies' => fn ($query) => $query->where('companies.id', $actor->company_id)]);
    }

    public function setStatus(User $actor, CustomModule $module, string $status): CustomModule
    {
        $this->ensureCompanyAdmin($actor);
        abort_unless(DB::table('company_custom_modules')->where('company_id', $actor->company_id)->where('custom_module_id', $module->id)->exists(), 404);

        DB::table('company_custom_modules')
            ->where('company_id', $actor->company_id)
            ->where('custom_module_id', $module->id)
            ->update(['status' => $status, 'disabled_at' => $status === 'disabled' ? now() : null, 'updated_at' => now()]);

        return $module->load(['companies' => fn ($query) => $query->where('companies.id', $actor->company_id)]);
    }

    public function uninstall(User $actor, CustomModule $module): void
    {
        $this->ensureCompanyAdmin($actor);
        DB::table('company_custom_modules')->where('company_id', $actor->company_id)->where('custom_module_id', $module->id)->delete();
    }

    private function ensureCompanyAdmin(User $actor): void
    {
        if (! $actor->company_id || ! $actor->can('custom_modules.edit')) {
            throw new AuthorizationException('You are not allowed to manage company modules.');
        }
    }
}
