<?php

date_default_timezone_set('Asia/Kolkata');

// 1. Load Environment Variables (MOVED UP)
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

// 2. Error Reporting (Secured - NOW AFTER ENV LOAD)
// Only show errors if explicitly in development mode
$appEnv = $_ENV['APP_ENV'] ?? 'production';

if ($appEnv === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

?>