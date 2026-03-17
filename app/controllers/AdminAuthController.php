<?php

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\JWT;
use App\Helpers\CSRF;
use App\Helpers\Validator;

class AdminAuthController
{
    private $db;

    public function __construct()
    {
        // Strictly connect to the Master DB for System Admin auth
        $database = new Database();
        $this->db = $database->connectMaster();
    }

    /**
     * POST /api/admin/login
     */
    public function login()
    {
        // Safe data fetching matching your style
        $data = !empty($_POST) ? (object) $_POST : json_decode(file_get_contents("php://input"));

        // 1. Validate Input
        if (!isset($data->email) || !isset($data->password)) {
            ResponseHelper::send(false, "Please provide email and password.", [], 400);
            return;
        }

        if (!Validator::email($data->email)) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        $email = strtolower(trim($data->email));

        // 2. Find the Super Admin in the Master DB
        $stmt = $this->db->prepare("SELECT id, name, email, password, status FROM system_admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

        // 3. Verify existence and password
        if (!$admin || !password_verify($data->password, $admin['password'])) {
            ResponseHelper::send(false, "Invalid credentials.", [], 401);
            return;
        }

        // 4. Check if account is active
        if ($admin['status'] !== 'active') {
            ResponseHelper::send(false, "Your admin account is inactive.", [], 403);
            return;
        }

        // 5. Generate CSRF Baseline
        $csrfToken = CSRF::generate();

        // 6. Generate Access Token (JWT)
        // Set a longer expiry for SuperAdmin since they don't use refresh tokens (e.g., 2-4 hours)
        $jwtExpiry = time() + (int) ($_ENV['JWT_ACCESS_LIFETIME'] ?? 7200); 
        
        $accessToken = JWT::encode([
            'user_id' => $admin['id'],
            'email' => $admin['email'],
            'user_type' => 'system_admin',
            'role' => 'SuperAdmin',
            'role_id' => null,     // Master Admins don't have a specific role_id in tenant tables
            'tenant_id' => null,   // NULL signifies they have global access
            'iat' => time(),
            'exp' => $jwtExpiry
        ], $_ENV['JWT_SECRET']);

        // 7. Send Response
        ResponseHelper::send(true, "Super Admin login successful.", [
            'access_token' => $accessToken,
            'csrf_token' => $csrfToken,
            'admin' => [
                'id' => $admin['id'],
                'name' => $admin['name'],
                'email' => $admin['email']
            ]
        ], 200);
    }
}