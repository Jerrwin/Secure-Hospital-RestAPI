<?php

namespace App\Models;

use PDO;

class User
{
    private $conn;
    private $table = 'users';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    // --- AUTHENTICATION FUNCTIONS ---

    public function findAnyUserByEmail($email)
    {
        // 1. Check System Admins
        // Added '999' as role_id for SuperAdmin to prevent errors in AuthController
        $stmt = $this->conn->prepare("SELECT id, 'system_admin' as type, NAME as name, email, PASSWORD as password, 'SuperAdmin' as role_name, 999 as role_id, NULL as tenant_id FROM system_admins WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            return $res;

        // 2. Check Users (Tenant Admins / Standard Users)
        // Added role_id to select list so AuthController doesn't crash
        $query = "SELECT u.id, 'users' as type, u.tenant_id, u.role_id, u.NAME as name, u.email, u.PASSWORD as password, 
                      r.NAME as role_name 
                  FROM users u 
                  LEFT JOIN roles r ON u.role_id = r.id 
                  WHERE u.email = :e LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':e' => $email]);

        if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            return $res;

        return false;
    }

    /**
     * Polymorphic User Lookup
     * Used during Token Refresh to get fresh data from the correct table.
     */
    public function getUserByType($id, $type)
    {
        if ($type === 'system_admin') {
            $query = "SELECT id, NAME as name, email, NULL as tenant_id, 'SuperAdmin' as role_name, 999 as role_id 
                      FROM system_admins WHERE id = :id LIMIT 1";
        } elseif ($type === 'staff') {
            // [MERGE] Added Staff Support from Praveen's code
            $query = "SELECT id, name, email, tenant_id, 'Staff' as role_name 
                      FROM staff WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        } else {
            // Default to users table
            $query = "SELECT u.id, u.NAME as name, u.email, u.tenant_id, u.role_id,
                           r.NAME as role_name 
                      FROM users u 
                      LEFT JOIN roles r ON u.role_id = r.id 
                      WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Store Refresh Token (Hashed)
     */
    public function storeRefreshToken($userId, $userType, $token, $expiry)
    {
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);

        $query = "INSERT INTO refresh_tokens (user_id, user_type, token, expiry_date) 
                  VALUES (:user_id, :user_type, :token, :expiry)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        $stmt->bindParam(':token', $tokenHash);
        $stmt->bindParam(':expiry', $expiry);

        return $stmt->execute();
    }

    /**
     * Validate Refresh Token
     */
    public function verifyRefreshToken($userId, $userType, $incomingRawToken)
    {
        $query = "SELECT * FROM refresh_tokens WHERE user_id = :user_id AND user_type = :user_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        $stmt->execute();

        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$sessions) {
            return "NO_DATA_FOUND";
        }

        $mismatchOccurred = false;

        foreach ($sessions as $row) {
            if (password_verify($incomingRawToken, $row['token'])) {
                if (strtotime($row['expiry_date']) < time()) {
                    return "EXPIRED";
                }
                return $row;
            }
            $mismatchOccurred = true;
        }

        return $mismatchOccurred ? "IDENTITY_MISMATCH" : "NO_DATA_FOUND";
    }

    public function deleteSessionByUserIdAndType($userId, $userType)
    {
        $query = "DELETE FROM refresh_tokens WHERE user_id = :user_id AND user_type = :user_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        return $stmt->execute();
    }

    public function deleteRefreshTokenById($id)
    {
        $query = "DELETE FROM refresh_tokens WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // --- TENANT & ADMIN CREATION ---

    public function create($data, $useTransaction = true)
    {
        try {
            if ($useTransaction) {
                $this->conn->beginTransaction();
            }

            // [MERGE] Logic: Insert user AND role_id in one go
            $query = "INSERT INTO " . $this->table . " 
                      (tenant_id, NAME, email, PASSWORD, role_id, STATUS) 
                      VALUES (:tenant_id, :name, :email, :password, :role_id, 'active')";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':tenant_id' => $data['tenant_id'],
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':password' => $data['password'],
                ':role_id' => $data['role_id']
            ]);

            $userId = $this->conn->lastInsertId();

            if ($useTransaction) {
                $this->conn->commit();
            }
            return $userId;

        } catch (\Exception $e) {
            if ($useTransaction) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function tenantExists($tenantId)
    {
        $query = "SELECT id FROM tenants WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $tenantId);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function checkAdminExistsForTenant($tenantId)
    {
        // [MERGE] Using JOIN logic to verify by Role Name 'Admin'
        $query = "SELECT u.id FROM users u 
                  JOIN roles r ON u.role_id = r.id
                  WHERE u.tenant_id = :tenant_id AND r.name = 'Admin' AND u.deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // [ADDED] For Change Password feature
    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // [ADDED] For Change Password feature
    public function updatePassword($id, $newPasswordHash)
    {
        $query = "UPDATE " . $this->table . " SET PASSWORD = :password WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password', $newPasswordHash);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function softDelete($id)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}