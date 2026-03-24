<?php

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\ResponseHelper;
use PDO;

class TenantRegistrationController
{
    private $db;

    public function __construct()
    {
        // We strictly use connectMaster() because these actions only happen in the Master DB
        $database = new Database();
        $this->db = $database->connectMaster();
    }

    /**
     * POST /api/tenant/register
     * Handles new hospital registrations
     */
    public function register()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        // 1. Validate Input (Added 'theme' here)
        $requiredFields = ['hospital_name', 'subdomain', 'admin_name', 'admin_email', 'password', 'theme'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                ResponseHelper::send(false, "Missing required field: {$field}", [], 400);
                return;
            }
        }

        // 2. Validate Theme strictly
        $allowedThemes = ['blue', 'dark', 'warm'];
        if (!in_array($data['theme'], $allowedThemes)) {
            ResponseHelper::send(false, "Invalid theme selected. Allowed themes: blue, dark, warm.", [], 400);
            return;
        }

        // Format the subdomain (lowercase, remove spaces and special chars) to be URL-safe
        $subdomain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['subdomain']));
        $adminEmail = strtolower(trim($data['admin_email']));
        $futureDbName = 'tenant_' . $subdomain . '_db';

        // 3. Check for duplicates (Email, Subdomain, or DB Name)
        $stmt = $this->db->prepare("SELECT id FROM tenant_details WHERE admin_email = ? OR tenant_code = ? OR db_name = ?");
        $stmt->execute([$adminEmail, $subdomain, $futureDbName]);

        if ($stmt->rowCount() > 0) {
            ResponseHelper::send(false, "A registration with this email or subdomain already exists.", [], 409);
            return;
        }

        // 4. Hash the password
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        // 5. Insert into Master DB as 'pending' (Added 'theme' here)
        $query = "INSERT INTO tenant_details 
                  (hospital_name, tenant_code, admin_email, admin_password, admin_name, theme, db_name, db_user, db_pass, status) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'root', '', 'pending')";

        $stmt = $this->db->prepare($query);
        $success = $stmt->execute([
            $data['hospital_name'],
            $subdomain,
            $adminEmail,
            $hashedPassword,
            $data['admin_name'],
            $data['theme'], 
            $futureDbName
        ]);

        if ($success) {
            ResponseHelper::send(true, "Registration successful. Your application is now pending review.", [], 201);
        } else {
            ResponseHelper::send(false, "Failed to register hospital.", [], 500);
        }
    }

    /**
     * POST /api/tenant/status
     * Checks the approval status using email and password
     */
    public function checkStatus()
    {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['email']) || empty($data['password'])) {
            ResponseHelper::send(false, "Email and password are required.", [], 400);
            return;
        }

        $email = strtolower(trim($data['email']));
        $password = $data['password'];

        // 1. Find the tenant application
        $stmt = $this->db->prepare("SELECT hospital_name, tenant_code, admin_password, status FROM tenant_details WHERE admin_email = ?");
        $stmt->execute([$email]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            ResponseHelper::send(false, "No registration found for this email.", [], 404);
            return;
        }

        // 2. Verify the password
        if (!password_verify($password, $tenant['admin_password'])) {
            ResponseHelper::send(false, "Invalid credentials.", [], 401);
            return;
        }

        // 3. Return the status safely
        $responseData = [
            'hospital_name' => $tenant['hospital_name'],
            'subdomain' => $tenant['tenant_code'], // Map DB column back to meaning
            'status' => $tenant['status']
        ];

        // Add a helpful message based on the status
        $message = "";
        if ($tenant['status'] === 'pending') {
            $message = "Your application is still under review.";
        } elseif ($tenant['status'] === 'active') {
            $message = "Your application is approved! You can now log in at the main portal.";
        } else {
            $message = "Your account is currently suspended. Please contact support.";
        }

        ResponseHelper::send(true, $message, $responseData, 200);
    }

    /**
     * GET /api/tenant/config
     * Public metadata endpoint to identify the hospital by subdomain
     */
    public function getConfig()
    {
        // 1. Identify Tenant from Host Header
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);
        
        // If no dots, we assume it's just 'localhost' or a non-subdomain host
        if (count($parts) < 2) {
            ResponseHelper::send(false, "Hospital profile not found", null, 404);
            return;
        }

        $subdomain = strtolower($parts[0]);

        // 2. Query Master DB
        // The spec identifies 'id', 'name' (hospital_name), and 'status'
        $stmt = $this->db->prepare("SELECT id, hospital_name as name, theme, status FROM tenant_details WHERE tenant_code = ? LIMIT 1");
        $stmt->execute([$subdomain]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tenant) {
            ResponseHelper::send(false, "Hospital profile not found", null, 404);
            return;
        }

        // 3. Return precisely formatted response per specification
        ResponseHelper::send(true, null, [
            "id" => (int) $tenant['id'],
            "name" => $tenant['name'],
            "theme" => $tenant['theme'],
            "status" => $tenant['status']
        ]);
    }
}
