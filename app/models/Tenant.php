<?php

namespace App\Models;

use PDO;

class Tenant
{
    private $conn;
    private $table = 'tenants';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Create a new Hospital/Tenant
     * Matches your schema: NAME, email, phone, address, STATUS
     */
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (NAME, email, phone, address, STATUS) 
                  VALUES (:name, :email, :phone, :address, 'active')";

        $stmt = $this->conn->prepare($query);

        $name = $data['name'] ?? $data['NAME'] ?? null;
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $address = $data['address'] ?? null;

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':address', $address);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Check if a tenant already exists by email or phone.
     */
    public function checkDuplicates($email, $phone)
    {
        $query = "SELECT email, phone FROM " . $this->table . " 
                  WHERE (email = :email OR phone = :phone) 
                  AND STATUS != 'deleted' LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':email' => $email,
            ':phone' => $phone
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all tenants (For Super Admin dashboard)
     */
    public function getAll()
    {
        $query = "SELECT * FROM " . $this->table . " WHERE STATUS != 'deleted' ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a tenant by ID
     */
    public function find($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id AND STATUS != 'deleted' LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update Tenant Info
     */
    public function update($id, $data)
    {
        $allowedColumns = ['NAME', 'email', 'phone', 'address', 'STATUS'];
        $filteredData = array_intersect_key($data, array_flip($allowedColumns));

        if (empty($filteredData))
            return false;

        $fields = "";
        foreach ($filteredData as $key => $value) {
            $fields .= "$key = :$key, ";
        }
        $fields = rtrim($fields, ", ");

        $query = "UPDATE " . $this->table . " SET $fields WHERE id = :id";
        $filteredData['id'] = $id;

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($filteredData);
    }

    /**
     * Soft Delete
     */
    public function softDelete($id)
    {
        $query = "UPDATE " . $this->table . " SET STATUS = 'deleted', deleted_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
}