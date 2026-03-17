<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
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
     */
    private function requireSuperAdmin()
    {
        \App\Middleware\AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'] ?? null;

        if (!$currentUser || ($currentUser['role'] !== 'SuperAdmin' && $currentUser['user_type'] !== 'system_admin')) {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmins can perform this action.", [], 403);
            exit;
        }
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

        $data = !empty($_POST) ? (object) $_POST : json_decode(file_get_contents("php://input"));

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