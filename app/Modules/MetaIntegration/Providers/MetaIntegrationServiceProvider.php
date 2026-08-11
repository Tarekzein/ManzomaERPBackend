<?php

namespace App\Modules\MetaIntegration\Providers;

use App\Modules\MetaIntegration\Console\MaintainMetaConnections;
use App\Modules\MetaIntegration\Console\RetryMetaEvents;
use App\Modules\MetaIntegration\Console\SyncMetaAudiences;
use App\Modules\MetaIntegration\Events\CrmContactDeleted;
use App\Modules\MetaIntegration\Events\CrmLeadCreated;
use App\Modules\MetaIntegration\Events\CrmOpportunityWon;
use App\Modules\MetaIntegration\Events\InvoicePaid;
use App\Modules\MetaIntegration\Services\MetaAudienceService;
use App\Modules\MetaIntegration\Services\MetaConversionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MetaIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->commands([MaintainMetaConnections::class, RetryMetaEvents::class, SyncMetaAudiences::class]);
        }

        Event::listen(CrmLeadCreated::class, function (CrmLeadCreated $event) {
            $this->app->make(MetaConversionService::class)
                ->recordEvent($event->contact->company_id, 'crm_lead_created', $event->contact);
        });

        Event::listen(CrmOpportunityWon::class, function (CrmOpportunityWon $event) {
            $this->app->make(MetaConversionService::class)
                ->recordEvent($event->opportunity->company_id, 'crm_opportunity_won', $event->opportunity);
        });

        Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
            $this->app->make(MetaConversionService::class)
                ->recordEvent($event->invoice->company_id, 'invoice_paid', $event->invoice);
        });

        Event::listen(CrmContactDeleted::class, function (CrmContactDeleted $event) {
            $this->app->make(MetaAudienceService::class)->removeContact($event->contact);
        });
    }
}
