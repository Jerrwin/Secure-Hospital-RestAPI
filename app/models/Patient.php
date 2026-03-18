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

    /**
     * Create Patient
     * Automatically handles encryption of medical history.
     */
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (tenant_id, first_name, last_name, email, phone_number, password, dob, gender, blood_group, status, address, medical_history, created_by, created_at) 
                  VALUES (:tenant_id, :first_name, :last_name, :email, :phone_number, :password, :dob, :gender, :blood_group, :status, :address, :medical_history, :created_by, NOW())";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':first_name', $data['first_name']);
        $stmt->bindParam(':last_name', $data['last_name']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':phone_number', $data['phone_number']);
        $stmt->bindParam(':password', $data['password']);
        $stmt->bindParam(':dob', $data['dob']);
        $stmt->bindParam(':gender', $data['gender']);
        $stmt->bindParam(':blood_group', $data['blood_group']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':medical_history', $data['medical_history']);
        $stmt->bindParam(':created_by', $data['created_by']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Get All by Tenant
     * Automatically decrypts history for display.
     */
    public function getAllByTenant($tenantId)
    {
        $query = "SELECT id, tenant_id, first_name, last_name, email, phone_number, dob, gender, blood_group, status, address, medical_history, created_by, created_at, updated_at FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $patients;
    }

    /**
     * Get Single Patient
     */
    public function getById($id)
    {
        $query = "SELECT id, tenant_id, first_name, last_name, email, phone_number, dob, gender, blood_group, status, address, medical_history, created_by, created_at, updated_at FROM " . $this->table . " WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        return $patient;
    }

    /**
     * Soft Delete (Tenant Scoped)
     */
    public function softDelete($id, $tenantId = null)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";
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

    /**
     * Update Patient
     * Dynamically updates fields and encrypts medical history if provided.
     */
    public function update($id, $data, $tenantId = null)
    {
        $fields = [];
        if (isset($data['first_name']))
            $fields[] = "first_name = :first_name";
        if (isset($data['last_name']))
            $fields[] = "last_name = :last_name";
        if (isset($data['email']))
            $fields[] = "email = :email";
        if (isset($data['phone_number']))
            $fields[] = "phone_number = :phone_number";
        if (isset($data['password']))
            $fields[] = "password = :password";
        if (isset($data['dob']))
            $fields[] = "dob = :dob";
        if (isset($data['gender']))
            $fields[] = "gender = :gender";
        if (isset($data['blood_group']))
            $fields[] = "blood_group = :blood_group";
        if (isset($data['status']))
            $fields[] = "status = :status";
        if (isset($data['address']))
            $fields[] = "address = :address";
        if (isset($data['medical_history']))
            $fields[] = "medical_history = :medical_history";

        if (empty($fields)) {
            return false;
        }

        $query = "UPDATE " . $this->table . " SET " . implode(', ', $fields) . " WHERE id = :id";
        if ($tenantId) {
            $query .= " AND tenant_id = :tenant_id";
        }

        $stmt = $this->conn->prepare($query);

        if (isset($data['first_name']))
            $stmt->bindParam(':first_name', $data['first_name']);
        if (isset($data['last_name']))
            $stmt->bindParam(':last_name', $data['last_name']);
        if (isset($data['email']))
            $stmt->bindParam(':email', $data['email']);
        if (isset($data['phone_number']))
            $stmt->bindParam(':phone_number', $data['phone_number']);
        if (isset($data['password']))
            $stmt->bindParam(':password', $data['password']);
        if (isset($data['dob']))
            $stmt->bindParam(':dob', $data['dob']);
        if (isset($data['gender']))
            $stmt->bindParam(':gender', $data['gender']);
        if (isset($data['blood_group']))
            $stmt->bindParam(':blood_group', $data['blood_group']);
        if (isset($data['status']))
            $stmt->bindParam(':status', $data['status']);
        if (isset($data['address']))
            $stmt->bindParam(':address', $data['address']);
        if (isset($data['medical_history'])) {
            $stmt->bindParam(':medical_history', $data['medical_history']);
        }

        $stmt->bindParam(':id', $id);
        if ($tenantId) {
            $stmt->bindParam(':tenant_id', $tenantId);
        }

        return $stmt->execute();
    }

    /**
     * For Dashboard
     */
    public function countByTenant($tenantId)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }

    public function findByEmail($email)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE email = :email AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}