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
     * Create new Staff Profile
     */
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (tenant_id, user_id, name, gender, address, phone_number, status) 
                  VALUES (:tenant_id, :user_id, :name, :gender, :address, :phone_number, :status)";

        $stmt = $this->conn->prepare($query);

        $status = $data['status'] ?? 'active';

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':gender', $data['gender']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':phone_number', $data['phone_number']);
        $stmt->bindParam(':status', $status);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Update Staff Profile
     */
    public function update($id, $data)
    {
        $query = "UPDATE " . $this->table . " SET 
                  name = :name,
                  gender = :gender,
                  address = :address,
                  phone_number = :phone_number,
                  status = :status,
                  updated_at = NOW()
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':gender', $data['gender']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':phone_number', $data['phone_number']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }

    /**
     * Get All Staff (Admin View - By Tenant)
     * [MERGE] Joins with users and roles to provide full details
     */
    public function getAllByTenant($tenantId)
    {
        $query = "SELECT s.*, u.email, r.name as role_name 
                  FROM " . $this->table . " s
                  JOIN users u ON s.user_id = u.id
                  JOIN roles r ON u.role_id = r.id
                  WHERE s.tenant_id = :tenant_id AND s.deleted_at IS NULL";

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

    /**
     * Count Staff (For Dashboard)
     */
    public function countByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " 
                  WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
}