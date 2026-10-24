<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Strict Manual Role Check (Prevents crashes if helper function is missing)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'Admin Working Hours Management';
$success_msg = '';
$error_msg = '';
$db_error = '';

// Fallback constant
$default_target_seconds = defined('DEFAULT_DAILY_TARGET_SECONDS') ? DEFAULT_DAILY_TARGET_SECONDS : 21600; // Default 6 hours

// Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $success_msg = "Working hours successfully updated and status recalculated.";
}

// Handle Form Submissions (Super Admin Manual Adjustments)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = sanitizeInput($_POST['action'] ?? '');

    if ($action === 'update_work_log') {
        $log_id = filter_input(INPUT_POST, 'log_id', FILTER_VALIDATE_INT);
        $target_admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
        $edit_date = sanitizeInput($_POST['edit_date'] ?? date('Y-m-d'));
        $new_hours = filter_input(INPUT_POST, 'new_hours', FILTER_VALIDATE_FLOAT);
        $reason = sanitizeInput($_POST['adjustment_reason'] ?? 'Manual override');

        if ($new_hours === false || $new_hours < 0 || $new_hours > 24 || !$target_admin_id) {
            $error_msg = "Invalid parameters or working hours provided (must be between 0 and 24).";
        } else {
            try {
                $new_seconds = (int)($new_hours * 3600);
                
                // Inline Status Calculation Fallback
                $status = 'Low';
                if ($new_seconds >= $default_target_seconds) $status = 'Excellent';
                elseif ($new_seconds >= ($default_target_seconds * 0.8)) $status = 'Good';
                elseif ($new_seconds >= ($default_target_seconds * 0.5)) $status = 'Below Average';

                $pdo->beginTransaction();

                if ($log_id) {
                    // Update existing log
                    $stmtUpdate = $pdo->prepare("UPDATE daily_work_logs SET total_seconds = ?, status = ? WHERE id = ?");
                    $stmtUpdate->execute([$new_seconds, $status, $log_id]);
                } else {
                    // Insert new log if none exists for this day
                    $stmtInsert = $pdo->prepare("INSERT INTO daily_work_logs (admin_id, work_date, total_seconds, target_seconds, status) VALUES (?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$target_admin_id, $edit_date, $new_seconds, $default_target_seconds, $status]);
                    $log_id = $pdo->lastInsertId();
                }

                // Audit Log
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([
                    $_SESSION['user_id'], $_SESSION['role'], 'update_working_time', 'daily_work_logs', $log_id,
                    "Updated working time to {$new_hours} hours for Admin ID {$target_admin_id} on {$edit_date}. Reason: {$reason}",
                    $_SERVER['REMOTE_ADDR']
                ]);

                $pdo->commit();
                
                // PRG Redirect
                header("Location: /public/admins/working-hours.php?date={$edit_date}&msg=updated");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Filter Parameters
$filter_admin = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$filter_date = sanitizeInput($_GET['date'] ?? date('Y-m-d'));

$where = ["u.role = 'admin'", "u.deleted_at IS NULL"];
$params = [];

if ($filter_admin) {
    $where[] = "u.id = ?";
    $params[] = $filter_admin;
}

$whereClause = implode(' AND ', $where);

// Fetch Admins and Logs Safely
$reportRows = [];
$adminsList = [];

try {
    // 1. Fetch dropdown list
    $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Main Data Query (Uses COALESCE to handle missing log days)
    $query = "
        SELECT 
            u.id as admin_id,
            u.name as admin_name,
            u.email,
            p.profile_photo,
            dwl.id as log_id,
            COALESCE(dwl.total_seconds, 0) as total_seconds,
            COALESCE(dwl.target_seconds, $default_target_seconds) as target_seconds,
            COALESCE(dwl.status, 'Not Started') as work_status
        FROM users u
        LEFT JOIN admin_profiles p ON u.id = p.user_id
        LEFT JOIN daily_work_logs dwl ON u.id = dwl.admin_id AND dwl.work_date = ?
        WHERE $whereClause
        ORDER BY u.name ASC
    ";

    $queryParams = array_merge([$filter_date], $params);
    $stmt = $pdo->prepare($query);
    $stmt->execute($queryParams);
    $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: Missing `daily_work_logs` table. <br><br>
                 <strong>Please run this SQL in your database:</strong><br>
                 <code>CREATE TABLE `daily_work_logs` (`id` int(11) NOT NULL AUTO_INCREMENT, `admin_id` int(11) NOT NULL, `work_date` date NOT NULL, `total_seconds` int(11) DEFAULT 0, `target_seconds` int(11) DEFAULT 21600, `status` varchar(50) DEFAULT 'Low', PRIMARY KEY (`id`), UNIQUE KEY `admin_date` (`admin_id`,`work_date`));</code>";
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

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; cursor: pointer; }
    .app-btn-icon:hover { transform: scale(1.05); }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 800px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }
    
    /* Avatars & Badges */
    .app-avatar { width: 46px; height: 46px; border-radius: 14px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
    .app-avatar-fallback { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }
    
    .app-badge-soft { padding: 0.4rem 0.9rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.3px; }
    .badge-excellent { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .badge-good { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
    .badge-below { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .badge-low { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
    .badge-none { background: rgba(100, 116, 139, 0.15); color: #475569; }

    /* Mobile Item Card */
    .mobile-item-card { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-item-card:active { transform: scale(0.98); background: #f8fafc; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-clock-history fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Time & Attendance</h3>
                <p class="text-muted small mb-0 fw-medium">Monitor and adjust active working hours.</p>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= escapeOutput($success_msg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= escapeOutput($error_msg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
            <form method="GET" action="/public/admins/working-hours.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-5 col-lg-4">
                    <label class="ultra-label">Select Employee</label>
                    <select name="admin_id" class="form-select ultra-input">
                        <option value="">All Employees</option>
                        <?php foreach ($adminsList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $filter_admin === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Target Date</label>
                    <input type="date" name="date" class="form-control ultra-input" value="<?= htmlspecialchars($filter_date, ENT_QUOTES) ?>" required>
                </div>
                <div class="col-12 col-md-3 col-lg-5 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 flex-lg-grow-0 px-4"><i class="bi bi-search me-1"></i> Check</button>
                    <?php if ($filter_admin || $filter_date !== date('Y-m-d')): ?>
                        <a href="/public/admins/working-hours.php" class="btn btn-ultra-secondary fw-bold shadow-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Record for Date: <span class="text-primary"><?= date('d M Y', strtotime($filter_date)) ?></span></h6>
            <div class="d-flex align-items-center gap-2">
                <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap to adjust</span>-->
                <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">Target: <?= round($default_target_seconds / 3600, 1) ?>h / day</span>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card List with Popup Modal)                  -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($reportRows)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-clock-history text-muted fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No records found</h5>
                    <p class="text-muted small">No administrative data available for this date.</p>
                </div>
            <?php else: ?>
                <?php foreach ($reportRows as $row): 
                    $seconds = (int)$row['total_seconds'];
                    $hoursDecimal = round($seconds / 3600, 2);
                    $formatted_time = sprintf('%02d:%02d:%02d', ($seconds/3600), ($seconds/60%60), $seconds%60);
                    $status = $row['work_status'];
                    
                    $badgeClass = 'badge-none';
                    if($status === 'Excellent') $badgeClass = 'badge-excellent';
                    if($status === 'Good') $badgeClass = 'badge-good';
                    if($status === 'Below Average') $badgeClass = 'badge-below';
                    if($status === 'Low') $badgeClass = 'badge-low';
                ?>
                    <div class="mobile-item-card"
                         onclick="openEditModal(
                             <?= (int)$row['log_id'] ?>,
                             <?= (int)$row['admin_id'] ?>,
                             '<?= htmlspecialchars(addslashes($row['admin_name']), ENT_QUOTES) ?>',
                             <?= $hoursDecimal ?>
                         )">
                        <div class="d-flex align-items-center">
                            <?php if (!empty($row['profile_photo'])): ?>
                                <img src="/uploads/profiles/<?= htmlspecialchars($row['profile_photo'], ENT_QUOTES) ?>" alt="Avatar" class="app-avatar me-3">
                            <?php else: ?>
                                <div class="app-avatar-fallback me-3">
                                    <?= strtoupper(substr($row['admin_name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($row['admin_name'], ENT_QUOTES) ?></div>
                                <span class="app-badge-soft <?= $badgeClass ?> mt-1"><?= htmlspecialchars($status, ENT_QUOTES) ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-primary font-monospace fs-5"><?= $formatted_time ?></div>
                            <!--<small class="text-muted">Tap to adjust</small>-->
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
                        <th>Employees</th>
                        <th>Worked Time</th>
                        <th>Required Time</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reportRows)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="5" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-clock-history text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No records found</h5>
                                <p class="text-muted small">No administrative data available for this date.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): 
                            $seconds = (int)$row['total_seconds'];
                            $hoursDecimal = round($seconds / 3600, 2);
                            
                            $formatted_time = sprintf('%02d:%02d:%02d', ($seconds/3600), ($seconds/60%60), $seconds%60);
                            $formatted_target = sprintf('%02d:%02d:%02d', ($row['target_seconds']/3600), ($row['target_seconds']/60%60), $row['target_seconds']%60);
                            
                            $status = $row['work_status'];
                            $badgeClass = 'badge-none';
                            if($status === 'Excellent') $badgeClass = 'badge-excellent';
                            if($status === 'Good') $badgeClass = 'badge-good';
                            if($status === 'Below Average') $badgeClass = 'badge-below';
                            if($status === 'Low') $badgeClass = 'badge-low';
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($row['profile_photo'])): ?>
                                            <img src="/uploads/profiles/<?= htmlspecialchars($row['profile_photo'], ENT_QUOTES) ?>" alt="Avatar" class="app-avatar me-3">
                                        <?php else: ?>
                                            <div class="app-avatar-fallback me-3">
                                                <?= strtoupper(substr($row['admin_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($row['admin_name'], ENT_QUOTES) ?></div>
                                            <small class="text-muted fw-medium"><?= htmlspecialchars($row['email'], ENT_QUOTES) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary font-monospace fs-5 bg-primary bg-opacity-10 px-3 py-1 rounded-3">
                                        <?= $formatted_time ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-muted font-monospace"><?= $formatted_target ?></span>
                                </td>
                                <td>
                                    <span class="app-badge-soft <?= $badgeClass ?>">
                                        <?php if($status === 'Excellent') echo '<i class="bi bi-star-fill me-1"></i>'; ?>
                                        <?php if($status === 'Good') echo '<i class="bi bi-check-circle-fill me-1"></i>'; ?>
                                        <?php if($status === 'Below Average') echo '<i class="bi bi-exclamation-circle-fill me-1"></i>'; ?>
                                        <?php if($status === 'Low') echo '<i class="bi bi-arrow-down-circle-fill me-1"></i>'; ?>
                                        <?php if($status === 'Not Started') echo '<i class="bi bi-dash-circle-fill me-1"></i>'; ?>
                                        <?= htmlspecialchars($status, ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-ultra-secondary py-2 px-3 fw-bold"
                                            onclick="openEditModal(<?= (int)$row['log_id'] ?>, <?= (int)$row['admin_id'] ?>, '<?= htmlspecialchars(addslashes($row['admin_name']), ENT_QUOTES) ?>', <?= $hoursDecimal ?>)">
                                        <i class="bi bi-pencil-square me-1"></i> Adjust
                                    </button>
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
<!-- MODAL: ADJUST WORKING HOURS POPUP                            -->
<!-- ============================================================ -->
<div class="modal fade" id="editWorkingHoursModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/public/admins/working-hours.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_work_log">
            <input type="hidden" name="log_id" id="modalLogId" value="">
            <input type="hidden" name="admin_id" id="modalAdminId" value="">
            <input type="hidden" name="edit_date" value="<?= htmlspecialchars($filter_date, ENT_QUOTES) ?>">
            
            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-stopwatch"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Adjust Work Time</h4>
                            <small class="text-muted fw-medium">Manual override for attendance logging</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="bg-light rounded-4 p-3 mb-4 border">
                    <span class="text-muted small fw-bold text-uppercase d-block mb-1">Target Employee</span>
                    <h5 id="modalAdminName" class="text-primary font-monospace fw-bold mb-0"></h5>
                </div>
                
                <div class="mb-4">
                    <label class="ultra-label">Total Working Hours <span class="text-danger">*</span></label>
                    <input type="number" step="0.1" min="0" max="24" class="form-control ultra-input font-monospace fs-4 text-center text-primary" name="new_hours" id="modalHoursInput" required>
                    <div class="text-center mt-2 text-muted small fw-medium"><i class="bi bi-info-circle me-1"></i> e.g., <code>6.5</code> = 6 hours 30 minutes</div>
                </div>

                <div class="mb-4">
                    <label class="ultra-label">Reason for Adjustment <span class="text-danger">*</span></label>
                    <textarea class="form-control ultra-input" name="adjustment_reason" rows="2" placeholder="e.g., System glitch, Missing punch..." required></textarea>
                </div>
                
                <div class="mt-5 d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-check-lg me-2"></i> Save Adjustments</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Triggers the Time Adjustment Modal and injects parameters.
 */
function openEditModal(logId, adminId, adminName, currentHours) {
    document.getElementById('modalLogId').value = logId;
    document.getElementById('modalAdminId').value = adminId;
    document.getElementById('modalAdminName').textContent = adminName;
    document.getElementById('modalHoursInput').value = currentHours;
    
    const editModal = new bootstrap.Modal(document.getElementById('editWorkingHoursModal'));
    editModal.show();
}

// Security Script
document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    if (e.key === "F12") { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>