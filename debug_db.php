<?php
require_once __DIR__ . '/app/core/Database.php';
require_once __DIR__ . '/app/models/Billing.php';

use App\Core\Database;
use App\Models\Billing;

$db = (new Database())->connectTenant('hospital_demo_db'); // Assuming this is the DB
$billing = new Billing($db);

echo "--- All Invoices ---\n";
print_r($billing->getAllInvoices());
