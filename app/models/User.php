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

    /**
     * AUTHENTICATION: Find hospital staff or patients in the CURRENT tenant database.
     */
    public function findAnyUserByEmail($email)
    {
        // 1. Check Hospital Staff (Admins, Doctors, Nurses)
        $sqlUsers = "SELECT u.id, 'users' as type, u.tenant_id, u.role_id, u.name, u.email, 
                            u.PASSWORD as password, r.name as role_name 
                     FROM users u 
                     LEFT JOIN roles r ON u.role_id = r.id 
                     WHERE u.email = :e AND u.deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($sqlUsers);
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $res;
        }

        // 2. Check Patients (In the same tenant database)
        $sqlPatients = "SELECT id, 'patients' as type, tenant_id, first_name as name, email, 
                               password, 'Patient' as role_name 
                        FROM patients 
                        WHERE email = :e AND deleted_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($sqlPatients);
        $stmt->execute([':e' => $email]);
        if ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $res['role_id'] = 6; // Fixed Role ID for Patients
            return $res;
        }

        return false;
    }

    /**
     * Used during Token Refresh to get fresh data from the correct table.
     */
    public function getUserByType($id, $type)
    {
        // Removed 'system_admin' check because that table is in the Master DB
        if ($type === 'patients') {
            $query = "SELECT id, first_name as name, email, tenant_id, 'Patient' as role_name, 6 as role_id FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        } elseif ($type === 'staff') {
            $query = "SELECT id, name, email, tenant_id, 'Staff' as role_name, NULL as role_id FROM staff WHERE id = :id AND deleted_at IS NULL LIMIT 1";
        } else {
            $query = "SELECT u.id, u.name, u.email, u.tenant_id, u.role_id, r.name as role_name 
                      FROM users u 
                      LEFT JOIN roles r ON u.role_id = r.id 
                      WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
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
     * Validates a refresh token by searching all sessions in the table.
     * Used when the Access Token is missing (e.g. after page refresh).
     */
    public function verifyRefreshTokenGeneric($incomingRawToken)
    {
        // 1. Fetch ALL tokens (usually a small table)
        // Optimization: In large systems, we would use a blind index or hash prefix.
        $query = "SELECT * FROM refresh_tokens";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $allSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allSessions as $row) {
            if (password_verify($incomingRawToken, $row['token'])) {
                if (strtotime($row['expiry_date']) < time()) {
                    return "EXPIRED";
                }
                return $row;
            }
        }

        return "NO_DATA_FOUND";
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
        $query = "INSERT INTO users (tenant_id, name, email, PASSWORD, role_id, STATUS) 
          VALUES (:tenant_id, :name, :email, :password, :role_id, 'active')";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':tenant_id' => $data['tenant_id'],
            ':name'      => $data['name'],
            ':email'     => $data['email'],
            ':password'  => $data['password'],
            ':role_id'   => $data['role_id']
        ]);

        $userId = $this->conn->lastInsertId();
        return $userId;
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
