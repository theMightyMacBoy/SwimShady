<?php

namespace App\Services;

class DeviceTokens
{
    /** 48-char hex device token (24 random bytes), same scheme as the previous API. */
    public static function new(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
