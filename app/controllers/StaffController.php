<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\User;
use App\Models\Staff;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class StaffController
{
    private $db;
    private $userModel;
    private $staffModel;
    private $masterTenantModel; // 2. Property for the MasterTenant model

    public function __construct()
    {
        $database = new Database();
        // Start with Master connection to initialize models
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        $this->masterTenantModel = new MasterTenant($masterDb); // Initialize MasterTenant
        $this->staffModel = new Staff($this->db);
        $this->userModel = new User($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        // 3. Use getDetailsById from MasterTenant instead of local SQL
        $tenant = $this->masterTenantModel->getDetailsById($tenantId);

        if (!$tenant || $tenant['status'] !== 'active') {
            return false;
        }

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // Re-initialize models with the actual Tenant Connection
        $this->staffModel = new Staff($this->db);
        $this->userModel = new User($this->db);
        return true;
    }

    public function register()
    {
        // 1. Auth & Role Check
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $currentUser = $_REQUEST['user'];

        // ALWAYS use the tenant_id from the Admin's token for security
        $tenantId = $currentUser['tenant_id'];

        $data = json_decode(file_get_contents("php://input"), true);

        // 2. Basic Validation: Ensure all fields are present
        $requiredFields = ['name', 'email', 'password', 'role_id', 'gender', 'phone_number'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                ResponseHelper::send(false, "Missing required field: $field", [], 400);
                return;
            }
        }

        // Advanced Validation (Email, Password, Phone)
        if (!\App\Helpers\Validator::email($data['email'])) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        if (!\App\Helpers\Validator::password($data['password'])) {
            ResponseHelper::send(false, "Password must be at least 8 characters and include uppercase, numbers, and symbols.", [], 400);
            return;
        }

        if (!\App\Helpers\Validator::phone($data['phone_number'])) {
            ResponseHelper::send(false, "Invalid phone number. Exactly 10 digits required.", [], 400);
            return;
        }

        if (!$tenantId) {
            ResponseHelper::send(false, "Tenant ID context missing.", [], 400);
            return;
        }

        // 3. CRITICAL: Switch the connection NOW
        // This re-initializes $this->userModel and $this->staffModel with the Tenant DB
        if (!$this->connectByTenantId($tenantId)) {
            ResponseHelper::send(false, "Hospital database not found.", [], 404);
            return;
        }

        // 4. NOW it is safe to check the email and phone because we are in the Tenant DB
        if ($this->userModel->findAnyUserByEmail($data['email'])) {
            ResponseHelper::send(false, "Email already exists in this hospital.", [], 409);
            return;
        }

        if ($this->staffModel->findByPhone($data['phone_number'])) {
            ResponseHelper::send(false, "Phone number already exists in this hospital.", [], 409);
            return;
        }

        // 5. Transactional Create
        try {
            $this->db->beginTransaction();

            // A. Create User Account
            $userData = [
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role_id' => $data['role_id']
            ];

            // Pass false to prevent internal commit/rollback
            $userId = $this->userModel->create($userData, false);

            // B. Create Staff Profile
            $staffData = [
                'tenant_id' => $tenantId,
                'user_id' => $userId, // Added user_id link
                'name' => $data['name'],
                'gender' => $data['gender'],
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'status' => $data['status'] ?? 'active'
            ];

            $staffId = $this->staffModel->create($staffData);

            if (!$userId || !$staffId) {
                throw new \Exception("Failed to create records.");
            }

            $this->db->commit();
            ResponseHelper::send(true, "Staff registered successfully", ['user_id' => $userId, 'staff_id' => $staffId], 201);
        } catch (\Exception $e) {
            $this->db->rollBack();
            ResponseHelper::send(false, "Registration Failed: " . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api/staff
     * Check: Admin Only
     */
    public function index()
    {
        AuthMiddleware::handle();
        // Removed SuperAdmin - Only Hospital Staff can see other Hospital Staff
        RoleMiddleware::handle(['Admin']); 

        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        if (!$this->connectByTenantId($tenantId)) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $staffMembers = $this->staffModel->getAllByTenant($tenantId);
        ResponseHelper::send(true, "Staff list retrieved", $staffMembers);
    }

    /**
     * PUT /api/staff/{id}
     * Check: Admin Only
     * Action: Update Staff Details
     */
    public function update($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $currentUser = $_REQUEST['user'];
        $data = json_decode(file_get_contents("php://input"), true);

        // FIX: Switch connection first!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Tenant database error.", [], 500);
            return;
        }

        // Security: Ensure staff belongs to this tenant
        $staff = $this->staffModel->getById($id);
        if (!$staff) {
            ResponseHelper::send(false, "Staff not found.", [], 404);
            return;
        }

        if (!empty($data['phone_number']) && !\App\Helpers\Validator::phone($data['phone_number'])) {
            ResponseHelper::send(false, "Invalid phone number format.", [], 400);
            return;
        }

        // Prepare data for update
        $updateData = [
            'name' => $data['name'] ?? $staff['name'],
            'gender' => $data['gender'] ?? $staff['gender'],
            'address' => $data['address'] ?? $staff['address'],
            'phone_number' => $data['phone_number'] ?? $staff['phone_number'],
            'status' => $data['status'] ?? $staff['status']
        ];

        if ($this->staffModel->update($id, $updateData)) {
            ResponseHelper::send(true, "Staff updated successfully");
        } else {
            ResponseHelper::send(false, "Failed to update staff", [], 500);
        }
    }

    /**
     * DELETE /api/staff/{id}
     * Check: Admin Only
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);
        $currentUser = $_REQUEST['user'];

        // FIX: Switch connection first!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Tenant database error.", [], 500);
            return;
        }

        // Verify staff belongs to this tenant! (Security Check)
        $staff = $this->staffModel->getById($id);
        if (!$staff) {
            ResponseHelper::send(false, "Staff not found.", [], 404);
            return;
        }

        if ($this->staffModel->delete($id)) {
            ResponseHelper::send(true, "Staff deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete staff", [], 500);
        }
    }
}
