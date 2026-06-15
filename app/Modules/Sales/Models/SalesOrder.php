<?php

namespace App\Modules\Sales\Models;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $table = 'sales_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_ship_date' => 'date', 'confirmed_at' => 'datetime', 'shipped_at' => 'datetime', 'invoiced_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class);
    }

    public function customer()
    {
        return $this->belongsTo(SalesContact::class, 'customer_id');
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
}
