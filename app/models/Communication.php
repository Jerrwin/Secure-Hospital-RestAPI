<?php

namespace App\Models;

use PDO;

class Communication
{
    private $conn;
    private $table = 'appointment_notes';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function create($data)
    {
        $query = "INSERT INTO " . $this->table . " 
                  (appointment_id, user_id, note, is_private) 
                  VALUES (:appointment_id, :user_id, :note, :is_private)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':appointment_id', $data['appointment_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $encryptedNote = \App\Helpers\Encryption::encrypt($data['note']);
        $stmt->bindParam(':note', $encryptedNote);
        $isPrivate = $data['is_private'] ?? 0;
        $stmt->bindParam(':is_private', $isPrivate);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function getByAppointmentId($appointmentId)
    {
        $query = "SELECT an.*, u.name as user_name, r.name as role_name 
                  FROM " . $this->table . " an
                  JOIN users u ON an.user_id = u.id
                  JOIN roles r ON u.role_id = r.id
                  WHERE an.appointment_id = :appointment_id 
                  ORDER BY an.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':appointment_id', $appointmentId);
        $stmt->execute();
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notes as &$n) {
            if (!empty($n['note'])) {
                $n['note'] = \App\Helpers\Encryption::decrypt($n['note']);
            }
        }
        return $notes;
    }
}
