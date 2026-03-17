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
    private $masterTenantModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        $this->masterTenantModel = new \App\Models\MasterTenant($masterDb);

        // Initialize models with masterDb as a fallback
        $this->patientModel = new Patient($this->db);
        $this->appointmentModel = new Appointment($this->db);
        $this->prescriptionModel = new Prescription($this->db);
        $this->staffModel = new Staff($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);
        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // RE-INITIALIZE all models with the actual Hospital Connection
        $this->patientModel = new Patient($this->db);
        $this->appointmentModel = new Appointment($this->db);
        $this->prescriptionModel = new Prescription($this->db);
        $this->staffModel = new Staff($this->db);

        return true;
    }

    // GET /api/dashboard/stats
    public function getStats()
    {
        AuthMiddleware::handle();
        // Allow all active roles to see dashboard
        RoleMiddleware::handle(['Admin', 'Provider', 'Nurse', 'Pharmacist', 'Receptionist']);

        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];

        if (!$this->connectByTenantId($tenantId)) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

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
