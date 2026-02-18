<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\User;
use App\Models\Staff;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class StaffController
{
    private $db;
    private $userModel;
    private $staffModel;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
        $this->userModel = new User($this->db);
        $this->staffModel = new Staff($this->db);
    }

    /**
     * POST /api/staff/register
     * Check: Admin Only
     * Action: Register Staff (User + Staff Profile)
     */
    public function register()
    {
        // 1. Auth & Role Check
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'SuperAdmin']); // Allow SuperAdmin too if needed, but req says 'Admin'

        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        // If SuperAdmin is registering, they must provide tenant_id
        $data = json_decode(file_get_contents("php://input"), true);

        if ($currentUser['role'] === 'SuperAdmin') {
            if (!isset($data['tenant_id'])) {
                ResponseHelper::send(false, "SuperAdmin must provide tenant_id", [], 400);
                return;
            }
            $tenantId = $data['tenant_id'];
        }

        // 2. Validate Input
        if (empty($data['name']) || empty($data['email']) || empty($data['password']) || empty($data['role_id']) || empty($data['gender'])) {
            ResponseHelper::send(false, "Missing required fields (name, email, password, role_id, gender)", [], 400);
            return;
        }

        // 3. Email Check
        if ($this->userModel->findAnyUserByEmail($data['email'])) {
            ResponseHelper::send(false, "Email already exists", [], 409);
            return;
        }

        // 4. Transactional Create
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
                'user_id' => $userId,
                'name' => $data['name'], // Verify if name should match
                'gender' => $data['gender'],
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null
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
     * Check: Admin, Provider, Nurse
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'SuperAdmin', 'Provider', 'Nurse']); // Adjust visibility rules as needed

        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        if ($currentUser['role'] === 'SuperAdmin') {
            // SuperAdmin might want to see all or filter by tenant params
            // For now, return empty or all if implemented
            ResponseHelper::send(false, "SuperAdmin view not implemented yet", [], 501);
            return;
        }

        $staffMembers = $this->staffModel->getAllByTenant($tenantId);
        ResponseHelper::send(true, "Staff list retrieved", $staffMembers);
    }

    /**
     * DELETE /api/staff/{id}
     * Check: Admin Only
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        // Verify staff belongs to this tenant! (Security Check)
        $staff = $this->staffModel->getById($id);
        if (!$staff || $staff['tenant_id'] != $_REQUEST['user']['tenant_id']) {
            ResponseHelper::send(false, "Staff not found or access denied", [], 404);
            return;
        }

        if ($this->staffModel->delete($id)) {
            ResponseHelper::send(true, "Staff deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete staff", [], 500);
        }
    }
}
