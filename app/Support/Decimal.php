<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Immutable fixed-point decimal, backed by bcmath strings.
 *
 * Money and quantities must never be computed in PHP floats. `0.1 + 0.2` is
 * not `0.3` in binary floating point, and a POS that multiplies a price by a
 * quantity, applies a percentage discount and then a tax rate accumulates that
 * error until a receipt disagrees with its own invoice. Every operational
 * column in this schema is `decimal(18,4)`, so that is the working scale here:
 * arithmetic stays exact and the value round-trips to the database unchanged.
 *
 * Instances are immutable — every operation returns a new Decimal.
 */
final class Decimal
{
    /** Matches decimal(18,4) across the operational schema. */
    public const SCALE = 4;

    /** Currency amounts presented to a customer. */
    public const MONEY_SCALE = 2;

    private function __construct(private readonly string $value) {}

    public static function of(string|int|float|self|null $value, int $scale = self::SCALE): self
    {
        if ($value instanceof self) {
            return new self(bcadd($value->value, '0', $scale));
        }

        if ($value === null) {
            return new self(bcadd('0', '0', $scale));
        }

        // Floats are accepted at the boundary — request payloads arrive as
        // JSON numbers — but are normalised through a decimal string
        // immediately so no float arithmetic ever happens.
        $normalized = is_float($value)
            ? number_format($value, $scale + 2, '.', '')
            : trim((string) $value);

        if ($normalized === '') {
            $normalized = '0';
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("Value [{$normalized}] is not a decimal number.");
        }

        return new self(bcadd($normalized, '0', $scale));
    }

    public static function zero(int $scale = self::SCALE): self
    {
        return new self(bcadd('0', '0', $scale));
    }

    public function plus(string|int|float|self $addend): self
    {
        return new self(bcadd($this->value, self::of($addend)->value, self::SCALE));
    }

    public function minus(string|int|float|self $subtrahend): self
    {
        return new self(bcsub($this->value, self::of($subtrahend)->value, self::SCALE));
    }

    public function times(string|int|float|self $multiplier): self
    {
        // Multiply at double scale, then round once, so a chain of
        // multiplications does not truncate at every step.
        $raw = bcmul($this->value, self::of($multiplier, self::SCALE * 2)->value, self::SCALE * 2);

        return new self(self::roundHalfUp($raw, self::SCALE));
    }

    public function dividedBy(string|int|float|self $divisor): self
    {
        $divisorValue = self::of($divisor);

        if ($divisorValue->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $raw = bcdiv($this->value, $divisorValue->value, self::SCALE * 2);

        return new self(self::roundHalfUp($raw, self::SCALE));
    }

    /** A percentage of this value: `of(200)->percentage(15)` is 30. */
    public function percentage(string|int|float|self $percent): self
    {
        return $this->times(self::of($percent))->dividedBy('100');
    }

    public function negated(): self
    {
        return self::zero()->minus($this);
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    /** Round to a presentation scale, half away from zero. */
    public function round(int $scale = self::MONEY_SCALE): self
    {
        return new self(bcadd(self::roundHalfUp($this->value, $scale), '0', self::SCALE));
    }

    public function compareTo(string|int|float|self $other): int
    {
        return bccomp($this->value, self::of($other)->value, self::SCALE);
    }

    public function equals(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    /** @param  iterable<string|int|float|self>  $values */
    public static function sum(iterable $values): self
    {
        $total = self::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    /** The database representation: a decimal(18,4) string. */
    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Only for presentation and comparison against float-typed legacy data.
     * Never feed the result back into arithmetic.
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /**
     * bcmath truncates; accounting rounds. This rounds half away from zero,
     * which is what invoices and receipts are expected to do.
     */
    private static function roundHalfUp(string $value, int $scale): string
    {
        if (! str_contains($value, '.')) {
            return bcadd($value, '0', $scale);
        }

        $negative = str_starts_with($value, '-');
        $magnitude = $negative ? substr($value, 1) : $value;

        // Add half of the last retained digit, then truncate.
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($magnitude, $half, $scale);

        return $negative ? '-'.$rounded : $rounded;
    }
}
