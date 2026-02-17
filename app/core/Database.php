<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private $host;
    private $name;
    private $user;
    private $pass;
    private $port;
    public $conn;

    public function __construct() {
        
        // Load credentials from Environment Variables
        $this->host = $_ENV['DB_HOST'];
        $this->name = $_ENV['DB_NAME'];
        $this->user = $_ENV['DB_USER'];
        $this->pass = $_ENV['DB_PASS'];
        $this->port = $_ENV['DB_PORT'];
    }

    public function connect() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->name . ";charset=utf8mb4";
            
            $this->conn = new PDO($dsn, $this->user, $this->pass);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
            die();
        }

        return $this->conn;
    }
}
?>