<?php

namespace App\Modules\TikTokIntegration\Console;

use App\Modules\TikTokIntegration\Models\TikTokLeadFormMapping;
use App\Modules\TikTokIntegration\Services\TikTokLeadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Drives TikTok's two-phase lead export: request on one pass, collect on the
 * next. Running every few minutes keeps leads arriving without hammering the
 * API, since an export takes time to build.
 */
class SyncTikTokLeads extends Command
{
    protected $signature = 'tiktok:sync-leads {--company= : Limit the run to one company}';

    protected $description = 'Request and collect TikTok Lead Ads exports, importing new leads into the CRM';

    public function handle(TikTokLeadService $leads): int
    {
        $stats = ['requested' => 0, 'pending' => 0, 'imported' => 0, 'empty' => 0, 'failed' => 0, 'errors' => 0];

        TikTokLeadFormMapping::query()
            ->active()
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', $company))
            ->whereHas('connection', fn ($query) => $query->where('status', 'connected'))
            ->with('connection')
            ->orderBy('id')
            ->chunkById(50, function ($mappings) use ($leads, &$stats) {
                foreach ($mappings as $mapping) {
                    try {
                        $outcome = $leads->sync($mapping);
                        $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        Log::error('[tiktok] lead sync failed', [
                            'mapping_id' => $mapping->id,
                            'company_id' => $mapping->company_id,
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

        $this->info('TikTok lead sync complete.');

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
