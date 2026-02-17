<?php

namespace App\Helpers;

class RefreshToken
{
    //Generates a new random token and its expiry date.
    public static function generate()
    {
        // 1. Generate random string
        $token = bin2hex(random_bytes(32));

        // 2. Calculate Expiry
        // Default to 7 days (604800 seconds) if not set in .env
        $lifetime = (int)$_ENV['REFRESH_TOKEN_LIFETIME'] ?? 600;
        $expiry = date('Y-m-d H:i:s', time() + $lifetime);

        return [
            'token' => $token,
            'expiry' => $expiry
        ];
    }
}
?>