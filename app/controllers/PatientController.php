<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Patient;
use App\Helpers\ResponseHelper;
use App\Helpers\Encryption;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\Validator;

class PatientController
{
    private $patientModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->patientModel = new Patient($db);
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
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        $requiredFields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
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

        // Check for duplicate email
        if ($this->patientModel->findByEmail($data['email'])) {
            ResponseHelper::send(false, "Patient already exists with this email.", [], 409);
            return;
        }

        // [SECURE] Encrypt the history before it ever touches the DB
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_BCRYPT),
            'dob' => $data['dob'],
            'gender' => $data['gender'],
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
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];
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
            $existing = $this->patientModel->findByEmail($data['email']);
            if ($existing && $existing['id'] != $id) {
                ResponseHelper::send(false, "Email already in use by another patient.", [], 409);
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
}