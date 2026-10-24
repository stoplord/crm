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
    // 1. Sanitize the incoming search term
    $searchTerm = sanitizeInput($_GET['q'] ?? '');
    
    // 2. Base SQL Query
    // We include a subquery to get the latest next_followup_date from client_activities
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
    }

    // 4. Apply Search Filters (Rule 13)
    if (!empty($searchTerm)) {
        $sql .= " AND (
            c.name LIKE ? OR 
            c.phone LIKE ? OR 
            c.whatsapp LIKE ? OR 
            c.email LIKE ? OR 
            c.client_code LIKE ? OR 
            c.city LIKE ? OR 
            c.state LIKE ?
        ) ";
        
        // Prepare the wildcard search term for LIKE clauses
        $likeTerm = '%' . $searchTerm . '%';
        
        // Bind the same term 7 times for the 7 OR conditions
        $params = array_merge($params, array_fill(0, 7, $likeTerm));
    }

    // 5. Order results (Newest first) and limit to prevent massive payload crashes (Rule 36 partial)
    $sql .= " ORDER BY c.created_at DESC LIMIT 50";

    // 6. Execute Query securely
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // 7. Format output data to include UI-ready badges securely
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
    error_log("Search API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'msg' => 'A database error occurred while searching.']);
}
?>