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
        // 1. Protect the route
        AuthMiddleware::handle();

        // 2. Role Check: Only SuperAdmin
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can create tenants.", [], 403);
            return;
        }

        // 3. Get Data (Compatible with JsonMiddleware)
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // 4. Validation
        if (empty($data['name']) || empty($data['email']) || empty($data['phone']) || empty($data['address'])) {
            ResponseHelper::send(false, "All fields (name, email, phone, address) are required.", [], 400);
            return;
        }

        // 5. Create Tenant
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

        // Role Check
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can view all tenants.", [], 403);
            return;
        }

        $tenants = $this->tenantModel->getAll();
        ResponseHelper::send(true, "Tenants retrieved.", $tenants);
    }
}