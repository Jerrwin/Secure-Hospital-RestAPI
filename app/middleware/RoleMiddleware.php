<?php

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class RoleMiddleware
{
    /**
     * Handle Role-Based Access Control
     * @param array $allowedRoles List of allowed roles (e.g., ['SuperAdmin', 'Admin'])
     */
    public static function handle($allowedRoles = [])
    {
        // 1. Ensure User is Authenticated (AuthMiddleware should have run before this)
        if (!isset($_REQUEST['user']) || empty($_REQUEST['user'])) {
            ResponseHelper::send(false, "Unauthorized: Access denied.", [], 401);
            exit;
        }

        $userRole = $_REQUEST['user']['role'] ?? null;

        // 2. Check if the user's role is in the allowed list
        if (!in_array($userRole, $allowedRoles)) {
            ResponseHelper::send(false, "Forbidden: You do not have permission to access this resource.", [], 403);
            exit;
        }
    }
}
