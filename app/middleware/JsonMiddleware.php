<?php

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class JsonMiddleware
{
    public static function handle()
    {
        // 1. Force all responses to be JSON
        header('Content-Type: application/json; charset=UTF-8');

        $method = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];

        // Loose check for specific routes that might not have a body
        $isRefreshRoute = (stripos($requestUri, 'refresh') !== false);
        $isLogoutRoute = (stripos($requestUri, 'logout') !== false);

        // 2. Only validate input for methods that send data (POST, PUT, PATCH)
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {

            $input = file_get_contents("php://input");

            // 3. If it's a special route and body is empty, skip validation
            if (($isRefreshRoute || $isLogoutRoute) && empty($input)) {
                return;
            }

            // Get Headers safely
            $headers = getallheaders();
            $contentType = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';

            // 4. VALIDATION: Check if Content-Type is 'application/json'
            if (stripos($contentType, 'application/json') === false) {
                ResponseHelper::send(false, "Error: Content-Type must be application/json", [], 415);
            }

            // 5. VALIDATION: Check if body is empty
            if (empty($input)) {
                ResponseHelper::send(false, "Error: Request body is empty", [], 400);
            }

            // 6. Decode JSON into an associative array
            $data = json_decode($input, true);

            // 7. VALIDATION: Check for JSON syntax errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                ResponseHelper::send(false, "Error: Invalid JSON Format - " . json_last_error_msg(), [], 400);
            }

            // 8. Success: Attach data to global $_POST for Controllers to use
            $_POST = $data;
            $_REQUEST = array_merge($_REQUEST, $data);
        }
    }
}

?>