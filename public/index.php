<?php


// Load core configuration and start the secure session
require_once __DIR__ . '/../config/database.php';

// 1. Check if the user has an active session
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    
    // 2. Enforce Inactivity Auto-Logout for Admins on the initial load (Rule 20)
    if ($_SESSION['role'] === 'admin' && isset($_SESSION['last_activity'])) {
        $inactive_seconds = time() - $_SESSION['last_activity'];
        
        if ($inactive_seconds > INACTIVITY_TIMEOUT_SECONDS) { // Configured as 300s (5 mins)
            // The session has expired while the browser was closed/inactive
            // Redirect to a specific logout route to log the exact duration securely
            header("Location: /public/logout.php?reason=inactivity");
            exit;
        }
        
        // Update last activity timestamp for the new page load
        $_SESSION['last_activity'] = time();
    }
    
    // 3. Session is valid, route to the dashboard
    header("Location: /public/dashboard.php");
    exit;
}

// 4. No valid session found, route to the login portal
header("Location: /public/login.php");
exit;
?>