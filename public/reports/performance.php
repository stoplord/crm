<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Strict Manual Role Check (Prevents crashes if helper function is missing)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'Admin Performance Report';
$adminsPerf = [];
$db_error = '';

try {
    // Fetch Performance Data for all active Admins with aggregated database metrics
    $query = "
        SELECT 
            u.id, u.name, u.email, u.mobile, u.status, u.created_at,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deleted_at IS NULL) as total_clients,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND temperature = 'HOT' AND deleted_at IS NULL) as hot_leads,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Converted' AND deleted_at IS NULL) as converted_deals,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deal_status = 'Rejected' AND deleted_at IS NULL) as rejected_deals,
            (SELECT COUNT(*) FROM client_activities WHERE admin_id = u.id) as total_followups,
            COALESCE((SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND work_date = CURDATE()), 0) as today_time,
            COALESCE((SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND YEARWEEK(work_date, 1) = YEARWEEK(CURDATE(), 1)), 0) as week_time,
            COALESCE((SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = u.id AND MONTH(work_date) = MONTH(CURDATE()) AND YEAR(work_date) = YEAR(CURDATE())), 0) as month_time
        FROM users u 
        WHERE u.role = 'admin' AND u.deleted_at IS NULL
        ORDER BY u.name ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $adminsPerf = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Gracefully catch missing tables/columns to prevent a 500 Server Error
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br><br>
                 <strong>Potential Fix:</strong> Ensure the <code>daily_work_logs</code> and <code>client_activities</code> tables exist, and <code>clients</code> contains the <code>deal_status</code> and <code>temperature</code> columns.";
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    
    /* Ultra Glass/Neumorphic Cards */
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; transition: transform 0.3s ease; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; }
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

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }
    
    /* Mobile Card List Items */
    .mobile-admin-item { background: #ffffff; border-radius: 20px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-admin-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Details Modal */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; height: 100%; display: flex; flex-direction: column; justify-content: center; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-person-lines-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Performance Report</h3>
                <p class="text-muted small mb-0 fw-medium">Evaluate active working hours, lead conversions & follow-up outputs.</p>
            </div>
        </div>
        <!--<div class="d-flex gap-2 d-print-none">-->
            <!--<button onclick="window.print()" class="btn btn-ultra-secondary shadow-sm">-->
            <!--    <i class="bi bi-printer me-2"></i> Print-->
            <!--</button>-->
        <!--    <a href="/public/admins/working-hours.php" class="btn btn-ultra-primary shadow-sm">-->
        <!--        <i class="bi bi-clock-history me-2"></i> Working Hours-->
        <!--    </a>-->
        <!--</div>-->
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4 d-print-none">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Active Employees: <span class="text-primary"><?= count($adminsPerf) ?></span> records found</h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap for details</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Admin list only, click opens detailed modal) -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($adminsPerf)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-people text-muted fs-1 d-block mb-2"></i>
                    <h6 class="fw-bold text-dark">No active employees found</h6>
                </div>
            <?php else: ?>
                <?php foreach ($adminsPerf as $ap): 
                    $total_clients = (int)($ap['total_clients'] ?? 0);
                    $converted = (int)($ap['converted_deals'] ?? 0);
                    $conversionRate = $total_clients > 0 ? round(($converted / $total_clients) * 100, 1) : 0;
                    
                    $today_sec = (int)($ap['today_time'] ?? 0);
                    $week_sec = (int)($ap['week_time'] ?? 0);
                    $month_sec = (int)($ap['month_time'] ?? 0);
                    
                    $fmtToday = sprintf('%02d:%02d:%02d', ($today_sec/3600), ($today_sec/60%60), $today_sec%60);
                    $fmtWeek  = sprintf('%02d:%02d:%02d', ($week_sec/3600), ($week_sec/60%60), $week_sec%60);
                    $fmtMonth = sprintf('%02d:%02d:%02d', ($month_sec/3600), ($month_sec/60%60), $month_sec%60);
                ?>
                    <div class="mobile-admin-item" 
                         onclick="openAdminPerformanceModal(
                             <?= (int)$ap['id'] ?>,
                             '<?= htmlspecialchars(addslashes($ap['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($ap['email']), ENT_QUOTES) ?>',
                             '<?= number_format($total_clients) ?>',
                             '<?= number_format((int)$ap['hot_leads']) ?>',
                             '<?= number_format((int)$ap['total_followups']) ?>',
                             '<?= number_format($converted) ?>',
                             '<?= $conversionRate ?>%',
                             '<?= number_format((int)$ap['rejected_deals']) ?>',
                             '<?= $fmtToday ?>',
                             '<?= $fmtWeek ?>',
                             '<?= $fmtMonth ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($ap['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($ap['email'], ENT_QUOTES) ?></div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="btn btn-sm btn-light rounded-circle shadow-sm border" style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="bi bi-chevron-right text-primary"></i>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- 2. DESKTOP VIEW (Full data table)                            -->
        <!-- ============================================================ -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Clients</th>
                        <th>Hot Leads</th>
                        <th>Follow-ups</th>
                        <th>Converted</th>
                        <th>Rejected</th>
                        <th>Today's Time</th>
                        <th>Weekly Time</th>
                        <th>Monthly Time</th>
                        <th class="text-end pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adminsPerf)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="10" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-people text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No active employee accounts found</h5>
                                <p class="text-muted small">No employee performance metrics recorded.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adminsPerf as $ap): 
                            $total_clients = (int)($ap['total_clients'] ?? 0);
                            $converted = (int)($ap['converted_deals'] ?? 0);
                            $conversionRate = $total_clients > 0 ? round(($converted / $total_clients) * 100, 1) : 0;
                            
                            $today_sec = (int)($ap['today_time'] ?? 0);
                            $week_sec = (int)($ap['week_time'] ?? 0);
                            $month_sec = (int)($ap['month_time'] ?? 0);
                            
                            $fmtToday = sprintf('%02d:%02d:%02d', ($today_sec/3600), ($today_sec/60%60), $today_sec%60);
                            $fmtWeek  = sprintf('%02d:%02d:%02d', ($week_sec/3600), ($week_sec/60%60), $week_sec%60);
                            $fmtMonth = sprintf('%02d:%02d:%02d', ($month_sec/3600), ($month_sec/60%60), $month_sec%60);
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($ap['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6">
                                                <a href="/public/admins/profile.php?id=<?= (int)$ap['id'] ?>" class="text-decoration-none text-dark">
                                                    <?= htmlspecialchars($ap['name'], ENT_QUOTES) ?>
                                                </a>
                                            </div>
                                            <small class="text-muted fw-medium"><?= htmlspecialchars($ap['email'], ENT_QUOTES) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <?= number_format($total_clients) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-danger rounded-pill px-3 py-2 shadow-sm">
                                        🔥 <?= number_format((int)$ap['hot_leads']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded border">
                                        <?= number_format((int)$ap['total_followups']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-success fw-bold font-monospace fs-5"><?= number_format($converted) ?></span>
                                    <small class="text-muted d-block fw-semibold">(<?= $conversionRate ?>%)</small>
                                </td>
                                <td>
                                    <span class="text-danger fw-bold font-monospace fs-6"><?= number_format((int)$ap['rejected_deals']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary font-monospace fs-6 bg-primary bg-opacity-10 px-2 py-1 rounded-3">
                                        <?= $fmtToday ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-monospace text-dark fw-bold small"><?= $fmtWeek ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-muted small"><?= $fmtMonth ?></span>
                                </td>
                                <td class="text-end pe-4 d-print-none">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="/public/admins/profile.php?id=<?= (int)$ap['id'] ?>" class="app-btn-icon bg-primary bg-opacity-10 text-primary" title="View Profile">
                                            <i class="bi bi-eye-fill"></i>
                                        </a>
                                        <a href="/public/admins/working-hours.php?admin_id=<?= (int)$ap['id'] ?>" class="app-btn-icon bg-secondary bg-opacity-10 text-dark" title="View Working Hours">
                                            <i class="bi bi-clock-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MOBILE DETAILS MODAL POPUP                                   -->
<!-- ============================================================ -->
<div class="modal fade" id="adminPerformanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Admin Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mName" class="fw-bold text-dark mb-0"></h5>
                            <small id="mEmail" class="text-muted fw-medium"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Sales & Clients Metrics -->
                <h6 class="text-muted small fw-bold text-uppercase mb-3 letter-spacing-1">Clients & Conversion</h6>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Total Clients</span>
                            <h4 id="mClients" class="fw-bold text-dark mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Hot Leads</span>
                            <h4 id="mHotLeads" class="fw-bold text-danger mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Follow-ups</span>
                            <h4 id="mFollowups" class="fw-bold text-dark mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Converted Deals</span>
                            <div class="d-flex align-items-baseline gap-2">
                                <h4 id="mConverted" class="fw-bold text-success mb-0 font-monospace"></h4>
                                <small id="mConvRate" class="text-muted fw-bold"></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Rejected Deals</span>
                            <h5 id="mRejected" class="fw-bold text-danger mb-0 font-monospace"></h5>
                        </div>
                    </div>
                </div>

                <!-- Logged Hours Metrics -->
                <h6 class="text-muted small fw-bold text-uppercase mb-3 letter-spacing-1">Logged Active Time</h6>
                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Today</span>
                            <div id="mToday" class="fw-bold text-primary font-monospace small"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Week</span>
                            <div id="mWeek" class="fw-bold text-dark font-monospace small"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Month</span>
                            <div id="mMonth" class="fw-bold text-muted font-monospace small"></div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Actions -->
                <div class="d-flex gap-2">
                    <a id="mProfileBtn" href="#" class="btn btn-ultra-secondary flex-grow-1 py-3">
                        <i class="bi bi-person-fill me-1 text-primary"></i> Profile
                    </a>
                    <a id="mHoursBtn" href="#" class="btn btn-ultra-primary flex-grow-1 py-3">
                        <i class="bi bi-clock-history me-1"></i> Working Hours
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Populates and triggers the Admin Performance Details modal for mobile view.
 */
function openAdminPerformanceModal(id, name, email, clients, hotLeads, followups, converted, convRate, rejected, today, week, month) {
    document.getElementById('mAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mName').textContent = name;
    document.getElementById('mEmail').textContent = email;
    document.getElementById('mClients').textContent = clients;
    document.getElementById('mHotLeads').textContent = hotLeads;
    document.getElementById('mFollowups').textContent = followups;
    document.getElementById('mConverted').textContent = converted;
    document.getElementById('mConvRate').textContent = '(' + convRate + ')';
    document.getElementById('mRejected').textContent = rejected;
    document.getElementById('mToday').textContent = today;
    document.getElementById('mWeek').textContent = week;
    document.getElementById('mMonth').textContent = month;
    
    document.getElementById('mProfileBtn').href = '/public/admins/profile.php?id=' + id;
    document.getElementById('mHoursBtn').href = '/public/admins/working-hours.php?admin_id=' + id;

    const modal = new bootstrap.Modal(document.getElementById('adminPerformanceModal'));
    modal.show();
}

// Security Scripts Preserved
document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    if (e.key === "F12") { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
});
</script>

<!-- Print Styles to hide Sidebar/Navigation natively -->
<style>
    @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .d-print-none { display: none !important; }
        .container-fluid { padding: 0 !important; width: 100% !important; margin: 0 !important; }
        .ultra-card { border: 1px solid #ddd !important; box-shadow: none !important; background: #fff !important; color: #000 !important; }
    }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>