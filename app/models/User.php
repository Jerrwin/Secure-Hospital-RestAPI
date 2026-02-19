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
        $stmt = $this->conn->prepare("SELECT id, 'system_admin' as type, name, email, password, 'SuperAdmin' as role_name, 999 as role_id, NULL as tenant_id FROM system_admins WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            return $res;

        // 2. Check Tenant Admins (Users table)
        //  Joined users -> roles directly.
        // Fixed: Column names (password -> password_hash)
        // 2. Check Tenant Admins (Users table)
        $stmt = $this->conn->prepare("SELECT u.id, 'users' as type, u.tenant_id, u.role_id, u.name, u.email, u.PASSWORD as password, r.name as role_name 
                                  FROM users u 
                                  LEFT JOIN roles r ON u.role_id = r.id 
                                  WHERE u.email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            return $res;
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
            $query = "SELECT id, NAME as name, email, NULL as tenant_id, 'SuperAdmin' as role_name FROM system_admins WHERE id = :id LIMIT 1";
        } elseif ($type === 'staff') {
            // FIXED: Table name 'staff' instead of 'staffs'
            // Added deleted_at check
            $query = "SELECT id, name, email, tenant_id, 'Staff' as role_name FROM staff WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        } else {
            // Default to users table (tenant_admin)
            $query = "SELECT u.id, u.name, u.email, u.tenant_id, r.name as role_name 
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
     * Store Refresh Token (Hashed for Security)
     */
    public function storeRefreshToken($userId, $userType, $token, $expiry)
    {
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);

        $query = "INSERT INTO refresh_tokens (user_id, user_type, token, expiry_date) 
              VALUES (:user_id, :user_type, :token, :expiry)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType); // Store if it's 'system_admin' or 'tenant_user'
        $stmt->bindParam(':token', $tokenHash);
        $stmt->bindParam(':expiry', $expiry);

        return $stmt->execute();
    }

    /**
     * Validates a refresh token using secure hashing logic.
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
            // 2. Use password_verify because we stored a hash!
            if (password_verify($incomingRawToken, $row['token'])) {

                // 3. Match found! Check for Expiry
                if (strtotime($row['expiry_date']) < time()) {
                    return "EXPIRED";
                }

                return $row;
            }

            $mismatchOccurred = true;
        }

        return $mismatchOccurred ? "IDENTITY_MISMATCH" : "NO_DATA_FOUND";
    }

    /**

     * Clears specific sessions by ID and Type.

     */

    public function deleteSessionByUserIdAndType($userId, $userType)
    {
        $query = "DELETE FROM refresh_tokens WHERE user_id = :user_id AND user_type = :user_type";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);

        return $stmt->execute();

    }

    // Added this for the Rotation logic in AuthController
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

            $query = "INSERT INTO " . $this->table . " 
                      (tenant_id, name, email, PASSWORD, role_id, STATUS) 
                      VALUES (:tenant_id, :name, :email, :password, :role_id, 'active')";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':tenant_id' => $data['tenant_id'],
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':password' => $data['password'],
                ':role_id' => $data['role_id'] // Added role_id
            ]);

            $userId = $this->conn->lastInsertId();


            // Note: In case we need to update users.role_id specifically if the INSERT above didn't include it (it didn't include role_id in values list)
            // The INSERT above was: (tenant_id, NAME, email, PASSWORD, STATUS) ...
            // Validating if role_id is in users table... YES.
            // So we should add role_id to the INSERT query instead of separate table.

            $updateRole = "UPDATE users SET role_id = :role_id WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateRole);
            $updateStmt->execute([':role_id' => $data['role_id'], ':id' => $userId]);

            if ($useTransaction) {
                $this->conn->commit();
            }
            return $userId;

        } catch (\Exception $e) {
            if ($useTransaction) {
                $this->conn->rollBack();
            }
            throw $e; // Re-throw to let caller handle rollback
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
        // Assuming Role ID 2 is 'Admin' or using Join if Role Name is needed.
        // But user request said "super admin is ... create a admin".
        // Let's assume we check by Role Name via Join.
        // "Admin" role name.
        // "Admin" role name.
        $query = "SELECT u.id FROM users u 
                  JOIN roles r ON u.role_id = r.id
                  WHERE u.tenant_id = :tenant_id AND r.name = 'Admin' AND u.deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function getById($id)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updatePassword($id, $newPasswordHash)
    {
        $query = "UPDATE " . $this->table . " SET PASSWORD = :password WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password', $newPasswordHash);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    /**
     * Soft Delete (works for users table)
     */
    public function softDelete($id)
    {
        $query = "UPDATE " . $this->table . " SET deleted_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}

?>