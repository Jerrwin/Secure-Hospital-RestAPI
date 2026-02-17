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
        RoleMiddleware::handle(['Provider', 'Nurse', 'Admin']); // Admin too? User said "patient crud can be done by only provider and nurse others cant". So remove Admin.
        
        // Re-check role strictly based on user request
        $currentUser = $_REQUEST['user'];
        if (!in_array($currentUser['role'], ['Provider', 'Nurse'])) {
            ResponseHelper::send(false, "Forbidden: Only Provider and Nurse can manage patients.", [], 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['name']) || empty($data['medical_history'])) {
            ResponseHelper::send(false, "Name and Medical History are required.", [], 400);
            return;
        }

        // Encrypt Medical Data
        $encryptedData = Encryption::encrypt($data['medical_history']);
        
        // Blind Index for Phone (if provided)
        $phoneHash = isset($data['phone']) ? hash_hmac('sha256', $data['phone'], $_ENV['HASH_SECRET']) : null;

        $patientData = [
            'tenant_id' => $currentUser['tenant_id'],
            'name' => $data['name'],
            'medical_history' => $encryptedData,
            'phone_hash' => $phoneHash
        ];

        $id = $this->patientModel->create($patientData);

        if ($id) {
            ResponseHelper::send(true, "Patient created successfully", ['id' => $id], 201);
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
