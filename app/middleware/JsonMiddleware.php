<?php

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class JsonMiddleware
{
    public static function handle()
    {
        header('Content-Type: application/json; charset=UTF-8');

        $method = $_SERVER['REQUEST_METHOD'];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $input = file_get_contents("php://input");
            
            // 1. SILENT: If body is empty, just let it pass (for Logout/Refresh)
            if (empty($input)) {
                return; 
            }

            // 2. STRICT: If there IS a body, it MUST be valid JSON
            $data = json_decode($input, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // 🎯 Show error ONLY if the format is actually wrong
                ResponseHelper::send(false, "Invalid JSON Format: " . json_last_error_msg(), [], 400);
                exit; 
            }

            // 3. Success: Populate globals
            if (!empty($data)) {
                $_POST = $data;
                $_REQUEST = array_merge($_REQUEST, $data);
            }
        }
    }
}