<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\User;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
use App\Helpers\JWT;
use App\Helpers\RefreshToken;
use App\Helpers\CSRF;
use App\Helpers\Validator;

class AuthController
{
    private $userModel;
    private $masterTenantModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        // Initialize Master models
        $this->masterTenantModel = new MasterTenant($this->db);
        $this->userModel = new User($this->db);
    }

    /**
     * Helper: Connect using ID (Used for Refresh, Logout, Change Password)
     */
    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);

        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // Re-initialize User model with Tenant DB
        $this->userModel = new User($this->db);
        return true;
    }

    /**
     * Helper: Connect using Subdomain (Used for Login)
     */
    private function switchToTenantDatabase($subdomain)
    {
        $tenant = $this->masterTenantModel->getDetailsBySubdomain($subdomain);

        if (!$tenant || $tenant['status'] !== 'active') {
            ResponseHelper::send(false, "Invalid hospital domain or account inactive.", [], 403);
            exit;
        }

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // Re-initialize User model with Tenant DB
        $this->userModel = new User($this->db);
    }

    /**
     * POST /api/auth/login
     */
    public function login()
    {
        $data = !empty($_POST) ? (object) $_POST : json_decode(file_get_contents("php://input"));

        if (!isset($data->email) || !isset($data->password)) {
            ResponseHelper::send(false, "Email and password are required.", [], 400);
            return;
        }

        // Dynamically extract subdomain from the request URL
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);

        // Ensure we actually got a subdomain (like apollo.localhost or apollo.hospitalapp.com)
        if (count($parts) < 2 || $parts[0] === 'www') {
            ResponseHelper::send(false, "Tenant domain not specified.", [], 400);
            return;
        }
        $subdomain = strtolower($parts[0]);

        // 1. Switch to the specific hospital's database
        $this->switchToTenantDatabase($subdomain);

        // 2. Fetch User from that specific DB
        $user = $this->userModel->findAnyUserByEmail($data->email);

        // 3. Security Check
        // Note: Using password_verify() against your stored hash
        if (!$user || !password_verify($data->password, $user['password'] ?? $user['PASSWORD'])) {
            ResponseHelper::send(false, "Invalid credentials", [], 401);
            return;
        }

        $userType = $user['type'] ?? 'staff';

        // 4. Tokens & CSRF (Standard logic)
        $csrfToken = CSRF::generate();
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 3600);

        $accessToken = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $userType,
            'role' => $user['role_name'],
            'role_id' => $user['role_id'],
            'tenant_id' => $user['tenant_id'], // Now matching the ID you injected (e.g., 2)
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        $refreshData = RefreshToken::generate();
        $this->userModel->deleteSessionByUserIdAndType($user['id'], $userType);
        $this->userModel->storeRefreshToken($user['id'], $userType, $refreshData['token'], $refreshData['expiry']);

        setcookie('refresh_token', $refreshData['token'], time() + 604800, '/', '', false, true);

        ResponseHelper::send(true, "Login successful", [
            'access_token' => $accessToken,
            'csrf_token' => $csrfToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['NAME'] ?? $user['name'],
                'email' => $user['email'],
                'role' => $user['role_name'],
                'tenant_id' => $user['tenant_id']
            ]
        ]);
    }

    /**
     * POST /api/auth/refresh
     * Rotates both Access Token, Refresh Token, AND CSRF Token.
     */
    public function refresh()
    {
        // 1. Get Refresh Token from Cookie
        $incomingToken = $_COOKIE['refresh_token'] ?? null;
        if (!$incomingToken) {
            ResponseHelper::send(false, "Session not found. Please login again.", [], 401);
            return;
        }

        // 2. STRENGTHENED: Resolve Tenant from Subdomain (NOT from token payload)
        // This ensures the session stays valid even if the user refreshes the page!
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            ResponseHelper::send(false, "Tenant domain not specified.", [], 400);
            return;
        }
        $subdomain = strtolower($parts[0]);

        $tenant = $this->masterTenantModel->getDetailsBySubdomain($subdomain);
        if (!$tenant) {
            ResponseHelper::send(false, "Invalid hospital context.", [], 404);
            return;
        }

        // Switch to the correct DB
        if (!$this->connectByTenantId($tenant['id'])) {
            ResponseHelper::send(false, "Database connection failed.", [], 500);
            return;
        }

        // 3. Database Validation (Generic search because we don't have user_id yet)
        $tokenRow = $this->userModel->verifyRefreshTokenGeneric($incomingToken);

        if ($tokenRow === "NO_DATA_FOUND" || $tokenRow === "EXPIRED") {
            setcookie('refresh_token', '', time() - 3600, '/');
            ResponseHelper::send(false, "Session invalid: $tokenRow", [], 401);
            return;
        }

        // 4. SECURITY: ROTATION (Delete old token, Issue new set)
        $this->userModel->deleteRefreshTokenById($tokenRow['id']);

        $user = $this->userModel->getUserByType($tokenRow['user_id'], $tokenRow['user_type']);

        if (!$user) {
            ResponseHelper::send(false, "User account no longer exists.", [], 404);
            return;
        }

        // New CSRF Token
        $newCsrf = CSRF::generate();

        // New Access Token
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 3600);
        $newAccessToken = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $tokenRow['user_type'],
            'role' => $user['role_name'],
            'role_id' => $user['role_id'],
            'tenant_id' => $user['tenant_id'],
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        // New Refresh Token
        $newRefreshData = RefreshToken::generate();
        $this->userModel->storeRefreshToken($tokenRow['user_id'], $tokenRow['user_type'], $newRefreshData['token'], $newRefreshData['expiry']);

        // Update Cookie
        setcookie('refresh_token', $newRefreshData['token'], time() + 604800, '/', '', false, true);

        // 5. Respond with Rotated Credentials
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
        $user = $_REQUEST['user'];

        if ($this->connectByTenantId($user['tenant_id'])) {
            $this->userModel->deleteSessionByUserIdAndType($user['user_id'], $user['user_type']);
        }

        setcookie('refresh_token', '', time() - 3600, '/');
        ResponseHelper::send(true, "Logged out successfully.");
    }

    /**
     * POST /api/auth/change-password
     */
    public function changePassword()
    {
        \App\Middleware\AuthMiddleware::handle();
        $userSession = $_REQUEST['user'];

        // FIX: Route to the correct DB first before fetching the user
        if (!$this->connectByTenantId($userSession['tenant_id'])) {
            ResponseHelper::send(false, "Hospital context error.", [], 404);
            return;
        }

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || !Validator::password($newPassword)) {
            ResponseHelper::send(false, "Invalid input or weak password.", [], 400);
            return;
        }

        $user = $this->userModel->getById($userSession['user_id']);
        $dbPass = $user['password'] ?? $user['PASSWORD'];

        if (!$user || !password_verify($currentPassword, $dbPass)) {
            ResponseHelper::send(false, "Incorrect current password.", [], 401);
            return;
        }

        if ($this->userModel->updatePassword($userSession['user_id'], password_hash($newPassword, PASSWORD_BCRYPT))) {
            ResponseHelper::send(true, "Password changed successfully.");
        } else {
            ResponseHelper::send(false, "Failed to update password.", [], 500);
        }
    }
}
