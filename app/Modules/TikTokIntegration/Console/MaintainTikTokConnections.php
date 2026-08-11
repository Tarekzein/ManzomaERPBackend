<?php

namespace App\Modules\TikTokIntegration\Console;

use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Services\TikTokTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes TikTok tokens before they lapse. TikTok does issue refresh tokens,
 * so most connections renew unattended; the ones that cannot are escalated.
 */
class MaintainTikTokConnections extends Command
{
    protected $signature = 'tiktok:maintain-connections {--company= : Limit the run to one company}';

    protected $description = 'Refresh TikTok access tokens and flag connections that need reconnecting';

    public function handle(TikTokTokenService $tokens): int
    {
        $stats = ['healthy' => 0, 'refreshed' => 0, 'expiring' => 0, 'expired' => 0, 'skipped' => 0, 'errors' => 0];

        TikTokConnection::query()
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->where('status', '!=', 'disconnected')
            ->orderBy('id')
            ->chunkById(50, function ($connections) use ($tokens, &$stats) {
                foreach ($connections as $connection) {
                    try {
                        $outcome = $tokens->maintain($connection);
                        $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        Log::error('[tiktok] connection maintenance failed', [
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

        $this->info('TikTok connections checked.');

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
