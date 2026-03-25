<?php

namespace App\Helpers;

use App\Models\ActivityLog;

class ActivityLogger
{
    private static $instance = null;
    private $activityLog;
    private $db;

    private function __construct($db)
    {
        $this->db = $db;
        $this->activityLog = new ActivityLog($db);
    }

    public static function getInstance($db = null)
    {
        if (self::$instance === null) {
            if ($db === null) {
                throw new \Exception("Database connection required for first initialization");
            }
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Log user activity
     */
    public static function log($action, $details = '', $endpoint = '', $method = '')
    {
        try {
            $instance = self::getInstance();
            
            // Get user info from request
            $user = $_REQUEST['user'] ?? null;
            
            if (!$user) {
                return false; // Don't log if no user context
            }

            $logData = [
                'tenant_id' => $user['tenant_id'] ?? null,
                'user_id' => $user['user_id'] ?? null,
                'user_name' => $user['name'] ?? 'Unknown',
                'user_email' => $user['email'] ?? 'Unknown',
                'user_role' => $user['role'] ?? 'Unknown',
                'action' => $action,
                'method' => $method ?: $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'endpoint' => $endpoint ?: $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
                'ip_address' => self::getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'details' => is_array($details) ? json_encode($details) : $details
            ];

            return $instance->activityLog->log($logData);
        } catch (\Exception $e) {
            // Fail silently to not break the main application
            error_log("Activity Logger Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication activities
     */
    public static function logAuth($action, $email = '', $success = true, $details = '')
    {
        $details = array_merge([
            'email' => $email,
            'success' => $success,
            'ip' => self::getClientIP()
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log CRUD operations
     */
    public static function logCRUD($action, $resource, $resourceId = null, $details = '')
    {
        $details = array_merge([
            'resource' => $resource,
            'resource_id' => $resourceId
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log file operations
     */
    public static function logFile($action, $filename, $details = '')
    {
        $details = array_merge([
            'filename' => $filename,
            'file_size' => file_exists($filename) ? filesize($filename) : 0
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log payment activities
     */
    public static function logPayment($action, $amount, $method = '', $details = '')
    {
        $details = array_merge([
            'amount' => $amount,
            'payment_method' => $method
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log appointment activities
     */
    public static function logAppointment($action, $appointmentId = null, $patientId = null, $details = '')
    {
        $details = array_merge([
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log billing activities
     */
    public static function logBilling($action, $invoiceId = null, $amount = null, $details = '')
    {
        $details = array_merge([
            'invoice_id' => $invoiceId,
            'amount' => $amount
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log staff activities
     */
    public static function logStaff($action, $staffId = null, $details = '')
    {
        $details = array_merge([
            'staff_id' => $staffId
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
    }

    /**
     * Log patient activities
     */
    public static function logPatient($action, $patientId = null, $details = '')
    {
        $details = array_merge([
            'patient_id' => $patientId
        ], is_array($details) ? $details : []);

        return self::log($action, $details);
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
     * Update database connection (useful for tenant switching)
     */
    public static function updateDatabase($db)
    {
        self::$instance = new self($db);
    }

    /**
     * Get activity logs
     */
    public static function getLogs($tenantId, $limit = 100, $offset = 0)
    {
        try {
            $instance = self::getInstance();
            return $instance->activityLog->getByTenant($tenantId, $limit, $offset);
        } catch (\Exception $e) {
            error_log("Activity Logger Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user logs
     */
    public static function getUserLogs($tenantId, $userId, $limit = 50)
    {
        try {
            $instance = self::getInstance();
            return $instance->activityLog->getByUser($tenantId, $userId, $limit);
        } catch (\Exception $e) {
            error_log("Activity Logger Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent activities
     */
    public static function getRecentActivities($tenantId, $limit = 10)
    {
        try {
            $instance = self::getInstance();
            return $instance->activityLog->getRecentActivities($tenantId, $limit);
        } catch (\Exception $e) {
            error_log("Activity Logger Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search logs
     */
    public static function searchLogs($tenantId, $searchTerm, $limit = 50)
    {
        try {
            $instance = self::getInstance();
            return $instance->activityLog->search($tenantId, $searchTerm, $limit);
        } catch (\Exception $e) {
            error_log("Activity Logger Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get statistics
     */
    public static function getStatistics($tenantId, $days = 30)
    {
        try {
            $instance = self::getInstance();
            return $instance->activityLog->getStatistics($tenantId, $days);
        } catch (\Exception $e) {
            error_log("Activity Logger Error: " . $e->getMessage());
            return [];
        }
    }
}
