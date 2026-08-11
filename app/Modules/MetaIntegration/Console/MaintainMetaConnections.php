<?php

namespace App\Modules\MetaIntegration\Console;

use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Services\MetaTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps every company's Meta connection alive.
 *
 * Meta issues no refresh tokens: a long-lived user token must be re-exchanged
 * while it is still valid or the integration simply stops after ~60 days. This
 * runs daily, extends what it can, and escalates what it cannot.
 */
class MaintainMetaConnections extends Command
{
    protected $signature = 'meta:maintain-connections {--company= : Limit the run to one company}';

    protected $description = 'Refresh Meta access tokens, verify granted permissions, and flag connections that need reconnecting';

    public function handle(MetaTokenService $tokens): int
    {
        $stats = ['healthy' => 0, 'refreshed' => 0, 'expiring' => 0, 'expired' => 0, 'permanent' => 0, 'skipped' => 0, 'errors' => 0];

        MetaConnection::query()
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->whereNotIn('status', ['disconnected'])
            ->orderBy('id')
            ->chunkById(50, function ($connections) use ($tokens, &$stats) {
                foreach ($connections as $connection) {
                    try {
                        $outcome = $tokens->maintain($connection);
                        $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        Log::error('[meta] connection maintenance failed', [
                            'company_id' => $connection->company_id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        foreach ($stats as $outcome => $count) {
            if ($count > 0) {
                $this->line(str_pad($outcome, 12).$count);
            }
        }

        $this->info('Meta connections checked.');

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
