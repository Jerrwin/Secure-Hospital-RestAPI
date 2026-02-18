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

    // POST /api/appointments (Tested & Working)
    public function create()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Nurse', 'Admin', 'Receptionist']);

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);
        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        if (empty($data)) {
            ResponseHelper::send(false, "Request body is empty", [], 400);
            return;
        }

        if (empty($data['patient_id']) || empty($data['appointment_date']) || empty($data['start_time']) || empty($data['end_time'])) {
            ResponseHelper::send(false, "Missing required fields.", [], 400);
            return;
        }

        if ($data['end_time'] <= $data['start_time']) {
            ResponseHelper::send(false, "End time must be after start time.", [], 400);
            return;
        }

        if (in_array($currentUser['role'], ['Nurse', 'Receptionist', 'Admin'])) {
            if (empty($data['provider_id'])) {
                ResponseHelper::send(false, "A provider_id (Doctor) must be specified.", [], 400);
                return;
            }
            $targetProviderId = $data['provider_id'];
        } else {
            $targetProviderId = $currentUser['user_id'];
        }

        $patient = $this->patientModel->getById($data['patient_id']);
        if (!$patient || $patient['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Patient not found or access denied.", [], 404);
            return;
        }

        $targetProvider = $this->userModel->getUserByType($targetProviderId, 'users');
        if (!$targetProvider || $targetProvider['tenant_id'] != $tenantId || $targetProvider['role_name'] !== 'Provider') {
            ResponseHelper::send(false, "Invalid Provider specified.", [], 400);
            return;
        }

        if ($data['appointment_date'] < date('Y-m-d')) {
            ResponseHelper::send(false, "Cannot book for past dates", [], 400);
            return;
        }

        if ($this->appointmentModel->isSlotBooked($targetProviderId, $data['appointment_date'], $data['start_time'], $data['end_time'])) {
            ResponseHelper::send(false, "Provider is already booked for this time slot.", [], 409);
            return;
        }

        $appointmentData = [
            'tenant_id' => $tenantId,
            'patient_id' => $data['patient_id'],
            'provider_id' => $targetProviderId,
            'created_by' => $currentUser['user_id'],
            'appointment_date' => $data['appointment_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'STATUS' => 'scheduled'
        ];

        $id = $this->appointmentModel->create($appointmentData);
        if ($id) {
            $appointmentData['id'] = (int) $id;
            ResponseHelper::send(true, "Appointment scheduled successfully.", $appointmentData, 201);
        } else {
            ResponseHelper::send(false, "Server Error", [], 500);
        }
    }

    // GET /api/appointments (Tested & Working)
    public function index()
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];

        $filters = [
            'start_date' => $_GET['start_date'] ?? null,
            'end_date' => $_GET['end_date'] ?? null
        ];

        $list = $this->appointmentModel->getAllByTenant($currentUser['tenant_id'], $filters);
        ResponseHelper::send(true, "Appointments retrieved", $list);
    }

    // [RESTORED] GET /api/appointments/upcoming
    public function getUpcoming()
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];

        // Use the dedicated method we are about to add to the Model
        $list = $this->appointmentModel->getUpcomingByTenant($currentUser['tenant_id']);
        ResponseHelper::send(true, "Upcoming appointments", $list);
    }

    // [RESTORED] PUT /api/appointments/update/{id}
    public function update($id)
    {
        AuthMiddleware::handle();
        $currentUser = $_REQUEST['user'];
        $tenantId = $currentUser['tenant_id'];

        $data = !empty($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);

        // 1. Check if appointment exists
        $existing = $this->appointmentModel->find($id);
        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Appointment not found or access denied", [], 404);
            return;
        }

        // 2. Prevent editing past/completed appointments
        if (in_array(strtolower($existing['STATUS']), ['cancelled', 'completed'])) {
            ResponseHelper::send(false, "Cannot modify finished appointments", [], 400);
            return;
        }

        // 3. Conflict Check (if time is changing)
        $pId = $data['provider_id'] ?? $existing['provider_id'];
        $date = $data['appointment_date'] ?? $existing['appointment_date'];
        $start = $data['start_time'] ?? $existing['start_time'];
        $end = $data['end_time'] ?? $existing['end_time'];

        // Exclude current ID from conflict check
        if ($this->appointmentModel->isSlotBooked($pId, $date, $start, $end, $id)) {
            ResponseHelper::send(false, "Time slot conflict", [], 409);
            return;
        }

        // 4. Update
        if ($this->appointmentModel->update($id, $data)) {
            ResponseHelper::send(true, "Appointment updated successfully");
        } else {
            ResponseHelper::send(false, "Update failed", [], 500);
        }
    }

    // PUT /api/appointments/cancel/{id} (Tested & Working)
    public function cancel($id)
    {
        AuthMiddleware::handle();
        $tenantId = $_REQUEST['user']['tenant_id'];
        $existing = $this->appointmentModel->find($id);

        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Not found", [], 404);
            return;
        }

        if (strtolower($existing['STATUS']) === 'completed') {
            ResponseHelper::send(false, "Cannot cancel a completed appointment.", [], 400);
            return;
        }

        $res = $this->appointmentModel->update($id, ['STATUS' => 'cancelled']);
        ResponseHelper::send($res, $res ? "Cancelled" : "Failed");
    }

    // PUT /api/appointments/complete/{id} (Tested & Working)
    public function complete($id)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Provider', 'Admin']);

        $tenantId = $_REQUEST['user']['tenant_id'];
        $existing = $this->appointmentModel->find($id);

        if (!$existing || $existing['tenant_id'] != $tenantId) {
            ResponseHelper::send(false, "Not found", [], 404);
            return;
        }

        $res = $this->appointmentModel->update($id, ['STATUS' => 'completed']);
        ResponseHelper::send($res, $res ? "Completed" : "Failed");
    }

    // [RESTORED] GET /api/appointments/show/{id}
    public function show($id)
    {
        AuthMiddleware::handle();
        $appointment = $this->appointmentModel->find($id);

        // Security Check
        if (!$appointment || $appointment['tenant_id'] != $_REQUEST['user']['tenant_id']) {
            ResponseHelper::send(false, "Access denied", [], 404);
            return;
        }
        ResponseHelper::send(true, "Details", $appointment);
    }
}