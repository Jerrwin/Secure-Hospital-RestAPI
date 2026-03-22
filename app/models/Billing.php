<?php

namespace App\Models;

use PDO;

class Billing
{
    private $conn;
    private $invoiceTable = 'invoices';
    private $paymentTable = 'payments';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    // Create Invoice
    public function createInvoice($data)
    {
        $query = "INSERT INTO invoices (tenant_id, appointment_id, patient_id, amount, STATUS) 
                  VALUES (:tenant_id, :appointment_id, :patient_id, :amount, 'pending')";
                  
        $stmt = $this->conn->prepare($query);

        // Bind the data perfectly to the SQL statement
        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':appointment_id', $data['appointment_id']);
        $stmt->bindParam(':patient_id', $data['patient_id']);
        $stmt->bindParam(':amount', $data['amount']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    // Get Invoice by Appointment ID
    public function getInvoiceByAppointment($appointmentId)
    {
        $query = "SELECT * FROM " . $this->invoiceTable . " WHERE appointment_id = :appointment_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':appointment_id', $appointmentId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllByTenant($tenantId, $filters = [])
    {
        $query = "SELECT * FROM " . $this->invoiceTable . " WHERE tenant_id = :tenant_id";
        $params = [':tenant_id' => $tenantId];
        
        if (!empty($filters['status'])) {
            $query .= " AND STATUS = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['patient_id'])) {
            $query .= " AND patient_id = :patient_id";
            $params[':patient_id'] = $filters['patient_id'];
        }
        
        $query .= " ORDER BY id DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get Invoice by ID
    public function getInvoiceById($id)
    {
        $query = "SELECT * FROM " . $this->invoiceTable . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createPayment($data) {
    try {
        $this->conn->beginTransaction();

        // 1. Insert the payment record
        $query = "INSERT INTO payments (invoice_id, amount, method, transaction_id, payment_date, STATUS) 
                  VALUES (:invoice_id, :amount, :method, :transaction_id, CURDATE(), 'success')";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':invoice_id', $data['invoice_id']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':method', $data['method']);
        $stmt->bindParam(':transaction_id', $data['transaction_id']);
        $stmt->execute();

        // 2. Automatically flip the invoice status to 'paid'
        $updateQuery = "UPDATE invoices SET STATUS = 'paid' WHERE id = :invoice_id";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->bindParam(':invoice_id', $data['invoice_id']);
        $updateStmt->execute();

        $this->conn->commit();
        return true;
    } catch (\Exception $e) {
        $this->conn->rollBack();
        return false;
    }
}

    public function updateInvoiceStatus($id, $status)
    {
        $query = "UPDATE " . $this->invoiceTable . " SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function update($id, $data)
    {
        $allowedColumns = ['amount', 'status'];
        $filteredData = array_intersect_key($data, array_flip($allowedColumns));

        if (empty($filteredData)) {
            return false;
        }

        $fields = "";
        foreach ($filteredData as $key => $value) {
            $fields .= "$key = :$key, ";
        }
        $fields = rtrim($fields, ", ");

        $query = "UPDATE " . $this->invoiceTable . " SET $fields WHERE id = :id";
        $filteredData['id'] = $id;

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($filteredData);
    }
}
