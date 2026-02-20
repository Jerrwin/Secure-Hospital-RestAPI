<?php

namespace App\Controllers;

use App\Models\Calendar;
use App\Core\Database;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class CalendarController
{
    private $calendarModel;

    public function __construct(){
        $db = (new Database())->connect();
        $this->calendarModel = new Calendar($db);
    }

    /* =========================================
       DEFAULT INDEX METHOD
       ========================================= */
    public function index(){
        $this->range();
    }

    /* =========================================
       FULL MONTH CALENDAR VIEW
       ========================================= */
    public function range(){

        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin','Receptionist','Nurse','Provider']);

        $user = $_REQUEST['user'];

        $start = $_GET['start'] ?? date('Y-m-01');
        $end   = $_GET['end'] ?? date('Y-m-t');

        $providerId = null;

        if($user['role'] === 'Provider'){
            $providerId = $user['user_id'];
        }

        $raw = $this->calendarModel->getRangeData(
            $user['tenant_id'],
            $start,
            $end,
            $providerId
        );

        $grouped = [];

        foreach($raw as $row){
            $date = $row['appointment_date'];

            if(!isset($grouped[$date])){
                $grouped[$date] = [
                    'date'=>$date,
                    'total_appointments'=>0,
                    'appointments'=>[]
                ];
            }

            $grouped[$date]['total_appointments']++;

            // ✅ UPDATED: Include medical_history in range view
            $grouped[$date]['appointments'][] = [
                'time'=>date('h:i A',strtotime($row['start_time'])),
                'patient'=>$row['first_name'].' '.$row['last_name'],
                'medical_history'=>$row['medical_history'], // ✅ Added
                'status'=>$row['status'],
                'doctor'=>$row['doctor_name']
            ];
        }

        echo json_encode([
            "success"=>true,
            "message"=>"Calendar month data",
            "data"=>array_values($grouped)
        ]);
    }


    /* =========================================
       DATE CLICK TOOLTIP API
       ========================================= */
    public function getByDate(){

        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin','Receptionist','Nurse','Provider']);

        $user = $_REQUEST['user'];

        $date = $_GET['date'] ?? null;

        if(!$date){
            echo json_encode(["success"=>false,"message"=>"date required"]);
            return;
        }

        $providerId = null;

        if($user['role'] === 'Provider'){
            $providerId = $user['user_id'];
        }

        $rows = $this->calendarModel->getByDate(
            $user['tenant_id'],
            $date,
            $providerId
        );

        if (empty($rows)) {
            echo json_encode([
                "success"=>true,
                "message"=>"No appointments on this date",
                "data"=>[],
                "note"=>"No appointments today"
            ]);
            return;
        }

        $result = [];

        foreach($rows as $row){
            $result[] = [
                "date"=>$row['appointment_date'],
                "time"=>date('h:i A',strtotime($row['start_time']))." - ".
                        date('h:i A',strtotime($row['end_time'])),
                "status"=>$row['status'],
                "patient"=>[
                    "full_name"=>$row['first_name'].' '.$row['last_name'],
                    "medical_history"=>$row['medical_history']
                ],
                "doctor"=>[
                    "name"=>$row['doctor_name'],
                    "phone"=>$row['doctor_phone'],
                    "email"=>$row['doctor_email']
                ]
            ];
        }

        echo json_encode([
            "success"=>true,
            "message"=>"Selected date appointments",
            "data"=>$result
        ]);
    }
}
