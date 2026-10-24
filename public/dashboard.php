<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login session
requireLogin();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$pageTitle = 'Dashboard';

$success_msg = '';
$error_msg = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'target_updated') {
    $success_msg = 'Monthly target amount updated successfully.';
}

// Helper function to fetch an admin's monthly target safely
function getAdminMonthlyTarget($pdo, $admin_id) {
    // 1. Check audit_logs for custom set target (crash-proof without DB changes)
    try {
        $stmt = $pdo->prepare("SELECT description FROM audit_logs WHERE action = 'set_monthly_target' AND target_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$admin_id]);
        $val = $stmt->fetchColumn();
        if ($val !== false && is_numeric($val)) {
            return (float)$val;
        }
    } catch (Exception $e) {}

    // 2. Check users table if monthly_target column exists
    try {
        $stmt = $pdo->prepare("SELECT monthly_target FROM users WHERE id = ?");
        $stmt->execute([$admin_id]);
        $val = $stmt->fetchColumn();
        if ($val !== false && is_numeric($val) && (float)$val > 0) {
            return (float)$val;
        }
    } catch (Exception $e) {}

    // 3. Fallback to defined constant or default target of ₹1,00,000
    return defined('DEFAULT_MONTHLY_TARGET') ? (float)DEFAULT_MONTHLY_TARGET : 100000.00;
}

// Handle POST: Update Target Amount (Super Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed.");
    }

    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);

    if ($action === 'update_target' && $role === 'super_admin') {
        $target_admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
        $target_amount   = filter_input(INPUT_POST, 'target_amount', FILTER_VALIDATE_FLOAT);

        if ($target_admin_id && $target_amount !== false && $target_amount >= 0) {
            try {
                // Store in audit_logs for persistence across all environments
                $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, 'super_admin', 'set_monthly_target', 'admin_target', ?, ?, ?)");
                $stmt->execute([$user_id, $target_admin_id, (string)$target_amount, $_SERVER['REMOTE_ADDR']]);

                // Also update users table if column exists (silent catch if missing)
                try {
                    $stmtU = $pdo->prepare("UPDATE users SET monthly_target = ? WHERE id = ?");
                    $stmtU->execute([$target_amount, $target_admin_id]);
                } catch (Exception $e) {}

                header("Location: /public/dashboard.php?msg=target_updated");
                exit;
            } catch (Exception $e) {
                $error_msg = "Error updating target: " . $e->getMessage();
            }
        } else {
            $error_msg = "Please enter a valid target amount.";
        }
    }
}

// ==========================================
// 1. DATA FETCHING: SUPER ADMIN
// ==========================================
if ($role === 'super_admin') {
    
    // Admin Stats
    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(status = 'active') as active FROM users WHERE role = 'admin' AND deleted_at IS NULL");
    $adminStats = $stmt->fetch();

    // Client & Lead Stats
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_clients,
            SUM(temperature = 'HOT') as hot_leads,
            SUM(temperature = 'COLD') as cold_leads,
            SUM(deal_status = 'Converted') as converted,
            SUM(deal_status = 'Rejected') as rejected,
            SUM(deal_status = 'Open') as open_leads,
            SUM(DATE(created_at) = CURDATE()) as today_new
        FROM clients 
        WHERE deleted_at IS NULL
    ");
    $clientStats = $stmt->fetch();

    // Sales Overview
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as converted_count,
            SUM(quotation_amount) as total_quotation,
            SUM(final_price) as total_converted
        FROM clients 
        WHERE deal_status = 'Converted' AND deleted_at IS NULL
    ");
    $salesStats = $stmt->fetch();
    $conversionRate = $clientStats['total_clients'] > 0 ? round(($clientStats['converted'] / $clientStats['total_clients']) * 100, 1) : 0;

    // Today's Activity Stats
    $todayFollowups = $pdo->query("SELECT COUNT(*) FROM client_activities WHERE DATE(next_followup_date) = CURDATE()")->fetchColumn();
    $todayMeetings = $pdo->query("SELECT COUNT(*) FROM meetings WHERE meeting_date = CURDATE() AND status != 'Cancelled'")->fetchColumn();

    // Admin Performance Table Data (Augmented with month and total revenue)
    $adminsPerf = $pdo->query("
        SELECT u.id, u.name, u.email,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deleted_at IS NULL) as total_clients,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND temperature = 'HOT' AND deleted_at IS NULL) as hot_leads,
            (SELECT COUNT(*) FROM client_activities WHERE admin_id = u.id) as total_followups,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Converted' AND deleted_at IS NULL) as converted,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Rejected' AND deleted_at IS NULL) as rejected,
            COALESCE((SELECT SUM(final_price) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Converted' AND deleted_at IS NULL), 0) as total_revenue,
            COALESCE((SELECT SUM(final_price) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Converted' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE()) AND deleted_at IS NULL), 0) as month_revenue,
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND work_date = CURDATE()) as today_time,
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND YEARWEEK(work_date, 1) = YEARWEEK(CURDATE(), 1)) as week_time,
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND MONTH(work_date) = MONTH(CURDATE()) AND YEAR(work_date) = YEAR(CURDATE())) as month_time
        FROM users u 
        WHERE u.role = 'admin' AND u.deleted_at IS NULL
        ORDER BY u.name ASC
    ")->fetchAll();
}

