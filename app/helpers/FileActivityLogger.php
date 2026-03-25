<?php

namespace App\Helpers;

class FileActivityLogger
{
    private static $logDir;
    private static $maxFileSize = 10 * 1024 * 1024; // 10MB per file
    private static $maxFiles = 5; // Keep 5 days of logs

    public static function init()
    {
        self::$logDir = __DIR__ . '/../../logs/activities';
        
        // Create logs directory if it doesn't exist
        if (!file_exists(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        
        // Create tenant-specific subdirectories if a tenant is identified
        $tenantId = $_REQUEST['user']['tenant_id'] ?? null;
        if ($tenantId) {
            self::$logDir .= '/' . $tenantId;
            if (!file_exists(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
        }
    }

    /**
     * Log activity to file
     */
    public static function log($action, $details = '', $endpoint = '', $method = '', $user = null, $controllerMethod = '')
    {
        try {
            self::init();
            
            // Accept user parameter or get from request
            $user = $user ?? $_REQUEST['user'] ?? null;
            if (!$user) {
                error_log("FileActivityLogger: No user context provided for action: $action");
                return false;
            }

            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'date' => date('Y-m-d'),
                'tenant_id' => $user['tenant_id'] ?? 'unknown',
                'user_id' => $user['user_id'] ?? 'unknown',
                'user_name' => $user['name'] ?? 'Unknown',
                'user_email' => $user['email'] ?? 'unknown',
                'user_role' => $user['role'] ?? 'unknown',
                'action' => $action,
                'method' => $method ?: $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'endpoint' => $endpoint ?: $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
                'ip_address' => self::getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'details' => is_array($details) ? json_encode($details) : $details,
                'controller_method' => $controllerMethod ?: 'UNKNOWN'
            ];

            // Write to daily log file
            $filename = self::$logDir . '/activity_' . date('Y-m-d') . '.log';
            
            // Format: Pretty Header + Single Line JSON
            $responseTime = defined('APP_START_TIME') ? round((microtime(true) - APP_START_TIME) * 1000) : 0;
            $recordCount = is_array($details) && isset($details['count']) ? $details['count'] : 0;

            $logLine = "--------------------------------------------------\n";
            $logLine .= "[" . $logEntry['timestamp'] . "] " . $logEntry['action'] . " by " . $logEntry['user_name'] . "\n";
            $logLine .= "Method: " . $logEntry['method'] . " | Endpoint: " . $logEntry['endpoint'] . "\n";
            $logLine .= "JSON: " . json_encode([
                'status' => 'SUCCESS',
                'response_time_ms' => $responseTime,
                'record_count' => $recordCount,
                'ip' => $logEntry['ip_address']
            ], JSON_PRETTY_PRINT) . "\n";
            $logLine .= "--------------------------------------------------\n\n";
            
            $result = file_put_contents($filename, $logLine, FILE_APPEND | LOCK_EX);
            
            if ($result === false) {
                error_log("FileActivityLogger: Failed to write to file: $filename");
                return false;
            }
            
            error_log("FileActivityLogger: Successfully logged action: $action to $filename");
            
            // Clean old files
            self::cleanOldLogs();
            
            return true;
        } catch (\Exception $e) {
            error_log("File Activity Logger Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication activities
     */
    public static function logAuth($action, $email = '', $success = true, $details = '', $user = null, $controllerMethod = '')
    {
        $details = array_merge([
            'email' => $email,
            'success' => $success,
            'ip' => self::getClientIP()
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', $user, $controllerMethod);
    }

    /**
     * Log CRUD operations
     */
    public static function logCRUD($action, $resource, $resourceId = null, $details = '', $controllerMethod = '')
    {
        $details = array_merge([
            'resource' => $resource,
            'resource_id' => $resourceId
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', null, $controllerMethod);
    }

    /**
     * Log payment activities
     */
    public static function logPayment($action, $amount, $method = '', $details = '', $controllerMethod = '')
    {
        $details = array_merge([
            'amount' => $amount,
            'payment_method' => $method
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', null, $controllerMethod);
    }

    /**
     * Log billing activities
     */
    public static function logBilling($action, $invoiceId = null, $amount = null, $details = '', $controllerMethod = '')
    {
        $details = array_merge([
            'invoice_id' => $invoiceId,
            'amount' => $amount
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', null, $controllerMethod);
    }

    /**
     * Log staff activities
     */
    public static function logStaff($action, $staffId = null, $details = '', $controllerMethod = '')
    {
        $details = array_merge([
            'staff_id' => $staffId
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', null, $controllerMethod);
    }

    /**
     * Log appointment activities
     */
    public static function logAppointment($action, $appointmentId = null, $patientId = null, $details = '', $controllerMethod = '')
    {
        $details = array_merge([
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId
        ], is_array($details) ? $details : []);

        return self::log($action, $details, '', '', null, $controllerMethod);
    }

    /**
     * Get logs for a specific date range
     */
    public static function getLogs($tenantId, $startDate = null, $endDate = null, $limit = 100)
    {
        try {
            $logDir = __DIR__ . '/../../logs/activities/' . $tenantId;
            
            if (!file_exists($logDir)) {
                return [];
            }

            $logs = [];
            $files = glob($logDir . '/activity_*.log');
            rsort($files); // Most recent first

            $count = 0;
            foreach ($files as $file) {
                if ($count >= $limit) break;

                $fileDate = basename($file, '.log');
                $fileDate = str_replace('activity_', '', $fileDate);

                // Filter by date range if provided
                if ($startDate && $fileDate < $startDate) continue;
                if ($endDate && $fileDate > $endDate) continue;

                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $fileContent = implode("\n", $lines);
                
                // Splitting by dashed lines to separate blocks
                $blocks = explode("--------------------------------------------------", $fileContent);
                foreach (array_reverse($blocks) as $block) {
                    if (empty(trim($block))) continue;
                    if ($count >= $limit) break;

                    // Extract JSON block
                    if (preg_match('/JSON:\s(\{.*\})/s', $block, $matches)) {
                        $logEntry = json_decode($matches[1], true);
                        if ($logEntry) {
                            // Recover hidden metadata for dashboard
                            if (isset($logEntry['_meta'])) {
                                $logEntry = array_merge($logEntry, $logEntry['_meta']);
                                unset($logEntry['_meta']);
                            }
                            
                            // Extract timestamp from header if missing in JSON
                            if (!isset($logEntry['timestamp']) && preg_match('/\[(.*?)\]/', $block, $tMatches)) {
                                $logEntry['timestamp'] = $tMatches[1];
                            }

                            $logs[] = $logEntry;
                            $count++;
                        }
                    }
                }
            }

            return $logs;
        } catch (\Exception $e) {
            error_log("Get Logs Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent activities for dashboard
     */
    public static function getRecentActivities($tenantId, $limit = 10)
    {
        return self::getLogs($tenantId, null, null, $limit);
    }

    /**
     * Search logs
     */
    public static function searchLogs($tenantId, $searchTerm, $limit = 50)
    {
        try {
            $logs = self::getLogs($tenantId, null, null, 1000); // Get more for searching
            $filteredLogs = [];

            foreach ($logs as $log) {
                $searchable = json_encode($log);
                if (stripos($searchable, $searchTerm) !== false) {
                    $filteredLogs[] = $log;
                    if (count($filteredLogs) >= $limit) break;
                }
            }

            return array_slice($filteredLogs, 0, $limit);
        } catch (\Exception $e) {
            error_log("Search Logs Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get activity statistics
     */
    public static function getStatistics($tenantId, $days = 30)
    {
        try {
            $startDate = date('Y-m-d', strtotime("-$days days"));
            $logs = self::getLogs($tenantId, $startDate);

            $stats = [];
            $totalActivities = count($logs);
            $uniqueUsers = [];
            $actionCounts = [];

            foreach ($logs as $log) {
                $uniqueUsers[] = $log['user_id'];
                $action = $log['action'];
                $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
            }

            return [
                'total_activities' => $totalActivities,
                'unique_users' => count(array_unique($uniqueUsers)),
                'actions' => $actionCounts,
                'period_days' => $days
            ];
        } catch (\Exception $e) {
            error_log("Statistics Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean old log files
     */
    private static function cleanOldLogs()
    {
        try {
            $files = glob(self::$logDir . '/activity_*.log');
            
            if (count($files) <= self::$maxFiles) {
                return;
            }

            // Sort files by date
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Remove oldest files
            $filesToDelete = array_slice($files, 0, count($files) - self::$maxFiles);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        } catch (\Exception $e) {
            error_log("Clean Logs Error: " . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     */
    private static function getClientIP()
    {
        $ipaddress = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
            
        return $ipaddress;
    }

    /**
     * Get log file list for admin
     */
    public static function getLogFiles($tenantId)
    {
        $logDir = __DIR__ . '/../../logs/activities/' . $tenantId;
        
        if (!file_exists($logDir)) {
            return [];
        }

        $files = glob($logDir . '/activity_*.log');
        rsort($files);
        
        $fileInfo = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $fileDate = str_replace(['activity_', '.log'], '', $filename);
            
            $fileInfo[] = [
                'filename' => $filename,
                'date' => $fileDate,
                'size' => filesize($file),
                'modified' => filemtime($file)
            ];
        }

        return $fileInfo;
    }
}
