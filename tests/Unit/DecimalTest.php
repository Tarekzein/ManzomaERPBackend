<?php

namespace Tests\Unit;

use App\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_it_adds_without_binary_floating_point_error(): void
    {
        $this->assertSame('0.3000', Decimal::of('0.1')->plus('0.2')->toString());
        // The float answer this replaces is 0.30000000000000004.
        $this->assertNotSame(0.1 + 0.2, 0.3);
    }

    public function test_a_line_total_with_discount_and_tax_stays_exact(): void
    {
        // 3 × 19.99 = 59.97, less 10% = 53.973, plus 14% tax.
        $lineTotal = Decimal::of('19.99')->times(3);
        $this->assertSame('59.9700', $lineTotal->toString());

        $afterDiscount = $lineTotal->minus($lineTotal->percentage(10));
        $this->assertSame('53.9730', $afterDiscount->toString());

        $tax = $afterDiscount->percentage('14')->round();
        $this->assertSame('7.5600', $tax->toString());
        $this->assertSame('61.5300', $afterDiscount->round()->plus($tax)->toString());
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame('2.35', substr(Decimal::of('2.345')->round()->toString(), 0, 4));
        $this->assertSame('2.35', substr(Decimal::of('2.3450')->round()->toString(), 0, 4));
        $this->assertSame('-2.35', substr(Decimal::of('-2.345')->round()->toString(), 0, 5));
        $this->assertSame('2.34', substr(Decimal::of('2.3449')->round()->toString(), 0, 4));
    }

    public function test_sum_and_comparison(): void
    {
        $total = Decimal::sum(['10.005', '0.005', '1']);
        $this->assertSame('11.0100', $total->toString());
        $this->assertTrue($total->greaterThan('11'));
        $this->assertTrue($total->lessThan('11.02'));
        $this->assertTrue(Decimal::of('11.01')->equals($total));
        $this->assertTrue(Decimal::zero()->isZero());
        $this->assertTrue(Decimal::of('-1')->isNegative());
    }

    public function test_floats_are_normalised_at_the_boundary(): void
    {
        // A JSON request body arrives as a float; it must land on the exact
        // decimal the cashier saw, not the binary approximation.
        $this->assertSame('19.9900', Decimal::of(19.99)->toString());
        $this->assertSame('0.1000', Decimal::of(0.1)->toString());
    }

    public function test_division_rounds_rather_than_truncating(): void
    {
        $this->assertSame('3.3333', Decimal::of('10')->dividedBy('3')->toString());
        $this->assertSame('0.6667', Decimal::of('2')->dividedBy('3')->toString());
    }

    public function test_it_rejects_values_that_are_not_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('12,50');
    }

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('1')->dividedBy('0');
    }
}
