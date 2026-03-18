<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private $host;
    private $port;

    // Master DB Credentials (from .env)
    private $masterDb;
    private $masterUser;
    private $masterPass;

    public function __construct()
    {
        $this->host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $this->port = $_ENV['DB_PORT'] ?? '3308'; // Make sure this matches your MySQL port

        $this->masterDb = $_ENV['DB_MASTER_NAME'] ?? 'tenant';
        $this->masterUser = $_ENV['DB_MASTER_USER'] ?? 'root';
        $this->masterPass = $_ENV['DB_MASTER_PASS'] ?? '200323';
    }

    /**
     * 1. CONNECT TO MASTER DB
     * Use this for SuperAdmin logins and finding Tenant DB names.
     */
    public function connectMaster()
    {
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->masterDb};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $this->masterUser, $this->masterPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            // In production, log this error instead of displaying it
            die(json_encode(["success" => false, "message" => "Master DB Connection Failed."]));
        }
    }

    /**
     * 2. CONNECT TO TENANT DB
     * Use this for EVERYTHING else (Patients, Appointments, Staff).
     * * @param string $dbName The physical name of the hospital's database
     * @param string|null $dbUser Optional specific database user
     * @param string|null $dbPass Optional specific database password
     */
    public function connectTenant($dbName, $dbUser = null, $dbPass = null)
    {
        // If a specific user isn't provided, fallback to the root user
        $user = $dbUser ?: $this->masterUser;
        $pass = $dbPass ?: $this->masterPass;

        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        } catch (PDOException $e) {
            die(json_encode(["success" => false, "message" => "Tenant DB Connection Failed."]));
        }
    }
}
