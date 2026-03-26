<?php

namespace App\Models;

use App\Core\BaseModel;
use PDO;

class Billing extends BaseModel
{
    protected $table = 'invoices';
    private $paymentTable = 'payments';


    // Create Invoice
    public function createInvoice($data)
    {
        $query = "INSERT INTO invoices (tenant_id, appointment_id, patient_id, amount, STATUS) 
                  VALUES (:tenant_id, :appointment_id, :patient_id, :amount, 'pending')";

        $stmt = $this->db->prepare($query);

        // Bind the data perfectly to the SQL statement
        $stmt->bindParam(':tenant_id', $data['tenant_id']);
        $stmt->bindParam(':appointment_id', $data['appointment_id']);
        $stmt->bindParam(':patient_id', $data['patient_id']);
        $stmt->bindParam(':amount', $data['amount']);

        if ($stmt->execute()) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    // Get Invoice by Appointment ID
    public function getInvoiceByAppointment($appointmentId)
    {
        $query = "SELECT i.*, i.id as invoice_id, p.method, p.transaction_id, p.payment_date, CONCAT(pt.first_name, ' ', pt.last_name) as patient_name 
                  FROM " . $this->table . " i 
                  LEFT JOIN " . $this->paymentTable . " p ON i.id = p.invoice_id
                  LEFT JOIN patients pt ON i.patient_id = pt.id 
                  WHERE i.appointment_id = :appointment_id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':appointment_id', $appointmentId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get Paginated Invoices with Filters & Search
     */
    public function getAllByTenantPaginated($tenantId, $filters = [])
    {
        $sql = "SELECT i.*, i.id as invoice_id, p.method, p.transaction_id, p.payment_date, CONCAT(pt.first_name, ' ', pt.last_name) as patient_name 
                FROM " . $this->table . " i 
                LEFT JOIN " . $this->paymentTable . " p ON i.id = p.invoice_id
                LEFT JOIN patients pt ON i.patient_id = pt.id 
                WHERE i.tenant_id = :tenant_id";

        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['patient_id'])) {
            $sql .= " AND i.patient_id = :patient_id";
            $params[':patient_id'] = $filters['patient_id'];
        }

        $searchColumns = [
            "CONCAT(pt.first_name, ' ', pt.last_name)",
            "p.transaction_id"
        ];

        return $this->fetchPaginated($sql, $params, $filters, $searchColumns, "i.id DESC", "i.id");

    }

    public function getAllByTenant($tenantId, $filters = [])
    {
        $query = "SELECT * FROM " . $this->table . " WHERE tenant_id = :tenant_id";
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

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get Invoice by ID
    public function getInvoiceById($id)
    {
        $query = "SELECT i.*, i.id as invoice_id, p.method, p.transaction_id, p.payment_date, CONCAT(pt.first_name, ' ', pt.last_name) as patient_name 
                  FROM " . $this->table . " i 
                  LEFT JOIN " . $this->paymentTable . " p ON i.id = p.invoice_id
                  LEFT JOIN patients pt ON i.patient_id = pt.id 
                  WHERE i.id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createPayment($data)
    {
        try {
            $this->db->beginTransaction();

            // 1. Insert the payment record
            $query = "INSERT INTO payments (invoice_id, amount, method, transaction_id, payment_date, STATUS) 
                  VALUES (:invoice_id, :amount, :method, :transaction_id, NOW(), 'success')";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':invoice_id', $data['invoice_id']);
            $stmt->bindParam(':amount', $data['amount']);
            $stmt->bindParam(':method', $data['method']);
            $stmt->bindParam(':transaction_id', $data['transaction_id']);
            $stmt->execute();

            // 2. Automatically flip the invoice status to 'paid'
            $updateQuery = "UPDATE invoices SET STATUS = 'paid' WHERE id = :invoice_id";
            $updateStmt = $this->db->prepare($updateQuery);
            $updateStmt->bindParam(':invoice_id', $data['invoice_id']);
            $updateStmt->execute();

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function updateInvoiceStatus($id, $status)
    {
        $query = "UPDATE " . $this->table . " SET status = :status WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getAllInvoices($status = null)
    {
        // We join 'payments' for transaction info and 'patients' for the name
        $query = "SELECT i.*, i.id as invoice_id, p.method, p.transaction_id, p.payment_date, CONCAT(pt.first_name, ' ', pt.last_name) as patient_name 
                  FROM " . $this->table . " i 
                  LEFT JOIN " . $this->paymentTable . " p ON i.id = p.invoice_id
                  LEFT JOIN patients pt ON i.patient_id = pt.id";

        if ($status) {
            $query .= " WHERE i.STATUS = :status";
        }
        $query .= " ORDER BY i.id DESC";
        $stmt = $this->db->prepare($query);
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $query = "UPDATE " . $this->table . " SET $fields WHERE id = :id";
        $filteredData['id'] = $id;

        $stmt = $this->db->prepare($query);
        return $stmt->execute($filteredData);
    }
}
