<?php

namespace App\Helpers;

class Encryption
{
    private static $method = "AES-256-CBC";

    /**
     * Encrypts the data
     */
    public static function encrypt($data)
    {
        $key = hex2bin($_ENV['ENCRYPTION_KEY']);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$method));
        $encrypted = openssl_encrypt($data, self::$method, $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * Decrypts the data
     */
    public static function decrypt($data)
    {
        $key = hex2bin($_ENV['ENCRYPTION_KEY']);
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, self::$method, $key, 0, $iv);
    }
}
