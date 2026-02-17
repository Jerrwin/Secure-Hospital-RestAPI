<?php

namespace App\Helpers;

class CSRF
{
    /**
     * Generate a new token and store it in the session
     */
    public static function generate()
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Verify the incoming token against the session
     */
    public static function verify($incomingToken)
    {

        if (!isset($_SESSION['csrf_token']) || empty($incomingToken)) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($_SESSION['csrf_token'], $incomingToken);
    }
}