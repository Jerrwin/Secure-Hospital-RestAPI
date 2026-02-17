<?php

date_default_timezone_set('Asia/Kolkata');

// 1. Error Reporting (KEPT)
// Critical for development. Shows you if something breaks.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Load Environment Variables (ADDED)
// Reads the .env file and sets up database credentials
$envPath = __DIR__ . '/../.env';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;
        
        // Parse "KEY=VALUE"
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
} else {
    error_log("Warning: .env file not found in " . $envPath);
}

?>