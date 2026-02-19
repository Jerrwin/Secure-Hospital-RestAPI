<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Patient;
use App\Helpers\ResponseHelper;
use App\Helpers\Encryption;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

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
        // Fixed: Strictly only Provider and Nurse
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['dob']) || empty($data['gender']) || empty($data['medical_history'])) {
            ResponseHelper::send(false, "First Name, Last Name, DOB, Gender, and Medical History are required.", [], 400);
            return;
        }

        // [SECURE] Encrypt the history before it ever touches the DB
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
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
        if (!$patient || $patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Patient not found or access denied.", [], 404);
            return;
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

        if (!$patient || $patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Patient not found or access denied.", [], 404);
            return;
        }

        if ($this->patientModel->softDelete($id, $currentUser['tenant_id'])) {
            ResponseHelper::send(true, "Patient deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete patient", [], 500);
        }
    }
}