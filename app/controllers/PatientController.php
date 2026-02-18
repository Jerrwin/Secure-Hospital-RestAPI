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
     * Roles: Provider, Nurse
     */
    public function create()
    {
        AuthMiddleware::handle();

        $currentUser = $_REQUEST['user']; // From your JWT
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden", [], 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // Validate the actual columns in your table
        if (empty($data['first_name']) || empty($data['medical_history'])) {
            ResponseHelper::send(false, "First Name and Medical History are required.", [], 400);
            return;
        }

        // Encrypt Medical Data
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'dob' => $data['dob'] ?? null,
            'gender' => $data['gender'] ?? null,
            'medical_history' => $encryptedHistory,
            'created_by' => $currentUser['user_id'] // Use the ID from JWT
        ];

        $id = $this->patientModel->create($patientData);

        if ($id) {
            // 🎯 Prepare the response with the ID and the details
            // We use the original medical history from $data so it's readable (not encrypted)
            $responseData = [
                'id' => (int) $id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'dob' => $data['dob'] ?? null,
                'gender' => $data['gender'] ?? null,
                'medical_history' => $data['medical_history']
            ];
            ResponseHelper::send(true, "Patient created successfully", $responseData, 201);
        } else {
            ResponseHelper::send(false, "Failed to create patient", [], 500);
        }
    }

    /**
     * Get All Patients (Tenant Scoped)
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']);

        $currentUser = $_REQUEST['user'];
        $patients = $this->patientModel->getAllByTenant($currentUser['tenant_id']);

        // Decrypt data for display
        foreach ($patients as &$patient) {
            if (!empty($patient['medical_history'])) {
                $patient['medical_history'] = Encryption::decrypt($patient['medical_history']);
            }
        }

        ResponseHelper::send(true, "Patients retrieved", $patients);
    }

    /**
     * Delete Patient
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']); // "others cant"

        // Verify ownership/tenant
        $patient = $this->patientModel->getById($id);
        $currentUser = $_REQUEST['user'];

        if (!$patient || $patient['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Patient not found or access denied", [], 404);
            return;
        }

        if ($this->patientModel->softDelete($id)) {
            ResponseHelper::send(true, "Patient deleted successfully");
        } else {
            ResponseHelper::send(false, "Failed to delete patient", [], 500);
        }
    }
}
