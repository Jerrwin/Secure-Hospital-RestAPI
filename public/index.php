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
require_once __DIR__ . '/../app/middleware/JsonMiddleware.php';
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';

// 4. Load Models (Needed by Controllers)
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Tenant.php';

// 5. Load Controllers
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/TenantController.php';

// 6. Use Classes
use App\Core\Router;
use App\Middleware\JsonMiddleware;
use App\Controllers\AuthController;
use App\Controllers\TenantController;

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

// Tenant Endpoints
$router->post('/api/tenants', [TenantController::class, 'create']);
$router->get('/api/tenants', [TenantController::class, 'index']);

// 10. Dispatch
$router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

?>