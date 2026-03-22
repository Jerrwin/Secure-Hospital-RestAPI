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

// 3. Setup CORS (Essential for React Frontend on Port 3000 with Cookies)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, X-Request-Encrypted");
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// 4. Load Helpers & Middleware
require_once __DIR__ . '/../app/helpers/ResponseHelper.php';
require_once __DIR__ . '/../app/helpers/Validator.php';
require_once __DIR__ . '/../app/helpers/JWT.php';
require_once __DIR__ . '/../app/helpers/CSRF.php';
require_once __DIR__ . '/../app/helpers/RefreshToken.php';
require_once __DIR__ . '/../app/helpers/Encryption.php';
require_once __DIR__ . '/../app/middleware/JsonMiddleware.php';
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/middleware/RoleMiddleware.php';

// 5. Load Models
require_once __DIR__ . '/../app/models/User.php';
// require_once __DIR__ . '/../app/models/Tenant.php';
require_once __DIR__ . '/../app/models/Staff.php';
require_once __DIR__ . '/../app/models/Patient.php';
require_once __DIR__ . '/../app/models/Appointment.php';
// [MERGE] Added His New Models
require_once __DIR__ . '/../app/models/Prescription.php';
require_once __DIR__ . '/../app/models/Communication.php';
require_once __DIR__ . '/../app/models/Billing.php';
require_once __DIR__ . '/../app/models/Calendar.php';
require_once __DIR__ . '/../app/models/Notification.php';

require_once __DIR__ . '/../app/models/MasterTenant.php';

// 5. Load Controllers
require_once __DIR__ . '/../app/controllers/AuthController.php';
// require_once __DIR__ . '/../app/controllers/TenantController.php';
require_once __DIR__ . '/../app/controllers/StaffController.php';
require_once __DIR__ . '/../app/controllers/PatientController.php';
require_once __DIR__ . '/../app/controllers/AppointmentController.php';
// [MERGE] Added His New Controllers
require_once __DIR__ . '/../app/controllers/PrescriptionController.php';
require_once __DIR__ . '/../app/controllers/CommunicationController.php';
require_once __DIR__ . '/../app/controllers/BillingController.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';
require_once __DIR__ . '/../app/controllers/CalendarController.php';
require_once __DIR__ . '/../app/controllers/NotificationController.php';

require_once __DIR__ . '/../app/controllers/AdminAuthController.php';
require_once __DIR__ . '/../app/controllers/TenantRegistrationController.php';
require_once __DIR__ . '/../app/controllers/SystemAdminController.php';


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
use App\Controllers\CalendarController;
use App\Controllers\NotificationController;

use App\Controllers\AdminAuthController;
use App\Controllers\TenantRegistrationController;
use App\Controllers\SystemAdminController;


// 7. Run Global Middleware (YOUR LOGIC IS SAFER HERE)
JsonMiddleware::handle();

// 8. Setup Router
$router = new Router();

// --- 9. DEFINE ROUTES ---

// Auth Endpoints
$router->post('/api/auth/login', [AuthController::class, 'login']); //
$router->post('/api/auth/register', [AuthController::class, 'register']); //
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']); //
$router->post('/api/auth/logout', [AuthController::class, 'logout']); //
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword']); //

// [MERGE] Dashboard Endpoints (New)
$router->get('/api/dashboard/stats', [DashboardController::class, 'getStats']);

// Tenant Endpoints
// $router->post('/api/tenants', [TenantController::class, 'create']);//
// $router->get('/api/tenants', [TenantController::class, 'index']);//
// $router->get('/api/tenants/{id}', [TenantController::class, 'show']); //
// $router->put('/api/tenants/{id}', [TenantController::class, 'update']);// 
// $router->delete('/api/tenants/{id}', [TenantController::class, 'delete']);//

