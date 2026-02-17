<?php

namespace App\Helpers;

class JWT
{
    // 1. Generate Token (Sign In)
    public static function encode($payload, $secret)
    {
        // Header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = self::base64UrlEncode($header);
        
        // Payload
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        // Signature
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    // 2. Validate Token (Middleware)
    public static function validate($token, $secret)
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        list($header, $payload, $signature) = $parts;

        // Verify Signature
        $validSignature = hash_hmac('sha256', $header . "." . $payload, $secret, true);
        $base64UrlValidSignature = self::base64UrlEncode($validSignature);

        if (!hash_equals($base64UrlValidSignature, $signature)) {
            return false;
        }

        // Decode Payload
        $jsonPayload = self::base64UrlDecode($payload);
        $decodedPayload = json_decode($jsonPayload, true);

        // Check Expiry
        if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
            return false; // Token Expired
        }

        return $decodedPayload;
    }

    // --- Helpers ---

    private static function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode($data)
    {
        $urlUnsafeData = str_replace(['-', '_'], ['+', '/'], $data);

        // Fix padding logic:
        $remainder = strlen($urlUnsafeData) % 4;
        if ($remainder) {
            $padLength = 4 - $remainder;
            $urlUnsafeData .= str_repeat('=', $padLength);
        }

        return base64_decode($urlUnsafeData);
    }
}

?>