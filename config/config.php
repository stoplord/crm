<?php
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '.stoplord.in', // Your specific domain
    'secure' => true,           // Enforce HTTPS
    'httponly' => true,         // Prevent JavaScript access
    'samesite' => 'Strict'
]);
session_start();

// ==========================================
// 1. REGIONAL SETTINGS (Rule 49)
// ==========================================
// Force application timezone for all date/time calculations
date_default_timezone_set('Asia/Kolkata');

// ==========================================
// 2. ERROR HANDLING (Rule 39)
// ==========================================
// Set to TRUE for local development, FALSE for production
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    // Hide raw errors from users in production
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    
    // Log errors securely to the server file system
    ini_set('log_errors', 1);
    // Ensure the /logs directory exists outside public access
    ini_set('error_log', __DIR__ . '/../logs/php-error.log');
}

// ==========================================
// 3. APPLICATION CONSTANTS
// ==========================================
define('APP_NAME', 'CRM System');

// Dynamically determine the Base URL for absolute links in the UI
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $protocol . $domainName);

// ==========================================
// 4. DIRECTORY PATHS & FILE UPLOADS
// ==========================================
// Define absolute paths to prevent directory traversal attacks
define('ROOT_DIR', realpath(__DIR__ . '/..'));
define('UPLOAD_DIR', ROOT_DIR . '/uploads');
define('PROFILE_PIC_DIR', UPLOAD_DIR . '/profiles/');
define('DOCUMENT_DIR', UPLOAD_DIR . '/documents/');

// Automatically create secure upload directories if they don't exist
if (!is_dir(PROFILE_PIC_DIR)) { 
    mkdir(PROFILE_PIC_DIR, 0755, true); 
}
if (!is_dir(DOCUMENT_DIR)) { 
    mkdir(DOCUMENT_DIR, 0755, true); 
}

// Security constraints for uploaded files (Rule 38)
define('MAX_UPLOAD_SIZE', 5242880); // 5 MB maximum file size limit
define('ALLOWED_MIME_TYPES', [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp'
]);

// ==========================================
// 5. BUSINESS LOGIC DEFAULTS (Rule 23 & 20)
// ==========================================
// These constants act as reliable fallbacks if database `settings` table is unavailable
define('DEFAULT_DAILY_TARGET_HOURS', 6); 
define('DEFAULT_DAILY_TARGET_SECONDS', DEFAULT_DAILY_TARGET_HOURS * 3600); // 21600 seconds
define('INACTIVITY_TIMEOUT_SECONDS', 300); // 5 minutes inactivity cutoff

// ==========================================
// 6. SESSION SECURITY CONFIGURATION
// ==========================================
// Enforce strict session parameters before session_start() is called elsewhere
ini_set('session.cookie_httponly', 1); // Prevent XSS hijacking
ini_set('session.use_only_cookies', 1); // Prevent session fixation via URL
ini_set('session.cookie_samesite', 'Strict'); // CSRF mitigation layer
if ($protocol === "https://") {
    ini_set('session.cookie_secure', 1); // Only send cookies over HTTPS
}
?>