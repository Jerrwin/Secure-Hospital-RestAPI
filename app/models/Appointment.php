<?php

namespace App\Models;

use PDO;

class Appointment
{
    private $conn;
    private $table = 'appointments';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
              (tenant_id, patient_id, provider_id, created_by, appointment_date, start_time, end_time, STATUS) 
              VALUES (:tenant_id, :patient_id, :provider_id, :created_by, :appointment_date, :start_time, :end_time, :status)";

        $stmt = $this->conn->prepare($query);

        $success = $stmt->execute([
            ':tenant_id' => $data['tenant_id'],
            ':patient_id' => $data['patient_id'],
            ':provider_id' => $data['provider_id'],
            ':created_by' => $data['created_by'],
            ':appointment_date' => $data['appointment_date'],
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':status' => $data['status'] ?? 'scheduled'
        ]);

        return $success ? $this->conn->lastInsertId() : false;
    }

    public function update($id, $data)
    {
        // 1. Filter allowed columns
        $allowedColumns = ['patient_id', 'provider_id', 'appointment_date', 'start_time', 'end_time', 'STATUS'];
        $filteredData = array_intersect_key($data, array_flip($allowedColumns));

        // 🎯 FIX: Stop if no valid fields provided
        if (empty($filteredData)) {
            return false;
        }

        $fields = "";
        foreach ($filteredData as $key => $value) {
            $fields .= "$key = :$key, ";
        }
        $fields = rtrim($fields, ", ");

        $query = "UPDATE " . $this->table . " SET $fields WHERE id = :id";

        // Add the ID to the parameters array for binding
        $filteredData['id'] = $id;

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($filteredData);
    }

    public function find($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByTenant($tenantId)
    {
        // 🎯 FIX: Concatenate first and last name
        $query = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                  FROM " . $this->table . " a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  WHERE a.tenant_id = :tenant_id 
                  ORDER BY a.appointment_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUpcomingByTenant($tenantId)
    {
        // 🎯 FIX: Concatenate first and last name
        $query = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                  FROM " . $this->table . " a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  WHERE a.tenant_id = :tenant_id 
                  AND a.STATUS = 'scheduled' 
                  AND a.appointment_date >= CURDATE()
                  ORDER BY a.appointment_date ASC, a.start_time ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isSlotBooked($provider_id, $date, $start, $end, $exclude_id = null)
    {
        $query = "SELECT id FROM " . $this->table . " 
                  WHERE provider_id = :provider_id 
                  AND appointment_date = :date 
                  AND STATUS != 'cancelled' 
                  AND (start_time < :end AND end_time > :start)";

        $params = [':provider_id' => $provider_id, ':date' => $date, ':start' => $start, ':end' => $end];

        if ($exclude_id) {
            $query .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch() ? true : false;
    }
}