<?php

namespace App\Models;

use PDO;
use App\Helpers\Encryption;

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
                  (tenant_id, first_name, last_name, email, password, dob, gender, medical_history, created_by, created_at) 
                  VALUES (:tenant_id, :first_name, :last_name, :email, :password, :dob, :gender, :medical_history, :created_by, NOW())";

        $stmt = $this->conn->prepare($query);

        // Encrypt sensitive data before binding
        $encryptedHistory = Encryption::encrypt($data['medical_history']);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':first_name', $data['first_name']);
        $stmt->bindParam(':last_name', $data['last_name']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':password', $data['password']);
        $stmt->bindParam(':dob', $data['dob']);
        $stmt->bindParam(':gender', $data['gender']);
        $stmt->bindParam(':medical_history', $encryptedHistory);
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
        $query = "SELECT id, tenant_id, first_name, last_name, email, dob, gender, medical_history, created_by, created_at, updated_at FROM " . $this->table . " WHERE tenant_id = :tenant_id AND deleted_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($patients as &$patient) {
            if (!empty($patient['medical_history'])) {
                $patient['medical_history'] = Encryption::decrypt($patient['medical_history']);
            }
        }
        return $patients;
    }

    /**
     * Get Single Patient
     */
    public function getById($id)
    {
        $query = "SELECT id, tenant_id, first_name, last_name, email, dob, gender, medical_history, created_by, created_at, updated_at FROM " . $this->table . " WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($patient && !empty($patient['medical_history'])) {
            $patient['medical_history'] = Encryption::decrypt($patient['medical_history']);
        }
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
        if (isset($data['dob']))
            $fields[] = "dob = :dob";
        if (isset($data['gender']))
            $fields[] = "gender = :gender";
        if (isset($data['email']))
            $fields[] = "email = :email";
        if (isset($data['password']))
            $fields[] = "password = :password";
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
        if (isset($data['dob']))
            $stmt->bindParam(':dob', $data['dob']);
        if (isset($data['gender']))
            $stmt->bindParam(':gender', $data['gender']);
        if (isset($data['email']))
            $stmt->bindParam(':email', $data['email']);
        if (isset($data['password']))
            $stmt->bindParam(':password', $data['password']);

        if (isset($data['medical_history'])) {
            $encrypted = Encryption::encrypt($data['medical_history']);
            $stmt->bindParam(':medical_history', $encrypted);
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