<?php

namespace App\Modules\Sales\Models;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_receipt_date' => 'date', 'confirmed_at' => 'datetime', 'received_at' => 'datetime', 'matched_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function vendor()
    {
        return $this->belongsTo(SalesContact::class, 'vendor_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function financeInvoice()
    {
        return $this->belongsTo(Invoice::class, 'finance_invoice_id');
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function lines()
    {
        return $this->morphMany(SalesOrderLine::class, 'document');
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
