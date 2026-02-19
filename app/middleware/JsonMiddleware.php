<?php

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class JsonMiddleware
{
    public static function handle()
    {
        header('Content-Type: application/json; charset=UTF-8');

        $method = $_SERVER['REQUEST_METHOD'];

        // Included 'DELETE' from your version (safer to allow it)
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {

            $input = file_get_contents("php://input");

            // 1. SILENT CHECK: If body is empty, just let it pass.
            // This replaces the need for checking specific routes like 'logout' or 'refresh'.
            if (empty($input)) {
                return;
            }

            // ---------------------------------------------------------
            // 2. [MERGE] Decryption Logic (From Praveen's Code)
            // ---------------------------------------------------------
            // Check headers safely
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $isEncrypted = false;

            // Handle case-insensitivity for headers
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-request-encrypted' && $value === 'true') {
                    $isEncrypted = true;
                    break;
                }
            }

            // If encrypted header is found, decrypt before decoding
            if ($isEncrypted) {
                // Ensure 'Encryption.php' is in your App/Helpers folder!
                $decryptedInput = \App\Helpers\Encryption::decrypt($input);

                if ($decryptedInput === false) {
                    ResponseHelper::send(false, "Error: AES Decryption failed", [], 400);
                    exit;
                }

                // Replace the encrypted garbage with the real JSON string
                $input = $decryptedInput;
            }
            // ---------------------------------------------------------

            // 3. STRICT VALIDATION: Now we decode and check format
            $data = json_decode($input, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Show error ONLY if the format is actually broken
                ResponseHelper::send(false, "Invalid JSON Format: " . json_last_error_msg(), [], 400);
                exit;
            }

            // 4. Success: Populate globals
            if (!empty($data)) {
                $_POST = $data;
                $_REQUEST = array_merge($_REQUEST, $data);
            }
        }
    }
}