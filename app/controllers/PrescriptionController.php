<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Prescription;
use App\Models\MasterTenant;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class PrescriptionController
{
    private $prescriptionModel;
    private $masterTenantModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster(); // Connect to Master DB
        $this->db = $masterDb;

        $this->masterTenantModel = new MasterTenant($this->db);
        // We initialize the model with masterDb as a fallback
        $this->prescriptionModel = new Prescription($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);

        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // Re-initialize the model with the actual Hospital Connection
        $this->prescriptionModel = new Prescription($this->db);
        return true;
    }

    /**
     * Create Prescription
     * Roles: Provider Only
     * Rule: Appointment must be 'completed'
     */
    public function create()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['appointment_id']) || empty($data['notes'])) {
            ResponseHelper::send(false, "Appointment ID and Notes are required.", [], 400);
            return;
        }

        // 1. Verify Appointment matches Tenant and is COMPLETED
        $appointment = $this->prescriptionModel->getAppointmentDetails($data['appointment_id']);

        if (!$appointment) {
            ResponseHelper::send(false, "Appointment not found.", [], 404);
            return;
        }

        if ($appointment['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        $status = $appointment['status'] ?? $appointment['STATUS'] ?? null;

        if ($status !== 'completed') {
            ResponseHelper::send(false, "Cannot prescribe: Appointment is not completed yet.", [], 400);
            return;
        }

        // 2. Check if prescription already exists
        if ($this->prescriptionModel->existsForAppointment($data['appointment_id'])) {
            ResponseHelper::send(false, "Prescription already exists for this appointment.", [], 409);
            return;
        }

        // 3. Create
        $prescriptionData = [
            'tenant_id' => $currentUser['tenant_id'],
            'appointment_id' => $data['appointment_id'],
            'provider_id' => $currentUser['user_id'],
            'notes' => $data['notes']
        ];

        $id = $this->prescriptionModel->create($prescriptionData);

        if ($id) {
            ResponseHelper::send(true, "Prescription created successfully", ['id' => $id], 201);
        } else {
            ResponseHelper::send(false, "Failed to create prescription", [], 500);
        }
    }

    /**
     * Update Status (Verify)
     * Roles: Pharmacist Only
     */
    public function updateStatus($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Pharmacist']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        $status = $data['status'] ?? null;
        if (!in_array($status, ['verified', 'created'])) { // Add other statuses if needed
            ResponseHelper::send(false, "Invalid status.", [], 400);
            return;
        }

        // Verify Tenant
        $prescription = $this->prescriptionModel->getById($id);
        if (!$prescription) {
            ResponseHelper::send(false, "Prescription not found.", [], 404);
            return;
        }

        if ($prescription['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        if ($this->prescriptionModel->updateStatus($id, $status)) {
            ResponseHelper::send(true, "Prescription status updated to $status");
        } else {
            ResponseHelper::send(false, "Failed to update status", [], 500);
        }
    }

    /**
     * Get All Prescriptions
     * Roles: Provider, Pharmacist, Admin
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Pharmacist', 'Admin']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $prescriptions = $this->prescriptionModel->getAllByTenant($currentUser['tenant_id']);

        ResponseHelper::send(true, "Prescriptions retrieved", $prescriptions);
    }
}
