<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ReorderAlert;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Notifications\Services\NotificationService;

class ReorderAlertService
{
    public function __construct(private NotificationService $notifications) {}

    public function evaluate(StockBalance $balance): void
    {
        $open = ReorderAlert::where('stock_balance_id', $balance->id)->where('status', 'open')->first();
        if ((float) $balance->reorder_point > 0 && (float) $balance->quantity <= (float) $balance->reorder_point) {
            $alert = ReorderAlert::updateOrCreate(
                ['stock_balance_id' => $balance->id, 'status' => 'open'],
                ['company_id' => $balance->company_id, 'quantity' => $balance->quantity, 'reorder_point' => $balance->reorder_point]
            );
            if ($alert->wasRecentlyCreated) {
                $recipients = $this->notifications->recipientsForCompany((int) $balance->company_id, 'inventory.edit');
                $this->notifications->send(
                    $recipients,
                    'inventory.reorder',
                    'Inventory reorder alert',
                    "Stock reached {$alert->quantity}, below reorder point {$alert->reorder_point}.",
                    ['reorder_alert_id' => $alert->id, 'product_id' => $balance->product_id, 'warehouse_id' => $balance->warehouse_id],
                    '/inventory',
                    'critical',
                    $balance->company_id,
                );
            }
        } elseif ($open) {
            $open->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }
}