// ==========================================
// 2. DATA FETCHING: ADMIN
// ==========================================
else {
    // Client, Lead & Revenue Stats (Scoped to Admin with Month Revenue calculated)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_clients,
            SUM(temperature = 'HOT') as hot_leads,
            SUM(temperature = 'COLD') as cold_leads,
            SUM(deal_status = 'Open') as open_leads,
            SUM(deal_status = 'Converted') as converted,
            SUM(deal_status = 'Rejected') as rejected,
            COALESCE(SUM(CASE WHEN deal_status = 'Converted' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE()) THEN final_price ELSE 0 END), 0) as month_revenue,
            COALESCE(SUM(CASE WHEN deal_status = 'Converted' THEN final_price ELSE 0 END), 0) as total_revenue
        FROM clients 
        WHERE deleted_at IS NULL AND (assigned_admin_id = ? OR created_by = ?)
    ");
    $stmt->execute([$user_id, $user_id]);
    $myStats = $stmt->fetch();

    $myMonthlyTarget = getAdminMonthlyTarget($pdo, $user_id);
    $myMonthRevenue  = (float)($myStats['month_revenue'] ?? 0);
    $targetProgress  = $myMonthlyTarget > 0 ? round(($myMonthRevenue / $myMonthlyTarget) * 100, 1) : 0;

    // Activity Stats (Scoped to Admin)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_activities WHERE admin_id = ? AND DATE(next_followup_date) = CURDATE()");
    $stmt->execute([$user_id]);
    $myTodayFollowups = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_activities WHERE admin_id = ? AND DATE(next_followup_date) < CURDATE()");
    $stmt->execute([$user_id]);
    $myOverdueFollowups = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE admin_id = ? AND meeting_date = CURDATE() AND status != 'Cancelled'");
    $stmt->execute([$user_id]);
    $myTodayMeetings = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE admin_id = ? AND meeting_date > CURDATE() AND status != 'Cancelled'");
    $stmt->execute([$user_id]);
    $myUpcomingMeetings = $stmt->fetchColumn();

    // Working Time Stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = ? AND work_date = CURDATE()) as today_time,
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = ? AND YEARWEEK(work_date, 1) = YEARWEEK(CURDATE(), 1)) as week_time,
            (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = ? AND MONTH(work_date) = MONTH(CURDATE()) AND YEAR(work_date) = YEAR(CURDATE())) as month_time
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $myTime = $stmt->fetch();
    
    $todaySeconds = $myTime['today_time'] ?? 0;
    $timeStatus = getWorkingTimeStatus($todaySeconds, DEFAULT_DAILY_TARGET_SECONDS);
}

