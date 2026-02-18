<?php

// Ensure session is started for CSRF check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// 1. Load Configuration (Errors + .env)
require_once __DIR__ . '/../config/config.php';

// 2. Load Core Files
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Router.php';

// 3. Load Helpers & Middleware
require_once __DIR__ . '/../app/helpers/ResponseHelper.php';
require_once __DIR__ . '/../app/helpers/Validator.php';
require_once __DIR__ . '/../app/helpers/JWT.php';
require_once __DIR__ . '/../app/helpers/CSRF.php';
require_once __DIR__ . '/../app/helpers/RefreshToken.php';
require_once __DIR__ . '/../app/helpers/Encryption.php'; // Added
require_once __DIR__ . '/../app/middleware/JsonMiddleware.php';
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/middleware/RoleMiddleware.php'; // Added

// 4. Load Models (Needed by Controllers)
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Tenant.php';
require_once __DIR__ . '/../app/models/Staff.php'; // Added
require_once __DIR__ . '/../app/models/Patient.php'; // Added
require_once __DIR__ . '/../app/models/Prescription.php'; // Added
require_once __DIR__ . '/../app/models/Communication.php'; // Added
require_once __DIR__ . '/../app/models/Billing.php'; // Added

// 5. Load Controllers
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/TenantController.php';
require_once __DIR__ . '/../app/controllers/StaffController.php'; // Added
require_once __DIR__ . '/../app/controllers/PatientController.php'; // Added
require_once __DIR__ . '/../app/controllers/PrescriptionController.php'; // Added
require_once __DIR__ . '/../app/controllers/CommunicationController.php'; // Added
require_once __DIR__ . '/../app/controllers/BillingController.php'; // Added
require_once __DIR__ . '/../app/controllers/DashboardController.php'; // Added


// 6. Use Classes
use App\Core\Router;
use App\Middleware\JsonMiddleware;
use App\Controllers\AuthController;
use App\Controllers\TenantController;
use App\Controllers\StaffController; // Added
use App\Controllers\PatientController; // Added
use App\Controllers\PrescriptionController; // Added
use App\Controllers\CommunicationController; // Added
use App\Controllers\BillingController; // Added
use App\Controllers\DashboardController; // Added


// 7. Run Global Middleware
JsonMiddleware::handle();

// 8. Setup Router
$router = new Router();

// --- 9. DEFINE ROUTES ---

// Auth Endpoints
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword']); // Added

// Dashboard Endpoints
$router->get('/api/dashboard/stats', [DashboardController::class, 'getStats']);

// Tenant Endpoints
$router->post('/api/tenants', [TenantController::class, 'create']);
$router->get('/api/tenants', [TenantController::class, 'index']);

// Staff Endpoints
$router->post('/api/staff/register', [StaffController::class, 'register']);
$router->get('/api/staff', [StaffController::class, 'index']);
$router->put('/api/staff/{id}', [StaffController::class, 'update']);
$router->delete('/api/staff/{id}', [StaffController::class, 'delete']);

// Patient Endpoints
$router->post('/api/patients', [PatientController::class, 'create']);
$router->get('/api/patients', [PatientController::class, 'index']);
$router->put('/api/patients/{id}', [PatientController::class, 'update']);
$router->delete('/api/patients/{id}', [PatientController::class, 'delete']);


// Prescription Endpoints
$router->post('/api/prescriptions', [PrescriptionController::class, 'create']); // Provider only
$router->put('/api/prescriptions/{id}/status', [PrescriptionController::class, 'updateStatus']); // Pharmacist only
$router->get('/api/prescriptions', [PrescriptionController::class, 'index']);


// Communication Endpoints
$router->post('/api/communications', [CommunicationController::class, 'create']);
$router->get('/api/communications', [CommunicationController::class, 'index']);

// Billing Endpoints
$router->post('/api/invoices', [BillingController::class, 'createInvoice']);
$router->get('/api/invoices', [BillingController::class, 'getInvoice']);
$router->post('/api/payments', [BillingController::class, 'processPayment']);

// 10. Dispatch
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

?>