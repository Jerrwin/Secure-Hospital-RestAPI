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

        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':phone', $data['phone']);
        $stmt->bindParam(':address', $data['address']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    /**
     * Get all tenants (For Super Admin dashboard)
     */
    public function getAll()
    {
        $query = "SELECT * FROM " . $this->table . " ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}