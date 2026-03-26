<?php

namespace App\Controllers;

use App\Helpers\FileActivityLogger;
use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;

class FileActivityLogController
{
    /**
     * GET /api/activity-logs
     * Admin only: Get all logs for the current tenant
     */
    public function index()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        
        if ($user['role'] !== 'ADMIN' && $user['role'] !== 'SUPERADMIN') {
            ResponseHelper::send(false, "Unauthorized access.", null, 403);
            return;
        }

        $tenantId = $user['tenant_id'];
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

        $logs = FileActivityLogger::getLogs($tenantId, $startDate, $endDate, $limit);
        ResponseHelper::send(true, "Logs retrieved successfully.", $logs);
    }

    /**
     * GET /api/activity-logs/recent
     */
    public function recent()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

        $logs = FileActivityLogger::getRecentActivities($tenantId, $limit);
        ResponseHelper::send(true, "Recent activities retrieved.", $logs);
    }

    /**
     * GET /api/activity-logs/search
     */
    public function search()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];
        $term = $_GET['term'] ?? '';

        if (empty($term)) {
            ResponseHelper::send(false, "Search term is required.", null, 400);
            return;
        }

        $logs = FileActivityLogger::searchLogs($tenantId, $term);
        ResponseHelper::send(true, "Search results retrieved.", $logs);
    }

    /**
     * GET /api/activity-logs/statistics
     */
    public function statistics()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

        $stats = FileActivityLogger::getStatistics($tenantId, $days);
        ResponseHelper::send(true, "Statistics retrieved.", $stats);
    }

    /**
     * GET /api/activity-logs/files
     */
    public function logFiles()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];

        $files = FileActivityLogger::getLogFiles($tenantId);
        ResponseHelper::send(true, "Log files retrieved.", $files);
    }

    /**
     * GET /api/activity-logs/download/{date}
     */
    public function download($date)
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];
        $tenantId = $user['tenant_id'];

        $filename = "activity_$date.log";
        $filePath = __DIR__ . "/../../logs/activities/$tenantId/$filename";

        if (!file_exists($filePath)) {
            ResponseHelper::send(false, "Log file not found.", null, 404);
            return;
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($filePath);
        exit;
    }
}