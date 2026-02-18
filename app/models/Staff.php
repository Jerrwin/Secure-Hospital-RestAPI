<?php

namespace App\Models;

use PDO;

class Staff
{
    private $conn;
    private $table = 'staff';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Create new Staff
     */
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (tenant_id, user_id, name, gender, address, phone_number) 
                  VALUES (:tenant_id, :user_id, :name, :gender, :address, :phone_number)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':gender', $data['gender']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':phone_number', $data['phone_number']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Get All Staff (Admin View - By Tenant)
     */
    public function getAllByTenant($tenantId)
    {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id AND deleted_at IS NULL";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Single Staff (By ID)
     */
    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE id = :id AND deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Soft Delete Staff
     */
    public function delete($id)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
