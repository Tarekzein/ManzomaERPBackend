<?php

namespace Tests\Unit;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Support\PosCashierRegisterView;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class PosSecurityContractTest extends TestCase
{
    public function test_only_cash_and_card_have_verified_checkout_paths(): void
    {
        $this->assertSame(
            [PosRegisterPaymentMethod::TYPE_CASH, PosRegisterPaymentMethod::TYPE_CARD],
            PosRegisterPaymentMethod::CHECKOUT_TYPES,
        );
        $this->assertTrue(PosRegisterPaymentMethod::isCheckoutSupported('cash'));
        $this->assertTrue(PosRegisterPaymentMethod::isCheckoutSupported('card'));
        $this->assertFalse(PosRegisterPaymentMethod::isCheckoutSupported('wallet'));
        $this->assertFalse(PosRegisterPaymentMethod::isCheckoutSupported('transfer'));
    }

    public function test_cashier_register_payload_excludes_configuration_and_accounting_fields(): void
    {
        $register = new PosRegister;
        $register->forceFill([
            'id' => 10,
            'company_id' => 20,
            'code' => 'T1',
            'name' => 'Front Till',
            'currency' => 'EGP',
            'receipt_prefix' => 'RCP',
            'settings' => ['provider' => ['secret' => 'must-not-leak']],
        ]);

        $warehouse = new Warehouse;
        $warehouse->forceFill(['id' => 30, 'name' => 'Main Store']);
        $location = new WarehouseLocation;
        $location->forceFill(['id' => 40, 'name' => 'Counter']);

        $cash = new PosRegisterPaymentMethod;
        $cash->forceFill([
            'id' => 50,
            'tender_type' => 'cash',
            'label' => 'Cash',
            'provider' => null,
            'account_id' => 60,
            'clearing_account_id' => 61,
            'is_active' => true,
            'opens_drawer' => true,
            'sort_order' => 1,
            'settings' => ['api_key' => 'must-not-leak'],
        ]);
        $wallet = new PosRegisterPaymentMethod;
        $wallet->forceFill([
            'id' => 51,
            'tender_type' => 'wallet',
            'label' => 'Wallet',
            'is_active' => true,
            'opens_drawer' => false,
            'sort_order' => 2,
        ]);

        $register->setRelation('warehouse', $warehouse);
        $register->setRelation('location', $location);
        $register->setRelation('paymentMethods', new Collection([$cash, $wallet]));

        $payload = PosCashierRegisterView::make($register);

        $this->assertArrayNotHasKey('company_id', $payload);
        $this->assertArrayNotHasKey('settings', $payload);
        $this->assertCount(1, $payload['payment_methods']);
        $this->assertSame('cash', $payload['payment_methods'][0]['tender_type']);
        $this->assertArrayNotHasKey('account_id', $payload['payment_methods'][0]);
        $this->assertArrayNotHasKey('clearing_account_id', $payload['payment_methods'][0]);
        $this->assertArrayNotHasKey('settings', $payload['payment_methods'][0]);
    }

    public function test_legacy_negative_stock_flag_is_inert(): void
    {
        $register = new PosRegister;
        $register->forceFill(['settings' => ['stock' => ['allow_negative' => true]]]);

        $this->assertFalse($register->allowsNegativeStock());
    }
}
