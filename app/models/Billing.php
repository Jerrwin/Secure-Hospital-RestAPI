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
        $query = "INSERT INTO " . $this->invoiceTable . " 
                  (tenant_id, appointment_id, amount, status) 
                  VALUES (:tenant_id, :appointment_id, :amount, 'unpaid')";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':appointment_id', $data['appointment_id']);
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

    // Get Invoice by ID
    public function getInvoiceById($id)
    {
        $query = "SELECT * FROM " . $this->invoiceTable . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Record Payment
    public function createPayment($data)
    {
        $query = "INSERT INTO " . $this->paymentTable . " 
                  (invoice_id, amount, method, transaction_id) 
                  VALUES (:invoice_id, :amount, :method, :transaction_id)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':invoice_id', $data['invoice_id']);
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':method', $data['method']);
        $stmt->bindParam(':transaction_id', $data['transaction_id']);

        if ($stmt->execute()) {
            // Update Invoice Status to 'paid' if full amount (simplified for MVP: any payment marks as paid)
            // In a real system, we'd check total paid vs invoice amount.
            $this->updateInvoiceStatus($data['invoice_id'], 'paid');
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function updateInvoiceStatus($id, $status)
    {
        $query = "UPDATE " . $this->invoiceTable . " SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}
