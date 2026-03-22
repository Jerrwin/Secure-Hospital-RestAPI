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
                  (tenant_id, patient_id, provider_id, appointment_date, start_time, end_time, STATUS, created_by) 
                  VALUES (:tenant_id, :patient_id, :provider_id, :appointment_date, :start_time, :end_time, 'scheduled', :created_by)";

        $stmt = $this->conn->prepare($query);

        return $stmt->execute([
            ':tenant_id'        => $data['tenant_id'],
            ':patient_id'       => $data['patient_id'],
            ':provider_id'      => $data['provider_id'],
            ':appointment_date' => $data['appointment_date'],
            ':start_time'       => $data['start_time'],
            ':end_time'         => $data['end_time'],
            ':created_by'       => $data['created_by']
        ]) ? $this->conn->lastInsertId() : false;
    }

    public function update($id, $data)
    {
        $allowedColumns = ['patient_id', 'provider_id', 'appointment_date', 'start_time', 'end_time', 'STATUS'];

        // Normalize any case variation of 'status' to 'STATUS'
        $normalizedData = [];
        foreach ($data as $key => $value) {
            if (strtolower($key) === 'status') {
                $normalizedData['STATUS'] = $value;
            } else {
                $normalizedData[$key] = $value;
            }
        }

        // Filter only allowed columns
        $filteredData = array_intersect_key($normalizedData, array_flip($allowedColumns));

        if (empty($filteredData)) return false;

        $fields = "";
        foreach ($filteredData as $key => $value) {
            $fields .= "$key = :$key, ";
        }
        $fields = rtrim($fields, ", ");

        // Only filter by id — no tenant_id required in $data
        $query = "UPDATE " . $this->table . " SET $fields WHERE id = :id";
        $filteredData['id'] = $id;

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($filteredData);
    }

    public function getAllByTenant($tenantId, $filters = [])
    {
        $query = "SELECT a.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                         u.name as provider_name
                  FROM " . $this->table . " a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  LEFT JOIN users u ON a.provider_id = u.id
                  WHERE a.tenant_id = :tenant_id";

        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['start_date'])) {
            $query .= " AND a.appointment_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND a.appointment_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['patient_id'])) {
            $query .= " AND a.patient_id = :patient_id";
            $params[':patient_id'] = $filters['patient_id'];
        }
        if (!empty($filters['status'])) {
            $query .= " AND a.STATUS = :status";
            $params[':status'] = $filters['status'];
        }

        $query .= " ORDER BY a.appointment_date ASC, a.start_time ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUpcomingByTenant($tenantId, $patientId = null)
    {
        $query = "SELECT a.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                         u.name as provider_name
                  FROM " . $this->table . " a
                  LEFT JOIN patients p ON a.patient_id = p.id
                  LEFT JOIN users u ON a.provider_id = u.id
                  WHERE a.tenant_id = :tenant_id 
                  AND a.STATUS = 'scheduled'
                  AND a.appointment_date >= CURDATE()";

        $params = [':tenant_id' => $tenantId];
        if ($patientId) {
            $query .= " AND a.patient_id = :patient_id";
            $params[':patient_id'] = $patientId;
        }

        $query .= " ORDER BY a.appointment_date ASC, a.start_time ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isSlotBooked($providerId, $date, $startTime, $endTime, $excludeId = null)
    {
        $query = "SELECT id FROM " . $this->table . " 
                  WHERE provider_id = :provider_id 
                  AND appointment_date = :date 
                  AND STATUS != 'cancelled'
                  AND start_time < :end_time 
                  AND end_time > :start_time";

        $params = [
            ':provider_id' => $providerId,
            ':date'        => $date,
            ':start_time'  => $startTime,
            ':end_time'    => $endTime
        ];

        if ($excludeId) {
            $query .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function find($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countTodayByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id 
                  AND appointment_date = CURDATE() 
                  AND STATUS != 'cancelled'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public function countUpcomingByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id 
                  AND appointment_date > CURDATE() 
                  AND STATUS != 'cancelled'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
}