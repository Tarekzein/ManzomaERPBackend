<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\ReorderAlert;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Notifications\ReorderPointReached;
use Illuminate\Support\Facades\Notification;

class ReorderAlertService
{
    public function evaluate(StockBalance $balance): void
    {
        $open = ReorderAlert::where('stock_balance_id', $balance->id)->where('status', 'open')->first();
        if ((float) $balance->reorder_point > 0 && (float) $balance->quantity <= (float) $balance->reorder_point) {
            $alert = ReorderAlert::updateOrCreate(
                ['stock_balance_id' => $balance->id, 'status' => 'open'],
                ['company_id' => $balance->company_id, 'quantity' => $balance->quantity, 'reorder_point' => $balance->reorder_point]
            );
            if ($alert->wasRecentlyCreated) {
                Notification::send(User::where('company_id', $balance->company_id)->permission('inventory.edit')->get(), new ReorderPointReached($alert->load('balance')));
            }
        } elseif ($open) {
            $open->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }
}
