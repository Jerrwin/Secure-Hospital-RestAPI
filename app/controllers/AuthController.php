<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\User;
use App\Helpers\ResponseHelper;
use App\Helpers\JWT;
use App\Helpers\RefreshToken;
use App\Helpers\CSRF;
use App\Helpers\Validator;

class AuthController
{
    private $userModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->userModel = new User($db);
    }

    /**
     * POST /api/auth/register
     * Creates an Admin/User and validates that the Tenant ID exists.
     */
    public function register()
    {
        // 1. IDENTITY CHECK
        \App\Middleware\AuthMiddleware::handle();

        $currentUser = $_REQUEST['user'];

        // 2. PERMISSION CHECK: Only SuperAdmin should create Admins/Tenants
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can register users.", [], 403);
            return;
        }

        // 3. GET DATA (Using $_POST from JsonMiddleware if available, or fallback)
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // Convert to object for easier access if it's an array
        $data = (object) $data;

        if (empty($data->tenant_id) || empty($data->name) || empty($data->email) || empty($data->password)) {
            ResponseHelper::send(false, "Required fields missing", [], 400);
            return;
        }

        // 4. INPUT VALIDATION (From Praveen's Code - Stronger Security)
        if (!Validator::email($data->email)) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        if (!Validator::password($data->password)) {
            ResponseHelper::send(false, "Password weak. (Min 8 chars, 1 Upper, 1 Special).", [], 400);
            return;
        }

        // 5. DATABASE CHECKS
        if (!$this->userModel->tenantExists($data->tenant_id)) {
            ResponseHelper::send(false, "Registration failed: No such tenant exists.", [], 404);
            return;
        }

        if ($this->userModel->findAnyUserByEmail($data->email)) {
            ResponseHelper::send(false, "Email already exists", [], 409);
            return;
        }

        // One Admin Per Tenant Rule
        if ($this->userModel->checkAdminExistsForTenant($data->tenant_id)) {
            ResponseHelper::send(false, "This tenant already has an Admin. Only one allowed.", [], 409);
            return;
        }

        // 6. CREATE USER
        // Use provided role_id or default to 1 (Admin)
        $roleId = isset($data->role_id) ? $data->role_id : 1;

        $userData = [
            'tenant_id' => $data->tenant_id,
            'name' => strip_tags($data->name),
            'email' => filter_var($data->email, FILTER_SANITIZE_EMAIL),
            'password' => password_hash($data->password, PASSWORD_BCRYPT),
            'role_id' => $roleId
        ];

        $userId = $this->userModel->create($userData);

        if ($userId) {
            ResponseHelper::send(true, "Admin registered successfully", ['user_id' => $userId], 201);
        } else {
            ResponseHelper::send(false, "Failed to create user", [], 500);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login()
    {
        // 1. GET DATA
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);
        $data = (object) $data;

        if (empty($data->email) || empty($data->password)) {
            ResponseHelper::send(false, "Please provide email and password", [], 400);
            return;
        }

        // 2. FETCH USER
        $user = $this->userModel->findAnyUserByEmail($data->email);

        if (!$user || !password_verify($data->password, $user['password'])) {
            ResponseHelper::send(false, "Invalid credentials", [], 401);
            return;
        }

        // 3. PREPARE TOKEN DATA
        $userType = $user['type'];
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 3600);

        // [MERGE] Added role_id to payload (from Praveen's code)
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $userType,
            'role' => $user['role_name'],
            'role_id' => $user['role_id'], // Crucial for RoleMiddleware
            'tenant_id' => $user['tenant_id'],
            'iat' => time(),
            'exp' => $jwtExpiry
        ];

        $accessToken = JWT::encode($payload, $_ENV['JWT_SECRET']);
        $csrfToken = CSRF::generate();
        $refreshData = RefreshToken::generate();

        // 4. HANDLE SESSION
        $this->userModel->deleteSessionByUserIdAndType($user['id'], $userType);
        $this->userModel->storeRefreshToken(
            $user['id'],
            $userType,
            $refreshData['token'],
            $refreshData['expiry']
        );

        $cookieLifetime = (int) ($_ENV['REFRESH_TOKEN_LIFETIME'] ?? 604800);
        setcookie(
            'refresh_token',
            $refreshData['token'],
            time() + $cookieLifetime,
            '/',
            '',
            false,
            true
        );

        // 5. RESPONSE
        ResponseHelper::send(true, "Login successful", [
            'access_token' => $accessToken,
            'csrf_token' => $csrfToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role_name'],
                'tenant_id' => $user['tenant_id']
            ]
        ]);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh()
    {
        $incomingToken = $_COOKIE['refresh_token'] ?? null;
        if (!$incomingToken) {
            ResponseHelper::send(false, "Session not found.", [], 401);
            return;
        }

        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        $expiredUserId = null;
        $expiredUserType = null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $parts = explode('.', $matches[1]);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                $expiredUserId = $payload['user_id'] ?? null;
                $expiredUserType = $payload['user_type'] ?? null;
            }
        }

        if (!$expiredUserId || !$expiredUserType) {
            ResponseHelper::send(false, "Identity verification failed.", [], 401);
            return;
        }

        $tokenRow = $this->userModel->verifyRefreshToken($expiredUserId, $expiredUserType, $incomingToken);

        if ($tokenRow === "NO_DATA_FOUND" || $tokenRow === "IDENTITY_MISMATCH" || $tokenRow === "EXPIRED") {
            setcookie('refresh_token', '', time() - 3600, '/');
            ResponseHelper::send(false, "Session invalid: $tokenRow", [], 401);
            return;
        }

        $this->userModel->deleteRefreshTokenById($tokenRow['id']);
        $user = $this->userModel->getUserByType($expiredUserId, $expiredUserType);

        if (!$user) {
            ResponseHelper::send(false, "User account no longer exists.", [], 404);
            return;
        }

        $newCsrf = CSRF::generate();
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 3600);

        $newAccessToken = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $expiredUserType,
            'role' => $user['role_name'],
            'role_id' => $user['role_id'], // Added here too
            'tenant_id' => $user['tenant_id'],
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        $newRefreshData = RefreshToken::generate();
        $this->userModel->storeRefreshToken($expiredUserId, $expiredUserType, $newRefreshData['token'], $newRefreshData['expiry']);

        setcookie('refresh_token', $newRefreshData['token'], time() + 604800, '/', '', false, true);

        ResponseHelper::send(true, "Tokens rotated successfully", [
            'access_token' => $newAccessToken,
            'csrf_token' => $newCsrf,
            'expires_at' => date('Y-m-d H:i:s', $jwtExpiry)
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout()
    {
        \App\Middleware\AuthMiddleware::handle();

        $userId = $_REQUEST['user']['user_id'] ?? null;
        $userType = $_REQUEST['user']['user_type'] ?? null;

        if ($userId && $userType) {
            $this->userModel->deleteSessionByUserIdAndType($userId, $userType);
        }

        setcookie('refresh_token', '', time() - 3600, '/');
        ResponseHelper::send(true, "Logged out successfully.");
    }

    /**
     * POST /api/auth/change-password (Merged from Praveen's Code)
     */
    public function changePassword()
    {
        \App\Middleware\AuthMiddleware::handle();

        // Use array syntax as handle() populates $_REQUEST['user']
        $userId = $_REQUEST['user']['user_id'];

        // Get data safely
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            ResponseHelper::send(false, "Current and new password are required.", [], 400);
            return;
        }

        if (!Validator::password($newPassword)) {
            ResponseHelper::send(false, "New password weak.", [], 400);
            return;
        }

        // We need a specific getById method in UserModel
        $user = $this->userModel->getById($userId);
        if (!$user) {
            ResponseHelper::send(false, "User not found.", [], 404);
            return;
        }

        // Verify current password 
        // Note: Check if your DB column is 'password' or 'PASSWORD' (case sensitive on Linux)
        $dbPass = $user['password'] ?? $user['PASSWORD'];

        if (!password_verify($currentPassword, $dbPass)) {
            ResponseHelper::send(false, "Incorrect current password.", [], 401);
            return;
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        if ($this->userModel->updatePassword($userId, $newPasswordHash)) {
            ResponseHelper::send(true, "Password changed successfully.");
        } else {
            ResponseHelper::send(false, "Failed to update password.", [], 500);
        }
    }
}