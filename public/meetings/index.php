<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pageTitle = 'Meetings Management';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$success_msg = '';
$error_msg = '';
$db_error = '';

// Catch PRG Success Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'status_updated') $success_msg = "Meeting status successfully updated.";
}

// 1. Handle POST Requests (Status Updates) safely
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed.");
    }

    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);
    $meeting_id = filter_input(INPUT_POST, 'meeting_id', FILTER_VALIDATE_INT);

    if ($action === 'update_status' && $meeting_id) {
        $new_status = htmlspecialchars(trim($_POST['status'] ?? ''), ENT_QUOTES);
        
        if (!in_array($new_status, ['Scheduled', 'Completed', 'Cancelled', 'Rescheduled'])) {
            $error_msg = 'Invalid meeting status value.';
        } else {
            try {
                $pdo->beginTransaction();

                // RBAC Verification: Regular Admins can only update their own meetings
                if (!$isSuperAdmin) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM meetings WHERE id = ? AND admin_id = ? AND deleted_at IS NULL");
                    $stmtCheck->execute([$meeting_id, $user_id]);
                    if (!$stmtCheck->fetch()) {
                        throw new Exception("Unauthorized: You do not have permission to update this meeting.");
                    }
                }

                // Update Status
                $stmtUpd = $pdo->prepare("UPDATE meetings SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpd->execute([$new_status, $meeting_id]);

                // Audit Log
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([
                    $user_id, $role, 'update_meeting_status', 'meeting', $meeting_id,
                    "Changed meeting ID {$meeting_id} status to {$new_status}", $_SERVER['REMOTE_ADDR']
                ]);

                $pdo->commit();
                
                // PRG Redirect to prevent double submission
                header("Location: /public/meetings/index.php?msg=status_updated");
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Database Error: " . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// 2. Filter Parameters
$filter_status = htmlspecialchars(trim($_GET['status'] ?? ''), ENT_QUOTES);
$filter_admin  = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$filter_date   = htmlspecialchars(trim($_GET['date'] ?? ''), ENT_QUOTES);

$where = ["m.deleted_at IS NULL"];
$params = [];

// Apply Role-Based Scope
if (!$isSuperAdmin) {
    $where[] = "m.admin_id = ?";
    $params[] = $user_id;
} elseif ($isSuperAdmin && $filter_admin) {
    $where[] = "m.admin_id = ?";
    $params[] = $filter_admin;
}

if ($filter_status) {
    $where[] = "m.status = ?";
    $params[] = $filter_status;
}

if ($filter_date) {
    $where[] = "m.meeting_date = ?";
    $params[] = $filter_date;
}

$whereClause = implode(' AND ', $where);

// 3. Fetch Data with Try-Catch Block to prevent 500 Crashes
$meetings = [];
$adminsList = [];

try {
    // Fetch Admins for Super Admin dropdown filter
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Main Query
    $query = "
        SELECT 
            m.*, 
            c.client_code, c.name AS client_name, c.phone AS client_phone,
            u.name AS admin_name
        FROM meetings m
        JOIN clients c ON m.client_id = c.id
        JOIN users u ON m.admin_id = u.id
        WHERE $whereClause
        ORDER BY m.meeting_date ASC, m.meeting_time ASC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br><br>
                 <strong>Please ensure you have a `meetings` table with these columns:</strong><br>
                 <code>id, client_id, admin_id, meeting_date, meeting_time, meeting_type, location, meeting_link, notes, status, deleted_at</code>";
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

    /* Mobile Meeting Items */
    .mobile-meeting-item { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-meeting-item:active { transform: scale(0.98); background: #f8fafc; }

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
                <i class="bi bi-calendar-event fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Scheduled Meetings</h3>
                <p class="text-muted small mb-0 fw-medium">Track and manage client meetings, demos & follow-up schedules.</p>
            </div>
        </div>
        <!--<div>-->
        <!--    <a href="/public/meetings/schedule.php" class="btn btn-ultra-primary shadow-sm">-->
        <!--        <i class="bi bi-calendar-plus me-2"></i> Schedule New-->
        <!--    </a>-->
        <!--</div>-->
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= $success_msg ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= $error_msg ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Filter Card -->
        <div class="ultra-card mb-4 d-print-none">
            <form method="GET" action="/public/meetings/index.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Status</label>
                    <select name="status" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="Scheduled" <?= $filter_status === 'Scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Rescheduled" <?= $filter_status === 'Rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                        <option value="Cancelled" <?= $filter_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Meeting Date</label>
                    <input type="date" name="date" class="form-control ultra-input" value="<?= $filter_date ?>" onchange="this.form.submit()">
                </div>
                
                <?php if ($isSuperAdmin): ?>
                <div class="col-12 col-md-4 col-lg-4">
                    <label class="ultra-label">Assigned Admin</label>
                    <select name="admin_id" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Admins</option>
                        <?php foreach ($adminsList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $filter_admin === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-12 <?= $isSuperAdmin ? 'col-lg-2' : 'col-md-4 col-lg-6' ?> d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="/public/meetings/index.php" class="btn btn-ultra-secondary shadow-sm fw-bold">Reset</a>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Scheduled Meetings: <span class="text-primary"><?= count($meetings) ?> records found</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap meeting for details</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card list with popup detail on click)        -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($meetings)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-calendar-x text-muted fs-1 d-block mb-2"></i>
                    <h6 class="fw-bold text-dark mb-1">No meetings found</h6>
                    <p class="text-muted small mb-0">No meetings matching your current filter criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($meetings as $m): 
                    $mBadge = 'bg-primary';
                    if ($m['status'] === 'Completed') $mBadge = 'bg-success';
                    if ($m['status'] === 'Cancelled') $mBadge = 'bg-danger';
                    if ($m['status'] === 'Rescheduled') $mBadge = 'bg-warning text-dark';
                    
                    $isPastDue = false;
                    if ($m['status'] === 'Scheduled') {
                        $meetingDateTime = strtotime($m['meeting_date'] . ' ' . $m['meeting_time']);
                        if ($meetingDateTime < time()) $isPastDue = true;
                    }

                    $dateFormatted = date('d M, Y', strtotime($m['meeting_date']));
                    $timeFormatted = date('h:i A', strtotime($m['meeting_time']));
                ?>
                    <div class="mobile-meeting-item"
                         onclick="openMeetingDetailsModal(this)"
                         data-id="<?= (int)$m['id'] ?>"
                         data-clientid="<?= (int)$m['client_id'] ?>"
                         data-code="<?= htmlspecialchars($m['client_code'], ENT_QUOTES) ?>"
                         data-name="<?= htmlspecialchars($m['client_name'], ENT_QUOTES) ?>"
                         data-phone="<?= htmlspecialchars($m['client_phone'], ENT_QUOTES) ?>"
                         data-date="<?= $dateFormatted ?>"
                         data-time="<?= $timeFormatted ?>"
                         data-pastdue="<?= $isPastDue ? '1' : '0' ?>"
                         data-type="<?= htmlspecialchars($m['meeting_type'] ?? 'Unknown', ENT_QUOTES) ?>"
                         data-link="<?= htmlspecialchars($m['meeting_link'] ?? '', ENT_QUOTES) ?>"
                         data-loc="<?= htmlspecialchars($m['location'] ?? '', ENT_QUOTES) ?>"
                         data-status="<?= htmlspecialchars($m['status'], ENT_QUOTES) ?>"
                         data-badge="<?= $mBadge ?>"
                         data-admin="<?= htmlspecialchars($m['admin_name'], ENT_QUOTES) ?>"
                         data-notes="<?= htmlspecialchars($m['notes'] ?: 'No notes recorded', ENT_QUOTES) ?>">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($m['client_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($m['client_name'], ENT_QUOTES) ?></div>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="badge <?= $mBadge ?> rounded-pill px-2.5 py-1 small">
                                        <?= htmlspecialchars($m['status'], ENT_QUOTES) ?>
                                    </span>
                                    <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded fw-bold">
                                        #<?= htmlspecialchars($m['client_code'], ENT_QUOTES) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-dark small"><?= $dateFormatted ?></div>
                            <small class="<?= $isPastDue ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <?= $timeFormatted ?> <?= $isPastDue ? '⚠️' : '' ?>
                            </small>
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
                        <th>Date & Time</th>
                        <th>Client Details</th>
                        <th>Meeting Type / Link</th>
                        <th>Status</th>
                        <th>Assigned Admin</th>
                        <th>Notes / Agenda</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($meetings)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="7" class="text-center py-5 text-muted">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-calendar-x text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No meetings found</h5>
                                <p class="text-muted small">No meetings matching your criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($meetings as $m): 
                            $mBadge = 'bg-primary';
                            if ($m['status'] === 'Completed') $mBadge = 'bg-success';
                            if ($m['status'] === 'Cancelled') $mBadge = 'bg-danger';
                            if ($m['status'] === 'Rescheduled') $mBadge = 'bg-warning text-dark';
                            
                            $isPastDue = false;
                            if ($m['status'] === 'Scheduled') {
                                $meetingDateTime = strtotime($m['meeting_date'] . ' ' . $m['meeting_time']);
                                if ($meetingDateTime < time()) $isPastDue = true;
                            }
                        ?>
                            <tr <?= $isPastDue ? 'style="border-left: 4px solid #ef4444;"' : '' ?>>
                                <td>
                                    <span class="fw-bold text-dark d-block"><?= date('d M, Y', strtotime($m['meeting_date'])) ?></span>
                                    <small class="<?= $isPastDue ? 'text-danger fw-bold' : 'text-muted' ?>">
                                        <i class="bi bi-clock me-1"></i> <?= date('h:i A', strtotime($m['meeting_time'])) ?>
                                        <?= $isPastDue ? '(Past Due)' : '' ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($m['client_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-primary">
                                                <a href="/public/clients/view.php?id=<?= (int)$m['client_id'] ?>" class="text-decoration-none text-dark">
                                                    <?= htmlspecialchars($m['client_name'], ENT_QUOTES) ?>
                                                </a>
                                            </div>
                                            <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                                #<?= htmlspecialchars($m['client_code'], ENT_QUOTES) ?>
                                            </span>
                                            <div class="text-muted small mt-1 font-monospace"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($m['client_phone'], ENT_QUOTES) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border mb-1"><?= htmlspecialchars($m['meeting_type'] ?? 'Unknown', ENT_QUOTES) ?></span>
                                    <?php if (!empty($m['meeting_link'])): ?>
                                        <br><a href="<?= htmlspecialchars($m['meeting_link'], ENT_QUOTES) ?>" target="_blank" class="small text-decoration-underline text-primary fw-semibold"><i class="bi bi-link-45deg me-1"></i>Join Link</a>
                                    <?php elseif (!empty($m['location'])): ?>
                                        <br><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($m['location'], ENT_QUOTES) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $mBadge ?> px-3 py-2 rounded-pill shadow-sm"><?= htmlspecialchars($m['status'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($m['admin_name'], ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td style="max-width: 200px;">
                                    <span class="d-inline-block text-truncate text-muted small fw-medium" style="max-width: 200px;" title="<?= htmlspecialchars($m['notes'] ?? 'No notes recorded', ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($m['notes'] ?: 'No notes recorded', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-ultra-secondary py-2 px-3 dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Manage 
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-4 p-2">
                                            <li><h6 class="dropdown-header text-uppercase small fw-bold">Update Status</h6></li>
                                            
                                            <!-- Scheduled Status Update -->
                                            <li>
                                                <form method="POST" action="/public/meetings/index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="status" value="Scheduled">
                                                    <button type="submit" class="dropdown-item text-primary py-2 rounded-3"><i class="bi bi-calendar-check me-2"></i> Scheduled</button>
                                                </form>
                                            </li>
                                            
                                            <!-- Completed Status Update -->
                                            <li>
                                                <form method="POST" action="/public/meetings/index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="status" value="Completed">
                                                    <button type="submit" class="dropdown-item text-success py-2 rounded-3"><i class="bi bi-check-circle me-2"></i> Completed</button>
                                                </form>
                                            </li>

                                            <!-- Rescheduled Status Update -->
                                            <li>
                                                <form method="POST" action="/public/meetings/index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="status" value="Rescheduled">
                                                    <button type="submit" class="dropdown-item text-warning py-2 rounded-3"><i class="bi bi-arrow-repeat me-2"></i> Rescheduled</button>
                                                </form>
                                            </li>

                                            <!-- Cancelled Status Update -->
                                            <li>
                                                <form method="POST" action="/public/meetings/index.php">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="status" value="Cancelled">
                                                    <button type="submit" class="dropdown-item text-danger py-2 rounded-3"><i class="bi bi-x-circle me-2"></i> Cancelled</button>
                                                </form>
                                            </li>
                                            
                                            <li><hr class="dropdown-divider my-2"></li>
                                            <li>
                                                <a class="dropdown-item py-2 rounded-3 text-dark fw-medium" href="/public/clients/view.php?id=<?= (int)$m['client_id'] ?>">
                                                    <i class="bi bi-person me-2 text-primary"></i> View Client's Profile
                                                </a>
                                            </li>
                                        </ul>
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
<!-- MOBILE MEETING DETAILS & STATUS MANAGEMENT POPUP             -->
<!-- ============================================================ -->
<div class="modal fade" id="meetingDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Client Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mClientName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mClientCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Schedule & Status Summary -->
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Date & Time</span>
                            <div id="mDateTime" class="fw-bold text-dark small font-monospace"></div>
                            <div id="mPastDueBadge" class="badge bg-danger rounded-pill px-2 py-0.5 mt-1 small d-none">Past Due</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Current Status</span>
                            <span id="mStatusBadge" class="badge px-3 py-1.5 rounded-pill shadow-sm fs-6"></span>
                        </div>
                    </div>
                </div>

                <!-- Meeting Format & Details -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-camera-video text-primary me-2"></i> Format</span>
                        <span id="mType" class="badge bg-info text-dark"></span>
                    </div>
                    <div id="mLinkRow" class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border d-none">
                        <span class="small fw-semibold text-muted"><i class="bi bi-link-45deg text-primary me-2"></i> Video Link</span>
                        <a id="mLink" href="#" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold">Join Video Call</a>
                    </div>
                    <div id="mLocRow" class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border d-none">
                        <span class="small fw-semibold text-muted"><i class="bi bi-geo-alt text-danger me-2"></i> Location</span>
                        <span id="mLoc" class="small fw-bold text-dark"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-telephone text-primary me-2"></i> Phone</span>
                        <span id="mPhone" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-badge text-primary me-2"></i> Host Admin</span>
                        <span id="mAdmin" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                </div>

                <!-- Agenda / Notes -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Agenda & Notes</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mNotes" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.6;"></div>
                </div>

                <!-- Update Status Buttons -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Update Status</h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <form method="POST" action="/public/meetings/index.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="meeting_id" class="modalMeetingIdField">
                            <input type="hidden" name="status" value="Completed">
                            <button type="submit" class="btn btn-success w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                                <i class="bi bi-check-circle me-1"></i> Completed
                            </button>
                        </form>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="/public/meetings/index.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="meeting_id" class="modalMeetingIdField">
                            <input type="hidden" name="status" value="Rescheduled">
                            <button type="submit" class="btn btn-warning text-dark w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                                <i class="bi bi-arrow-repeat me-1"></i> Rescheduled
                            </button>
                        </form>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="/public/meetings/index.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="meeting_id" class="modalMeetingIdField">
                            <input type="hidden" name="status" value="Scheduled">
                            <button type="submit" class="btn btn-primary w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                                <i class="bi bi-calendar-check me-1"></i> Scheduled
                            </button>
                        </form>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="/public/meetings/index.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="meeting_id" class="modalMeetingIdField">
                            <input type="hidden" name="status" value="Cancelled">
                            <button type="submit" class="btn btn-danger w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                                <i class="bi bi-x-circle me-1"></i> Cancelled
                            </button>
                        </form>
                    </div>
                </div>

                <a id="mViewClientBtn" href="#" class="btn btn-ultra-secondary w-100 py-3 mt-2">
                    <i class="bi bi-person me-2 text-primary"></i> View Client's Profile
                </a>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Populates and triggers the mobile Meeting Details modal.
 */
function openMeetingDetailsModal(el) {
    const d = el.dataset;
    document.getElementById('mAvatar').textContent = d.name ? d.name.charAt(0).toUpperCase() : 'C';
    document.getElementById('mClientName').textContent = d.name;
    document.getElementById('mClientCode').textContent = '#' + d.code;
    document.getElementById('mPhone').textContent = d.phone;
    document.getElementById('mDateTime').textContent = d.date + ' • ' + d.time;
    
    // Past due badge
    const pastDueBadge = document.getElementById('mPastDueBadge');
    if (d.pastdue === '1') {
        pastDueBadge.classList.remove('d-none');
    } else {
        pastDueBadge.classList.add('d-none');
    }

    // Status badge
    const statusBadge = document.getElementById('mStatusBadge');
    statusBadge.textContent = d.status;
    statusBadge.className = 'badge px-3 py-1.5 rounded-pill shadow-sm fs-6 ' + d.badge;

    document.getElementById('mType').textContent = d.type;

    // Meeting Link vs Location
    const linkRow = document.getElementById('mLinkRow');
    const locRow = document.getElementById('mLocRow');
    if (d.link && d.link.trim() !== '') {
        document.getElementById('mLink').href = d.link;
        linkRow.classList.remove('d-none');
    } else {
        linkRow.classList.add('d-none');
    }

    if (d.loc && d.loc.trim() !== '') {
        document.getElementById('mLoc').textContent = d.loc;
        locRow.classList.remove('d-none');
    } else {
        locRow.classList.add('d-none');
    }

    document.getElementById('mAdmin').textContent = d.admin;
    document.getElementById('mNotes').textContent = d.notes;

    // Set hidden fields for status update forms
    const idFields = document.querySelectorAll('.modalMeetingIdField');
    idFields.forEach(function(field) {
        field.value = d.id;
    });

    document.getElementById('mViewClientBtn').href = '/public/clients/view.php?id=' + d.clientid;

    const modal = new bootstrap.Modal(document.getElementById('meetingDetailsModal'));
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>