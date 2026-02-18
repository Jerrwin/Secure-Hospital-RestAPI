<?php

namespace App\Models;

use PDO;

class Patient
{
    private $conn;
    private $table = 'patients';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    // Create Patient
    // Note: We removed Encryption here because PatientController already encrypts the data.
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (tenant_id, first_name, last_name, dob, gender, medical_history, created_by, created_at) 
                  VALUES (:tenant_id, :first_name, :last_name, :dob, :gender, :medical_history, :created_by, NOW())";

        $stmt = $this->conn->prepare($query);

        $stmt->execute([
            ':tenant_id' => $data['tenant_id'],
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':dob' => $data['dob'],
            ':gender' => $data['gender'],
            ':medical_history' => $data['medical_history'], // Already Encrypted by Controller
            ':created_by' => $data['created_by']
        ]);

        return $this->conn->lastInsertId();
    }

    // Get All by Tenant
    // Note: We removed Decryption here because PatientController handles it.
    public function getAllByTenant($tenantId)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get Single ID
    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Soft Delete (With Security Check)
    public function softDelete($id, $tenantId = null)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";

        // Security: Ensure we only delete if it belongs to the tenant
        if ($tenantId) {
            $query .= " AND tenant_id = :tenant_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);

        if ($tenantId) {
            $stmt->bindParam(':tenant_id', $tenantId);
        }

        return $stmt->execute();
    }

    // Update Patient (Dynamic SQL)
    public function update($id, $data, $tenantId = null)
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['first_name'])) {
            $fields[] = "first_name = :first_name";
            $params[':first_name'] = $data['first_name'];
        }
        if (isset($data['last_name'])) {
            $fields[] = "last_name = :last_name";
            $params[':last_name'] = $data['last_name'];
        }
        if (isset($data['dob'])) {
            $fields[] = "dob = :dob";
            $params[':dob'] = $data['dob'];
        }
        if (isset($data['gender'])) {
            $fields[] = "gender = :gender";
            $params[':gender'] = $data['gender'];
        }
        if (isset($data['medical_history'])) {
            $fields[] = "medical_history = :medical_history";
            // Important: We assume Controller already encrypted this!
            $params[':medical_history'] = $data['medical_history'];
        }

        if (empty($fields)) {
            return false;
        }

        $query = "UPDATE " . $this->table . " SET " . implode(', ', $fields) . " WHERE id = :id";

        if ($tenantId) {
            $query .= " AND tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }

    // Count (For Dashboard)
    public function countByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
}