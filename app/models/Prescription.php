<?php

namespace App\Models;

use App\Core\BaseModel;
use PDO;

class Prescription extends BaseModel
{
    protected $table = 'prescriptions';


    /**
     * Create Prescription Header & Items (Transactional)
     */
    public function create($data)
    {
        try {
            $this->db->beginTransaction();

            $query = "INSERT INTO " . $this->table . " 
                      (tenant_id, appointment_id, provider_id, notes, status) 
                      VALUES (:tenant_id, :appointment_id, :provider_id, :notes, 'created')";

            $stmt = $this->db->prepare($query);

            $stmt->bindParam(':tenant_id', $data['tenant_id']);
            $stmt->bindParam(':appointment_id', $data['appointment_id']);
            $stmt->bindParam(':provider_id', $data['provider_id']);
            $encryptedNotes = \App\Helpers\Encryption::encrypt($data['notes'] ?? '');
            $stmt->bindParam(':notes', $encryptedNotes);

            if (!$stmt->execute()) {
                throw new \Exception("Failed to create prescription header.");
            }

            $prescriptionId = $this->db->lastInsertId();

            // Insert Items
            if (!empty($data['items']) && is_array($data['items'])) {
                $itemQuery = "INSERT INTO prescription_items 
                              (prescription_id, medicine_name, dosage, frequency, duration, instruction) 
                              VALUES (:presc_id, :name, :dosage, :freq, :duration, :instr)";

                $itemStmt = $this->db->prepare($itemQuery);

                foreach ($data['items'] as $item) {
                    $itemStmt->execute([
                        ':presc_id' => $prescriptionId,
                        ':name'     => $item['medicine_name'],
                        ':dosage'   => $item['dosage'],
                        ':freq'     => $item['frequency'],
                        ':duration' => $item['duration'],
                        ':instr'    => $item['instruction'] ?? null
                    ]);
                }
            }

            $this->db->commit();
            return $prescriptionId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Prescription Model Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update Status (Verified by Pharmacist)
     */
    public function updateStatus($id, $status)
    {
        $query = "UPDATE " . $this->table . " SET status = :status WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }

    /**
     * Get Paginated Prescriptions with Filters & Search
     */
    public function getAllByTenantPaginated($tenantId, $filters = [])
    {
        $sql = "SELECT p.*, 
                       CONCAT(pt.first_name, ' ', pt.last_name) as patient_name, 
                       u.name as provider_name 
                FROM " . $this->table . " p
                JOIN appointments a ON p.appointment_id = a.id
                JOIN patients pt ON a.patient_id = pt.id
                JOIN users u ON p.provider_id = u.id
                WHERE p.tenant_id = :tenant_id";

        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['status'])) {
            $sql .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['patient_id'])) {
            $sql .= " AND a.patient_id = :patient_id";
            $params[':patient_id'] = $filters['patient_id'];
        }

        if (!empty($filters['provider_id'])) {
            $sql .= " AND p.provider_id = :provider_id";
            $params[':provider_id'] = $filters['provider_id'];
        }


        $searchColumns = [
            "CONCAT(pt.first_name, ' ', pt.last_name)",
            "u.name",
            "p.notes"
        ];

        $paginatedResult = $this->fetchPaginated($sql, $params, $filters, $searchColumns, "p.created_at DESC", "p.id");


        // Decrypt notes and fetch items for each prescription in the paginated result
        foreach ($paginatedResult['data'] as &$p) {
            if (!empty($p['notes'])) {
                $p['notes'] = \App\Helpers\Encryption::decrypt($p['notes']);
            }
            $p['items'] = $this->getItems($p['id']);
        }

        return $paginatedResult;
    }

