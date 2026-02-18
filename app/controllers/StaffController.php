<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\User;
use App\Models\Staff;
use App\Helpers\ResponseHelper;
use App\Helpers\Validator;
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
     */
    public function register()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'SuperAdmin']);

        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // [MERGE] SuperAdmin Tenant Assignment
        if ($currentUser['role'] === 'SuperAdmin') {
            if (!isset($data['tenant_id'])) {
                ResponseHelper::send(false, "SuperAdmin must provide tenant_id", [], 400);
                return;
            }
            $tenantId = $data['tenant_id'];
        }

        // 1. [MERGE] Strict Validation (From Praveen's Code)
        if (empty($data['name']) || empty($data['email']) || empty($data['password']) || empty($data['role_id']) || empty($data['gender'])) {
            ResponseHelper::send(false, "Missing required fields.", [], 400);
            return;
        }

        if (!Validator::email($data['email'])) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        if (!Validator::password($data['password'])) {
            ResponseHelper::send(false, "Password too weak.", [], 400);
            return;
        }

        if (!empty($data['phone_number']) && !Validator::phone($data['phone_number'])) {
            ResponseHelper::send(false, "Invalid phone number (10 digits required).", [], 400);
            return;
        }

        // 2. Email Check
        if ($this->userModel->findAnyUserByEmail($data['email'])) {
            ResponseHelper::send(false, "Email already exists", [], 409);
            return;
        }

        // 3. Transactional Create
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

            $userId = $this->userModel->create($userData, false);

            // B. Create Staff Profile
            $staffData = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $data['name'],
                'gender' => $data['gender'],
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'status' => $data['status'] ?? 'active'
            ];

            $staffId = $this->staffModel->create($staffData);

            $this->db->commit();
            ResponseHelper::send(true, "Staff registered successfully", ['user_id' => $userId, 'staff_id' => $staffId], 201);

        } catch (\Exception $e) {
            $this->db->rollBack();
            ResponseHelper::send(false, "Registration Failed: " . $e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api/staff
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'SuperAdmin', 'Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];

        // [MERGE] Praveen's SuperAdmin view logic
        if ($currentUser['role'] === 'SuperAdmin') {
            $query = "SELECT s.*, u.email, r.name as role_name 
                       FROM staff s 
                       JOIN users u ON s.user_id = u.id 
                       JOIN roles r ON u.role_id = r.id 
                       WHERE s.deleted_at IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            ResponseHelper::send(true, "All System Staff retrieved", $stmt->fetchAll(\PDO::FETCH_ASSOC));
            return;
        }

        $staffMembers = $this->staffModel->getAllByTenant($currentUser['tenant_id']);
        ResponseHelper::send(true, "Staff list retrieved", $staffMembers);
    }

    /**
     * PUT /api/staff/{id}
     * [MERGE] Added Praveen's Update logic
     */
    public function update($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $currentUser = $_REQUEST['user'];
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        $staff = $this->staffModel->getById($id);
        if (!$staff || $staff['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Staff not found or access denied", [], 404);
            return;
        }

        if (!empty($data['phone_number']) && !Validator::phone($data['phone_number'])) {
            ResponseHelper::send(false, "Invalid phone number format.", [], 400);
            return;
        }

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
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

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