<?php

namespace App\Controllers;

use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\Staff;
use App\Models\Billing;
use App\Models\Notification;
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
    private $billingModel;
    private $notificationModel;
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
        $this->billingModel = new Billing($this->db);
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
        $this->billingModel = new Billing($this->db);
        $this->notificationModel = new Notification($this->db);

        return true;
    }

    // GET /api/dashboard/stats
    public function getStats()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Provider', 'Nurse', 'Pharmacist', 'Receptionist', 'Patient']);

        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];
        $role = $user['role'];
        $userId = $user['user_id'];

        if (!$this->connectByTenantId($tenantId)) {
            ResponseHelper::send(false, "Hospital database not found or inactive.", [], 403);
            return;
        }

        try {
            $result = [];

            switch ($role) {
                case 'Admin':
                    $result = $this->getAdminDashboard($tenantId);
                    break;
                case 'Provider':
                    $result = $this->getProviderDashboard($tenantId, $userId);
                    break;
                case 'Receptionist':
                    $result = $this->getReceptionistDashboard($tenantId);
                    break;
                case 'Pharmacist':
                    $result = $this->getPharmacistDashboard($tenantId);
                    break;
                case 'Nurse':
                    $result = $this->getNurseDashboard($tenantId);
                    break;
                case 'Patient':
                    $result = $this->getPatientDashboard($tenantId, $userId);
                    break;
                default:
                    $result['stats'] = [];
                    break;
            }

            ResponseHelper::send(true, "Dashboard statistics retrieved successfully.", $result);
        } catch (\Exception $e) {
            ResponseHelper::send(false, "Failed to retrieve statistics: " . $e->getMessage(), [], 500);
        }
    }

    // ─── ADMIN ──────────────────────────────────────────────
    private function getAdminDashboard($tenantId)
    {
        $totalPatients = $this->patientModel->countByTenant($tenantId);
        $totalStaff = $this->staffModel->countByTenant($tenantId);
        $appointmentsToday = $this->appointmentModel->countTodayByTenant($tenantId);
        $prescriptionsPending = $this->prescriptionModel->countPendingByTenant($tenantId);

        // Revenue stats
        $totalRevenue = $this->getTotalRevenue($tenantId);
        $pendingInvoices = $this->countInvoicesByStatus($tenantId, 'pending');
        $paidInvoices = $this->countInvoicesByStatus($tenantId, 'paid');

        // 7-day appointment trend
        $weeklyTrend = $this->getWeeklyAppointmentTrend($tenantId);

        // Recent appointments (today)
        $todaysAppointments = $this->getTodaysAppointments($tenantId);

        return [
            'stats' => [
                'patients_total' => $totalPatients,
                'staff_total' => $totalStaff,
                'appointments_today' => $appointmentsToday,
                'prescriptions_pending' => $prescriptionsPending,
                'total_revenue' => $totalRevenue,
                'pending_invoices' => $pendingInvoices,
                'paid_invoices' => $paidInvoices,
            ],
            'weekly_trend' => $weeklyTrend,
            'appointments' => $todaysAppointments,
        ];
    }

    // ─── PROVIDER (Doctor) ──────────────────────────────────
    private function getProviderDashboard($tenantId, $providerId)
    {
        // My appointments today
        $myTodayCount = $this->countProviderTodayAppointments($tenantId, $providerId);
        $myTodayAppointments = $this->getProviderTodayAppointments($tenantId, $providerId);
        $prescriptionsPending = $this->prescriptionModel->countPendingByTenant($tenantId);
        $totalPatients = $this->patientModel->countByTenant($tenantId);

        // Unread notifications
        $unreadNotifs = $this->notificationModel->getUnreadCount($providerId, 'staff', $tenantId);

        return [
            'stats' => [
                'my_appointments_today' => $myTodayCount,
                'prescriptions_pending' => $prescriptionsPending,
                'patients_total' => $totalPatients,
                'unread_notifications' => $unreadNotifs,
            ],
            'appointments' => $myTodayAppointments,
        ];
    }

    // ─── RECEPTIONIST ───────────────────────────────────────
    private function getReceptionistDashboard($tenantId)
    {
        $appointmentsToday = $this->appointmentModel->countTodayByTenant($tenantId);
        $totalPatients = $this->patientModel->countByTenant($tenantId);
        $pendingInvoices = $this->countInvoicesByStatus($tenantId, 'pending');

        // Today's full schedule
        $todaysAppointments = $this->getTodaysAppointments($tenantId);

        return [
            'stats' => [
                'appointments_today' => $appointmentsToday,
                'patients_total' => $totalPatients,
                'pending_invoices' => $pendingInvoices,
            ],
            'appointments' => $todaysAppointments,
        ];
    }

    // ─── PHARMACIST ─────────────────────────────────────────
    private function getPharmacistDashboard($tenantId)
    {
        $newPrescriptions = $this->countPrescriptionsByStatus($tenantId, 'created');
        $verifiedPrescriptions = $this->countPrescriptionsByStatus($tenantId, 'verified');
        $dispensedToday = $this->countPrescriptionsByStatus($tenantId, 'dispensed');

        // Recent pending prescriptions for the queue
        $prescriptionQueue = $this->getRecentPrescriptions($tenantId, 10);

        return [
            'stats' => [
                'new_prescriptions' => $newPrescriptions,
                'verified_prescriptions' => $verifiedPrescriptions,
                'dispensed_today' => $dispensedToday,
            ],
            'prescriptions' => $prescriptionQueue,
        ];
    }

    // ─── NURSE ──────────────────────────────────────────────
    private function getNurseDashboard($tenantId)
    {
        $appointmentsToday = $this->appointmentModel->countTodayByTenant($tenantId);
        $totalPatients = $this->patientModel->countByTenant($tenantId);

        $todaysAppointments = $this->getTodaysAppointments($tenantId);

        return [
            'stats' => [
                'appointments_today' => $appointmentsToday,
                'patients_total' => $totalPatients,
            ],
            'appointments' => $todaysAppointments,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  HELPER QUERIES
    // ═══════════════════════════════════════════════════════════

    private function getTotalRevenue($tenantId)
    {
        $query = "SELECT COALESCE(SUM(p.amount), 0) as total 
                  FROM payments p 
                  JOIN invoices i ON p.invoice_id = i.id 
                  WHERE i.tenant_id = :tenant_id AND p.STATUS = 'success'";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return (float) $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }

    private function countInvoicesByStatus($tenantId, $status)
    {
        $query = "SELECT COUNT(*) as total FROM invoices WHERE tenant_id = :tenant_id AND STATUS = :status";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId, ':status' => $status]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }

    private function getWeeklyAppointmentTrend($tenantId)
    {
        $query = "SELECT DATE(appointment_date) as day, COUNT(*) as count 
                  FROM appointments 
                  WHERE tenant_id = :tenant_id 
                  AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                  AND appointment_date <= CURDATE()
                  AND STATUS != 'cancelled'
                  GROUP BY DATE(appointment_date) 
                  ORDER BY day ASC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fill in missing days with 0
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime($day));
            $found = 0;
            foreach ($rows as $row) {
                if ($row['day'] === $day) {
                    $found = (int) $row['count'];
                    break;
                }
            }
            $trend[] = ['day' => $dayName, 'date' => $day, 'count' => $found];
        }
        return $trend;
    }

    private function getTodaysAppointments($tenantId)
    {
        $query = "SELECT a.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                         u.name as provider_name
                  FROM appointments a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  LEFT JOIN users u ON a.provider_id = u.id
                  WHERE a.tenant_id = :tenant_id 
                  AND a.appointment_date = CURDATE()
                  ORDER BY a.start_time ASC
                  LIMIT 15";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function countProviderTodayAppointments($tenantId, $providerId)
    {
        $query = "SELECT COUNT(*) as total FROM appointments 
                  WHERE tenant_id = :tenant_id 
                  AND provider_id = :provider_id 
                  AND appointment_date = CURDATE() 
                  AND STATUS != 'cancelled'";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId, ':provider_id' => $providerId]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }

    private function getProviderTodayAppointments($tenantId, $providerId)
    {
        $query = "SELECT a.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as patient_name
                  FROM appointments a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  WHERE a.tenant_id = :tenant_id 
                  AND a.provider_id = :provider_id 
                  AND a.appointment_date = CURDATE()
                  ORDER BY a.start_time ASC";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId, ':provider_id' => $providerId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function countPrescriptionsByStatus($tenantId, $status)
    {
        $query = "SELECT COUNT(*) as total FROM prescriptions WHERE tenant_id = :tenant_id AND status = :status";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId, ':status' => $status]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['total'];
    }

    private function getRecentPrescriptions($tenantId, $limit = 10)
    {
        $query = "SELECT pr.*, 
                         CONCAT(pt.first_name, ' ', pt.last_name) as patient_name,
                         u.name as provider_name
                  FROM prescriptions pr
                  JOIN appointments a ON pr.appointment_id = a.id
                  JOIN patients pt ON a.patient_id = pt.id
                  JOIN users u ON pr.provider_id = u.id
                  WHERE pr.tenant_id = :tenant_id
                  ORDER BY pr.created_at DESC
                  LIMIT $limit";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── PATIENT ───────────────────────────────────────────
    private function getPatientDashboard($tenantId, $patientId)
    {
        // 1. Upcoming Appointments
        $upcoming = $this->appointmentModel->getUpcomingByTenant($tenantId, $patientId);
        $upcomingCount = count($upcoming);

        // 2. Active Prescriptions
        $prescriptions = $this->prescriptionModel->getAllByTenant($tenantId, $patientId);
        $activeCount = 0;
        foreach ($prescriptions as $p) {
            $status = strtolower($p['status'] ?? $p['STATUS'] ?? '');
            if ($status !== 'dispensed' && !empty($status)) {
                $activeCount++;
            }
        }

        // 3. Pending Invoices
        $query = "SELECT COUNT(*) as total FROM invoices WHERE tenant_id = :tenant_id AND patient_id = :patient_id AND STATUS = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId, ':patient_id' => $patientId]);
        $unpaidCount = (int) $stmt->fetch(\PDO::FETCH_ASSOC)['total'];

        return [
            'stats' => [
                'upcoming_appointments' => $upcomingCount,
                'active_prescriptions' => $activeCount,
                'unpaid_invoices' => $unpaidCount,
            ],
            'appointments' => $upcoming,
            'prescriptions' => array_slice($prescriptions, 0, 5) // Last 5 prescriptions
        ];
    }
}
