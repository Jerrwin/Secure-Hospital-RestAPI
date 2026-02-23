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
    private $staffModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
        $this->userModel = new User($this->db);
        $this->staffModel = new \App\Models\Staff($this->db);
    }

    /**
     * POST /api/auth/register
     * Creates an Admin and validates that the Tenant ID exists in the database.
     */
    public function register()
    {
        // 1. IDENTITY CHECK: Verify JWT and CSRF
        \App\Middleware\AuthMiddleware::handle();

        $currentUser = $_REQUEST['user'];

        // 2. PERMISSION CHECK: Only SuperAdmin should create Admins/Tenants
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can register users. Role found: " . ($currentUser['role'] ?? 'none'), [], 403);
            return;
        }

        // [MERGED] Praveen's safe data fetching
        $data = !empty($_POST) ? (object) $_POST : json_decode(file_get_contents("php://input"));

        if (!isset($data->tenant_id) || empty($data->tenant_id) || !isset($data->name) || !isset($data->email) || !isset($data->password) || !isset($data->role_id) || !isset($data->gender) || !isset($data->phone_number)) {
            ResponseHelper::send(false, "Required fields missing (tenant_id, name, email, password, role_id, gender, phone_number)", [], 400);
            return;
        }

        // 1.5 Input Validation
        if (!Validator::email($data->email)) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        if (!Validator::password($data->password)) {
            ResponseHelper::send(false, "Password does not meet complexity requirements.", [], 400);
            return;
        }

        if (!Validator::phone($data->phone_number)) {
            ResponseHelper::send(false, "Invalid phone number format (10 digits).", [], 400);
            return;
        }

        // 2. DATABASE CHECK: Does the tenant exist?
        if (!$this->userModel->tenantExists($data->tenant_id)) {
            ResponseHelper::send(false, "Registration failed: No such tenant exists.", [], 404);
            return;
        }

        // 3. Email duplicate check
        if ($this->userModel->findAnyUserByEmail($data->email)) {
            ResponseHelper::send(false, "Email already exists", [], 409);
            return;
        }

        // 4. Phone duplicate check
        if ($this->staffModel->findByPhone($data->phone_number)) {
            ResponseHelper::send(false, "Phone number already exists", [], 409);
            return;
        }

        // 3.5 One Admin Per Tenant Rule
        if ($this->userModel->checkAdminExistsForTenant($data->tenant_id)) {
            ResponseHelper::send(false, "Registration Validation Error: This tenant already has an Admin.", [], 409);
            return;
        }

        // 4. Transactional Create (Users + Staff)
        try {
            $this->db->beginTransaction();

            // A. Create User Account
            $userData = [
                'tenant_id' => $data->tenant_id,
                'name' => strip_tags($data->name),
                'email' => filter_var($data->email, FILTER_SANITIZE_EMAIL),
                'password' => password_hash($data->password, PASSWORD_BCRYPT),
                'role_id' => $data->role_id
            ];

            $userId = $this->userModel->create($userData, false); // Pass false for manual transaction control

            // B. Create Staff Profile
            $staffData = [
                'tenant_id' => $data->tenant_id,
                'user_id' => $userId,
                'name' => strip_tags($data->name),
                'gender' => $data->gender,
                'address' => $data->address ?? null,
                'phone_number' => $data->phone_number,
                'status' => 'active'
            ];

            $staffId = $this->staffModel->create($staffData);

            if (!$userId || !$staffId) {
                throw new \Exception("Failed to create records.");
            }

            $this->db->commit();
            ResponseHelper::send(true, "Admin registered successfully", ['user_id' => $userId, 'staff_id' => $staffId], 201);

        } catch (\Exception $e) {
            $this->db->rollBack();
            ResponseHelper::send(false, "Registration Failed: " . $e->getMessage(), [], 500);
        }
    }

    /**
     * POST /api/auth/login
     * Handles initial authentication and issues tokens.
     */
    public function login()
    {
        // [MERGED] Praveen's safe data fetching
        $data = !empty($_POST) ? (object) $_POST : json_decode(file_get_contents("php://input"));

        if (!isset($data->email) || !isset($data->password)) {
            ResponseHelper::send(false, "Please provide email and password", [], 400);
            return;
        }

        // 1. Fetch User (includes Role and Tenant ID)
        $user = $this->userModel->findAnyUserByEmail($data->email);

        // 2. Security Check: Password Verify
        if (!$user || !password_verify($data->password, $user['password'])) {
            ResponseHelper::send(false, "Invalid credentials", [], 401);
            return;
        }

        // 2.5 TENANT STATUS CHECK: Block login if the hospital is suspended
        // (We check !empty because SuperAdmin has a NULL tenant_id and should never be blocked)
        if (!empty($user['tenant_id'])) {
            $stmt = $this->db->prepare("SELECT STATUS FROM tenants WHERE id = :tenant_id LIMIT 1");
            $stmt->execute([':tenant_id' => $user['tenant_id']]);
            $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($tenant && $tenant['STATUS'] === 'inactive') {
                ResponseHelper::send(false, "Your hospital's account is currently suspended. Please contact the platform administrator.", [], 403);
                return;
            }
        }

        $userType = $user['type'];

        // 3. Generate CSRF Baseline
        $csrfToken = CSRF::generate();

        // 4. Generate Access Token (JWT)
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 3600);
        $accessToken = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'user_type' => $userType,
            'role' => $user['role_name'],
            'role_id' => $user['role_id'],
            'tenant_id' => $user['tenant_id'],
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        // 5. Generate Refresh Token (Rotation Baseline)
        $refreshData = RefreshToken::generate();

        // Clear all previous sessions for this user before creating a new one
        $this->userModel->deleteSessionByUserIdAndType($user['id'], $userType);

        // 6. Secure Storage: Save Hashed Refresh Token to DB
        $this->userModel->storeRefreshToken(
            $user['id'],
            $userType,
            $refreshData['token'],
            $refreshData['expiry']
        );

        // 7. Set HttpOnly Cookie for Refresh Token
        $cookieLifetime = (int) ($_ENV['REFRESH_TOKEN_LIFETIME'] ?? 604800);
        setcookie(
            'refresh_token',
            $refreshData['token'],
            time() + $cookieLifetime,
            '/',
            '',
            false, // Set to true if using HTTPS
            true   // Prevents JavaScript access (XSS Protection)
        );

        // 8. Final Response
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

        // 2. Extract Identity from Authorization Header (Even if expired)
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

        // FIX: Ensure both ID and Type are present
        if (!$expiredUserId || !$expiredUserType) {
            ResponseHelper::send(false, "Identity verification failed.", [], 401);
            return;
        }

        // 3. Database Validation
        $tokenRow = $this->userModel->verifyRefreshToken($expiredUserId, $expiredUserType, $incomingToken);

        if ($tokenRow === "NO_DATA_FOUND" || $tokenRow === "IDENTITY_MISMATCH" || $tokenRow === "EXPIRED") {
            setcookie('refresh_token', '', time() - 3600, '/');
            ResponseHelper::send(false, "Session invalid: $tokenRow", [], 401);
            return;
        }

        // 4. SECURITY: ROTATION (Delete old token, Issue new set)
        $this->userModel->deleteRefreshTokenById($tokenRow['id']);

        $user = $this->userModel->getUserByType($expiredUserId, $expiredUserType);

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
            'user_type' => $expiredUserType,
            'role' => $user['role_name'],
            'role_id' => $user['role_id'], // [MERGED] Praveen's fix applied here!
            'tenant_id' => $user['tenant_id'],
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        // New Refresh Token
        $newRefreshData = RefreshToken::generate();
        $this->userModel->storeRefreshToken($expiredUserId, $expiredUserType, $newRefreshData['token'], $newRefreshData['expiry']);

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
        // 1. IDENTITY CHECK: Need to know WHO is logging out
        \App\Middleware\AuthMiddleware::handle();

        $userId = $_REQUEST['user']['user_id'] ?? null;
        $userType = $_REQUEST['user']['user_type'] ?? null;

        if ($userId && $userType) {
            $this->userModel->deleteSessionByUserIdAndType($userId, $userType);
        }

        setcookie('refresh_token', '', time() - 3600, '/');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        ResponseHelper::send(true, "Logged out successfully.");
    }

    // POST /api/auth/change-password
    public function changePassword()
    {
        \App\Middleware\AuthMiddleware::handle();

        // [MERGED] Praveen's safe data fetching
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);
        $userId = $_REQUEST['user']['user_id'];

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            ResponseHelper::send(false, "Current and new password are required.", [], 400);
            return;
        }

        if (!Validator::password($newPassword)) {
            ResponseHelper::send(false, "New password does not meet complexity requirements.", [], 400);
            return;
        }

        $user = $this->userModel->getById($userId);
        if (!$user) {
            ResponseHelper::send(false, "User not found.", [], 404);
            return;
        }

        // [MERGED] Praveen's safety check for DB casing differences
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