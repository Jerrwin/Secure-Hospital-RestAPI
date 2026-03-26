<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Prescription;
use App\Models\MasterTenant;
use App\Models\Notification;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\FileActivityLogger;

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

        if (empty($data['appointment_id'])) {
            ResponseHelper::send(false, "Appointment ID is required.", [], 400);
            return;
        }

        // 1. Validate Items Structure
        if (empty($data['items']) || !is_array($data['items'])) {
            ResponseHelper::send(false, "At least one medication item is required.", [], 400);
            return;
        }

        foreach ($data['items'] as $item) {
            if (empty($item['medicine_name']) || empty($item['dosage']) || empty($item['frequency']) || empty($item['duration'])) {
                ResponseHelper::send(false, "Each item must have medicine_name, dosage, frequency, and duration.", [], 400);
                return;
            }
        }

        // 2. Verify Appointment matches Tenant and is COMPLETED
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

        // 3. Check if prescription already exists
        if ($this->prescriptionModel->existsForAppointment($data['appointment_id'])) {
            ResponseHelper::send(false, "Prescription already exists for this appointment.", [], 409);
            return;
        }

        // 4. Create
        $prescriptionData = [
            'tenant_id' => $currentUser['tenant_id'],
            'appointment_id' => $data['appointment_id'],
            'provider_id' => $currentUser['user_id'],
            'notes' => $data['notes'] ?? '',
            'items' => $data['items']
        ];

        $id = $this->prescriptionModel->create($prescriptionData);

        if ($id) {
            $prescription = $this->prescriptionModel->getById($id);
            FileActivityLogger::logCRUD('PRESCRIPTION_CREATE', 'prescriptions', (int)$id, [
                'appointment_id' => $data['appointment_id'],
                'items_count' => count($data['items'])
            ], __METHOD__);
            ResponseHelper::send(true, "Prescription created successfully", $prescription, 201);
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
        if (!in_array($status, ['verified', 'created', 'dispensed'])) {
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
            $prescription = $this->prescriptionModel->getById($id);
            FileActivityLogger::logCRUD('PRESCRIPTION_STATUS_CHANGE', 'prescriptions', (int)$id, [
                'status' => $status
            ], __METHOD__);

            // Trigger Patient Notification if dispensed
            if ($status === 'dispensed') {
                $notifModel = new Notification($this->db);
                $notifModel->create([
                    'tenant_id'    => $currentUser['tenant_id'],
                    'user_id'      => $prescription['patient_id'],
                    'user_type'    => 'patient',
                    'type'         => 'prescription',
                    'title'        => 'Prescription Ready',
                    'message'      => 'Your prescription from your appointment on ' . ($prescription['appointment_date'] ?? 'N/A') . ' is now ready for collection.',
                    'reference_id' => (int) $id
                ]);
            }

            ResponseHelper::send(true, "Prescription status updated to $status", $prescription);
        } else {
            ResponseHelper::send(false, "Failed to update status", [], 500);
        }
    }

    /**
     * Get All Prescriptions (Paginated)
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Pharmacist', 'Admin', 'Patient', 'Receptionist', 'Nurse']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $filters = $_GET;
        if ($currentUser['role'] === 'Patient') {
            $filters['patient_id'] = $currentUser['user_id'];
        }
        if ($currentUser['role'] === 'Provider') {
            $filters['provider_id'] = $currentUser['user_id'];
        }


        $result = $this->prescriptionModel->getAllByTenantPaginated($currentUser['tenant_id'], $filters);
        
        // Fetch items for each prescription in the paginated set
        foreach ($result['data'] as &$p) {
            $p['items'] = $this->prescriptionModel->getItems($p['id']);
        }

        FileActivityLogger::logCRUD('PRESCRIPTION_VIEW_ALL', 'prescriptions', null, [
            'count' => count($result['data']),
            'filters' => $filters
        ], __METHOD__);

        ResponseHelper::sendPaginated(true, "Prescriptions retrieved", $result['data'], $result['pagination']);
    }


    /**
     * Update Prescription (Patch)
     * Roles: Provider Only
     * Rule: Can only edit if status is 'created'
     */
    public function update($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Pharmacist']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $prescription = $this->prescriptionModel->getById($id);
        if (!$prescription) {
            ResponseHelper::send(false, "Prescription not found.", [], 404);
            return;
        }

        // Security: Must belong to tenant and MUST be created by a provider in this tenant
        if ($prescription['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        // IMPORTANT SAFETY RULE: Only edit if not verified or dispensed yet
        if ($prescription['STATUS'] !== 'created') {
            ResponseHelper::send(false, "Cannot edit: This prescription has already been processed by the pharmacy.", [], 400);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // Optional: Validate items structure if provided
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (empty($item['medicine_name']) || empty($item['dosage'])) {
                    ResponseHelper::send(false, "Invalid items structure.", [], 400);
                    return;
                }
            }
        }

        if ($this->prescriptionModel->update($id, $data)) {
            $prescription = $this->prescriptionModel->getById($id);
            FileActivityLogger::logCRUD('PRESCRIPTION_UPDATE', 'prescriptions', (int)$id, [
                'updated_fields' => array_keys($data)
            ], __METHOD__);
            ResponseHelper::send(true, "Prescription updated successfully", $prescription);
        } else {
            ResponseHelper::send(false, "Failed to update prescription", [], 500);
        }
    }

    /**
     * Get Single Prescription Detail
     * GET /api/prescriptions/{id}
     */
    public function show($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Pharmacist', 'Admin', 'Patient']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $prescription = $this->prescriptionModel->getById($id);

        if (!$prescription) {
            ResponseHelper::send(false, "Prescription not found.", [], 404);
            return;
        }

        // Access Control
        if ($prescription['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        // If patient, only show if it belongs to them
        if ($currentUser['role'] === 'Patient') {
            // Need to verify if the appointment belongs to this patient
            $appointment = $this->prescriptionModel->getAppointmentDetails($prescription['appointment_id']);
            if ($appointment['patient_id'] != $currentUser['user_id']) {
                ResponseHelper::send(false, "Access denied. Not your prescription.", [], 403);
                return;
            }
        }

        FileActivityLogger::logCRUD('PRESCRIPTION_VIEW_SINGLE', 'prescriptions', (int)$id, [], __METHOD__);

        ResponseHelper::send(true, "Prescription details retrieved", $prescription);
    }

    /**
     * Delete Prescription
     * DELETE /api/prescriptions/{id}
     */
    public function delete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Admin']);

        $currentUser = $_REQUEST['user'];

        if (!$this->connectByTenantId($currentUser['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $prescription = $this->prescriptionModel->getById($id);
        if (!$prescription) {
            ResponseHelper::send(false, "Prescription not found.", [], 404);
            return;
        }

        if ($prescription['tenant_id'] != $currentUser['tenant_id']) {
            ResponseHelper::send(false, "Access denied.", [], 403);
            return;
        }

        // Logic check: only delete if not processed
        if ($prescription['STATUS'] !== 'created') {
            ResponseHelper::send(false, "Cannot delete: This prescription has already been processed.", [], 400);
            return;
        }

        if ($this->prescriptionModel->delete($id)) {
            FileActivityLogger::logCRUD('PRESCRIPTION_DELETE', 'prescriptions', (int)$id, [], __METHOD__);
            ResponseHelper::send(true, "Prescription deleted successfully");
        } else {
            ResponseHelper::send(false, "Deletion failed", [], 500);
        }
    }
}
