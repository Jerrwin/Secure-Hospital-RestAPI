<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
use App\Helpers\JWT; // Needed to manually verify the token
use Exception;

class SystemAdminController
{
    private $tenantModel;

    public function __construct()
    {
        // System Admins operate entirely within the Master Control DB
        $database = new Database();
        $db = $database->connectMaster();
        
        // Inject the DB connection into our new Model
        $this->tenantModel = new MasterTenant($db);
    }

    /**
     * Helper to verify identity and SuperAdmin permission
     * [UPDATED] Bypasses CSRF and manually validates the JWT payload
     */
    private function requireSuperAdmin()
    {
        // 1. Manually Extract the JWT from the Header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            ResponseHelper::send(false, "Unauthorized: No token provided.", [], 401);
            exit;
        }

        $token = $matches[1];
        $payload = null;

        // 2. Decode the JWT manually (Using your exact logic from the refresh method)
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            // Fix Base64Url encoding and decode the payload (the middle part of the JWT)
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        }

        if (!$payload) {
            ResponseHelper::send(false, "Unauthorized: Invalid token format.", [], 401);
            exit;
        }

        // 3. Verify SuperAdmin Role
        if (!isset($payload['user_type']) || $payload['user_type'] !== 'system_admin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmins can perform this action.", [], 403);
            exit;
        }

        // 4. Set the user globally so the rest of the app can use it if needed
        $_REQUEST['user'] = $payload;
    }

    /**
     * GET /api/admin/tenants
     */
    public function index()
    {
        $this->requireSuperAdmin();

        $status = $_GET['status'] ?? null;
        $tenants = $this->tenantModel->getAll($status);

        ResponseHelper::send(true, "Tenants retrieved successfully.", ['tenants' => $tenants]);
    }

    /**
     * GET /api/admin/tenants/{id}
     */
    public function show($id)
    {
        $this->requireSuperAdmin();

        $tenant = $this->tenantModel->getById($id);

        if (!$tenant) {
            ResponseHelper::send(false, "Tenant not found.", [], 404);
            return;
        }

        ResponseHelper::send(true, "Tenant details retrieved.", ['tenant' => $tenant]);
    }

    /**
     * DELETE /api/admin/tenants/{id}
     */
    public function delete($id)
    {
        $this->requireSuperAdmin();

        if ($this->tenantModel->delete($id)) {
            ResponseHelper::send(true, "Tenant application deleted.");
        } else {
            ResponseHelper::send(false, "Tenant not found or already deleted.", [], 404);
        }
    }

    /**
     * PATCH /api/admin/tenants/{id}/status
     */
    public function updateStatus($id)
    {
        $this->requireSuperAdmin();

        // Ensure we read the raw JSON correctly since PATCH often relies on it
        $rawInput = file_get_contents("php://input");
        $data = !empty($_POST) ? (object) $_POST : json_decode($rawInput);

        if (!isset($data->status) || !in_array($data->status, ['pending', 'active', 'suspended'])) {
            ResponseHelper::send(false, "Invalid status provided.", [], 400);
            return;
        }

        // 1. Fetch current tenant details
        $tenant = $this->tenantModel->getById($id);

        if (!$tenant) {
            ResponseHelper::send(false, "Tenant not found.", [], 404);
            return;
        }

        // 2. Provision database if changing from pending to active
        if ($tenant['status'] === 'pending' && $data->status === 'active') {
            try {
                $this->tenantModel->provisionDatabase($tenant);
            } catch (Exception $e) {
                ResponseHelper::send(false, "Database provisioning failed: " . $e->getMessage(), [], 500);
                return;
            }
        }

        // 3. Update status in Master DB
        if ($this->tenantModel->updateStatus($id, $data->status)) {
            ResponseHelper::send(true, "Tenant status updated to {$data->status} successfully.");
        } else {
            ResponseHelper::send(false, "Failed to update tenant status.", [], 500);
        }
    }
}