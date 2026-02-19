<?php

namespace App\Controllers;

use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\Staff;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Helpers\ResponseHelper;
use App\Core\Database;

class DashboardController
{
    private $patientModel;
    private $appointmentModel;
    private $prescriptionModel;
    private $staffModel;

    public function __construct()
    {
        $database = new Database();
        $db = $database->connect();
        $this->patientModel = new Patient($db);
        $this->appointmentModel = new Appointment($db);
        $this->prescriptionModel = new Prescription($db);
        $this->staffModel = new Staff($db);
    }

    // GET /api/dashboard/stats
    public function getStats()
    {
        AuthMiddleware::handle();
        // Allow all active roles to see dashboard
        RoleMiddleware::handle(['Admin', 'Provider', 'Nurse', 'Pharmacist', 'Receptionist']);

        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];

        try {
            $stats = [
                'patients_total' => $this->patientModel->countByTenant($tenantId),
                'appointments_today' => $this->appointmentModel->countTodayByTenant($tenantId),
                'appointments_upcoming' => $this->appointmentModel->countUpcomingByTenant($tenantId),
                'prescriptions_pending' => $this->prescriptionModel->countPendingByTenant($tenantId)
            ];

            // Only Admins see total staff count
            if ($user['role'] === 'Admin') {
                $stats['staff_total'] = $this->staffModel->countByTenant($tenantId);
            }

            ResponseHelper::send(true, "Dashboard statistics retrieved successfully.", $stats);

        } catch (\Exception $e) {
            ResponseHelper::send(false, "Failed to retrieve statistics: " . $e->getMessage(), [], 500);
        }
    }
}
