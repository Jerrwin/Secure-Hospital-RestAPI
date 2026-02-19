<?php

namespace App\Middleware;

use App\Core\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\JWT;
use App\Helpers\CSRF;
use App\Models\User;

class AuthMiddleware
{
    public static function handle()
    {

        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        $providedCsrf = $headers['X-CSRF-TOKEN'] ?? $headers['x-csrf-token'] ?? null;

        $token = null;

        // Extract Bearer Token
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // --- 1. CSRF Security Check ---
        // Skip for GET, HEAD, OPTIONS
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            if (!CSRF::verify($providedCsrf)) {
                ResponseHelper::send(false, "Security Alert: Invalid or missing CSRF token.", [], 403);
            }
        }

        // --- 2. Try Validating Access Token (Success path) ---
        if ($token) {
            // Validate using your JWT Helper
            $userData = JWT::validate($token, $_ENV['JWT_SECRET']);
            if ($userData) {
                $_REQUEST['user'] = $userData;
                return; // User is authenticated, let them pass
            }
        }


        if (!isset($_COOKIE['refresh_token'])) {
            ResponseHelper::send(false, "Unauthorized: No session found. Please login again.", [], 401);
        }

        // Decode User ID from the expired token (without validating signature again)
        $expiredUserId = null;
        if ($token) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
                $expiredPayload = json_decode($payloadJson, true);
                $expiredUserId = $expiredPayload['user_id'] ?? null;
                $expiredUserType = $expiredPayload['user_type'] ?? null;
            }
        }

        // Must have an ID to verify against the cookie
        if (!$expiredUserId) {
            ResponseHelper::send(false, "Unauthorized: Please provide your access token.", [], 401);
        }

        // --- 4. Identity & Session Check (The "Risk Score" Logic) ---
        $database = new Database();
        $db = $database->connect();
        $userModel = new User($db);

        // This function must exist in your User Model
        $tokenRow = $userModel->verifyRefreshToken($expiredUserId, $expiredUserType, $_COOKIE['refresh_token']);

        switch ($tokenRow) {
            case "NO_DATA_FOUND":
                setcookie('refresh_token', '', time() - 3600, '/'); // Clean up
                ResponseHelper::send(false, "Unauthorized: No session found. Please login again.", [], 401);

            case "IDENTITY_MISMATCH":
                // Security Critical: Someone tried to use a stolen cookie with a different user's token
                ResponseHelper::send(false, "Unauthorized: Identity Mismatch detected.", [], 403);

            case "EXPIRED":
                setcookie('refresh_token', '', time() - 3600, '/');
                ResponseHelper::send(false, "Unauthorized: Session Expired. Please login again.", [], 401);

            default:
                // If code reaches here, the refresh token is valid, but the Access Token is expired.
                // The client should call the /refresh endpoint.
                break;
        }

        // 5. Final Response
        ResponseHelper::send(false, "Unauthorized: Access Token Expired", [], 401);
    }
}

?>