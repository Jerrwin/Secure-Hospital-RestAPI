<?php

// Ensure session is started for CSRF check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Load Configuration
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
require_once __DIR__ . '/../app/helpers/Encryption.php';
require_once __DIR__ . '/../app/middleware/JsonMiddleware.php';
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/middleware/RoleMiddleware.php';

// 4. Load Models
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Tenant.php';
require_once __DIR__ . '/../app/models/Staff.php';
require_once __DIR__ . '/../app/models/Patient.php';
require_once __DIR__ . '/../app/models/Appointment.php';
// [MERGE] Added His New Models
require_once __DIR__ . '/../app/models/Prescription.php';
require_once __DIR__ . '/../app/models/Communication.php';
require_once __DIR__ . '/../app/models/Billing.php';

// 5. Load Controllers
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/TenantController.php';
require_once __DIR__ . '/../app/controllers/StaffController.php';
require_once __DIR__ . '/../app/controllers/PatientController.php';
require_once __DIR__ . '/../app/controllers/AppointmentController.php';
// [MERGE] Added His New Controllers
require_once __DIR__ . '/../app/controllers/PrescriptionController.php';
require_once __DIR__ . '/../app/controllers/CommunicationController.php';
require_once __DIR__ . '/../app/controllers/BillingController.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';


// 6. Use Classes
use App\Core\Router;
use App\Middleware\JsonMiddleware;
use App\Controllers\AuthController;
use App\Controllers\TenantController;
use App\Controllers\StaffController;
use App\Controllers\PatientController;
use App\Controllers\AppointmentController;
// [MERGE] Added His Classes
use App\Controllers\PrescriptionController;
use App\Controllers\CommunicationController;
use App\Controllers\BillingController;
use App\Controllers\DashboardController;


// 7. Run Global Middleware (YOUR LOGIC IS SAFER HERE)
JsonMiddleware::handle();

// 8. Setup Router
$router = new Router();

// --- 9. DEFINE ROUTES ---

// Auth Endpoints
$router->post('/api/auth/login', [AuthController::class, 'login']);//
$router->post('/api/auth/register', [AuthController::class, 'register']);//
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);//
$router->post('/api/auth/logout', [AuthController::class, 'logout']);//
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword']);//

// [MERGE] Dashboard Endpoints (New)
$router->get('/api/dashboard/stats', [DashboardController::class, 'getStats']);

// Tenant Endpoints
$router->post('/api/tenants', [TenantController::class, 'create']);//
$router->get('/api/tenants', [TenantController::class, 'index']);//

// Staff Endpoints (Merged)
$router->post('/api/staff/register', [StaffController::class, 'register']);//
$router->get('/api/staff', [StaffController::class, 'index']);//
$router->delete('/api/staff/{id}', [StaffController::class, 'delete']);//
$router->put('/api/staff/{id}', [StaffController::class, 'update']); //

// Patient Endpoints (Merged)
$router->post('/api/patients', [PatientController::class, 'create']);//
$router->get('/api/patients', [PatientController::class, 'index']);//
$router->delete('/api/patients/{id}', [PatientController::class, 'delete']);//
$router->put('/api/patients/{id}', [PatientController::class, 'update']); // 

// Appointment Endpoints (KEPT YOURS - They are better structured)
$router->post('/api/appointments', [AppointmentController::class, 'create']);//
$router->get('/api/appointments/upcoming', [AppointmentController::class, 'getUpcoming']);//
$router->get('/api/appointments', [AppointmentController::class, 'index']);//
$router->get('/api/appointments/show/{id}', [AppointmentController::class, 'show']);//
$router->put('/api/appointments/cancel/{id}', [AppointmentController::class, 'cancel']);//
$router->put('/api/appointments/update/{id}', [AppointmentController::class, 'update']);//
$router->put('/api/appointments/complete/{id}', [AppointmentController::class, 'complete']);//

// Prescription Endpoints (New)
$router->post('/api/prescriptions', [PrescriptionController::class, 'create']);
$router->put('/api/prescriptions/{id}/status', [PrescriptionController::class, 'updateStatus']);
$router->get('/api/prescriptions', [PrescriptionController::class, 'index']);

// Communication Endpoints (New)
$router->post('/api/communications', [CommunicationController::class, 'create']);
$router->get('/api/communications', [CommunicationController::class, 'index']);

// [MERGE] Billing Endpoints (New)
$router->post('/api/invoices', [BillingController::class, 'createInvoice']);
$router->get('/api/invoices', [BillingController::class, 'getInvoice']);
$router->post('/api/payments', [BillingController::class, 'processPayment']);

// 10. Dispatch
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

?>