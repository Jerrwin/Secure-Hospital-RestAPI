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

    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
              (tenant_id, first_name, last_name, dob, gender, medical_history, created_by) 
              VALUES (:tenant_id, :first_name, :last_name, :dob, :gender, :medical_history, :created_by)";

        $stmt = $this->conn->prepare($query);

        if (
            $stmt->execute([
                ':tenant_id' => $data['tenant_id'],
                ':first_name' => $data['first_name'],
                ':last_name' => $data['last_name'],
                ':dob' => $data['dob'] ?? null,
                ':gender' => $data['gender'] ?? null,
                ':medical_history' => $data['medical_history'],
                ':created_by' => $data['created_by']
            ])
        ) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function getAllByTenant($tenantId)
    {
        // Selecting specific columns is faster than SELECT *
        $query = "SELECT id, first_name, last_name, dob, gender, created_at 
              FROM " . $this->table . " 
              WHERE tenant_id = :tenant_id AND deleted_at IS NULL";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function softDelete($id)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
