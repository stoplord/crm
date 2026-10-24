<?php


require_once __DIR__ . '/../config/database.php';

// ==========================================
// 1. BASIC ROLE CHECKS
// ==========================================

/**
 * Check if the currently logged-in user is a Super Admin.
 * @return bool
 */
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

/**
 * Check if the currently logged-in user is an Admin (or Super Admin).
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);
}

// ==========================================
// 2. ADMIN MANAGEMENT PERMISSIONS
// ==========================================

/**
 * RULE 1 & 2: Only Super Admin can create Admin accounts.
 * @return bool
 */
function canCreateAdmin() {
    return isSuperAdmin();
}

/**
 * RULE 4 & 5: Admin cannot delete Admins. Only Super Admin can delete records.
 * @return bool
 */
function canDeleteAdmin() {
    return isSuperAdmin();
}

/**
 * Admin MUST NOT be able to view or modify another admin's private profile unless explicitly permitted.
 * @param int $target_admin_id
 * @return bool
 */
function canViewAdminProfile($target_admin_id) {
    if (isSuperAdmin()) {
        return true;
    }
    // Admin can only view their own profile
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] == $target_admin_id;
}

// ==========================================
// 3. CLIENT MANAGEMENT PERMISSIONS
// ==========================================

/**
 * RULE 3 & 5: Admin cannot delete clients. Only Super Admin can delete records.
 * @return bool
 */
function canDeleteClient() {
    return isSuperAdmin();
}


function canViewClient($client_id) {
    if (isSuperAdmin()) {
        return true;
    }

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    global $pdo;
    
    // Check if the admin is assigned to the client or if they created the client
    $stmt = $pdo->prepare("
        SELECT id 
        FROM clients 
        WHERE id = ? 
        AND deleted_at IS NULL 
        AND (assigned_admin_id = ? OR created_by = ?)
    ");
    $stmt->execute([$client_id, $_SESSION['user_id'], $_SESSION['user_id']]);
    
    return $stmt->rowCount() > 0;
}


function canEditClient($client_id) {
    // Edit logic mirrors View logic based on the spec
    return canViewClient($client_id);
}


function canAssignClient() {
    return isSuperAdmin();
}

// ==========================================
// 4. SETTINGS & REPORTING PERMISSIONS
// ==========================================

/**
 * Admin MUST NOT access Super Admin-only settings.
 * @return bool
 */
function canAccessSettings() {
    return isSuperAdmin();
}

/**
 * RULE 8: Super Admin can see each Admin's performance.
 * @return bool
 */
function canViewAllPerformanceReports() {
    return isSuperAdmin();
}

// ==========================================
// 5. ENFORCEMENT MIDDLEWARE
// ==========================================


function enforcePermission($condition, $redirect_url = '/public/dashboard.php', $message = 'Access Denied: You do not have permission to perform this action.') {
    if (!$condition) {
        // Handle AJAX / Fetch API requests securely
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'msg' => $message]);
            exit;
        }

        // Handle standard HTTP requests
        $_SESSION['error_message'] = $message;
        header("Location: " . $redirect_url);
        exit;
    }
}


function enforceClientAccess($client_id) {
    if (!canViewClient($client_id)) {
        enforcePermission(false, '/public/clients/index.php', 'Unauthorized: You do not have access to this client.');
    }
}
?>