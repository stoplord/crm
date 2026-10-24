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

$pageTitle = 'Admin Login Activity';
$db_error = '';

// 1. Handle Filters Safely
$filter_admin = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$filter_date = isset($_GET['date']) ? htmlspecialchars(trim($_GET['date']), ENT_QUOTES) : '';

$where = ["u.role = 'admin'"];
$params = [];

if ($filter_admin) {
    $where[] = "w.admin_id = ?";
    $params[] = $filter_admin;
}
if ($filter_date) {
    $where[] = "w.session_date = ?";
    $params[] = $filter_date;
}

$whereClause = implode(' AND ', $where);

$sessions = [];
$adminsList = [];

// 2. Safely Query Database with Try-Catch
try {
    // Fetch Work Sessions
    $query = "
        SELECT 
            w.id, w.login_time, w.logout_time, w.duration_seconds, 
            w.logout_reason, w.ip_address, w.user_agent, w.session_date,
            u.name as admin_name, u.id as admin_id
        FROM work_sessions w
        JOIN users u ON w.admin_id = u.id
        WHERE $whereClause
        ORDER BY w.login_time DESC
        LIMIT 150
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Active Admins for Filter Dropdown
    $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Gracefully catch missing tables/columns to prevent a 500 Server Error crash
    $db_error = "Database Schema Error: Missing `work_sessions` table. <br><br>
                 <strong>Please run this SQL in your database:</strong><br>
                 <code>CREATE TABLE `work_sessions` (`id` int(11) NOT NULL AUTO_INCREMENT, `admin_id` int(11) NOT NULL, `login_time` datetime NOT NULL, `logout_time` datetime DEFAULT NULL, `duration_seconds` int(11) DEFAULT 0, `logout_reason` varchar(50) DEFAULT NULL, `ip_address` varchar(45) DEFAULT NULL, `user_agent` text DEFAULT NULL, `session_date` date NOT NULL, PRIMARY KEY (`id`));</code>";
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
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 14px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 1000px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Soft Badges */
    .app-badge-soft { padding: 0.4rem 0.9rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.3px; }
    .badge-active { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
    .badge-manual { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .badge-timeout { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .badge-other { background: rgba(100, 116, 139, 0.15); color: #475569; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Session Item Card */
    .mobile-item-card { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-item-card:active { transform: scale(0.98); background: #f8fafc; }

    /* Modal Styling */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-journal-text fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Employee's Login Activity</h3>
                <p class="text-muted small mb-0 fw-medium">Monitor session durations, IP addresses, and logouts.</p>
            </div>
        </div>
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Search & Filter Card -->
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/admins/activity.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-5 col-lg-4">
                    <label class="ultra-label">Filter by Employee</label>
                    <select name="admin_id" class="form-select ultra-input">
                        <option value="">All Employees</option>
                        <?php foreach ($adminsList as $admin): ?>
                            <option value="<?= $admin['id'] ?>" <?= $filter_admin === (int)$admin['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($admin['name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Filter by Date</label>
                    <input type="date" name="date" class="form-control ultra-input" value="<?= htmlspecialchars($filter_date, ENT_QUOTES) ?>">
                </div>
                <div class="col-12 col-md-3 col-lg-5 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 flex-lg-grow-0 px-4"><i class="bi bi-funnel me-1"></i> Apply Filter</button>
                    <?php if ($filter_admin || $filter_date): ?>
                        <a href="/public/admins/activity.php" class="btn btn-ultra-secondary fw-bold shadow-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Session Logs: <span class="text-primary"><?= count($sessions) ?> records</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap session for device info</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card List with Popup Modal)                  -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($sessions)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-clock-history text-muted fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No session logs found</h5>
                    <p class="text-muted small">Try adjusting your filters or date range.</p>
                </div>
            <?php else: ?>
                <?php foreach ($sessions as $session): 
                    $badgeClass = 'badge-active';
                    $reasonText = 'Active Session';
                    $icon = '<i class="bi bi-activity me-1"></i>';

                    if ($session['logout_reason'] === 'manual') {
                        $badgeClass = 'badge-manual';
                        $reasonText = 'Manual Logout';
                        $icon = '<i class="bi bi-box-arrow-right me-1"></i>';
                    } elseif ($session['logout_reason'] === 'inactivity') {
                        $badgeClass = 'badge-timeout';
                        $reasonText = 'Timeout';
                        $icon = '<i class="bi bi-hourglass-bottom me-1"></i>';
                    } elseif ($session['logout_reason']) {
                        $badgeClass = 'badge-other';
                        $reasonText = htmlspecialchars(ucfirst($session['logout_reason']), ENT_QUOTES);
                        $icon = '<i class="bi bi-info-circle me-1"></i>';
                    }

                    $duration = (int)$session['duration_seconds'];
                    $formatted_duration = sprintf('%02d:%02d:%02d', ($duration / 3600), ($duration / 60 % 60), $duration % 60);
                    $sessionDateFmt = date('d M, Y', strtotime($session['session_date']));
                    $loginTimeFmt = date('h:i:s A', strtotime($session['login_time']));
                    $logoutTimeFmt = $session['logout_time'] ? date('h:i:s A', strtotime($session['logout_time'])) : 'In progress...';
                ?>
                    <div class="mobile-item-card"
                         onclick="openSessionDetailsModal(
                             <?= (int)$session['admin_id'] ?>,
                             '<?= htmlspecialchars(addslashes($session['admin_name']), ENT_QUOTES) ?>',
                             '<?= $sessionDateFmt ?>',
                             '<?= $loginTimeFmt ?>',
                             '<?= $logoutTimeFmt ?>',
                             '<?= $formatted_duration ?>',
                             '<?= $reasonText ?>',
                             '<?= $badgeClass ?>',
                             '<?= htmlspecialchars(addslashes($session['ip_address'] ?? 'Unknown'), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($session['user_agent'] ?? 'Unknown'), ENT_QUOTES) ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3 shadow-sm">
                                <?= strtoupper(substr($session['admin_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($session['admin_name'], ENT_QUOTES) ?></div>
                                <span class="app-badge-soft <?= $badgeClass ?> mt-1"><?= $icon ?> <?= $reasonText ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-primary font-monospace fs-6"><?= $formatted_duration ?></div>
                            <small class="text-muted d-block"><?= $sessionDateFmt ?></small>
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
                        <th>Employee</th>
                        <th>Session Date</th>
                        <th>Timeline (In / Out)</th>
                        <th>Duration</th>
                        <th>Status / Reason</th>
                        <th>Network & Device</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="6" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-clock-history text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No session logs found</h5>
                                <p class="text-muted small">Try adjusting your filters or date range.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sessions as $session): 
                            $badgeClass = 'badge-active';
                            $reasonText = 'Active Session';
                            $icon = '<i class="bi bi-activity me-1"></i>';

                            if ($session['logout_reason'] === 'manual') {
                                $badgeClass = 'badge-manual';
                                $reasonText = 'Manual Logout';
                                $icon = '<i class="bi bi-box-arrow-right me-1"></i>';
                            } elseif ($session['logout_reason'] === 'inactivity') {
                                $badgeClass = 'badge-timeout';
                                $reasonText = 'Timeout';
                                $icon = '<i class="bi bi-hourglass-bottom me-1"></i>';
                            } elseif ($session['logout_reason']) {
                                $badgeClass = 'badge-other';
                                $reasonText = htmlspecialchars(ucfirst($session['logout_reason']), ENT_QUOTES);
                                $icon = '<i class="bi bi-info-circle me-1"></i>';
                            }

                            $duration = (int)$session['duration_seconds'];
                            $formatted_duration = sprintf('%02d:%02d:%02d', ($duration / 3600), ($duration / 60 % 60), $duration % 60);
                            $uaShort = mb_strimwidth($session['user_agent'] ?? 'Unknown', 0, 30, '...');
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3 shadow-sm">
                                            <?= strtoupper(substr($session['admin_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <a href="/public/admins/profile.php?id=<?= $session['admin_id'] ?>" class="text-decoration-none fw-bold text-dark fs-6 d-block">
                                                <?= htmlspecialchars($session['admin_name'], ENT_QUOTES) ?>
                                            </a>
                                            <span class="badge bg-light border text-muted rounded-pill mt-1">ID: #<?= $session['admin_id'] ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark"><?= date('d M, Y', strtotime($session['session_date'])) ?></span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1 font-monospace small">
                                        <div class="text-success fw-bold"><i class="bi bi-box-arrow-in-right me-1"></i> <?= date('h:i:s A', strtotime($session['login_time'])) ?></div>
                                        <?php if ($session['logout_time']): ?>
                                            <div class="text-danger fw-bold"><i class="bi bi-box-arrow-left me-1"></i> <?= date('h:i:s A', strtotime($session['logout_time'])) ?></div>
                                        <?php else: ?>
                                            <div class="text-muted fst-italic"><i class="bi bi-arrow-repeat me-1"></i> In progress...</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary font-monospace fs-5 bg-primary bg-opacity-10 px-3 py-1 rounded-3">
                                        <?= $formatted_duration ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="app-badge-soft <?= $badgeClass ?>">
                                        <?= $icon ?> <?= $reasonText ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold font-monospace text-dark small"><i class="bi bi-router me-1 text-muted"></i> <?= htmlspecialchars($session['ip_address'] ?? 'Unknown', ENT_QUOTES) ?></div>
                                    <div class="d-inline-block text-truncate text-muted small mt-1" style="max-width: 180px;" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($session['user_agent'] ?? '', ENT_QUOTES) ?>">
                                        <i class="bi bi-laptop me-1"></i> <?= htmlspecialchars($uaShort, ENT_QUOTES) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (count($sessions) === 150): ?>
            <div class="text-center text-muted small py-3 fw-medium">
                <i class="bi bi-info-circle me-1"></i> Showing latest 150 records. Use filters to narrow your search.
            </div>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE SESSION DETAILS & DEVICE INFO POPUP            -->
<!-- ============================================================ -->
<div class="modal fade" id="sessionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mSessionAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mSessionAdminName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mSessionStatusBadge" class="badge rounded-pill px-2.5 py-1 small mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Duration Metric Card -->
                <div class="metric-stat-card bg-primary bg-opacity-10 border-primary border-opacity-25 mb-4 text-center p-3">
                    <span class="metric-stat-label text-primary">Active Session Duration</span>
                    <h3 id="mSessionDuration" class="fw-bold text-primary mb-0 font-monospace"></h3>
                </div>

                <!-- Timeline Grid -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-event text-primary me-2"></i> Date</span>
                        <span id="mSessionDate" class="fw-bold text-dark small"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-box-arrow-in-right text-success me-2"></i> Login Time</span>
                        <span id="mSessionLogin" class="fw-bold text-success font-monospace small"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-box-arrow-left text-danger me-2"></i> Logout Time</span>
                        <span id="mSessionLogout" class="fw-bold text-danger font-monospace small"></span>
                    </div>
                </div>

                <!-- Network & User Agent -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Network & Device</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div class="mb-2">
                        <span class="small fw-semibold text-muted d-block">IP Address</span>
                        <span id="mSessionIp" class="font-monospace text-dark fw-bold"></span>
                    </div>
                    <hr class="my-2 border-secondary border-opacity-25">
                    <div>
                        <span class="small fw-semibold text-muted d-block">User Agent / Browser</span>
                        <span id="mSessionUa" class="small text-dark font-monospace" style="word-break: break-all;"></span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a id="mSessionProfileBtn" href="#" class="btn btn-ultra-secondary w-100 py-3">
                        <i class="bi bi-person me-2 text-primary"></i> View Employee Profile
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Opens and Populates Mobile Session Details Modal Popup.
 */
function openSessionDetailsModal(adminId, adminName, dateStr, loginTime, logoutTime, durationStr, reasonText, badgeClass, ipAddress, userAgent) {
    document.getElementById('mSessionAvatar').textContent = adminName.charAt(0).toUpperCase();
    document.getElementById('mSessionAdminName').textContent = adminName;
    document.getElementById('mSessionDate').textContent = dateStr;
    document.getElementById('mSessionLogin').textContent = loginTime;
    document.getElementById('mSessionLogout').textContent = logoutTime;
    document.getElementById('mSessionDuration').textContent = durationStr;
    document.getElementById('mSessionIp').textContent = ipAddress;
    document.getElementById('mSessionUa').textContent = userAgent;

    const badge = document.getElementById('mSessionStatusBadge');
    badge.textContent = reasonText;
    badge.className = 'badge rounded-pill px-2.5 py-1 small mt-1 ' + (badgeClass.includes('badge-active') ? 'badge-active' : (badgeClass.includes('badge-manual') ? 'badge-manual' : (badgeClass.includes('badge-timeout') ? 'badge-timeout' : 'badge-other')));

    document.getElementById('mSessionProfileBtn').href = '/public/admins/profile.php?id=' + adminId;

    const modal = new bootstrap.Modal(document.getElementById('sessionDetailsModal'));
    modal.show();
}

document.addEventListener("DOMContentLoaded", function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
});

// Security Script Preserved
document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    if (e.key === "F12") { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
});
</script>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>