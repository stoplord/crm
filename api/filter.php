<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login and AJAX-only access
requireLogin();

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('HTTP/1.1 403 Forbidden');
    die(json_encode(['status' => 'error', 'msg' => 'Direct access forbidden.']));
}

header('Content-Type: application/json');

try {
    // 1. Retrieve and Sanitize Filter Parameters
    $temperature = sanitizeInput($_GET['temperature'] ?? '');
    $dealStatus  = sanitizeInput($_GET['deal_status'] ?? '');
    $progress    = sanitizeInput($_GET['progress'] ?? '');
    $adminId     = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
    $dateFilter  = sanitizeInput($_GET['date_filter'] ?? '');
    $startDate   = sanitizeInput($_GET['start_date'] ?? '');
    $endDate     = sanitizeInput($_GET['end_date'] ?? '');

    // 2. Base SQL Query
    $sql = "
        SELECT 
            c.id, 
            c.client_code, 
            c.name, 
            c.temperature, 
            c.phone, 
            c.city, 
            c.requirement, 
            c.status, 
            c.deal_status,
            c.final_price, 
            c.created_at,
            u.name AS assigned_admin_name,
            (SELECT MAX(next_followup_date) FROM client_activities WHERE client_id = c.id) AS next_followup
        FROM clients c
        LEFT JOIN users u ON c.assigned_admin_id = u.id
        WHERE c.deleted_at IS NULL
    ";

    $params = [];

    // 3. Apply Role-Based Access Control (Rules 6 & 7)
    if ($_SESSION['role'] === 'admin') {
        $sql .= " AND (c.assigned_admin_id = ? OR c.created_by = ?) ";
        $params[] = $_SESSION['user_id'];
        $params[] = $_SESSION['user_id'];
    } elseif ($_SESSION['role'] === 'super_admin' && $adminId) {
        // Super Admin can filter by specific admin
        $sql .= " AND c.assigned_admin_id = ? ";
        $params[] = $adminId;
    }

    // 4. Apply Custom Filters
    
    // Temperature Filter
    if ($temperature && in_array(strtoupper($temperature), ['HOT', 'COLD'])) {
        $sql .= " AND c.temperature = ? ";
        $params[] = strtoupper($temperature);
    }

    // Deal Status Filter
    $validDealStatuses = ['Open', 'In Progress', 'Converted', 'Rejected', 'On Hold'];
    if ($dealStatus && in_array($dealStatus, $validDealStatuses)) {
        $sql .= " AND c.deal_status = ? ";
        $params[] = $dealStatus;
    }

    // Progress / Client Status Filter
    if ($progress) {
        $sql .= " AND c.status = ? ";
        $params[] = $progress;
    }

    // Date Filter Logic (Based on client creation date)
    if ($dateFilter) {
        switch ($dateFilter) {
            case 'today':
                $sql .= " AND DATE(c.created_at) = CURDATE() ";
                break;
            case 'yesterday':
                $sql .= " AND DATE(c.created_at) = CURDATE() - INTERVAL 1 DAY ";
                break;
            case 'this_week':
                // Assuming week starts on Monday (Mode 1)
                $sql .= " AND YEARWEEK(c.created_at, 1) = YEARWEEK(CURDATE(), 1) ";
                break;
            case 'this_month':
                $sql .= " AND YEAR(c.created_at) = YEAR(CURDATE()) AND MONTH(c.created_at) = MONTH(CURDATE()) ";
                break;
            case 'custom':
                if (!empty($startDate) && !empty($endDate)) {
                    $sql .= " AND DATE(c.created_at) BETWEEN ? AND ? ";
                    $params[] = $startDate;
                    $params[] = $endDate;
                }
                break;
        }
    }

    // 5. Order results (Newest first) and limit
    $sql .= " ORDER BY c.created_at DESC LIMIT 100";

    // 6. Execute Query securely
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // 7. Format output data for UI consistency
    $formattedResults = [];
    foreach ($results as $row) {
        $formattedResults[] = [
            'id' => $row['id'],
            'client_code' => escapeOutput($row['client_code']),
            'name' => escapeOutput($row['name']),
            'temperature_badge' => getTemperatureBadge($row['temperature']),
            'phone' => escapeOutput($row['phone']),
            'city' => escapeOutput($row['city'] ?? 'N/A'),
            'requirement_short' => mb_strimwidth(escapeOutput($row['requirement']), 0, 30, '...'),
            'status' => escapeOutput($row['status']),
            'deal_status_badge' => getDealStatusBadge($row['deal_status']),
            'assigned_admin' => escapeOutput($row['assigned_admin_name'] ?? 'Unassigned'),
            'next_followup' => formatDateTime($row['next_followup']),
            'final_price' => $row['final_price'] ? '₹' . number_format($row['final_price'], 2) : '-',
            'created_date' => formatDate($row['created_at'])
        ];
    }

    echo json_encode([
        'status' => 'success',
        'count' => count($formattedResults),
        'data' => $formattedResults
    ]);

} catch (PDOException $e) {
    // Log error securely, do not output raw error to user (Rule 39)
    error_log("Filter API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'msg' => 'A database error occurred while filtering clients.']);
}
?>