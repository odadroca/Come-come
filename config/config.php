<?php
/**
 * Come-Come Configuration
 * Version 0.170
 */

// Environment
define('APP_ENV', getenv('APP_ENV') ?: 'production'); // production, development
define('APP_VERSION', '0.170');

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('DATA_PATH', ROOT_PATH . '/data');
define('DB_PATH', DATA_PATH . '/comecome.db');
define('BACKUP_PATH', DATA_PATH . '/backups');

// Security
define('REQUIRE_HTTPS', APP_ENV === 'production'); // Force HTTPS in production
define('UNLOCK_CODE', '12345678'); // 8-digit emergency unlock code (change after install)

// Session
define('SESSION_LIFETIME', 60 * 60 * 24 * 7); // 7 days in seconds
define('SESSION_COOKIE_NAME', 'COMECOME_SESSION');

// Rate limiting
define('RATE_LIMIT_AUTH', 5);        // Auth endpoint: 5 requests per window
define('RATE_LIMIT_AUTH_WINDOW', 300); // Auth window: 5 minutes (300 seconds)
define('RATE_LIMIT_API', 100);       // API endpoints: 100 requests per window
define('RATE_LIMIT_API_WINDOW', 60);  // API window: 1 minute
define('RATE_LIMIT_GUEST', 50);      // Guest endpoints: 50 requests per window

// PIN security
define('PIN_HASH_COST', 12);         // Bcrypt cost (higher = slower, more secure)
define('PIN_MAX_ATTEMPTS', 5);        // Failed attempts before lockout
define('PIN_LOCKOUT_DURATION', 300);  // Lockout duration in seconds (5 minutes)

// Logging
define('LOG_PATH', DATA_PATH . '/logs');
define('LOG_ERRORS', true);
define('LOG_AUDIT', true);

// Localization
define('DEFAULT_LOCALE', 'en-UK');
define('SUPPORTED_LOCALES', ['en-UK', 'pt-PT']);

// Database
define('DB_TIMEOUT', 5000); // Connection timeout in milliseconds

// PDF Export
define('PDF_MAX_DAYS', 365); // Maximum days for "whole history" report

// Guest sessions
define('GUEST_TOKEN_LENGTH', 32); // Token length in bytes (64 hex chars)

// Ensure data directory exists
if (!file_exists(DATA_PATH)) {
    mkdir(DATA_PATH, 0750, true);
}

if (!file_exists(BACKUP_PATH)) {
    mkdir(BACKUP_PATH, 0750, true);
}

if (!file_exists(LOG_PATH)) {
    mkdir(LOG_PATH, 0750, true);
}

// Ensure correct permissions on data directory
if (file_exists(DATA_PATH)) {
    chmod(DATA_PATH, 0750);
}

// Ensure correct permissions on database file if it exists
if (file_exists(DB_PATH)) {
    chmod(DB_PATH, 0640);
}

// HTTPS enforcement
if (REQUIRE_HTTPS && !isset($_SERVER['HTTPS']) && $_SERVER['REQUEST_METHOD'] !== 'CLI') {
    // Allow localhost for development
    $allowed_hosts = ['localhost', '127.0.0.1', '::1'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    
    if (!in_array($host, $allowed_hosts) && !preg_match('/\.local$/', $host)) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit('HTTPS required');
    }
}

// Error handling
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_PATH . '/php_errors.log');
}