    /**
     * Legacy getter (non-paginated)
     */
    public function getAllByTenant($tenantId, $patientId = null)
    {
        $query = "SELECT p.*, a.appointment_date, a.start_time, 
                         CONCAT(pt.first_name, ' ', pt.last_name) as patient_name,
                         u.name as provider_name
                  FROM " . $this->table . " p
                  JOIN appointments a ON p.appointment_id = a.id
                  JOIN patients pt ON a.patient_id = pt.id
                  JOIN users u ON p.provider_id = u.id
                  WHERE p.tenant_id = :tenant_id";

        $params = [':tenant_id' => $tenantId];
        if ($patientId) {
            $query .= " AND a.patient_id = :patient_id";
            $params[':patient_id'] = $patientId;
        }

        $query .= " ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch items for each prescription (Decoupled to keep queries simpler)
        foreach ($prescriptions as &$p) {
            // Decrypt notes
            if (!empty($p['notes'])) {
                $p['notes'] = \App\Helpers\Encryption::decrypt($p['notes']);
            }
            $itemQuery = "SELECT * FROM prescription_items WHERE prescription_id = :id";
            $itemStmt = $this->db->prepare($itemQuery);
            $itemStmt->execute([':id' => $p['id']]);
            $p['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $prescriptions;
    }

    /**
     * Update Prescription (Header & Items)
     */
    public function update($id, $data)
    {
        try {
            $this->db->beginTransaction();

            // 1. Update Header (Notes)
            if (isset($data['notes'])) {
                $query = "UPDATE " . $this->table . " SET notes = :notes WHERE id = :id";
                $stmt = $this->db->prepare($query);
                $encryptedNotes = \App\Helpers\Encryption::encrypt($data['notes']);
                $stmt->bindParam(':notes', $encryptedNotes);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
            }

            // 2. Update Items (Replace All)
            if (isset($data['items']) && is_array($data['items'])) {
                // Delete existing items
                $delQuery = "DELETE FROM prescription_items WHERE prescription_id = :id";
                $delStmt = $this->db->prepare($delQuery);
                $delStmt->execute([':id' => $id]);

                // Insert new items
                $insQuery = "INSERT INTO prescription_items 
                              (prescription_id, medicine_name, dosage, frequency, duration, instruction) 
                              VALUES (:presc_id, :name, :dosage, :freq, :duration, :instr)";
                $insStmt = $this->db->prepare($insQuery);

                foreach ($data['items'] as $item) {
                    $insStmt->execute([
                        ':presc_id' => $id,
                        ':name'     => $item['medicine_name'],
                        ':dosage'   => $item['dosage'],
                        ':freq'     => $item['frequency'],
                        ':duration' => $item['duration'],
                        ':instr'    => $item['instruction'] ?? null
                    ]);
                }
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Prescription Update Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if prescription already exists for appointment
     */
    public function existsForAppointment($appointmentId)
    {
        $query = "SELECT id FROM " . $this->table . " WHERE appointment_id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
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
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $appointmentId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get Prescription Items
     */
    public function getItems($prescriptionId)
    {
        $query = "SELECT * FROM prescription_items WHERE prescription_id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $prescriptionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Single Prescription Detail (Including Items)
     */
    public function getById($id)
    {
        $query = "SELECT p.*, 
                         CONCAT(pt.first_name, ' ', pt.last_name) as patient_name, 
                         u.name as provider_name 
                   FROM " . $this->table . " p
                   JOIN appointments a ON p.appointment_id = a.id
                   JOIN patients pt ON a.patient_id = pt.id
                   JOIN users u ON p.provider_id = u.id
                   WHERE p.id = :id LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($p) {
            // Decrypt notes
            if (!empty($p['notes'])) {
                $p['notes'] = \App\Helpers\Encryption::decrypt($p['notes']);
            }

            // Fetch Items
            $p['items'] = $this->getItems($id);
        }

        return $p;
    }

    /**
     * Delete Prescription (Transactional via ON DELETE CASCADE)
     */
    public function delete($id)
    {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function countPendingByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id AND status != 'verified' AND status != 'dispensed'";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
}
