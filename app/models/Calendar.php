<?php

namespace App\Models;

use PDO;
use App\Helpers\Encryption; // ✅ Add Encryption Helper

class Calendar
{
    private $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    /* ===============================
       MONTH RANGE CALENDAR DATA
       =============================== */
    public function getRangeData($tenantId, $start, $end, $providerId = null){

        $query = "SELECT 
                    a.appointment_date,
                    a.start_time,
                    a.end_time,
                    a.status,
                    p.first_name,
                    p.last_name,
                    p.medical_history,
                    s.name as doctor_name,
                    s.phone_number as doctor_phone,
                    u.email as doctor_email,
                    a.provider_id
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN staff s ON a.provider_id = s.user_id
                JOIN users u ON s.user_id = u.id
                WHERE a.tenant_id = :tenant_id
                AND a.appointment_date BETWEEN :start AND :end";

        // doctor login → only their calendar
        if ($providerId) {
            $query .= " AND a.provider_id = :provider_id";
        }

        $query .= " ORDER BY a.start_time ASC";

        $stmt = $this->conn->prepare($query);

        $params = [
            ':tenant_id' => $tenantId,
            ':start' => $start,
            ':end' => $end
        ];

        if ($providerId) {
            $params[':provider_id'] = $providerId;
        }

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Decrypt medical_history for each row
        foreach ($results as &$row) {
            if (!empty($row['medical_history'])) {
                $row['medical_history'] = Encryption::decrypt($row['medical_history']);
            }
        }

        return $results;
    }


    /* ===============================
       SINGLE DATE TOOLTIP DATA
       =============================== */
    public function getByDate($tenantId, $date, $providerId = null){

        $query = "SELECT 
                    a.appointment_date,
                    a.start_time,
                    a.end_time,
                    a.status,
                    p.first_name,
                    p.last_name,
                    p.medical_history,
                    s.name as doctor_name,
                    s.phone_number as doctor_phone,
                    u.email as doctor_email
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN staff s ON a.provider_id = s.user_id
                JOIN users u ON s.user_id = u.id
                WHERE a.tenant_id = :tenant_id
                AND a.appointment_date = :date";

        if($providerId){
            $query .= " AND a.provider_id = :provider_id";
        }

        $query .= " ORDER BY a.start_time ASC";

        $stmt = $this->conn->prepare($query);

        $params = [
            ':tenant_id'=>$tenantId,
            ':date'=>$date
        ];

        if($providerId){
            $params[':provider_id']=$providerId;
        }

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Decrypt medical_history for each row
        foreach ($results as &$row) {
            if (!empty($row['medical_history'])) {
                $row['medical_history'] = Encryption::decrypt($row['medical_history']);
            }
        }

        return $results;
    }
}
