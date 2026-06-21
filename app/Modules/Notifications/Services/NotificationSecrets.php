<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class NotificationSecrets
{
    public static function encrypt(?string $value): ?string
    {
        return $value ? Crypt::encryptString($value) : $value;
    }

    public static function decrypt(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}
