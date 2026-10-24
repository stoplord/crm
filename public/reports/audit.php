<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

// Strict Manual Role Check (Prevents crashes if helper function is missing)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'System Audit Logs';

// 1. Filter Parameters with Native Sanitization
$filter_action = htmlspecialchars(trim($_GET['action_type'] ?? ''), ENT_QUOTES);
$filter_role   = htmlspecialchars(trim($_GET['role'] ?? ''), ENT_QUOTES);
$filter_date   = htmlspecialchars(trim($_GET['date'] ?? ''), ENT_QUOTES);
$filter_user   = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

// 2. Safely Query Database with Try-Catch
$auditLogs = [];
$actionsList = [];
$usersList = [];
$db_error = '';

try {
    // Fetch unique actions for filter dropdown
    $actionsList = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

    // Fetch users list for filter dropdown
    $usersList = $pdo->query("SELECT id, name, role FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build Query Constraints
    $where = ["1=1"];
    $params = [];

    if ($filter_action) {
        $where[] = "a.action = ?";
        $params[] = $filter_action;
    }
    if ($filter_role) {
        $where[] = "a.role = ?";
        $params[] = $filter_role;
    }
    if ($filter_date) {
        $where[] = "DATE(a.created_at) = ?";
        $params[] = $filter_date;
    }
    if ($filter_user) {
        $where[] = "a.user_id = ?";
        $params[] = $filter_user;
    }

    $whereClause = implode(' AND ', $where);

    // Fetch Audit Logs with User details
    $query = "
        SELECT 
            a.*, 
            u.name AS user_name, u.email AS user_email
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE $whereClause
        ORDER BY a.created_at DESC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Gracefully catch missing tables/columns to prevent a 500 Server Error crash
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br><br>
                 <strong>Please ensure your `audit_logs` table contains these columns:</strong><br>
                 <code>id, user_id, role, action, target_type, target_id, description, ip_address, created_at</code>";
}

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
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

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

    /* Soft Badges */
    .app-badge-soft { padding: 0.4rem 0.8rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; letter-spacing: 0.3px; display: inline-flex; align-items: center; }

    /* Mobile Audit Cards */
    .mobile-audit-item { background: #ffffff; border-radius: 20px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-audit-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Details Modal */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-shield-check fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Employee Audit Logs</h3>
                <p class="text-muted small mb-0 fw-medium">Trace security events, administrative actions & data modifications.</p>
            </div>
        </div>
        <!--<div class="d-print-none">-->
        <!--    <button onclick="window.print()" class="btn btn-ultra-secondary shadow-sm">-->
        <!--        <i class="bi bi-printer me-2"></i> Print / Export-->
        <!--    </button>-->
        <!--</div>-->
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4 d-print-none">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Filter Card -->
        <div class="ultra-card mb-4 d-print-none">
            <form method="GET" action="/public/reports/audit.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="ultra-label">Filter by Action</label>
                    <select name="action_type" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <?php foreach ($actionsList as $act): ?>
                            <option value="<?= htmlspecialchars($act, ENT_QUOTES) ?>" <?= $filter_action === $act ? 'selected' : '' ?>>
                                <?= htmlspecialchars($act, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="ultra-label">Filter by User</label>
                    <select name="user_id" class="form-select ultra-input select2-enable" onchange="this.form.submit()">
                        <option value="">All Users</option>
                        <?php foreach ($usersList as $usr): ?>
                            <option value="<?= $usr['id'] ?>" <?= $filter_user === (int)$usr['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usr['name'], ENT_QUOTES) ?> (<?= ucfirst($usr['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="ultra-label">Date</label>
                    <input type="date" name="date" class="form-control ultra-input" value="<?= $filter_date ?>" onchange="this.form.submit()">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="ultra-label">Role</label>
                    <select name="role" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <option value="super_admin" <?= $filter_role === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2 d-flex align-items-end">
                    <a href="/public/reports/audit.php" class="btn btn-ultra-secondary w-100 shadow-sm fw-bold">Reset</a>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Audit Records: <span class="text-primary"><?= count($auditLogs) ?> entries</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap record for details</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card list with modal detail on click)        -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($auditLogs)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-shield-x text-muted fs-1 d-block mb-2"></i>
                    <h6 class="fw-bold text-dark mb-1">No audit records found</h6>
                    <p class="text-muted small mb-0">Try changing your filters or selecting a different date.</p>
                </div>
            <?php else: ?>
                <?php foreach ($auditLogs as $log): 
                    $roleLabel = htmlspecialchars(str_replace('_', ' ', ucfirst($log['role'] ?? '')), ENT_QUOTES);
                    $roleBadge = ($log['role'] === 'super_admin') ? 'bg-danger' : 'bg-primary';
                    $datePart = date('d M Y', strtotime($log['created_at']));
                    $timePart = date('h:i:s A', strtotime($log['created_at']));
                    $targetInfo = htmlspecialchars($log['target_type'] ?? '', ENT_QUOTES) . ($log['target_id'] ? ' (#' . (int)$log['target_id'] . ')' : '');
                    $actorName = htmlspecialchars($log['user_name'] ?? 'System / Deleted User', ENT_QUOTES);
                    $actorEmail = htmlspecialchars($log['user_email'] ?? 'No email', ENT_QUOTES);
                ?>
                    <div class="mobile-audit-item"
                         onclick="openAuditDetailsModal(this)"
                         data-name="<?= $actorName ?>"
                         data-email="<?= $actorEmail ?>"
                         data-userid="<?= (int)$log['user_id'] ?>"
                         data-role="<?= $roleLabel ?>"
                         data-rolebadge="<?= $roleBadge ?>"
                         data-action="<?= htmlspecialchars($log['action'] ?? '', ENT_QUOTES) ?>"
                         data-date="<?= $datePart ?>"
                         data-time="<?= $timePart ?>"
                         data-target="<?= $targetInfo ?>"
                         data-ip="<?= htmlspecialchars($log['ip_address'] ?? 'N/A', ENT_QUOTES) ?>"
                         data-desc="<?= htmlspecialchars($log['description'] ?? '', ENT_QUOTES) ?>">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($log['user_name'] ?? 'S', 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= $actorName ?></div>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.7rem;">
                                        <?= htmlspecialchars($log['action'], ENT_QUOTES) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block font-monospace fw-semibold"><?= $datePart ?></small>
                            <small class="text-muted"><?= $timePart ?></small>
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
                        <th>Timestamp</th>
                        <th>User / Actor</th>
                        <th>Role</th>
                        <th>Action Type</th>
                        <th>Target Info</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditLogs)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="7" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-shield-x text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No audit log records found</h5>
                                <p class="text-muted small">No audit log records matching the criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): 
                            $roleBadge = ($log['role'] === 'super_admin') ? 'bg-danger' : 'bg-primary';
                        ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold text-dark d-block"><?= date('d M Y', strtotime($log['created_at'])) ?></span>
                                    <small class="text-muted font-monospace"><?= date('h:i:s A', strtotime($log['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($log['user_name'] ?? 'S', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($log['user_name'] ?? 'System / Deleted User', ENT_QUOTES) ?></div>
                                            <small class="text-muted font-monospace">ID: #<?= (int)$log['user_id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= $roleBadge ?> px-3 py-2 rounded-pill shadow-sm">
                                        <?= htmlspecialchars(str_replace('_', ' ', ucfirst($log['role'] ?? '')), ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace shadow-sm px-2.5 py-1.5">
                                        <?= htmlspecialchars($log['action'], ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted small fw-bold text-uppercase">
                                        <?= htmlspecialchars($log['target_type'], ENT_QUOTES) ?> 
                                        <?= $log['target_id'] ? '(#' . (int)$log['target_id'] . ')' : '' ?>
                                    </span>
                                </td>
                                <td style="max-width: 320px;">
                                    <div class="text-dark small fw-medium" style="white-space: pre-wrap;"><?= htmlspecialchars($log['description'], ENT_QUOTES) ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace text-muted small bg-light px-2 py-1 rounded border">
                                        <?= htmlspecialchars($log['ip_address'] ?? 'N/A', ENT_QUOTES) ?>
                                    </span>
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
<!-- MOBILE AUDIT LOG DETAILS MODAL POPUP                         -->
<!-- ============================================================ -->
<div class="modal fade" id="auditDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Actor Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mName" class="fw-bold text-dark mb-0"></h5>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span id="mRoleBadge" class="badge rounded-pill px-2.5 py-1 small"></span>
                                <small id="mUserId" class="text-muted font-monospace"></small>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Event Details -->
                <h6 class="text-muted small fw-bold text-uppercase mb-3 letter-spacing-1">Event Information</h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Action</span>
                            <span id="mAction" class="badge bg-light text-dark border font-monospace text-start text-break py-1.5 px-2"></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Target Entity</span>
                            <span id="mTarget" class="fw-bold text-dark small text-break"></span>
                        </div>
                    </div>
                </div>

                <!-- Network & Time -->
                <div class="d-flex flex-column gap-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history text-primary me-2"></i> Timestamp</span>
                        <span id="mTimestamp" class="fw-bold text-dark small font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-router text-primary me-2"></i> IP Address</span>
                        <span id="mIp" class="font-monospace text-muted small"></span>
                    </div>
                </div>

                <!-- Event Description -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Activity Description</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mDesc" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.6;"></div>
                </div>

                <button type="button" class="btn btn-ultra-secondary w-100 py-3" data-bs-dismiss="modal">Close</button>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Populates and displays the mobile Audit Details modal popup.
 */
function openAuditDetailsModal(el) {
    const d = el.dataset;
    document.getElementById('mAvatar').textContent = d.name ? d.name.charAt(0).toUpperCase() : 'S';
    document.getElementById('mName').textContent = d.name;
    document.getElementById('mUserId').textContent = 'ID: #' + d.userid;
    
    const roleBadge = document.getElementById('mRoleBadge');
    roleBadge.textContent = d.role;
    roleBadge.className = 'badge rounded-pill px-2.5 py-1 small ' + d.rolebadge;

    document.getElementById('mAction').textContent = d.action;
    document.getElementById('mTarget').textContent = d.target;
    document.getElementById('mTimestamp').textContent = d.date + ', ' + d.time;
    document.getElementById('mIp').textContent = d.ip;
    document.getElementById('mDesc').textContent = d.desc ? d.desc : 'No description provided.';

    const modal = new bootstrap.Modal(document.getElementById('auditDetailsModal'));
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

<!-- Print Styles -->
<style>
    @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .d-print-none { display: none !important; }
        .container-fluid { padding: 0 !important; width: 100% !important; margin: 0 !important; }
        .ultra-card { border: 1px solid #ddd !important; box-shadow: none !important; background: #fff !important; color: #000 !important; }
    }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>