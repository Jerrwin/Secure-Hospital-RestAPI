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
                  (tenant_id, name, medical_history, phone_hash, created_at) 
                  VALUES (:tenant_id, :name, :medical_history, :phone_hash, NOW())";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':medical_history', $data['medical_history']); // Encrypted
        $stmt->bindParam(':phone_hash', $data['phone_hash']); // Blind Index

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function getAllByTenant($tenantId)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
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
