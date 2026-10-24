<?php
/**
 * CRM SYSTEM - CLIENT LIST & MANAGEMENT
 * Public: public/clients/index.php
 * Standalone & Crash-Proof Version.
 * Features: Live search, multi-parameter filtering, pagination, role-based visibility, and quick-contact dropdown.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

$pageTitle = 'Client Management';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$success_msg = '';
$error_msg = '';
$db_error = '';

// 1. Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success_msg = 'Client record has been securely soft-deleted.';
}

// 2. Handle POST Actions (Super Admin Deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed.");
    }

    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);

    if ($action === 'delete_client' && $client_id) {
        if (!$isSuperAdmin) {
            $error_msg = 'Unauthorized: Only Super Admin can delete client records.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Verify client exists
                $stmt = $pdo->prepare("SELECT name, client_code FROM clients WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($client) {
                    $del = $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?");
                    $del->execute([$client_id]);

                    $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $audit->execute([
                        $user_id, $role, 'soft_delete_client', 'client', $client_id,
                        "Soft-deleted client {$client['client_code']} ({$client['name']})",
                        $_SERVER['REMOTE_ADDR']
                    ]);

                    $pdo->commit();
                    
                    // PRG Redirect retaining query parameters so user stays on the same filtered page
                    $redirectUrl = '/public/clients/index.php?msg=deleted';
                    $qParams = $_GET;
                    unset($qParams['msg']); // Remove old message parameter
                    if (!empty($qParams)) {
                        $redirectUrl .= '&' . http_build_query($qParams);
                    }
                    
                    header("Location: " . $redirectUrl);
                    exit;
                } else {
                    $pdo->rollBack();
                    $error_msg = 'Client record not found or already deleted.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = 'A database error occurred while deleting the client.';
            }
        }
    }
}

// 3. Setup Pagination & Filters
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 25;
if (!in_array($limit, [10, 25, 50, 100])) $limit = 25;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page - 1) * $limit;

$temperature = htmlspecialchars(trim($_GET['temperature'] ?? ''), ENT_QUOTES);
$dealStatus  = htmlspecialchars(trim($_GET['deal_status'] ?? ''), ENT_QUOTES);
$progress    = htmlspecialchars(trim($_GET['progress'] ?? ''), ENT_QUOTES);
$filterAdmin = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$dateFilter  = htmlspecialchars(trim($_GET['date_filter'] ?? ''), ENT_QUOTES);
$searchTerm  = htmlspecialchars(trim($_GET['q'] ?? ''), ENT_QUOTES);

$where = ["c.deleted_at IS NULL"];
$params = [];

// Apply Role-Based Access Controls
if (!$isSuperAdmin) {
    // Admins see only their own assigned or created clients
    $where[] = "(c.assigned_admin_id = ? OR c.created_by = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
} elseif ($isSuperAdmin && $filterAdmin) {
    $where[] = "c.assigned_admin_id = ?";
    $params[] = $filterAdmin;
}

if ($temperature && in_array(strtoupper($temperature), ['HOT', 'COLD'])) {
    $where[] = "c.temperature = ?";
    $params[] = strtoupper($temperature);
}

if ($dealStatus) {
    $where[] = "c.deal_status = ?";
    $params[] = $dealStatus;
}

if ($progress) {
    $where[] = "c.status = ?";
    $params[] = $progress;
}

if ($dateFilter) {
    switch ($dateFilter) {
        case 'today':
            $where[] = "DATE(c.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $where[] = "DATE(c.created_at) = CURDATE() - INTERVAL 1 DAY";
            break;
        case 'this_week':
            $where[] = "YEARWEEK(c.created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'this_month':
            $where[] = "YEAR(c.created_at) = YEAR(CURDATE()) AND MONTH(c.created_at) = MONTH(CURDATE())";
            break;
    }
}

if ($searchTerm) {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.email LIKE ? OR c.client_code LIKE ? OR c.city LIKE ?)";
    $likeTerm = '%' . $searchTerm . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $likeTerm;
    }
}

$whereClause = implode(' AND ', $where);

// 4. Safely Fetch Data with Try-Catch
$clients = [];
$totalRecords = 0;
$totalPages = 1;
$adminsList = [];

try {
    // Fetch Admins List for Filter (Super Admin Only)
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get Total Count for Pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit) ?: 1;

    // Fetch Client Records safely
    $query = "
        SELECT 
            c.id, c.client_code, c.name, c.temperature, c.phone, c.city, c.requirement, 
            c.status, c.deal_status, c.final_price, c.created_at,
            u.name AS assigned_admin_name,
            (SELECT MAX(next_followup_date) FROM client_activities WHERE client_id = c.id) AS next_followup
        FROM clients c
        LEFT JOIN users u ON c.assigned_admin_id = u.id
        WHERE $whereClause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Ensure the <code>clients</code> and <code>client_activities</code> tables exist with all required columns (e.g., deal_status, temperature).";
}

// Helper for pagination links
function buildPaginationQueryString($paramsToKeep) {
    $qs = '';
    foreach ($paramsToKeep as $key => $val) {
        if (!empty($val)) {
            $qs .= '&' . urlencode($key) . '=' . urlencode($val);
        }
    }
    return $qs;
}
$queryString = buildPaginationQueryString([
    'limit' => $limit, 'q' => $searchTerm, 'temperature' => $temperature, 
    'deal_status' => $dealStatus, 'progress' => $progress, 
    'date_filter' => $dateFilter, 'admin_id' => $filterAdmin
]);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    
    /* Ultra Glass/Neumorphic Cards */
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; transition: transform 0.3s ease; }
    
    /* Ultra Inputs */
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; display: inline-block; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 14px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    /* Search Bar Specific */
    .ultra-search-wrapper { position: relative; display: flex; align-items: center; }
    .ultra-search-icon { position: absolute; left: 16px; color: #64748b; font-size: 1.1rem; }
    .ultra-search-input { padding-left: 45px !important; }

    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; cursor: pointer; }
    .app-btn-icon:hover { transform: scale(1.05); }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 1000px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Soft Badges overrides for Data Logic preservation */
    .badge { padding: 0.4rem 0.8rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; letter-spacing: 0.3px; }
    
    /* Elegant Dropdown Styling */
    .ultra-dropdown .dropdown-menu { border: none; border-radius: 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.1); padding: 10px; min-width: 220px; }
    .ultra-dropdown .dropdown-item { border-radius: 12px; padding: 10px 15px; font-weight: 600; margin-bottom: 4px; transition: all 0.2s; }
    .ultra-dropdown .dropdown-item:hover { background: #f8fafc; transform: translateX(4px); }

    /* Custom Pagination */
    .app-pagination .page-link { border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; margin: 0 4px; color: #475569; font-weight: 600; transition: all 0.2s; }
    .app-pagination .page-item.active .page-link { background: #0f172a; color: #fff; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2); }
    .app-pagination .page-link:hover:not(.active) { background: #e2e8f0; }

    /* Mobile Client Item Card */
    .mobile-client-item { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-client-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-people-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Client Directory</h3>
                <p class="text-muted small mb-0 fw-medium">Search, filter, track progress & details</p>
            </div>
        </div>
        <a href="/public/clients/create.php" class="btn btn-ultra-primary d-inline-flex align-items-center">
            <i class="bi bi-person-plus-fill me-2 fs-5"></i> Add New Client
        </a>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Search & Filter Card -->
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/clients/index.php" id="filterForm" class="row g-3 align-items-end">
                <div class="col-12 col-md-12 col-xl-4">
                    <label class="ultra-label">Global Search</label>
                    <div class="ultra-search-wrapper">
                        <i class="bi bi-search ultra-search-icon"></i>
                        <input type="text" name="q" class="form-control ultra-input ultra-search-input" placeholder="Search by Name/Phone/ID/City" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>">
                    </div>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="ultra-label">Lead Types</label>
                    <select name="temperature" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Leads</option>
                        <option value="HOT" <?= $temperature === 'HOT' ? 'selected' : '' ?>>🔥 HOT</option>
                        <option value="COLD" <?= $temperature === 'COLD' ? 'selected' : '' ?>>❄️ COLD</option>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="ultra-label">Deal Status</label>
                    <select name="deal_status" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Deals</option>
                        <option value="Open" <?= $dealStatus === 'Open' ? 'selected' : '' ?>>Open</option>
                        <option value="In Progress" <?= $dealStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Converted" <?= $dealStatus === 'Converted' ? 'selected' : '' ?>>Converted</option>
                        <option value="Rejected" <?= $dealStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="On Hold" <?= $dealStatus === 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="ultra-label">Progress</label>
                    <select name="progress" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Progress</option>
                        <?php foreach (['New', 'Contacted', 'Demo Scheduled', 'Demo Completed', 'Follow Up', 'Meeting Scheduled', 'Quotation Sent', 'Negotiation', 'Converted', 'Rejected', 'On Hold', 'Lost'] as $st): ?>
                            <option value="<?= $st ?>" <?= $progress === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="ultra-label">Created Date</label>
                    <select name="date_filter" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Time</option>
                        <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $dateFilter === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>

                <?php if ($isSuperAdmin): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="ultra-label">Assigned Employee</label>
                    <select name="admin_id" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Employees</option>
                        <?php foreach ($adminsList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $filterAdmin === (int)$adm['id'] ? 'selected' : '' ?>><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-12 col-md-6 col-xl-8 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 flex-md-grow-0 px-4"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <?php if ($searchTerm || $temperature || $dealStatus || $progress || $dateFilter || $filterAdmin): ?>
                        <a href="/public/clients/index.php" class="btn btn-ultra-secondary shadow-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Total Clients: <span class="text-primary"><?= number_format($totalRecords) ?></span></h6>
            
            <div class="d-flex align-items-center gap-2">
                <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap for options</span>-->
                <div class="d-flex align-items-center">
                    <label class="small text-muted fw-bold me-2 text-uppercase letter-spacing-1">Per Page:</label>
                    <select class="form-select form-select-sm ultra-input py-1 px-3" style="width: 80px; min-height: 32px; border-radius: 10px;" onchange="location.href='?limit='+this.value+'<?= preg_replace('/&limit=\d+/', '', $queryString) ?>'">
                        <?php foreach ([10, 25, 50, 100] as $l): ?>
                            <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card List with Popup Modal)                  -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($clients)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-search text-muted fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No clients found</h5>
                    <p class="text-muted small">No records match your current filters or search criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($clients as $c): 
                    $tempBadgeText = $c['temperature'] === 'HOT' ? '🔥 HOT' : '❄️ COLD';
                    $tempBadgeClass = $c['temperature'] === 'HOT' ? 'bg-danger text-white' : 'bg-info text-dark';
                    
                    $dsClass = 'bg-secondary';
                    if($c['deal_status'] === 'Converted') $dsClass = 'bg-success';
                    if($c['deal_status'] === 'Rejected') $dsClass = 'bg-danger';
                    if($c['deal_status'] === 'In Progress') $dsClass = 'bg-primary';
                    if($c['deal_status'] === 'On Hold') $dsClass = 'bg-warning text-dark';
                    
                    $followupFmt = !empty($c['next_followup']) ? date('d M, h:i A', strtotime($c['next_followup'])) : 'No schedule';
                    $clientNameEsc = htmlspecialchars(addslashes($c['name']), ENT_QUOTES);
                    $clientCodeEsc = htmlspecialchars(addslashes($c['client_code']), ENT_QUOTES);
                ?>
                    <div class="mobile-client-item"
                         onclick="openClientDetailsModal(
                             <?= (int)$c['id'] ?>,
                             '<?= $clientCodeEsc ?>',
                             '<?= $clientNameEsc ?>',
                             '<?= htmlspecialchars(addslashes($c['phone']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($c['city'] ?? 'Unknown'), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($c['requirement'] ?? 'None'), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($c['status']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($c['deal_status']), ENT_QUOTES) ?>',
                             '<?= $dsClass ?>',
                             '<?= $c['temperature'] ?>',
                             '<?= htmlspecialchars(addslashes($c['assigned_admin_name'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                             '<?= $followupFmt ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></div>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="badge <?= $tempBadgeClass ?> px-2 py-0.5" style="font-size: 0.7rem;"><?= $tempBadgeText ?></span>
                                    <span class="badge <?= $dsClass ?> px-2 py-0.5" style="font-size: 0.7rem;"><?= htmlspecialchars($c['deal_status'], ENT_QUOTES) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-1 rounded d-inline-block fw-bold">
                                #<?= htmlspecialchars($c['client_code'], ENT_QUOTES) ?>
                            </span>
                            <small class="text-muted d-block mt-1"><?= htmlspecialchars($c['city'] ?? 'Unknown', ENT_QUOTES) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- 2. DESKTOP VIEW (Full Data Table)                            -->
        <!-- ============================================================ -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Client Profile</th>
                        <th>Status / Deal</th>
                        <th>Contact / Loc</th>
                        <th>Requirement</th>
                        <th>Assignment</th>
                        <th>Action Required</th>
                        <th class="text-end pe-4">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="7" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-search text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No clients found</h5>
                                <p class="text-muted small">No records match your current filters or search criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $c): 
                            $tempBadge = $c['temperature'] === 'HOT' ? '<span class="badge bg-danger px-3 py-2 shadow-sm"><i class="bi bi-fire me-1"></i> HOT</span>' : '<span class="badge bg-info text-dark px-3 py-2 shadow-sm"><i class="bi bi-snow me-1"></i> COLD</span>';
                            
                            $dsClass = 'bg-secondary';
                            if($c['deal_status'] === 'Converted') $dsClass = 'bg-success';
                            if($c['deal_status'] === 'Rejected') $dsClass = 'bg-danger';
                            if($c['deal_status'] === 'In Progress') $dsClass = 'bg-primary';
                            if($c['deal_status'] === 'On Hold') $dsClass = 'bg-warning text-dark';
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark fs-6">
                                        <a href="/public/clients/view.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none text-dark d-flex align-items-center gap-2">
                                            <div class="app-avatar-fallback">
                                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></div>
                                                <div class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-1 rounded mt-1 d-inline-block">ID: <?= htmlspecialchars($c['client_code'], ENT_QUOTES) ?></div>
                                            </div>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2 align-items-start">
                                        <?= $tempBadge ?>
                                        <span class="badge <?= $dsClass ?> px-3 py-2 shadow-sm"><?= htmlspecialchars($c['deal_status'], ENT_QUOTES) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <!-- Interactive Dropdown Menu -->
                                    <div class="dropdown ultra-dropdown mb-2">
                                        <a href="#" class="text-decoration-none text-dark fw-bold d-inline-flex align-items-center bg-light px-3 py-2 rounded-pill border" data-bs-toggle="dropdown">
                                            <i class="bi bi-telephone-fill text-primary me-2"></i> <?= htmlspecialchars($c['phone'], ENT_QUOTES) ?> <i class="bi bi-chevron-down ms-2 small text-muted"></i>
                                        </a>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item text-primary" href="tel:<?= htmlspecialchars($c['phone'], ENT_QUOTES) ?>">
                                                    <i class="bi bi-telephone-outbound-fill me-2"></i> Standard Voice Call
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-success" href="https://wa.me/91<?= htmlspecialchars($c['phone'], ENT_QUOTES) ?>" target="_blank">
                                                    <i class="bi bi-whatsapp me-2"></i> WhatsApp Message
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="text-muted small fw-medium mt-1 ms-1">
                                        <i class="bi bi-geo-alt-fill text-info me-1"></i> <?= htmlspecialchars($c['city'] ?? 'Unknown', ENT_QUOTES) ?>
                                    </div>
                                </td>
                                <td style="max-width: 200px;">
                                    <div class="bg-light p-2 rounded-3 text-dark small fw-medium border text-truncate" title="<?= htmlspecialchars($c['requirement'] ?? 'None', ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($c['requirement'] ?? 'None', ENT_QUOTES) ?>
                                    </div>
                                    <span class="badge bg-dark bg-opacity-10 text-dark border px-2 py-1 mt-2"><?= htmlspecialchars($c['status'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($c['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($c['next_followup'])): ?>
                                        <span class="badge bg-warning bg-opacity-25 text-dark border border-warning border-opacity-50 px-3 py-2 rounded-pill shadow-sm">
                                            <i class="bi bi-bell-fill me-1 text-warning"></i> <?= date('d M, h:i A', strtotime($c['next_followup'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic fw-medium">- No schedule -</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="/public/clients/view.php?id=<?= (int)$c['id'] ?>" class="app-btn-icon bg-primary bg-opacity-10 text-primary" title="View Profile">
                                            <i class="bi bi-eye-fill"></i>
                                        </a>
                                        <a href="/public/clients/edit.php?id=<?= (int)$c['id'] ?>" class="app-btn-icon bg-info bg-opacity-10 text-info" title="Edit Client">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <?php if ($isSuperAdmin): ?>
                                            <button type="button" class="app-btn-icon bg-danger bg-opacity-10 text-danger" title="Delete Client" 
                                                    onclick="confirmClientDelete(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['client_code']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($c['name']), ENT_QUOTES) ?>')">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
                <nav aria-label="Pagination">
                    <ul class="pagination app-pagination mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm" href="?page=<?= $page - 1 ?><?= $queryString ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= ($page === $i) ? 'active' : '' ?>">
                                <a class="page-link shadow-sm" href="?page=<?= $i ?><?= $queryString ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm" href="?page=<?= $page + 1 ?><?= $queryString ?>"><i class="bi bi-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE CLIENT DETAILS & QUICK MANAGEMENT POPUP        -->
<!-- ============================================================ -->
<div class="modal fade" id="clientDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Client Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mClientAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mClientName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mClientCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Badges Summary -->
                <div class="d-flex gap-2 mb-4">
                    <span id="mClientTempBadge" class="badge px-3 py-2"></span>
                    <span id="mClientDealBadge" class="badge px-3 py-2"></span>
                </div>

                <!-- Contact & Location Grid -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-telephone text-primary me-2"></i> Phone</span>
                        <a id="mClientPhoneLink" href="#" class="fw-bold text-dark font-monospace text-decoration-none"></a>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-whatsapp text-success me-2"></i> WhatsApp</span>
                        <a id="mClientWaLink" href="#" target="_blank" class="fw-bold text-success text-decoration-none small">Direct Message <i class="bi bi-box-arrow-up-right ms-1"></i></a>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-geo-alt text-info me-2"></i> Location</span>
                        <span id="mClientCity" class="fw-bold text-dark"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-badge text-primary me-2"></i> Assigned Employee</span>
                        <span id="mClientAdmin" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-bell text-warning me-2"></i> Next Follow-up</span>
                        <span id="mClientFollowup" class="small fw-bold text-dark"></span>
                    </div>
                </div>

                <!-- Requirements -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Client Requirement</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mClientReq" class="text-dark small fw-medium" style="white-space: pre-wrap;"></div>
                    <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Progress Stage:</span>
                        <span id="mClientProgress" class="badge bg-dark bg-opacity-10 text-dark border px-2 py-1"></span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex flex-column gap-2">
                    <a id="mClientViewProfileBtn" href="#" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-eye-fill me-2"></i> View Client's Profile
                    </a>
                    <div class="row g-2">
                        <div class="col-6">
                            <a id="mClientEditBtn" href="#" class="btn btn-ultra-secondary w-100 py-2.5">
                                <i class="bi bi-pencil-fill me-1 text-info"></i> Edit Client
                            </a>
                        </div>
                        <div class="col-6">
                            <?php if ($isSuperAdmin): ?>
                                <button type="button" id="mClientDeleteBtn" class="btn btn-light border text-danger w-100 rounded-pill py-2.5 fw-bold">
                                    <i class="bi bi-trash3-fill me-1"></i> Delete
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-light border text-muted w-100 rounded-pill py-2.5 fw-bold" disabled>
                                    Restricted
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Super Admin Client Deletion Confirmation Modal -->
<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="deleteClientModal" tabindex="-1" aria-labelledby="deleteClientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <!-- Re-attach Query String to the Action URL -->
        <div class="modal-content app-modal-content">
            <form method="POST" action="/public/clients/index.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING'], ENT_QUOTES) : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" name="client_id" id="modalDeleteClientId" value="">
                
                <div class="modal-body p-4 p-md-5 text-center">
                    <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px; font-size: 2.5rem;">
                        <i class="bi bi-person-dash-fill"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Remove Client?</h4>
                    <p class="text-muted mb-4">You are about to securely soft-delete the following client profile:</p>
                    
                    <div class="bg-light rounded-4 p-3 mb-4 border">
                        <h5 id="modalDeleteClientCode" class="text-primary font-monospace fw-bold mb-1"></h5>
                        <h5 id="modalDeleteClientName" class="text-dark fw-bold mb-0"></h5>
                    </div>
                    
                    <div class="text-start small text-muted mb-4 px-2">
                        <i class="bi bi-info-circle-fill text-warning me-2"></i> Historical activities and timelines will remain accessible in audit records for compliance.
                    </div>
                    
                    <div class="d-flex gap-3 mt-4">
                        <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill flex-grow-1 fw-bold py-3 shadow-sm">Confirm Deletion</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Opens Mobile Client Details Modal Popup.
 */
function openClientDetailsModal(id, code, name, phone, city, req, progress, dealStatus, dsClass, temp, admin, followup) {
    document.getElementById('mClientAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mClientName').textContent = name;
    document.getElementById('mClientCode').textContent = '#' + code;
    
    // Temperature Badge
    const tempBadge = document.getElementById('mClientTempBadge');
    tempBadge.textContent = temp === 'HOT' ? '🔥 HOT' : '❄️ COLD';
    tempBadge.className = 'badge px-3 py-2 ' + (temp === 'HOT' ? 'bg-danger text-white' : 'bg-info text-dark');

    // Deal Status Badge
    const dealBadge = document.getElementById('mClientDealBadge');
    dealBadge.textContent = dealStatus;
    dealBadge.className = 'badge px-3 py-2 ' + dsClass;

    document.getElementById('mClientPhoneLink').textContent = phone;
    document.getElementById('mClientPhoneLink').href = 'tel:' + phone;
    document.getElementById('mClientWaLink').href = 'https://wa.me/91' + phone;
    document.getElementById('mClientCity').textContent = city;
    document.getElementById('mClientAdmin').textContent = admin;
    document.getElementById('mClientFollowup').textContent = followup;
    document.getElementById('mClientReq').textContent = req && req.trim() !== '' ? req : 'No specific requirement noted.';
    document.getElementById('mClientProgress').textContent = progress;

    document.getElementById('mClientViewProfileBtn').href = '/public/clients/view.php?id=' + id;
    document.getElementById('mClientEditBtn').href = '/public/clients/edit.php?id=' + id;

    const deleteBtn = document.getElementById('mClientDeleteBtn');
    if (deleteBtn) {
        deleteBtn.onclick = function() {
            const detailModal = bootstrap.Modal.getInstance(document.getElementById('clientDetailsModal'));
            if (detailModal) detailModal.hide();
            confirmClientDelete(id, code, name);
        };
    }

    const modal = new bootstrap.Modal(document.getElementById('clientDetailsModal'));
    modal.show();
}

function confirmClientDelete(clientId, clientCode, clientName) {
    document.getElementById('modalDeleteClientId').value = clientId;
    document.getElementById('modalDeleteClientCode').textContent = clientCode;
    document.getElementById('modalDeleteClientName').textContent = clientName;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteClientModal'));
    deleteModal.show();
}
</script>
<?php endif; ?>

<script>
    // Security Scripts Preserved
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === "F12") { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
        if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>