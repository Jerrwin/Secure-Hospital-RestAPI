<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Patient;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
use App\Helpers\Encryption;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\Validator;

class PatientController
{
    private $db;
    private $patientModel;
    private $masterTenantModel;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        $this->masterTenantModel = new MasterTenant($masterDb);
        $this->patientModel = new Patient($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);

        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // Re-initialize model with the actual Tenant Connection
        $this->patientModel = new Patient($this->db);
        return true;
    }

    /**
     * Create Patient
     * Roles: Provider, Nurse Only
     */
    public function create()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];

        // Switch to the Tenant DB before doing any queries!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        $requiredFields = [
            'first_name' => 'First Name',
            'dob' => 'Date of Birth',
            'gender' => 'Gender',
            'medical_history' => 'Medical History',
            'email' => 'Email',
            'password' => 'Password'
        ];

        foreach ($requiredFields as $field => $label) {
            if (empty($data[$field])) {
                ResponseHelper::send(false, "$label is required.", [], 400);
                return;
            }
        }

        if (!Validator::email($data['email'])) {
            ResponseHelper::send(false, "Invalid email format.", [], 400);
            return;
        }

        if (!Validator::password($data['password'])) {
            ResponseHelper::send(false, "Password does not meet complexity requirements.", [], 400);
            return;
        }

        // Validate email if updated
        if (!empty($data['email'])) {
            if (!Validator::email($data['email'])) {
                ResponseHelper::send(false, "Invalid email format.", [], 400);
                return;
            }
        }

        // [NEW] Check for duplicate email before proceeding (Reverted)
        if ($this->patientModel->findByEmail($data['email'])) {
            ResponseHelper::send(false, "Email already exists. Please use a unique email for each patient.", [], 409);
            return;
        }

        // [SECURE] Encrypt the history before it ever touches the DB
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null, // Match frontend (optional)
            'email' => $data['email'],
            'phone_number' => $data['phone_number'], // SAVE PHONE
            'password' => password_hash($data['password'], PASSWORD_BCRYPT),
            'dob' => $data['dob'],
            'gender' => $data['gender'],
            'blood_group' => $data['blood_group'], // SAVE BLOOD GROUP
            'status' => $data['status'] ?? 'active', // Default to 'active' as per user request
            'address' => $data['address'], // SAVE ADDRESS
            'medical_history' => $encryptedHistory,
            'created_by' => $currentUser['user_id']
        ];

        $id = $this->patientModel->create($patientData);

        if ($id) {
            ResponseHelper::send(true, "Patient created successfully", ['id' => (int) $id], 201);
        } else {
            ResponseHelper::send(false, "Failed to create patient", [], 500);
        }
    }

    /**
     * Get All Patients
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse', 'Pharmacist', 'Receptionist']);

        $currentUser = $_REQUEST['user'];

        // Switch to the Tenant DB before doing any queries!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $patients = $this->patientModel->getAllByTenant($currentUser['tenant_id']);

        // [SECURE] Decrypt history so staff can actually read it
        foreach ($patients as &$patient) {
            if (!empty($patient['medical_history'])) {
                $patient['medical_history'] = Encryption::decrypt($patient['medical_history']);
            }
        }

        ResponseHelper::send(true, "Patients retrieved", $patients);
    }

    /**
     * Update Patient
     */
    public function update($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];

        // Switch to the Tenant DB before doing any queries!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        $patient = $this->patientModel->getById($id);
        if (!$patient) {
            ResponseHelper::send(false, "Patient not found.", [], 404);
            return;
        }

        if ($patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        // [SECURE] Validate and Hash password if updated
        if (!empty($data['password'])) {
            if (!Validator::password($data['password'])) {
                ResponseHelper::send(false, "New password does not meet complexity requirements.", [], 400);
                return;
            }
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        // Validate email if updated
        if (!empty($data['email'])) {
            if (!Validator::email($data['email'])) {
                ResponseHelper::send(false, "Invalid email format.", [], 400);
                return;
            }
        }

        // [SECURE] Encrypt if updated
        if (!empty($data['medical_history'])) {
            $data['medical_history'] = Encryption::encrypt($data['medical_history']);
        }

        if ($this->patientModel->update($id, $data, $currentUser['tenant_id'])) {
            ResponseHelper::send(true, "Patient updated successfully.");
        } else {
            ResponseHelper::send(false, "Failed to update patient.", [], 500);
        }
    }

    /**
     * Delete Patient
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];

        // Switch to the Tenant DB before doing any queries!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $patient = $this->patientModel->getById($id);

        if (!$patient) {
            ResponseHelper::send(false, "Patient not found.", [], 404);
            return;
        }

        if ($patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        if ($this->patientModel->softDelete($id, $currentUser['tenant_id'])) {
            ResponseHelper::send(true, "Patient deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete patient", [], 500);
        }
    }

    /**
     * Get Single Patient Details
     * GET /api/patients/{id}
     */
    public function show($id)
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];

        // Switch to the Tenant DB before doing any queries!
        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        $patient = $this->patientModel->getById($id);

        if (!$patient) {
            ResponseHelper::send(false, "Patient not found.", [], 404);
            return;
        }

        // Access Control: 
        // 1. Staff can view patients in their own tenant
        // 2. Patient can only view their own record
        if ($currentUser['role'] === 'Patient') {
            if ($patient['id'] != $currentUser['user_id']) {
                ResponseHelper::send(false, "Access denied. You can only view your own profile.", [], 403);
                return;
            }
        } else {
            // Admin, Provider, Nurse, etc.
            if ($patient['tenant_id'] != $currentUser['tenant_id']) {
                ResponseHelper::send(false, "Access denied. Patient belongs to another hospital.", [], 403);
                return;
            }
        }

        if (!empty($patient['medical_history'])) {
            $patient['medical_history'] = Encryption::decrypt($patient['medical_history']);
        }

        ResponseHelper::send(true, "Patient details retrieved", $patient);
    }
}