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
        // 1. Protect the route: Verify JWT and CSRF
        // This ensures only logged-in users can add tenants
        AuthMiddleware::handle();

        // 2. Role Check: Only SuperAdmin should be allowed to create tenants
        $currentUser = $_REQUEST['user'];
        if ($currentUser['role'] !== 'SuperAdmin') {
            ResponseHelper::send(false, "Forbidden: Only SuperAdmin can create tenants.", [], 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // 3. Validation
        if (!isset($data['name']) || empty($data['name'])) {
            ResponseHelper::send(false, "Hospital name is required.", [], 400);
            return;
        }

        // 4. Create Tenant
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
        
        $tenants = $this->tenantModel->getAll();
        ResponseHelper::send(true, "Tenants retrieved.", $tenants);
    }
}

?>