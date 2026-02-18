<?php

namespace App\Controllers;

use App\Models\Billing;
use App\Models\Appointment;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\ResponseHelper;
use App\Core\Database;

class BillingController
{
    private $billingModel;
    private $appointmentModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->billingModel = new Billing($db);
        $this->appointmentModel = new Appointment($db);
    }

    // POST /api/invoices
    public function createInvoice()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Receptionist', 'Provider']);

        $user = $_REQUEST['user'];
        $data = json_decode(file_get_contents("php://input"), true);
        
        $appointmentId = $data['appointment_id'];
        $appointment = $this->appointmentModel->getById($appointmentId);

        if (!$appointment) {
            ResponseHelper::send(false, "Appointment not found.", [], 404);
            return;
        }

        if ($user['tenant_id'] != $appointment['tenant_id']) {

            ResponseHelper::send(false, "Unauthorized tenant.", [], 403);
            return;
        }

        // Check if invoice already exists
        $existing = $this->billingModel->getInvoiceByAppointment($appointmentId);
        if ($existing) {
             ResponseHelper::send(false, "Invoice already exists for this appointment.", [], 409);
             return;
        }

        $data['tenant_id'] = $user['tenant_id'];

        if ($this->billingModel->createInvoice($data)) {
            ResponseHelper::send(true, "Invoice created successfully.", [], 201);
        } else {
            ResponseHelper::send(false, "Failed to create invoice.", [], 500);
        }
    }

    // GET /api/invoices?appointment_id={id}
    public function getInvoice()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $appointmentId = $_GET['appointment_id'] ?? null;

        if (!$appointmentId) {
             ResponseHelper::send(false, "Appointment ID required.", [], 400);
             return;
        }

        $appointment = $this->appointmentModel->getById($appointmentId);
        if (!$appointment) {
            ResponseHelper::send(false, "Appointment not found.", [], 404);
            return;
        }

        // Access Control
        if ($user['tenant_id'] != $appointment['tenant_id']) {
            ResponseHelper::send(false, "Unauthorized tenant.", [], 403);
            return;
        }

        if ($user['role'] === 'Patient' && $appointment['patient_id'] != $user['patient_id']) {
             ResponseHelper::send(false, "Unauthorized.", [], 403);
             return;
        }

        $invoice = $this->billingModel->getInvoiceByAppointment($appointmentId);
        
        if ($invoice) {
            ResponseHelper::send(true, "Invoice retrieved.", $invoice);
        } else {
            ResponseHelper::send(false, "Invoice not found.", [], 404);
        }
    }

    // POST /api/payments
    public function processPayment()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Receptionist']); // Only staff resolves payment

        $user = $_REQUEST['user'];
        $data = json_decode(file_get_contents("php://input"), true);
        
        $invoiceId = $data['invoice_id'];
        $invoice = $this->billingModel->getInvoiceById($invoiceId);

        if (!$invoice) {
             ResponseHelper::send(false, "Invoice not found.", [], 404);
             return;
        }

        // Verify Tenant via Appointment
        $appointment = $this->appointmentModel->getById($invoice['appointment_id']);
        if ($user['tenant_id'] != $appointment['tenant_id']) {
             ResponseHelper::send(false, "Unauthorized tenant.", [], 403);
             return;
        }

        if ($this->billingModel->createPayment($data)) {
             ResponseHelper::send(true, "Payment recorded successfully.", [], 201);
        } else {
             ResponseHelper::send(false, "Failed to record payment.", [], 500);
        }
    }
}
