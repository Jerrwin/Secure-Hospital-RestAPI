<?php

namespace App\Helpers;

class ResponseHelper {
    public static function send($success, $message = "", $data = [], $statusCode = 200) {
        http_response_code($statusCode);
        
        // Prepare the standard JSON response
        $response = json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ]);

        // Check if the client requested an encrypted response
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        
        // Handle case-insensitivity for headers (some servers send 'x-response-encrypted')
        $isEncrypted = false;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-response-encrypted' && $value === 'true') {
                $isEncrypted = true;
                break;
            }
        }

        if ($isEncrypted) {
            // ⚠️ CRITICAL: Ensure you have copied 'app/helpers/Encryption.php' from Praveen's zip!
            echo \App\Helpers\Encryption::encrypt($response);
        } else {
            // Default behavior (Same as your original code)
            echo $response;
        }
        
        exit;
    }

    /**
     * Send a paginated JSON response.
     */
    public static function sendPaginated($success, $message, $data, $pagination, $statusCode = 200) {
        http_response_code($statusCode);

        $response = json_encode([
            'success'    => $success,
            'message'    => $message,
            'data'       => $data,
            'pagination' => $pagination
        ]);

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $isEncrypted = false;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-response-encrypted' && $value === 'true') {
                $isEncrypted = true;
                break;
            }
        }

        if ($isEncrypted) {
            echo \App\Helpers\Encryption::encrypt($response);
        } else {
            echo $response;
        }

        exit;
    }
}