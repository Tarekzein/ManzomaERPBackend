<?php

namespace App\Modules\Organizations\Console;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileOrganizationQuotas extends Command
{
    protected $signature = 'organizations:reconcile-quotas
        {--chunk=200 : Number of organizations to process per chunk}';

    protected $description = 'Expire stale invitations and reconcile organization subscription quota state';

    public function __construct(private readonly OrganizationQuotaService $quotas)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $processed = 0;
        $expiredInvitations = 0;
        $errors = 0;
        $chunk = max((int) $this->option('chunk'), 1);

        Organization::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunk, function ($organizations) use (&$processed, &$expiredInvitations, &$errors): void {
                foreach ($organizations as $organization) {
                    try {
                        DB::transaction(function () use ($organization, &$expiredInvitations): void {
                            $locked = Organization::query()
                                ->whereKey($organization->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();

                            $expiredInvitations += OrganizationInvitation::query()
                                ->where('organization_id', $locked->getKey())
                                ->where('status', OrganizationInvitation::STATUS_PENDING)
                                ->where('expires_at', '<=', now())
                                ->update([
                                    'status' => OrganizationInvitation::STATUS_EXPIRED,
                                    'updated_at' => now(),
                                ]);

                            $this->quotas->reconcile($locked);
                        }, 3);
                        $processed++;
                    } catch (\Throwable $exception) {
                        $errors++;
                        Log::error('[organizations] quota reconciliation failed', [
                            'organization_id' => $organization->getKey(),
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Reconciled {$processed} organizations and expired {$expiredInvitations} invitations.");

        if ($errors > 0) {
            $this->error("Quota reconciliation failed for {$errors} organizations.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
