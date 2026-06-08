<?php

namespace App\Modules\Inventory\Notifications;

use App\Modules\Inventory\Models\ReorderAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReorderPointReached extends Notification
{
    use Queueable;

    public function __construct(private readonly ReorderAlert $alert) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory.reorder',
            'reorder_alert_id' => $this->alert->id,
            'product_id' => $this->alert->balance->product_id,
            'warehouse_id' => $this->alert->balance->warehouse_id,
            'quantity' => (float) $this->alert->quantity,
            'reorder_point' => (float) $this->alert->reorder_point,
        ];
    }
}
