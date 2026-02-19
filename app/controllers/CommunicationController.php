<?php

namespace App\Controllers;

use App\Models\Communication;
use App\Models\Appointment;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\ResponseHelper;
use App\Core\Database;

class CommunicationController
{
    private $communicationModel;
    private $appointmentModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->communicationModel = new Communication($db);
        $this->appointmentModel = new Appointment($db);
    }

    // POST /api/communications
    public function create()
    {
        AuthMiddleware::handle();
        // Provider, Nurse, Admin, Receptionist, Patient (if owner)
        $user = $_REQUEST['user'];
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['appointment_id']) || empty($data['note'])) {
            ResponseHelper::send(false, "Appointment ID and Note content are required.", [], 400);
            return;
        }

        $appointment = $this->appointmentModel->find($data['appointment_id']);

        if (!$appointment) {
            ResponseHelper::send(false, "Appointment not found.", [], 404);
            return;
        }

        // Logic to restrict access: only participant or tenant admin
        // For MVP, we allow tenant staff and the patient
        if ($user['role'] === 'Patient' && $appointment['patient_id'] != $user['patient_id']) {
            ResponseHelper::send(false, "Unauthorized.", [], 403);
            return;
        }

        if ($user['tenant_id'] != $appointment['tenant_id']) {
            ResponseHelper::send(false, "Unauthorized tenant.", [], 403);
            return;
        }

        $data['user_id'] = $user['user_id'];

        if ($this->communicationModel->create($data)) {
            ResponseHelper::send(true, "Note added successfully.", [], 201);
        } else {
            ResponseHelper::send(false, "Failed to add note.", [], 500);
        }
    }

    // GET /api/communications?appointment_id={id}
    public function index()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];

        $appointmentId = $_GET['appointment_id'] ?? null;

        if (!$appointmentId) {
            ResponseHelper::send(false, "Appointment ID required.", [], 400);
            return;
        }

        // Validate Access
        $appointment = $this->appointmentModel->find($appointmentId);
        if (!$appointment) {
            ResponseHelper::send(false, "Appointment not found.", [], 404);
            return;
        }

        if ($user['role'] === 'Patient' && $appointment['patient_id'] != $user['patient_id']) {
            ResponseHelper::send(false, "Unauthorized.", [], 403);
            return;
        }

        if ($user['tenant_id'] != $appointment['tenant_id']) {
            ResponseHelper::send(false, "Unauthorized tenant.", [], 403);
            return;
        }

        $notes = $this->communicationModel->getByAppointmentId($appointmentId);

        // Filter private notes for Patient
        if ($user['role'] === 'Patient') {
            $notes = array_filter($notes, function ($note) {
                return $note['is_private'] == 0;
            });
            $notes = array_values($notes); // Re-index
        }

        ResponseHelper::send(true, "Notes retrieved.", $notes);
    }
}