// Staff Endpoints (Merged)
$router->post('/api/staff/register', [StaffController::class, 'register']); //
$router->get('/api/staff', [StaffController::class, 'index']); //
$router->delete('/api/staff/{id}', [StaffController::class, 'delete']); //
$router->put('/api/staff/{id}', [StaffController::class, 'update']); //

// Patient Endpoints (Merged)
$router->post('/api/patients', [PatientController::class, 'create']); //
$router->get('/api/patients/{id}', [PatientController::class, 'show']); //
$router->get('/api/patients', [PatientController::class, 'index']); //
$router->delete('/api/patients/{id}', [PatientController::class, 'delete']); // 
$router->put('/api/patients/{id}', [PatientController::class, 'update']); // 

// Appointment Endpoints (KEPT YOURS - They are better structured)
$router->post('/api/appointments', [AppointmentController::class, 'create']); //
$router->get('/api/appointments/upcoming', [AppointmentController::class, 'getUpcoming']); //
$router->get('/api/appointments', [AppointmentController::class, 'index']); //
$router->get('/api/appointments/show/{id}', [AppointmentController::class, 'show']); //
$router->put('/api/appointments/cancel/{id}', [AppointmentController::class, 'cancel']); //
$router->put('/api/appointments/update/{id}', [AppointmentController::class, 'update']); //
$router->put('/api/appointments/complete/{id}', [AppointmentController::class, 'complete']); 

// Calendar Endpoints
$router->get('/api/calendar', [CalendarController::class, 'index']);      // Default (current month)
$router->get('/api/calendar/range', [CalendarController::class, 'range']);  // Custom range
$router->get('/api/calendar/date', [CalendarController::class, 'getByDate']); // Tooltip

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
$router->get('/api/invoices/{id}', [BillingController::class, 'show']);
$router->post('/api/payments', [BillingController::class, 'processPayment']);


// Prescription Endpoints
$router->post('/api/prescriptions', [PrescriptionController::class, 'create']); // Provider only
$router->put('/api/prescriptions/{id}/status', [PrescriptionController::class, 'updateStatus']); // Pharmacist only
$router->get('/api/prescriptions', [PrescriptionController::class, 'index']); // For all roles
$router->get('/api/prescriptions/{id}', [PrescriptionController::class, 'show']);
$router->patch('/api/prescriptions/{id}', [PrescriptionController::class, 'update']); // Doctor only
$router->delete('/api/prescriptions/{id}', [PrescriptionController::class, 'delete']); // Doctor/Admin only


// Communication Endpoints
$router->post('/api/communications', [CommunicationController::class, 'create']);
$router->get('/api/communications', [CommunicationController::class, 'index']);

// Billing Endpoints
$router->post('/api/invoices', [BillingController::class, 'createInvoice']);
$router->get('/api/invoices', [BillingController::class, 'getInvoice']);
$router->put('/api/invoices/{id}', [BillingController::class, 'update']);
$router->post('/api/payments', [BillingController::class, 'processPayment']);

// Notification Endpoints
$router->get('/api/notifications', [NotificationController::class, 'index']);
$router->put('/api/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
$router->put('/api/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

// Super Admin Auth
$router->post('/api/superadmin/login', [AdminAuthController::class, 'login']);

// Public Hospital Registration (No Token Required)
$router->get('/api/tenant/config', [TenantRegistrationController::class, 'getConfig']);
$router->post('/api/tenant/register', [TenantRegistrationController::class, 'register']);
$router->post('/api/tenant/status', [TenantRegistrationController::class, 'checkStatus']);

// Super Admin Dashboard (Managing Tenants)
$router->get('/api/admin/tenants', [SystemAdminController::class, 'index']); // List all (pending, active)
$router->get('/api/admin/tenants/{id}', [SystemAdminController::class, 'show']); // View specific application
$router->patch('/api/admin/tenants/{id}/status', [SystemAdminController::class, 'updateStatus']); // Approve/Suspend
$router->delete('/api/admin/tenants/{id}', [SystemAdminController::class, 'delete']); // Delete spam applications

// 10. Dispatch
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
