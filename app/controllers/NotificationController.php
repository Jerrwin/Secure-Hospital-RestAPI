<?php

namespace App\Controllers;

use App\Models\Notification;
use App\Middleware\AuthMiddleware;
use App\Helpers\ResponseHelper;
use App\Core\Database;

class NotificationController
{
    private $notificationModel;
    private $masterTenantModel;
    private $db;

    public function __construct()
    {
        $database = new Database();
        $masterDb = $database->connectMaster();
        $this->db = $masterDb;

        $this->masterTenantModel = new \App\Models\MasterTenant($masterDb);
        $this->notificationModel = new Notification($this->db);
    }

    private function connectByTenantId($tenantId)
    {
        if (!$tenantId) return false;

        $tenant = $this->masterTenantModel->getDetailsById($tenantId);
        if (!$tenant || $tenant['status'] !== 'active') return false;

        $database = new Database();
        $this->db = $database->connectTenant($tenant['db_name']);

        $this->notificationModel = new Notification($this->db);
        return true;
    }

    /**
     * Determine user_type from the role string.
     * Patients are 'patient', everyone else is 'staff'.
     */
    private function getUserType($role)
    {
        return strtolower($role) === 'patient' ? 'patient' : 'staff';
    }

    // GET /api/notifications
    public function index()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];

        if (!$this->connectByTenantId($user['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $userType = $this->getUserType($user['role']);
        $notifications = $this->notificationModel->getByUser(
            $user['user_id'],
            $userType,
            $user['tenant_id']
        );
        $unreadCount = $this->notificationModel->getUnreadCount(
            $user['user_id'],
            $userType,
            $user['tenant_id']
        );

        ResponseHelper::send(true, "Notifications retrieved.", [
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    }

    // PUT /api/notifications/{id}/read
    public function markAsRead($id)
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];

        if (!$this->connectByTenantId($user['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $userType = $this->getUserType($user['role']);
        $this->notificationModel->markAsRead($id, $user['user_id'], $userType);

        ResponseHelper::send(true, "Notification marked as read.");
    }

    // PUT /api/notifications/read-all
    public function markAllAsRead()
    {
        AuthMiddleware::handle();
        $user = $_REQUEST['user'];

        if (!$this->connectByTenantId($user['tenant_id'])) {
            ResponseHelper::send(false, "Hospital database not found.", [], 403);
            return;
        }

        $userType = $this->getUserType($user['role']);
        $this->notificationModel->markAllAsRead(
            $user['user_id'],
            $userType,
            $user['tenant_id']
        );

        ResponseHelper::send(true, "All notifications marked as read.");
    }
}
