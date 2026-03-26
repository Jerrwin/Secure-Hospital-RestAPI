<?php

namespace App\Models;

use PDO;

class Notification
{
    private $conn;
    private $table = 'notifications';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Create a new notification.
     */
    public function create($data)
    {
        $query = "INSERT INTO {$this->table} 
                  (tenant_id, user_id, user_type, type, title, message, reference_id) 
                  VALUES (:tenant_id, :user_id, :user_type, :type, :title, :message, :reference_id)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':user_type', $data['user_type']);
        $stmt->bindParam(':type', $data['type']);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':message', $data['message']);
        $stmt->bindParam(':reference_id', $data['reference_id']);

        return $stmt->execute();
    }

    /**
     * Get all notifications for a specific user (staff or patient).
     */
    public function getByUser($userId, $userType, $tenantId)
    {
        $query = "SELECT * FROM {$this->table} 
                  WHERE user_id = :user_id 
                  AND user_type = :user_type 
                  AND tenant_id = :tenant_id 
                  ORDER BY created_at DESC 
                  LIMIT 50";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count unread notifications for a user.
     */
    public function getUnreadCount($userId, $userType, $tenantId)
    {
        $query = "SELECT COUNT(*) as count FROM {$this->table} 
                  WHERE user_id = :user_id 
                  AND user_type = :user_type 
                  AND tenant_id = :tenant_id 
                  AND is_read = 0";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        $stmt->bindParam(':tenant_id', $tenantId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $row['count'];
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead($id, $userId, $userType)
    {
        $query = "UPDATE {$this->table} SET is_read = 1 
                  WHERE id = :id AND user_id = :user_id AND user_type = :user_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        return $stmt->execute();
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead($userId, $userType, $tenantId)
    {
        $query = "UPDATE {$this->table} SET is_read = 1 
                  WHERE user_id = :user_id 
                  AND user_type = :user_type 
                  AND tenant_id = :tenant_id 
                  AND is_read = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        $stmt->bindParam(':tenant_id', $tenantId);
        return $stmt->execute();
    }

    /**
     * Delete a single notification.
     */
    public function delete($id, $userId, $userType)
    {
        $query = "DELETE FROM {$this->table} 
                  WHERE id = :id AND user_id = :user_id AND user_type = :user_type";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_type', $userType);
        return $stmt->execute();
    }
}
