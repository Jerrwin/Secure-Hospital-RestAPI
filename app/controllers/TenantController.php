<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Tenant;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;

class TenantController
{
    private $tenantModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->tenantModel = new Tenant($db);
    }

    /**
     * POST /api/tenants
     * Create a new hospital/branch.
     */
    public function create()
    {
        AuthMiddleware::handle();

        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can create tenants.", [], 403);
            return;
        }

        // [MERGED] Praveen's data fetcher to handle both JSON and Form Data
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        if (isset($data['NAME']))
            $data['name'] = $data['NAME'];

        if (empty($data['name']) || empty($data['email']) || empty($data['phone']) || empty($data['address'])) {
            ResponseHelper::send(false, "Name, Email, Phone, and Address are required and cannot be empty.", [], 400);
            return;
        }

        // Check for duplicates
        $existing = $this->tenantModel->checkDuplicates($data['email'], $data['phone']);
        if ($existing) {
            $conflictField = "";
            if ($existing['email'] == $data['email'])
                $conflictField = "Email";
            elseif ($existing['phone'] == $data['phone'])
                $conflictField = "Phone";

            ResponseHelper::send(false, "Tenant already exists with this $conflictField.", [], 409);
            return;
        }

        $tenantId = $this->tenantModel->create($data);

        if ($tenantId) {
            ResponseHelper::send(true, "Tenant created successfully.", ['tenant_id' => $tenantId], 201);
        } else {
            ResponseHelper::send(false, "Failed to create tenant.", [], 500);
        }
    }

    /**
     * GET /api/tenants
     * List all hospitals.
     */
    public function index()
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can view all tenants.", [], 403);
            return;
        }
        $tenants = $this->tenantModel->getAll();
        ResponseHelper::send(true, "Tenants retrieved.", $tenants);
    }

    /**
     * GET /api/tenants/{id}
     * Get single hospital details.
     */
    public function show($id)
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden.", [], 403);
            return;
        }

        $tenant = $this->tenantModel->find($id);
        if (!$tenant) {
            ResponseHelper::send(false, "Tenant not found.", [], 404);
            return;
        }
        ResponseHelper::send(true, "Details retrieved.", $tenant);
    }

    /**
     * PUT /api/tenants/{id}
     * Update hospital info.
     */
    public function update($id)
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden.", [], 403);
            return;
        }

        // [MERGED] Praveen's data fetcher
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        if (empty($data)) {
            ResponseHelper::send(false, "No data provided.", [], 400);
            return;
        }

        if (isset($data['name']))
            $data['NAME'] = $data['name'];
        if (isset($data['NAME']))
            $data['name'] = $data['NAME'];

        foreach (['name', 'email', 'phone', 'address'] as $field) {
            if (isset($data[$field]) && trim((string) $data[$field]) === '') {
                ResponseHelper::send(false, "Field '$field' cannot be empty.", [], 400);
                return;
            }
        }

        $existing = $this->tenantModel->find($id);
        if (!$existing) {
            ResponseHelper::send(false, "Tenant not found.", [], 404);
            return;
        }

        if ($this->tenantModel->update($id, $data)) {
            ResponseHelper::send(true, "Tenant updated successfully.");
        } else {
            ResponseHelper::send(false, "Update failed.", [], 500);
        }
    }

    /**
     * DELETE /api/tenants/{id}
     * Remove hospital (soft delete).
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden.", [], 403);
            return;
        }

        if ($this->tenantModel->softDelete($id)) {
            ResponseHelper::send(true, "Tenant deleted successfully.");
        } else {
            ResponseHelper::send(false, "Delete failed.", [], 500);
        }
    }
}