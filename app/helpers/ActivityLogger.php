<?php

namespace App\Helpers;

/**
 * ActivityLogger - A wrapper helper that bridges to FileActivityLogger.
 * This resolves any "Undefined class ActivityLog" errors by removing the DB dependency.
 */
class ActivityLogger
{
    /**
     * Log user activity - Bridges to FileActivityLogger
     */
    public static function log($action, $details = '', $endpoint = '', $method = '')
    {
        return FileActivityLogger::log($action, $details, $endpoint, $method);
    }

    /**
     * Log authentication activities
     */
    public static function logAuth($action, $email = '', $success = true, $details = '')
    {
        return FileActivityLogger::logAuth($action, $email, $success, $details);
    }

    /**
     * Log CRUD operations
     */
    public static function logCRUD($action, $resource, $resourceId = null, $details = '')
    {
        return FileActivityLogger::logCRUD($action, $resource, $resourceId, $details);
    }

    /**
     * Log payment activities
     */
    public static function logPayment($action, $amount, $method = '', $details = '')
    {
        return FileActivityLogger::logPayment($action, $amount, $method, $details);
    }

    /**
     * Log billing activities
     */
    public static function logBilling($action, $invoiceId = null, $amount = null, $details = '')
    {
        return FileActivityLogger::logBilling($action, $invoiceId, $amount, $details);
    }

    /**
     * Log staff activities
     */
    public static function logStaff($action, $staffId = null, $details = '')
    {
        return FileActivityLogger::logStaff($action, $staffId, $details);
    }

    /**
     * Log appointment activities
     */
    public static function logAppointment($action, $appointmentId = null, $patientId = null, $details = '')
    {
        return FileActivityLogger::logAppointment($action, $appointmentId, $patientId, $details);
    }

    /**
     * Log patient activities
     */
    public static function logPatient($action, $patientId = null, $details = '')
    {
        return FileActivityLogger::logPatient($action, $patientId, $details);
    }

    /**
     * Get activity logs for dashboard/admin
     */
    public static function getLogs($tenantId, $limit = 100)
    {
        return FileActivityLogger::getLogs($tenantId, null, null, $limit);
    }

    /**
     * Get recent activities
     */
    public static function getRecentActivities($tenantId, $limit = 10)
    {
        return FileActivityLogger::getRecentActivities($tenantId, $limit);
    }

    /**
     * Search logs
     */
    public static function searchLogs($tenantId, $searchTerm, $limit = 50)
    {
        return FileActivityLogger::searchLogs($tenantId, $searchTerm, $limit);
    }

    /**
     * Get statistics
     */
    public static function getStatistics($tenantId, $days = 30)
    {
        return FileActivityLogger::getStatistics($tenantId, $days);
    }
}
