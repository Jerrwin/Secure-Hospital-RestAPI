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
        $stmt = $this->conn->prepare("SELECT id, 'system_admin' as type, NAME as name, email, PASSWORD as password, 'SuperAdmin' as role_name, NULL as tenant_id FROM system_admins WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC))
            return $res;

        // 2. Check Users (Dynamic Role from Database)
        // We join the 'roles' table using u.role_id = r.id
        $query = "SELECT u.id, 'users' as type, u.tenant_id, u.NAME as name, u.email, u.PASSWORD as password, 
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
            // System Admins don't have a role_id, they are always SuperAdmin
            $query = "SELECT id, NAME as name, email, NULL as tenant_id, 'SuperAdmin' as role_name 
                  FROM system_admins 
                  WHERE id = :id LIMIT 1";
        } else {
            // All other users (Admin, Provider, Nurse, etc.) are in the 'users' table.
            // We JOIN with the 'roles' table to get the correct name (e.g., 'Provider')
            $query = "SELECT u.id, u.NAME as name, u.email, u.tenant_id, 
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
                (tenant_id, NAME, email, PASSWORD, role_id, STATUS) 
                VALUES (:tenant_id, :name, :email, :password, :role_id, 'active')";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':tenant_id' => $data['tenant_id'],
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':password' => $data['password'],
                ':role_id' => $data['role_id'] // Add this line
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
        // We check the 'users' table directly now.
        // I'm using role_id = 1 (assuming 1 is your Admin ID).
        $query = "SELECT id FROM users 
              WHERE tenant_id = :tenant_id 
              AND role_id = 1 
              AND deleted_at IS NULL 
              LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();

        // If rowCount > 0, an Admin already exists!
        return $stmt->rowCount() > 0;
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

    // In app/models/User.php

    public function findById($id)
    {
        // Query that joins roles so we can check if they are a 'Provider'
        $query = "SELECT u.*, r.name as role_name 
              FROM " . $this->table . " u
              LEFT JOIN roles r ON u.role_id = r.id
              WHERE u.id = :id LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

}

?>