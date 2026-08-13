<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;

/**
 * Receipt rendering payload.
 *
 * Built entirely from the sale's own snapshots, never from the live catalogue,
 * so a reprint a year later shows the prices the customer actually paid. The
 * same structure drives the browser view, the thermal print and the PDF.
 */
class PosReceiptService
{
    public function __construct(private readonly PosPolicy $policy) {}

    public function build(User $user, PosSale $sale): array
    {
        $companyId = $this->policy->companyId($user, 'pos.view');
        abort_unless((int) $sale->company_id === $companyId, 404);

        $sale->loadMissing(['lines', 'tenders', 'register', 'cashier:id,name', 'returns.lines']);
        $company = $this->policy->companyName($user);

        return [
            'receipt_number' => $sale->receipt_number,
            'uuid' => $sale->uuid,
            'status' => $sale->status,
            'issued_at' => $sale->completed_at?->toISOString(),
            'business_date' => $sale->business_date?->toDateString(),
            'currency' => $sale->currency,
            'company' => ['name' => $company],
            'register' => ['name' => $sale->register?->name],
            'cashier' => ['name' => $sale->cashier?->name],
            'lines' => $sale->lines->map(fn ($line) => [
                'name' => $line->product_name,
                'sku' => $line->sku,
                'quantity' => $this->trim($line->quantity),
                'unit_price' => $this->money($line->unit_price),
                'discount' => $this->money($line->discount_amount),
                'tax' => $this->money($line->tax_amount),
                'total' => $this->money($line->line_total),
            ])->values(),
            'totals' => [
                'subtotal' => $this->money($sale->subtotal),
                'discount' => $this->money($sale->discount_total),
                'tax' => $this->money($sale->tax_total),
                'rounding' => $this->money($sale->rounding_total),
                'total' => $this->money($sale->total),
                'paid' => $this->money($sale->paid_total),
                'change' => $this->money($sale->change_total),
                'returned' => $this->money($sale->returned_total),
            ],
            'tenders' => $sale->tenders->map(fn ($tender) => [
                'type' => $tender->tender_type,
                'amount' => $this->money($tender->amount),
                'tendered' => $this->money($tender->tendered_amount),
                'change' => $this->money($tender->change_amount),
                // A masked tail only; the PAN never existed in this system.
                'card' => $tender->card_last4 ? "•••• {$tender->card_last4}" : null,
            ])->values(),
            'returns' => $sale->returns->map(fn ($return) => [
                'receipt_number' => $return->receipt_number,
                'total' => $this->money($return->total),
                'business_date' => $return->business_date?->toDateString(),
            ])->values(),
            'note' => $sale->note,
        ];
    }

    private function money(?string $value): string
    {
        return Decimal::of($value)->round()->toString();
    }

    /** Quantities print without trailing zeros: "2" not "2.0000". */
    private function trim(?string $value): string
    {
        $decimal = Decimal::of($value);

        return rtrim(rtrim($decimal->toString(), '0'), '.') ?: '0';
    }
}
