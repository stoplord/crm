<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

function startWorkSession($admin_id) {
    global $pdo;
    $now = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = $_SERVER['HTTP_USER_AGENT'];
    
    $stmt = $pdo->prepare("INSERT INTO work_sessions (admin_id, login_time, last_activity, session_date, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$admin_id, $now, $now, date('Y-m-d'), $ip, $ua]);
    $_SESSION['current_session_id'] = $pdo->lastInsertId();
    $_SESSION['last_activity'] = time();
}

function closeActiveSession($admin_id, $reason = 'manual') {
    global $pdo;
    if (!isset($_SESSION['current_session_id'])) return;
    
    $session_id = $_SESSION['current_session_id'];
    $now = date('Y-m-d H:i:s');
    
    // Calculate duration based on last known activity, NOT logout time, to prevent idle time counting
    $stmt = $pdo->prepare("UPDATE work_sessions SET logout_time = ?, logout_reason = ?, duration_seconds = TIMESTAMPDIFF(SECOND, login_time, last_activity) WHERE id = ?");
    $stmt->execute([$now, $reason, $session_id]);
    
    // Aggregate to daily log
    $stmt = $pdo->prepare("SELECT duration_seconds, session_date FROM work_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
    
    $updateDaily = $pdo->prepare("INSERT INTO daily_work_logs (admin_id, work_date, total_seconds) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE total_seconds = total_seconds + VALUES(total_seconds)");
    $updateDaily->execute([$admin_id, $session['session_date'], $session['duration_seconds']]);
    
    unset($_SESSION['current_session_id']);
}

// Process Heartbeat sent from JS
$input = json_decode(file_get_contents('php://input'), true);
if (isset($input['action']) && $input['action'] === 'heartbeat') {
    $time_since_last = time() - $_SESSION['last_activity'];
    
    if ($time_since_last > 300) { // 5 minutes inactivity
        closeActiveSession($admin_id, 'inactivity');
        echo json_encode(['status' => 'logged_out', 'msg' => '5 minutes inactivity']);
        exit;
    }
    
    // Valid heartbeat, update last activity
    $stmt = $pdo->prepare("UPDATE work_sessions SET last_activity = ? WHERE id = ?");
    $stmt->execute([$now, $_SESSION['current_session_id']]);
    $_SESSION['last_activity'] = time();
    
    // Get total time today for UI sync
    $stmt = $pdo->prepare("SELECT SUM(total_seconds) as previous_total FROM daily_work_logs WHERE admin_id = ? AND work_date = ?");
    $stmt->execute([$admin_id, $today]);
    $previous_total = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, login_time, last_activity) as current_session FROM work_sessions WHERE id = ?");
    $stmt->execute([$_SESSION['current_session_id']]);
    $current_session = $stmt->fetchColumn() ?: 0;
    
    echo json_encode(['status' => 'active', 'total_seconds' => $previous_total + $current_session]);
}
?>