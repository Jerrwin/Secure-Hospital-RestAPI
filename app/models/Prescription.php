<?php

namespace App\Models;

use PDO;

class Prescription
{
    private $conn;
    private $table = 'prescriptions';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Create Prescription
     */
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (tenant_id, appointment_id, provider_id, notes, status) 
                  VALUES (:tenant_id, :appointment_id, :provider_id, :notes, 'created')";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':appointment_id', $data['appointment_id']);
        $stmt->bindParam(':provider_id', $data['provider_id']);
        $encryptedNotes = \App\Helpers\Encryption::encrypt($data['notes']);
        $stmt->bindParam(':notes', $encryptedNotes);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Update Status (Verified by Pharmacist)
     */
    public function updateStatus($id, $status)
    {
        $query = "UPDATE " . $this->table . " SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }

    /**
     * Get All Prescriptions by Tenant
     */
    public function getAllByTenant($tenantId)
    {
        $query = "SELECT p.*, 
                         CONCAT(pt.first_name, ' ', pt.last_name) as patient_name, 
                         u.name as provider_name 
                  FROM " . $this->table . " p
                  JOIN appointments a ON p.appointment_id = a.id
                  JOIN patients pt ON a.patient_id = pt.id
                  JOIN users u ON p.provider_id = u.id
                  WHERE p.tenant_id = :tenant_id
                  ORDER BY p.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prescriptions as &$p) {
            if (!empty($p['notes'])) {
                $p['notes'] = \App\Helpers\Encryption::decrypt($p['notes']);
            }
        }
        return $prescriptions;
    }
    
    /**
     * Check if prescription already exists for appointment
     */
    public function existsForAppointment($appointmentId)
    {
        $query = "SELECT id FROM " . $this->table . " WHERE appointment_id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $appointmentId);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Get Appointment Details (to verify status before creating)
     */
    public function getAppointmentDetails($appointmentId)
    {
        $query = "SELECT * FROM appointments WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $appointmentId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countPendingByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id AND status != 'verified' AND status != 'dispensed'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
}
