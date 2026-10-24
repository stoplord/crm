<?php
/**
 * CRM SYSTEM - SECURE LOGOUT SCRIPT
 * Safely ends the session, calculates active working time for Admins, 
 * inserts audit logs, and redirects to the login portal.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $role    = $_SESSION['role'];
    
    // Determine reason for logout (manual, inactivity, forced)
    $reason  = isset($_GET['reason']) ? sanitizeInput($_GET['reason']) : 'manual';
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];

    try {
        $pdo->beginTransaction();

        // ==========================================
        // 1. ADMIN WORKING TIME CALCULATION
        // ==========================================
        if ($role === 'admin' && isset($_SESSION['current_session_id'])) {
            $session_id = $_SESSION['current_session_id'];
            $now = date('Y-m-d H:i:s');
            
            // RULE 31: Calculate duration based on `last_activity` (NOT current logout time)
            // This ensures the 5-minute inactivity window is NOT added to their working hours.
            $stmt = $pdo->prepare("
                UPDATE work_sessions 
                SET logout_time = ?, 
                    logout_reason = ?, 
                    duration_seconds = TIMESTAMPDIFF(SECOND, login_time, last_activity) 
                WHERE id = ?
            ");
            $stmt->execute([$now, $reason, $session_id]);

            // Retrieve the verified active duration for this specific session
            $stmt = $pdo->prepare("SELECT duration_seconds, session_date FROM work_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $sessionData = $stmt->fetch();

            if ($sessionData) {
                // RULE 21 & 22: Accumulate time into today's log without overwriting previous sessions
                $updateDaily = $pdo->prepare("
                    INSERT INTO daily_work_logs (admin_id, work_date, total_seconds) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE total_seconds = total_seconds + VALUES(total_seconds)
                ");
                $updateDaily->execute([$user_id, $sessionData['session_date'], $sessionData['duration_seconds']]);
            }
        }

        // ==========================================
        // 2. AUDIT LOGGING (RULE 27 & 28)
        // ==========================================
        $auditDesc = ($role === 'super_admin') ? 'Super Admin logged out' : "Admin logged out (Reason: $reason)";
        
        $audit = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, role, action, target_type, target_id, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $audit->execute([$user_id, $role, 'logout', 'user', $user_id, $auditDesc, $ip, $ua]);

        $pdo->commit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Logout Error: " . $e->getMessage());
        // We continue with session destruction even if DB logging fails to prevent stuck sessions
    }
}

// ==========================================
// 3. SECURE SESSION DESTRUCTION
// ==========================================
// Unset all session variables
$_SESSION = [];

// Destroy the session cookie securely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session file on the server
session_destroy();

// ==========================================
// 4. REDIRECT
// ==========================================
$redirect_url = "/public/login.php";

// Pass the correct message to the login page (Rule 20)
if (isset($_GET['reason']) && $_GET['reason'] === 'inactivity') {
    $redirect_url .= "?msg=timeout";
} else {
    $redirect_url .= "?msg=logged_out";
}

header("Location: " . $redirect_url);
exit;
?>