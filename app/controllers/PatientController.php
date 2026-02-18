<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Patient;
use App\Helpers\ResponseHelper;
use App\Helpers\Encryption; // [IMPORTANT] Keep this!
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
     * Roles: Provider, Nurse
     */
    public function create()
    {
        AuthMiddleware::handle();

        // Role Check
        $currentUser = $_REQUEST['user'];
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden: Only Provider and Nurse can add patients.", [], 403);
            return;
        }

        // Input Handling
        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // Validation (From His Code - Stricter is better)
        if (empty($data['first_name']) || empty($data['last_name']) || empty($data['dob']) || empty($data['gender']) || empty($data['medical_history'])) {
            ResponseHelper::send(false, "All fields (Name, DOB, Gender, Medical History) are required.", [], 400);
            return;
        }

        // [MERGE] Encrypt Medical Data (From Your Code)
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'dob' => $data['dob'],
            'gender' => $data['gender'],
            'medical_history' => $encryptedHistory, // Saving Encrypted
            'created_by' => $currentUser['user_id']
        ];

        $id = $this->patientModel->create($patientData);

        if ($id) {
            // Return readable data to the user who just created it
            $responseData = array_merge($patientData, ['id' => $id, 'medical_history' => $data['medical_history']]);
            ResponseHelper::send(true, "Patient created successfully", $responseData, 201);
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

        // Strict Role Check
        $currentUser = $_REQUEST['user'];
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden.", [], 403);
            return;
        }

        $patients = $this->patientModel->getAllByTenant($currentUser['tenant_id']);

        // [MERGE] Decrypt data for display (From Your Code)
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

        $currentUser = $_REQUEST['user'];
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden: Only Provider and Nurse can update patients.", [], 403);
            return;
        }

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // Check ownership
        $patient = $this->patientModel->getById($id);
        if (!$patient || $patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Patient not found or access denied.", [], 404);
            return;
        }

        // [MERGE] If updating medical history, Encrypt it first!
        if (!empty($data['medical_history'])) {
            $data['medical_history'] = Encryption::encrypt($data['medical_history']);
        }

        // Pass tenant_id to Model for safety (From His Code)
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

        $currentUser = $_REQUEST['user'];
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden.", [], 403);
            return;
        }

        $patient = $this->patientModel->getById($id);
        if (!$patient || $patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Patient not found.", [], 404);
            return;
        }

        // Use strict delete with tenant check (From His Code)
        if ($this->patientModel->softDelete($id, $currentUser['tenant_id'])) {
            ResponseHelper::send(true, "Patient deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete patient", [], 500);
        }
    }
}