<?php

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\FileActivityLogger;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class FileActivityLogController
{
    /**
     * GET /api/activity-logs
     * Get all activity logs for tenant
     */
    public function index()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $limit = (int)($_GET['limit'] ?? 100);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $logs = FileActivityLogger::getLogs($user['tenant_id'], $startDate, $endDate, $limit);
        ResponseHelper::send(true, "Activity logs retrieved", $logs);
    }

    /**
     * GET /api/activity-logs/recent
     * Get recent activities for dashboard
     */
    public function recent()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin', 'Provider', 'Nurse', 'Pharmacist', 'Receptionist']);

        $user = $_REQUEST['user'];
        
        $limit = (int)($_GET['limit'] ?? 10);
        $logs = FileActivityLogger::getRecentActivities($user['tenant_id'], $limit);
        ResponseHelper::send(true, "Recent activities retrieved", $logs);
    }

    /**
     * GET /api/activity-logs/user/{id}
     * Get activities for a specific user
     */
    public function userActivities($userId)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $limit = (int)($_GET['limit'] ?? 50);
        $logs = FileActivityLogger::getLogs($user['tenant_id'], null, null, $limit);
        
        // Filter by specific user
        $userLogs = array_filter($logs, function($log) use ($userId) {
            return $log['user_id'] == $userId;
        });
        
        ResponseHelper::send(true, "User activities retrieved", array_values($userLogs));
    }

    /**
     * GET /api/activity-logs/search
     * Search activity logs
     */
    public function search()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $searchTerm = $_GET['q'] ?? '';
        $limit = (int)($_GET['limit'] ?? 50);
        
        if (empty($searchTerm)) {
            ResponseHelper::send(false, "Search term is required", [], 400);
            return;
        }

        $logs = FileActivityLogger::searchLogs($user['tenant_id'], $searchTerm, $limit);
        ResponseHelper::send(true, "Search results retrieved", $logs);
    }

    /**
     * GET /api/activity-logs/statistics
     * Get activity statistics
     */
    public function statistics()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $days = (int)($_GET['days'] ?? 30);
        $stats = FileActivityLogger::getStatistics($user['tenant_id'], $days);
        ResponseHelper::send(true, "Activity statistics retrieved", $stats);
    }

    /**
     * GET /api/activity-logs/files
     * Get available log files
     */
    public function logFiles()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $files = FileActivityLogger::getLogFiles($user['tenant_id']);
        ResponseHelper::send(true, "Log files retrieved", $files);
    }

    /**
     * GET /api/activity-logs/download/{date}
     * Download specific log file
     */
    public function download($date)
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        // Validate date format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            ResponseHelper::send(false, "Invalid date format. Use YYYY-MM-DD", [], 400);
            return;
        }

        $logDir = __DIR__ . '/../../logs/activities/' . $user['tenant_id'];
        $filename = 'activity_' . $date . '.log';
        $filepath = $logDir . '/' . $filename;

        if (!file_exists($filepath)) {
            ResponseHelper::send(false, "Log file not found", [], 404);
            return;
        }

        // Set headers for file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

        readfile($filepath);
        exit;
    }

    /**
     * DELETE /api/activity-logs/cleanup
     * Clean up old log files
     */
    public function cleanup()
    {
        AuthMiddleware::handle();
        RoleMiddleware::handle(['Admin']);

        $user = $_REQUEST['user'];
        
        $days = (int)($_GET['days'] ?? 30);
        
        if ($days < 7) {
            ResponseHelper::send(false, "Cannot delete logs newer than 7 days", [], 400);
            return;
        }

        $logDir = __DIR__ . '/../../logs/activities/' . $user['tenant_id'];
        
        if (!file_exists($logDir)) {
            ResponseHelper::send(false, "Log directory not found", [], 404);
            return;
        }

        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        $files = glob($logDir . '/activity_*.log');
        $deletedCount = 0;

        foreach ($files as $file) {
            $fileDate = basename($file, '.log');
            $fileDate = str_replace('activity_', '', $fileDate);
            
            if ($fileDate < $cutoffDate) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        ResponseHelper::send(true, "Cleanup completed", [
            'deleted_files' => $deletedCount,
            'cutoff_date' => $cutoffDate
        ]);
    }
}
