<?php

// 1. Secure Session Initialization (Prevents random idle logouts)
if (session_status() === PHP_SESSION_NONE) {
    // Extend PHP's native garbage collection to 12 hours (43200 seconds)
    ini_set('session.gc_maxlifetime', 43200);
    
    session_set_cookie_params([
        'lifetime' => 43200, 
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // Require HTTPS if active
        'httponly' => true,         // Prevent JavaScript from stealing the session
        'samesite' => 'Strict'      // Prevent Cross-Site Request Forgery
    ]);
    session_start();
}


function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        
        // Strictly check if the requested URL is an API endpoint
        $isApiEndpoint = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
        
        if ($isApiEndpoint) {
            // Safely output JSON ONLY for background API calls
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'status' => 'error', 
                'msg' => 'Session expired', 
                'redirect' => '/public/login.php?msg=timeout'
            ]);
            exit;
        }
        

        header("Location: /public/login.php?msg=timeout");
        exit;
    }
}


function requireSuperAdmin() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        // Kick unauthorized Admins back to their own dashboard
        header("Location: /public/dashboard.php?error=unauthorized");
        exit;
    }
}


function isSuperAdmin() {
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin');
}
?>