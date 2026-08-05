<?php

namespace App\Modules\Subscriptions\Support;

/**
 * Paymob signs callbacks by concatenating a fixed, lexically ordered subset of
 * the payload fields and hashing them with HMAC-SHA512 using the merchant HMAC
 * secret. The order below is defined by Paymob and must not be re-sorted.
 *
 * @see https://developers.paymob.com/egypt/manage-callback/hmac-calculation
 */
class PaymobSignature
{
    public const TRANSACTION_FIELDS = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order.id',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    public const TOKEN_FIELDS = [
        'card_subtype',
        'created_at',
        'email',
        'id',
        'masked_pan',
        'merchant_id',
        'order_id',
        'token',
    ];

    /**
     * @param  array  $object  the callback `obj` payload (or the flattened query string of a redirect callback)
     */
    public static function calculate(array $object, array $fields, string $secret): string
    {
        $concatenated = '';

        foreach ($fields as $field) {
            $concatenated .= self::stringify(self::value($object, $field));
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    public static function matches(array $object, array $fields, string $secret, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        return hash_equals(self::calculate($object, $fields, $secret), strtolower($signature));
    }

    /**
     * Redirect callbacks arrive as flat query parameters where nested fields
     * keep their dotted names (`source_data.pan`) and `order.id` collapses to
     * `order`, so both shapes are resolved here.
     */
    private static function value(array $object, string $field): mixed
    {
        if (array_key_exists($field, $object)) {
            return $object[$field];
        }

        // PHP's query parser rewrites dots in parameter names to underscores,
        // so `source_data.pan` reaches us as `source_data_pan` on redirects.
        $underscored = str_replace('.', '_', $field);

        if (array_key_exists($underscored, $object)) {
            return $object[$underscored];
        }

        $value = data_get($object, $field);

        if ($value === null && $field === 'order.id') {
            $order = $object['order'] ?? null;

            return is_array($order) ? ($order['id'] ?? null) : $order;
        }

        return $value;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}
