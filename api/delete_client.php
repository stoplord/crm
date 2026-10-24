<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requireLogin();
requireSuperAdmin(); // RULE: Only Super Admin can delete

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token']);
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    
    if ($client_id) {
        try {
            $pdo->beginTransaction();
            
            // Soft Delete Policy Implementation
            $stmt = $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$client_id]);
            
            // Audit Log
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$_SESSION['user_id'], $_SESSION['role'], 'soft_delete', 'client', $client_id, "Deleted client ID $client_id", $_SERVER['REMOTE_ADDR']]);
            
            $pdo->commit();
            header("Location: /public/clients/index.php?msg=deleted");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            die("Error deleting client.");
        }
    }
}
?>