// Include Header & Sidebar
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    
    /* Ultra Glass/Neumorphic Cards */
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; transition: transform 0.3s ease; }
    
    .card-hover { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .card-hover:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.08) !important; }
    
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; display: inline-block; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    .icon-box { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 14px; font-size: 1.4rem; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 10px 24px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Semantic Gradients */
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
    .bg-gradient-danger  { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
    .bg-gradient-info    { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white; }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 950px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Performance List Item */
    .mobile-admin-item { background: #ffffff; border-radius: 20px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-admin-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Modal Styling */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; height: 100%; display: flex; flex-direction: column; justify-content: center; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4" style="background-color: #f0f2f5; min-height: 100vh;">

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

    <!-- ========================================== -->
    <!-- SUPER ADMIN DASHBOARD VIEW                 -->
    <!-- ========================================== -->
    <?php if ($role === 'super_admin'): ?>
        
        <!-- 1. Welcome Banner -->
        <div class="ultra-card bg-gradient-primary border-0 text-white position-relative overflow-hidden mb-4 p-4 p-md-5">
            <div class="position-relative z-1">
                <h3 class="fw-bold mb-1 tracking-tight">Welcome, Super Admin! 👋</h3>
                <p class="mb-0 opacity-75 fs-6 fw-medium">Real-time overview of administrative metrics, revenue, and pipelines for <?= date('l, M d, Y') ?>.</p>
            </div>
            <!--<i class="bi bi-grid-1x2-fill position-absolute text-white opacity-15" style="font-size: 8rem; top: -20px; right: 15px; transform: rotate(15deg);"></i>-->
        </div>

        <!-- 2. Four Grids (Primary Stats) -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="ultra-card card-hover mb-0 h-100 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="ultra-label mb-0">Total Employees</span>
                        <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="bi bi-shield-lock-fill"></i></div>
                    </div>
                    <h2 class="fw-bold text-dark mb-0 font-monospace"><?= number_format($adminStats['total'] ?? 0) ?></h2>
                    <small class="text-success fw-bold"><?= $adminStats['active'] ?? 0 ?> Active</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ultra-card card-hover mb-0 h-100 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="ultra-label mb-0">Total Clients</span>
                        <div class="icon-box bg-info bg-opacity-10 text-info"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <h2 class="fw-bold text-dark mb-0 font-monospace"><?= number_format($clientStats['total_clients'] ?? 0) ?></h2>
                    <small class="text-primary fw-bold">+<?= $clientStats['today_new'] ?? 0 ?> Today</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ultra-card card-hover mb-0 h-100 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="ultra-label mb-0">Hot Leads</span>
                        <div class="icon-box bg-danger bg-opacity-10 text-danger"><i class="bi bi-fire"></i></div>
                    </div>
                    <h2 class="fw-bold text-danger mb-0 font-monospace"><?= number_format($clientStats['hot_leads'] ?? 0) ?></h2>
                    <small class="text-muted fw-medium">High Priority</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="ultra-card card-hover mb-0 h-100 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="ultra-label mb-0">Converted</span>
                        <div class="icon-box bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                    <h2 class="fw-bold text-success mb-0 font-monospace"><?= number_format($clientStats['converted'] ?? 0) ?></h2>
                    <small class="text-success fw-bold"><i class="bi bi-graph-up-arrow me-1"></i><?= $conversionRate ?>% Rate</small>
                </div>
            </div>
        </div>

        <!-- 3. Three Grids (Sales/Value Stats) -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-warning text-dark mb-0 h-100 card-hover p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Open Leads (In Progress)</span>
                            <h2 class="fw-bold mb-0 mt-2 font-monospace"><?= number_format($clientStats['open_leads'] ?? 0) ?></h2>
                        </div>
                        <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-info text-white mb-0 h-100 card-hover p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Total Quotation Value</span>
                            <h2 class="fw-bold mb-0 mt-2 font-monospace text-white">₹<?= number_format($salesStats['total_quotation'] ?? 0, 2) ?></h2>
                        </div>
                        <i class="bi bi-receipt fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-success text-white mb-0 h-100 card-hover p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Converted Revenue</span>
                            <h2 class="fw-bold mb-0 mt-2 font-monospace text-white">₹<?= number_format($salesStats['total_converted'] ?? 0, 2) ?></h2>
                        </div>
                        <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Two Grids (Today's Schedule & Conversion Insights) -->
        <div class="row g-4 mb-4">
            <!-- Today's Schedule -->
            <div class="col-12 col-lg-6">
                <div class="ultra-card h-100 mb-0 p-4">
                    <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                        <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary me-3" style="width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-calendar-event"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Today's Schedule</h5>
                    </div>
                    
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded-4 border">
                            <div class="d-flex align-items-center">
                                <div class="icon-box bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-telephone"></i></div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">Follow-ups</h6>
                                    <small class="text-muted">Calls scheduled today</small>
                                </div>
                            </div>
                            <h3 class="fw-bold text-primary mb-0 font-monospace"><?= $todayFollowups ?></h3>
                        </div>

                        <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded-4 border">
                            <div class="d-flex align-items-center">
                                <div class="icon-box bg-success bg-opacity-10 text-success me-3"><i class="bi bi-camera-video"></i></div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">Meetings</h6>
                                    <small class="text-muted">Online / In-Person Demos</small>
                                </div>
                            </div>
                            <h3 class="fw-bold text-success mb-0 font-monospace"><?= $todayMeetings ?></h3>
                        </div>

                        <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded-4 border">
                            <div class="d-flex align-items-center">
                                <div class="icon-box bg-info bg-opacity-10 text-info me-3"><i class="bi bi-person-plus"></i></div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">New Inquiries</h6>
                                    <small class="text-muted">Clients added today</small>
                                </div>
                            </div>
                            <h3 class="fw-bold text-info mb-0 font-monospace"><?= $clientStats['today_new'] ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Conversion Insights -->
            <div class="col-12 col-lg-6">
                <div class="ultra-card h-100 mb-0 p-4 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center border-bottom pb-3 mb-3">
                        <div class="section-icon-wrapper bg-success bg-opacity-10 text-success me-3" style="width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-award"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Conversion Insights</h5>
                    </div>

                    <div class="text-center py-2">
                        <h1 class="display-3 fw-bold text-success mb-0 font-monospace"><?= $conversionRate ?>%</h1>
                        <span class="text-muted fw-bold text-uppercase small letter-spacing-1">Platform Conversion Ratio</span>
                    </div>

                    <div class="row text-center bg-light rounded-4 p-3 border mb-3">
                        <div class="col-6 border-end">
                            <h4 class="fw-bold text-dark mb-0 font-monospace"><?= number_format($clientStats['converted'] ?? 0) ?></h4>
                            <small class="text-muted fw-semibold">Total Deals Won</small>
                        </div>
                        <div class="col-6">
                            <h4 class="fw-bold text-success mb-0 font-monospace">₹<?= number_format($salesStats['total_converted'] ?? 0, 2) ?></h4>
                            <small class="text-muted fw-semibold">Total Revenue</small>
                        </div>
                    </div>

                    <div class="text-center">
                        <a href="/public/reports/conversions.php" class="btn btn-ultra-secondary w-100 py-2.5 shadow-sm">
                            All Portfolios & Conversions <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Quick Shortcuts -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0 text-uppercase small letter-spacing-1">Management Shortcuts</h6>
        </div>
        <div class="row g-3 mb-5">
            <div class="col-6 col-md-3">
                <a href="/public/admins/create.php" class="text-decoration-none">
                    <div class="ultra-card text-center p-3 mb-0 card-hover">
                        <div class="icon-box bg-primary bg-opacity-10 text-primary mx-auto mb-2"><i class="bi bi-person-plus-fill"></i></div>
                        <h6 class="text-dark fw-bold mb-0">Add Employee</h6>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="/public/clients/create.php" class="text-decoration-none">
                    <div class="ultra-card text-center p-3 mb-0 card-hover">
                        <div class="icon-box bg-info bg-opacity-10 text-info mx-auto mb-2"><i class="bi bi-person-lines-fill"></i></div>
                        <h6 class="text-dark fw-bold mb-0">Add Client</h6>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="/public/meetings/schedule.php" class="text-decoration-none">
                    <div class="ultra-card text-center p-3 mb-0 card-hover">
                        <div class="icon-box bg-success bg-opacity-10 text-success mx-auto mb-2"><i class="bi bi-calendar-plus-fill"></i></div>
                        <h6 class="text-dark fw-bold mb-0">Schedule</h6>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="/public/reports/sales.php" class="text-decoration-none">
                    <div class="ultra-card text-center p-3 mb-0 card-hover">
                        <div class="icon-box bg-warning bg-opacity-10 text-warning mx-auto mb-2"><i class="bi bi-graph-up-arrow"></i></div>
                        <h6 class="text-dark fw-bold mb-0">Sales Report</h6>
                    </div>
                </a>
            </div>
        </div>

        <!-- 6. Admin Performance Overview -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Employee Performance & Targets: <span class="text-primary"><?= count($adminsPerf) ?> active</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap admin for details</span>-->
            <a href="/public/reports/performance.php" class="btn btn-sm btn-ultra-secondary d-none d-md-inline-flex py-1.5 px-3">View Full Report</a>
        </div>

        <!-- MOBILE VIEW: Admin List with Modal Popup Details -->
        <div class="d-block d-md-none">
            <?php if (empty($adminsPerf)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-people text-muted fs-1 d-block mb-2"></i>
                    <h6 class="fw-bold text-dark mb-0">No active employees found</h6>
                </div>
            <?php else: ?>
                <?php foreach ($adminsPerf as $ap): 
                    $target = getAdminMonthlyTarget($pdo, $ap['id']);
                    $monthRev = (float)($ap['month_revenue'] ?? 0);
                    $totRev = (float)($ap['total_revenue'] ?? 0);
                    $prog = $target > 0 ? round(($monthRev / $target) * 100, 1) : 0;
                    $todayTimeFmt = formatWorkingHours($ap['today_time'] ?? 0);
                    $weekTimeFmt = formatWorkingHours($ap['week_time'] ?? 0);
                ?>
                    <div class="mobile-admin-item"
                         onclick="openSuperAdminPerfModal(
                             <?= (int)$ap['id'] ?>,
                             '<?= htmlspecialchars(addslashes($ap['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($ap['email']), ENT_QUOTES) ?>',
                             '<?= number_format($ap['total_clients'] ?? 0) ?>',
                             '<?= number_format($ap['hot_leads'] ?? 0) ?>',
                             '<?= number_format($ap['converted'] ?? 0) ?>',
                             '<?= number_format($ap['rejected'] ?? 0) ?>',
                             '₹<?= number_format($monthRev, 2) ?>',
                             '₹<?= number_format($totRev, 2) ?>',
                             <?= (float)$target ?>,
                             '₹<?= number_format($target, 2) ?>',
                             '<?= $prog ?>%',
                             '<?= $todayTimeFmt ?>',
                             '<?= $weekTimeFmt ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($ap['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= escapeOutput($ap['name']) ?></div>
                                <div class="small text-muted font-monospace">
                                    Month: <span class="text-success fw-bold">₹<?= number_format($monthRev, 2) ?></span> / ₹<?= number_format($target, 0) ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= $prog >= 100 ? 'bg-success' : ($prog >= 50 ? 'bg-primary' : 'bg-warning text-dark') ?> rounded-pill px-2.5 py-1">
                                <?= $prog ?>%
                            </span>
                            <small class="text-muted d-block mt-1"><?= $todayTimeFmt ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DESKTOP VIEW: Full Data Table with Inline Target Setter -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th class="ps-4">Employee Details</th>
                        <th>Projects</th>
                        <th>Hot Leads</th>
                        <th>Converted</th>
                        <th>Month Revenue</th>
                        <th>Monthly Target</th>
                        <th>Today Time</th>
                        <th>Weekly Time</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adminsPerf)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="9" class="text-center text-muted py-4">No active employees found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adminsPerf as $ap): 
                            $target = getAdminMonthlyTarget($pdo, $ap['id']);
                            $monthRev = (float)($ap['month_revenue'] ?? 0);
                            $prog = $target > 0 ? round(($monthRev / $target) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($ap['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= escapeOutput($ap['name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($ap['email'], ENT_QUOTES) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border px-3 py-1.5"><?= number_format($ap['total_clients'] ?? 0) ?> Clients</span></td>
                                <td><span class="badge bg-danger rounded-pill px-2.5 py-1.5 shadow-sm">🔥 <?= number_format($ap['hot_leads'] ?? 0) ?></span></td>
                                <td><span class="fw-bold text-success font-monospace fs-6"><?= number_format($ap['converted'] ?? 0) ?></span></td>
                                <td>
                                    <span class="fw-bold text-success font-monospace fs-6">₹<?= number_format($monthRev, 2) ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="font-monospace fw-bold text-dark">₹<?= number_format($target, 2) ?></span>
                                        <button type="button" class="btn btn-sm btn-light border rounded-circle p-1" title="Edit Monthly Target" onclick="openSetTargetModal(<?= (int)$ap['id'] ?>, '<?= htmlspecialchars(addslashes($ap['name']), ENT_QUOTES) ?>', <?= (float)$target ?>)">
                                            <i class="bi bi-pencil small text-primary"></i>
                                        </button>
                                    </div>
                                    <div class="progress mt-1" style="height: 5px; width: 100px; border-radius: 10px;">
                                        <div class="progress-bar <?= $prog >= 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= min(100, $prog) ?>%"></div>
                                    </div>
                                </td>
                                <td><span class="font-monospace text-primary fw-bold"><?= formatWorkingHours($ap['today_time'] ?? 0) ?></span></td>
                                <td><span class="font-monospace text-muted"><?= formatWorkingHours($ap['week_time'] ?? 0) ?></span></td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-ultra-secondary py-1.5 px-3" onclick="openSetTargetModal(<?= (int)$ap['id'] ?>, '<?= htmlspecialchars(addslashes($ap['name']), ENT_QUOTES) ?>', <?= (float)$target ?>)">
                                            <i class="bi bi-bullseye me-1"></i> Target
                                        </button>
                                        <a href="/public/admins/profile.php?id=<?= $ap['id'] ?>" class="btn btn-sm btn-ultra-primary py-1.5 px-3">Profile</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <!-- ========================================== -->
    <!-- ADMIN DASHBOARD VIEW                       -->
    <!-- ========================================== -->
    <?php else: ?>

        <!-- 1. Greeting Message -->
        <div class="ultra-card bg-gradient-primary border-0 text-white mb-4 p-4 p-md-5 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center position-relative z-1">
                <div>
                    <h3 class="fw-bold mb-1 tracking-tight">Hello, <?= escapeOutput($_SESSION['name']) ?>! 🎯</h3>
                    <p class="mb-0 opacity-75 fs-6 fw-medium">Ready to close deals? Here is your pipeline and target tracking for <?= date('l, M d, Y') ?>.</p>
                </div>
                <i class="bi bi-rocket-takeoff fs-1 opacity-25 d-none d-sm-block me-3"></i>
            </div>
        </div>

        <!-- 2. Six Grids (Pipeline Overview + FIXED Month Revenue) -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100">
                    <h3 class="fw-bold text-primary mb-1 font-monospace"><?= number_format($myStats['total_clients'] ?? 0) ?></h3>
                    <span class="ultra-label mb-0">My Leads</span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100 border-bottom border-danger border-3">
                    <h3 class="fw-bold text-danger mb-1 font-monospace"><?= number_format($myStats['hot_leads'] ?? 0) ?></h3>
                    <span class="ultra-label mb-0 text-danger">Hot Leads</span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100 border-bottom border-warning border-3">
                    <h3 class="fw-bold text-warning mb-1 font-monospace"><?= number_format($myStats['open_leads'] ?? 0) ?></h3>
                    <span class="ultra-label mb-0 text-warning">Open Deals</span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100 border-bottom border-success border-3">
                    <h3 class="fw-bold text-success mb-1 font-monospace"><?= number_format($myStats['converted'] ?? 0) ?></h3>
                    <span class="ultra-label mb-0 text-success">Converted</span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100 border-bottom border-secondary border-3">
                    <h3 class="fw-bold text-dark mb-1 font-monospace"><?= number_format($myStats['rejected'] ?? 0) ?></h3>
                    <span class="ultra-label mb-0 text-secondary">Rejected</span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ultra-card text-center p-3 mb-0 card-hover h-100 border-bottom border-success border-3 bg-success bg-opacity-10">
                    <h3 class="fw-bold text-success mb-1 font-monospace fs-5">₹<?= number_format($myMonthRevenue, 2) ?></h3>
                    <span class="ultra-label mb-0 text-success">Revenue (Month)</span>
                </div>
            </div>
        </div>

        <!-- 3. Target Progress Card for Admin -->
        <div class="ultra-card mb-4 p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 border-bottom pb-3 mb-3">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-success bg-opacity-10 text-success me-3"><i class="bi bi-bullseye"></i></div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0">Monthly Sales Target Performance</h5>
                        <small class="text-muted fw-medium">Goal for <?= date('F Y') ?></small>
                    </div>
                </div>
                <div class="text-md-end">
                    <span class="badge <?= $targetProgress >= 100 ? 'bg-success' : 'bg-primary' ?> rounded-pill px-3 py-2 fs-6 shadow-sm">
                        <?= $targetProgress ?>% Achieved
                    </span>
                </div>
            </div>
            
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="bg-light p-3 rounded-4 border text-center">
                        <span class="ultra-label mb-1">Target Amount</span>
                        <h4 class="fw-bold text-dark mb-0 font-monospace">₹<?= number_format($myMonthlyTarget, 2) ?></h4>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="bg-success bg-opacity-10 border border-success border-opacity-25 p-3 rounded-4 text-center">
                        <span class="ultra-label text-success mb-1">Closed This Month</span>
                        <h4 class="fw-bold text-success mb-0 font-monospace">₹<?= number_format($myMonthRevenue, 2) ?></h4>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="bg-light p-3 rounded-4 border text-center">
                        <span class="ultra-label mb-1">Pending Gap</span>
                        <h4 class="fw-bold text-muted mb-0 font-monospace">₹<?= number_format(max(0, $myMonthlyTarget - $myMonthRevenue), 2) ?></h4>
                    </div>
                </div>
                <div class="col-12 mt-3">
                    <div class="progress" style="height: 12px; border-radius: 100px;">
                        <div class="progress-bar <?= $targetProgress >= 100 ? 'bg-success' : 'bg-primary' ?> progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= min(100, $targetProgress) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- 4. Tasks & Meetings -->
            <div class="col-12 col-xl-7">
                <div class="ultra-card h-100 mb-0 p-4">
                    <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                        <div class="icon-box bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-list-check"></i></div>
                        <h5 class="fw-bold mb-0 text-dark">Tasks & Meetings</h5>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center bg-light p-3 rounded-4 h-100 border-start border-primary border-4">
                                <div class="icon-box bg-white shadow-sm text-primary me-3"><i class="bi bi-telephone-forward"></i></div>
                                <div>
                                    <span class="ultra-label mb-0">Today's Follow-up</span>
                                    <h3 class="mb-0 fw-bold text-primary font-monospace"><?= $myTodayFollowups ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center bg-light p-3 rounded-4 h-100 border-start border-danger border-4">
                                <div class="icon-box bg-white shadow-sm text-danger me-3"><i class="bi bi-exclamation-triangle"></i></div>
                                <div>
                                    <span class="ultra-label mb-0 text-danger">Overdue Tasks</span>
                                    <h3 class="mb-0 fw-bold text-danger font-monospace"><?= $myOverdueFollowups ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center bg-light p-3 rounded-4 h-100 border-start border-success border-4">
                                <div class="icon-box bg-white shadow-sm text-success me-3"><i class="bi bi-calendar2-check"></i></div>
                                <div>
                                    <span class="ultra-label mb-0 text-success">Today's Meetings</span>
                                    <h3 class="mb-0 fw-bold text-success font-monospace"><?= $myTodayMeetings ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center bg-light p-3 rounded-4 h-100 border-start border-info border-4">
                                <div class="icon-box bg-white shadow-sm text-info me-3"><i class="bi bi-calendar-range"></i></div>
                                <div>
                                    <span class="ultra-label mb-0 text-info">Upcoming Meetings</span>
                                    <h3 class="mb-0 fw-bold text-info font-monospace"><?= $myUpcomingMeetings ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. Quick Actions & Working Time Tracker -->
            <div class="col-12 col-xl-5">
                <div class="row g-3 h-100">
                    <div class="col-6">
                        <a href="/public/clients/create.php" class="text-decoration-none">
                            <div class="ultra-card text-center py-4 h-100 card-hover mb-0">
                                <div class="icon-box bg-primary bg-opacity-10 text-primary mx-auto mb-2"><i class="bi bi-person-plus-fill"></i></div>
                                <h6 class="fw-bold text-dark mb-0">Add Client</h6>
                            </div>
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="/public/meetings/schedule.php" class="text-decoration-none">
                            <div class="ultra-card text-center py-4 h-100 card-hover mb-0">
                                <div class="icon-box bg-success bg-opacity-10 text-success mx-auto mb-2"><i class="bi bi-calendar-plus-fill"></i></div>
                                <h6 class="fw-bold text-dark mb-0">Schedule</h6>
                            </div>
                        </a>
                    </div>

                    <!-- Working Time Tracker Section -->
                    <div class="col-12">
                        <div class="ultra-card h-100 mb-0 p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="ultra-label mb-0"><i class="bi bi-stopwatch me-1 text-warning"></i> Active Time Tracker</span>
                                <?php
                                    $badgeColor = 'bg-secondary';
                                    if($timeStatus === 'Excellent') $badgeColor = 'bg-success';
                                    if($timeStatus === 'Good') $badgeColor = 'bg-primary';
                                    if($timeStatus === 'Below Average') $badgeColor = 'bg-warning text-dark';
                                    if($timeStatus === 'Low') $badgeColor = 'bg-danger';
                                ?>
                                <span class="badge <?= $badgeColor ?> rounded-pill px-3 py-1.5"><?= $timeStatus ?></span>
                            </div>
                            
                            <div class="text-center mb-3 bg-light rounded-4 py-3 border">
                                <span class="ultra-label mb-1">Today's Active Time</span>
                                <h1 class="display-5 fw-bold font-monospace text-dark mb-0" id="dashboard-today-time">
                                    <?= formatWorkingHours($todaySeconds) ?>
                                </h1>
                            </div>
                            
                            <div class="row text-center">
                                <div class="col-6 border-end">
                                    <span class="text-muted d-block small mb-1 fw-semibold">This Week</span>
                                    <h5 class="fw-bold text-dark mb-0 font-monospace"><?= formatWorkingHours($myTime['week_time'] ?? 0) ?></h5>
                                </div>
                                <div class="col-6">
                                    <span class="text-muted d-block small mb-1 fw-semibold">This Month</span>
                                    <h5 class="fw-bold text-dark mb-0 font-monospace"><?= formatWorkingHours($myTime['month_time'] ?? 0) ?></h5>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- ============================================================ -->
<!-- MODAL: SUPER ADMIN MOBILE PERFORMANCE DETAILS POPUP          -->
<!-- ============================================================ -->
<?php if ($role === 'super_admin'): ?>
<div class="modal fade" id="superAdminPerfModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mPerfAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mPerfName" class="fw-bold text-dark mb-0"></h5>
                            <small id="mPerfEmail" class="text-muted fw-medium"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Revenue & Target Highlight -->
                <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25 mb-4 text-center p-3">
                    <span class="metric-stat-label text-success">Revenue (This Month) / Target</span>
                    <div class="d-flex align-items-baseline justify-content-center gap-2 mt-1">
                        <h3 id="mPerfMonthRev" class="fw-bold text-success mb-0 font-monospace"></h3>
                        <span class="text-muted font-monospace">/ <span id="mPerfTargetFormatted"></span></span>
                    </div>
                    <span id="mPerfProgBadge" class="badge bg-success rounded-pill px-3 py-1 mt-2 small"></span>
                </div>

                <!-- Client Metrics -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Client Pipeline</h6>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Assigned Clients</span>
                            <h4 id="mPerfClients" class="fw-bold text-dark mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Hot Leads</span>
                            <h4 id="mPerfHot" class="fw-bold text-danger mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Converted</span>
                            <h4 id="mPerfConverted" class="fw-bold text-success mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Rejected</span>
                            <h4 id="mPerfRejected" class="fw-bold text-muted mb-0 font-monospace"></h4>
                        </div>
                    </div>
                </div>

                <!-- Working Time -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Working Hours Logged</h6>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Today</span>
                            <div id="mPerfTodayTime" class="fw-bold text-primary font-monospace fs-6"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">This Week</span>
                            <div id="mPerfWeekTime" class="fw-bold text-dark font-monospace fs-6"></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="d-flex flex-column gap-2">
                    <button type="button" id="mPerfSetTargetBtn" class="btn btn-ultra-secondary w-100 py-3">
                        <i class="bi bi-bullseye me-2 text-primary"></i> Edit Monthly Target
                    </button>
                    <a id="mPerfProfileBtn" href="#" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-person-badge me-2"></i> View Employee's Profile
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: SET TARGET AMOUNT (Both Mobile & Desktop Access)      -->
<!-- ============================================================ -->
<div class="modal fade" id="setTargetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/public/dashboard.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_target">
            <input type="hidden" name="admin_id" id="targetAdminId">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-bullseye"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Monthly Sales Target</h4>
                            <small class="text-muted fw-medium" id="targetAdminNameHeader"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="mb-4">
                    <label class="ultra-label">Monthly Target Amount (₹) <span class="text-danger">*</span></label>
                    <input type="number" step="1000" name="target_amount" id="targetAmountInput" class="form-control ultra-input font-monospace fs-5 text-success" required>
                    <!--<div class="text-muted small mt-2 ms-1">-->
                    <!--    <i class="bi bi-info-circle me-1"></i> This target will govern the progress and conversion reports for this administrator.-->
                    <!--</div>-->
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-check-lg me-1"></i> Save Target</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Opens the Super Admin Mobile Details Popup for an administrator.
 */
function openSuperAdminPerfModal(id, name, email, clients, hot, converted, rejected, monthRev, totRev, targetNum, targetFmt, prog, todayTime, weekTime) {
    document.getElementById('mPerfAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mPerfName').textContent = name;
    document.getElementById('mPerfEmail').textContent = email;
    document.getElementById('mPerfMonthRev').textContent = monthRev;
    document.getElementById('mPerfTargetFormatted').textContent = targetFmt;
    document.getElementById('mPerfClients').textContent = clients;
    document.getElementById('mPerfHot').textContent = hot;
    document.getElementById('mPerfConverted').textContent = converted;
    document.getElementById('mPerfRejected').textContent = rejected;
    document.getElementById('mPerfTodayTime').textContent = todayTime;
    document.getElementById('mPerfWeekTime').textContent = weekTime;

    const progBadge = document.getElementById('mPerfProgBadge');
    progBadge.textContent = prog + ' Target Achieved';

    document.getElementById('mPerfProfileBtn').href = '/public/admins/profile.php?id=' + id;
    
    // Wire up target edit button
    document.getElementById('mPerfSetTargetBtn').onclick = function() {
        const perfModal = bootstrap.Modal.getInstance(document.getElementById('superAdminPerfModal'));
        if (perfModal) perfModal.hide();
        openSetTargetModal(id, name, targetNum);
    };

    const modal = new bootstrap.Modal(document.getElementById('superAdminPerfModal'));
    modal.show();
}

/**
 * Opens Set Target Modal for setting an administrator's monthly target.
 */
function openSetTargetModal(adminId, adminName, currentTarget) {
    document.getElementById('targetAdminId').value = adminId;
    document.getElementById('targetAdminNameHeader').textContent = 'Employee: ' + adminName;
    document.getElementById('targetAmountInput').value = currentTarget;

    const modal = new bootstrap.Modal(document.getElementById('setTargetModal'));
    modal.show();
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($role === 'admin'): ?>
<!-- Sync Dashboard timer with the active navbar timer visually -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const navTimer = document.getElementById('working-time-display');
    const dashTimer = document.getElementById('dashboard-today-time');
    
    if (navTimer && dashTimer) {
        setInterval(() => {
            const parts = navTimer.innerText.split('Time: ');
            if (parts.length > 1) {
                dashTimer.innerText = parts[1];
            }
        }, 1000);
    }
});
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