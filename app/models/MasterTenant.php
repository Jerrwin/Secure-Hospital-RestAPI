<?php

namespace App\Models;

use PDO;
use Exception;
use App\Core\Database;

class MasterTenant
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // Fetch all tenants, optionally filtered by status
    public function getAll($status = null)
    {
        $query = "SELECT id, hospital_name, tenant_code, admin_email, admin_name, theme, db_name, status, created_at FROM tenant_details";
        $params = [];

        if ($status) {
            $query .= " WHERE status = ?";
            $params[] = $status;
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch a single tenant by ID
    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM tenant_details WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Delete a tenant application
    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM tenant_details WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // Update the status of a tenant in the master DB
    public function updateStatus($id, $status)
    {
        $stmt = $this->db->prepare("UPDATE tenant_details SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    // The Magic Method: Creates the physical DB and runs the schema
    public function provisionDatabase($tenant)
    {
        // 1. LOCATE THE FILE FIRST (Fail fast before doing anything!)
        // Adjust this depending on where your 'core' folder is:
        // If 'core' is inside 'app': use '/../core/tenant_schema.sql'
        // If 'core' is outside 'app' in the main folder: use '/../../core/tenant_schema.sql'
        $schemaPath = __DIR__ . '/../core/tenant_schema.sql';

        if (!file_exists($schemaPath)) {
            // This will print the exact absolute path so you can see where it's looking
            $attemptedPath = realpath(__DIR__ . '/../') . '\core\tenant_schema.sql';
            throw new Exception("Critical Error: Schema file not found. PHP is looking exactly here: " . $attemptedPath);
        }

        // Read the file now that we know it exists
        $schemaSql = file_get_contents($schemaPath);

        // 2. NOW Create the physical database
        $dbName = $tenant['db_name'];
        $this->db->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // 3. Connect directly to the newly created Tenant Database
        $database = new Database();
        $tenantDb = $database->connectTenant($dbName);

        // 4. Execute the SQL file to build the tables
        $tenantDb->exec($schemaSql);

        // 5. Insert the First Admin User into `users` (Passing the dynamic tenant_id)
        $insertAdmin = $tenantDb->prepare("
            INSERT INTO `users` (`tenant_id`, `NAME`, `email`, `PASSWORD`, `role_id`, `STATUS`) 
            VALUES (?, ?, ?, ?, 1, 'active')
        ");

        $insertAdmin->execute([
            $tenant['id'], // <--- This dynamically grabs the ID from tenant_details (e.g., 2)
            $tenant['admin_name'],
            $tenant['admin_email'],
            $tenant['admin_password']
        ]);

        // 6. Automatically insert a matching staff profile for the Admin
        $userId = $tenantDb->lastInsertId();
        $insertStaff = $tenantDb->prepare("
            INSERT INTO `staff` (`tenant_id`, `user_id`, `name`, `gender`, `status`) 
            VALUES (?, ?, ?, 'other', 'active')
        ");

        $insertStaff->execute([
            $tenant['id'], // <--- Grabs the ID from tenant_details again
            $userId,
            $tenant['admin_name']
        ]);
    }

    /**
     * Find tenant DB details by the numeric ID (used for Refresh/Logout/Actions)
     */
    public function getDetailsById($id)
    {
        $stmt = $this->db->prepare("SELECT id, db_name, db_user, db_pass, theme, status FROM tenant_details WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find tenant DB details by the Subdomain (used for Login)
     */
    public function getDetailsBySubdomain($subdomain)
    {
        // Currently your DB has `tenant_code`, we will query against it using the subdomain value
        $stmt = $this->db->prepare("SELECT id, db_name, db_user, db_pass, theme, status FROM tenant_details WHERE LOWER(tenant_code) = ?");
        $stmt->execute([strtolower(trim($subdomain))]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
