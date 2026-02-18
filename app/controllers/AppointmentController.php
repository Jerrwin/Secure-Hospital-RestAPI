<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class AppointmentController
{
    private $appointmentModel;
    private $patientModel;
    private $userModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
        $this->appointmentModel = new Appointment($this->db);
        $this->patientModel = new Patient($this->db);
        $this->userModel = new User($this->db);
    }

    public function create()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse']);

        // 🎯 FIX: Use $_POST directly (populated by JsonMiddleware)
        $data = $_POST;
        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        // Check if data is missing
        if (empty($data)) {
            ResponseHelper::send(false, "Request body is empty", [], 400);
            return;
        }

        // --- 1. INPUT VALIDATION ---
        if (empty($data['patient_id']) || empty($data['appointment_date']) || empty($data['start_time']) || empty($data['end_time'])) {
            ResponseHelper::send(false, "Missing required fields.", [], 400);
            return;
        }

        // --- 2. DETERMINE TARGET PROVIDER ID ---
        if ($currentUser['role'] === 'Nurse') {
            if (empty($data['provider_id'])) {
                ResponseHelper::send(false, "Nurses must specify a provider_id (Doctor) for the appointment.", [], 400);
                return;
            }
            $targetProviderId = $data['provider_id'];
        } else {
            $targetProviderId = $currentUser['user_id'];
        }

        // --- 3. VALIDATE PATIENT ---
        $patient = $this->patientModel->getById($data['patient_id']);
        if (!$patient) {
            ResponseHelper::send(false, "Patient not found.", [], 404);
            return;
        }
        if ($patient['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Security Warning: Access denied.", [], 403);
            return;
        }

        // --- 4. VALIDATE PROVIDER ---
        $targetProvider = $this->userModel->findById($targetProviderId);
        if (!$targetProvider) {
            ResponseHelper::send(false, "Provider not found.", [], 404);
            return;
        }
        if ($targetProvider['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Security Warning: Cross-tenant booking blocked.", [], 403);
            return;
        }
        if ($targetProvider['role_name'] !== 'Provider') {
            ResponseHelper::send(false, "Specified user is not a Doctor.", [], 400);
            return;
        }

        // --- 5. LOGIC: TIME SLOT CHECK ---
        if ($data['appointment_date'] < date('Y-m-d')) {
            ResponseHelper::send(false, "Cannot book for past dates", [], 400);
            return;
        }

        if ($this->appointmentModel->isSlotBooked($targetProviderId, $data['appointment_date'], $data['start_time'], $data['end_time'])) {
            ResponseHelper::send(false, "Provider is already booked for this time slot.", [], 409);
            return;
        }

        // --- 6. PREPARE & SAVE ---
        $appointmentData = [
            'tenant_id' => $tenantId,
            'patient_id' => $data['patient_id'],
            'provider_id' => $targetProviderId,
            'created_by' => $currentUser['user_id'],
            'appointment_date' => $data['appointment_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'status' => 'scheduled'
        ];

        try {
            $id = $this->appointmentModel->create($appointmentData);
            if ($id) {
                $appointmentData['id'] = (int) $id;
                ResponseHelper::send(true, "Appointment scheduled successfully.", $appointmentData, 201);
            } else {
                throw new \Exception("Database insert failed.");
            }
        } catch (\Exception $e) {
            ResponseHelper::send(false, "Server Error: " . $e->getMessage(), [], 500);
        }
    }

    public function update($id)
    {
        AuthMiddleware::handle();
        $tenantId = $_REQUEST['user']['tenant_id'];

        // 🎯 FIX: Use $_POST directly
        $data = $_POST;

        $existing = $this->appointmentModel->find($id);
        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Access Denied", [], 403);
            return;
        }

        if (in_array(strtolower($existing['status']), ['cancelled', 'completed'])) {
            ResponseHelper::send(false, "Cannot modify finished appointments", [], 400);
            return;
        }

        $pId = $data['provider_id'] ?? $existing['provider_id'];
        $date = $data['appointment_date'] ?? $existing['appointment_date'];
        $start = $data['start_time'] ?? $existing['start_time'];
        $end = $data['end_time'] ?? $existing['end_time'];

        if ($this->appointmentModel->isSlotBooked($pId, $date, $start, $end, $id)) {
            ResponseHelper::send(false, "Time slot conflict", [], 400);
            return;
        }

        if (isset($data['status'])) {
            $data['STATUS'] = $data['status'];
            unset($data['status']);
        }

        $res = $this->appointmentModel->update($id, $data);
        ResponseHelper::send($res, $res ? "Updated" : "Update failed");
    }

    public function cancel($id)
    {
        AuthMiddleware::handle();
        $tenantId = $_REQUEST['user']['tenant_id'];
        $existing = $this->appointmentModel->find($id);

        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Not found", [], 404);
            return;
        }

        // 🎯 FIX: Prevent cancelling a finished appointment
        // We use uppercase 'STATUS' to avoid the Undefined Key warning
        if (isset($existing['STATUS']) && strtolower($existing['STATUS']) === 'completed') {
            ResponseHelper::send(false, "Cannot cancel an appointment that is already completed.", [], 400);
            return;
        }

        $res = $this->appointmentModel->update($id, ['STATUS' => 'cancelled']);
        ResponseHelper::send($res, $res ? "Cancelled" : "Failed");
    }

    public function complete($id)
    {
        AuthMiddleware::handle();
        $tenantId = $_REQUEST['user']['tenant_id'];
        $existing = $this->appointmentModel->find($id);

        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Not found", [], 404);
            return;
        }

        if (strtolower($existing['STATUS']) === 'cancelled') {
            ResponseHelper::send(false, "Cannot complete a cancelled appointment.", [], 400);
            return;
        }

        $res = $this->appointmentModel->update($id, ['STATUS' => 'completed']);
        ResponseHelper::send($res, $res ? "Completed" : "Failed");
    }

    public function getUpcoming()
    {
        AuthMiddleware::handle();
        $list = $this->appointmentModel->getUpcomingByTenant($_REQUEST['user']['tenant_id']);
        ResponseHelper::send(true, "List fetched", $list);
    }

    public function getAll()
    {
        AuthMiddleware::handle();
        $tenantId = $_REQUEST['user']['tenant_id'];
        $list = $this->appointmentModel->getByTenant($tenantId);
        ResponseHelper::send(true, "All appointments retrieved", $list);
    }

    public function show($id) 
{
    AuthMiddleware::handle();
    $tenantId = $_REQUEST['user']['tenant_id'];
    
    $appointment = $this->appointmentModel->find($id);

    if (!$appointment || $appointment['tenant_id'] != $tenantId) {
        ResponseHelper::send(false, "Appointment not found or access denied", [], 404);
        return;
    }

    ResponseHelper::send(true, "Appointment details", $appointment);
}
}