<?php

namespace App\Modules\POS\Support;

use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;

/** A deliberately small register payload safe to send to a cashier device. */
final class PosCashierRegisterView
{
    public static function make(PosRegister $register): array
    {
        $warehouse = $register->relationLoaded('warehouse')
            ? $register->getRelation('warehouse')
            : null;
        $location = $register->relationLoaded('location')
            ? $register->getRelation('location')
            : null;
        $paymentMethods = $register->relationLoaded('paymentMethods')
            ? $register->getRelation('paymentMethods')
            : collect();

        return [
            'id' => $register->getKey(),
            'warehouse_id' => $register->warehouse_id === null ? null : (int) $register->warehouse_id,
            'location_id' => $register->location_id === null ? null : (int) $register->location_id,
            'code' => $register->code,
            'name' => $register->name,
            'currency' => $register->currency,
            'receipt_prefix' => $register->receipt_prefix,
            'is_active' => (bool) $register->is_active,
            'warehouse' => $warehouse ? ['id' => $warehouse->getKey(), 'name' => $warehouse->name] : null,
            'location' => $location ? ['id' => $location->getKey(), 'name' => $location->name] : null,
            'payment_methods' => $paymentMethods
                ->filter(fn (PosRegisterPaymentMethod $method) => $method->is_active
                    && PosRegisterPaymentMethod::isCheckoutSupported($method->tender_type))
                ->sortBy('sort_order')
                ->values()
                ->map(fn (PosRegisterPaymentMethod $method) => [
                    'id' => $method->getKey(),
                    'tender_type' => $method->tender_type,
                    'label' => $method->label,
                    'provider' => $method->provider,
                    'is_active' => (bool) $method->is_active,
                    'opens_drawer' => (bool) $method->opens_drawer,
                    'sort_order' => (int) $method->sort_order,
                ])
                ->all(),
        ];
    }
}
