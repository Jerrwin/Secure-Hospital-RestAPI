<?php

namespace App\Controllers;

use App\Models\Calendar;
use App\Core\Database;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\MasterTenant;
use App\Helpers\Encryption;
use App\Helpers\ResponseHelper;

class CalendarController
{
    private $calendarModel;
    private $db;
    private $masterTenantModel;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        $this->masterTenantModel = new MasterTenant($masterDb);
        // Initialize with Master DB initially; helper will re-initialize this
        $this->calendarModel = new \App\Models\Calendar($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);
        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        // RE-INITIALIZE Model with the actual Hospital Connection
        $this->calendarModel = new \App\Models\Calendar($this->db);
        return true;
    }

    /* =========================================
       DEFAULT INDEX METHOD
       ========================================= */
    public function index()
    {
        $this->range();
    }

    /* =========================================
       FULL MONTH CALENDAR VIEW
       ========================================= */
    public function range()
    {

        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Receptionist', 'Nurse', 'Provider']);

        $user = $_REQUEST['user'];

        if (!$this->connectByTenantId($user['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $start = $_GET['start'] ?? date('Y-m-01');
        $end   = $_GET['end'] ?? date('Y-m-t');

        $providerId = null;

        if ($user['role'] === 'Provider') {
            $providerId = $user['user_id'];
        }

        $raw = $this->calendarModel->getRangeData(
            $user['tenant_id'],
            $start,
            $end,
            $providerId
        );

        $grouped = [];

        foreach ($raw as $row) {
            $date = $row['appointment_date'];

            if (!empty($row['medical_history'])) {
                $row['medical_history'] = Encryption::decrypt($row['medical_history']);
            }

            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'date' => $date,
                    'total_appointments' => 0,
                    'appointments' => []
                ];
            }

            $grouped[$date]['total_appointments']++;

            // ✅ UPDATED: Include medical_history in range view
            $grouped[$date]['appointments'][] = [
                'time' => date('h:i A', strtotime($row['start_time'])),
                'patient' => $row['first_name'] . ' ' . $row['last_name'],
                'medical_history' => $row['medical_history'], // ✅ Added
                'status' => $row['status'],
                'doctor' => $row['doctor_name']
            ];
        }

        ResponseHelper::send(true, "Calendar month data", array_values($grouped));
    }


    /* =========================================
       DATE CLICK TOOLTIP API
       ========================================= */
    public function getByDate()
    {

        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Receptionist', 'Nurse', 'Provider']);

        $user = $_REQUEST['user'];

        if (!$this->connectByTenantId($user['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $date = $_GET['date'] ?? null;

        if (!$date) {
            echo json_encode(["success" => false, "message" => "date required"]);
            return;
        }

        $providerId = null;

        if ($user['role'] === 'Provider') {
            $providerId = $user['user_id'];
        }

        $rows = $this->calendarModel->getByDate(
            $user['tenant_id'],
            $date,
            $providerId
        );

        if (empty($rows)) {
            echo json_encode([
                "success" => true,
                "message" => "No appointments on this date",
                "data" => [],
                "note" => "No appointments today"
            ]);
            return;
        }

        $result = [];

        foreach ($rows as $row) {
            if (!empty($row['medical_history'])) {
                $row['medical_history'] = Encryption::decrypt($row['medical_history']);
            }
            $result[] = [
                "date" => $row['appointment_date'],
                "time" => date('h:i A', strtotime($row['start_time'])) . " - " .
                    date('h:i A', strtotime($row['end_time'])),
                "status" => $row['status'],
                "patient" => [
                    "full_name" => $row['first_name'] . ' ' . $row['last_name'],
                    "medical_history" => $row['medical_history']
                ],
                "doctor" => [
                    "name" => $row['doctor_name'],
                    "phone" => $row['doctor_phone'],
                    "email" => $row['doctor_email']
                ]
            ];
        }
        ResponseHelper::send(true, "Selected date appointments", $result);
    }
}
