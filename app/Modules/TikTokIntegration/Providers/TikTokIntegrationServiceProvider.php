<?php

namespace App\Modules\TikTokIntegration\Providers;

use App\Modules\MetaIntegration\Events\CrmContactDeleted;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\MetaIntegration\Events\CrmOpportunityWon;
use App\Modules\MetaIntegration\Events\InvoicePaid;
use App\Modules\TikTokIntegration\Console\MaintainTikTokConnections;
use App\Modules\TikTokIntegration\Console\RetryTikTokEvents;
use App\Modules\TikTokIntegration\Console\SyncTikTokAudiences;
use App\Modules\TikTokIntegration\Console\SyncTikTokLeads;
use App\Modules\TikTokIntegration\Services\TikTokAudienceService;
use App\Modules\TikTokIntegration\Services\TikTokEventService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TikTokIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([MaintainTikTokConnections::class, RetryTikTokEvents::class, SyncTikTokLeads::class, SyncTikTokAudiences::class]);
        }

        // The CRM/finance events are shared with the Meta module: one business
        // trigger can feed both ad platforms.
        Event::listen(CrmLeadCreated::class, function (CrmLeadCreated $event) {
            $this->app->make(TikTokEventService::class)
                ->recordEvent($event->contact->company_id, 'crm_lead_created', $event->contact);
        });

        Event::listen(CrmOpportunityWon::class, function (CrmOpportunityWon $event) {
            $this->app->make(TikTokEventService::class)
                ->recordEvent($event->opportunity->company_id, 'crm_opportunity_won', $event->opportunity);
        });

        // Deleting a contact locally must also remove it from TikTok audiences.
        Event::listen(CrmContactDeleted::class, function (CrmContactDeleted $event) {
            $this->app->make(TikTokAudienceService::class)
                ->removeContact($event->contact);
        });

        Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
            $this->app->make(TikTokEventService::class)
                ->recordEvent($event->invoice->company_id, 'invoice_paid', $event->invoice);
        });
    }
}
