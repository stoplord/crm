<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Task & Progress Management';
$role = $_SESSION['role'] ?? 'admin';
$user_id = (int)$_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$success_msg = '';
$error_msg = '';
$db_error = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') $success_msg = 'Task assigned successfully.';
    if ($_GET['msg'] === 'updated') $success_msg = 'Task status & progress updated.';
    if ($_GET['msg'] === 'deleted') $success_msg = 'Task securely removed.';
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);

    try {
        if ($action === 'create_task') {
            $title = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES);
            $desc = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES);
            $priority = htmlspecialchars(trim($_POST['priority'] ?? 'Medium'), ENT_QUOTES);
            $deadline_date = htmlspecialchars(trim($_POST['deadline_date'] ?? ''), ENT_QUOTES);
            $deadline_time = htmlspecialchars(trim($_POST['deadline_time'] ?? '18:00'), ENT_QUOTES);
            $assigned_to = $isSuperAdmin ? filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT) : $user_id;

            if (empty($title) || empty($deadline_date) || !$assigned_to) {
                throw new Exception("Title, assigned employee, and deadline date are required.");
            }

            $stmt = $pdo->prepare("INSERT INTO tasks (title, description, assigned_to, assigned_by, priority, status, progress, deadline_date, deadline_time) VALUES (?, ?, ?, ?, ?, 'Pending', 0, ?, ?)");
            $stmt->execute([$title, $desc, $assigned_to, $user_id, $priority, $deadline_date, $deadline_time]);
            $taskId = $pdo->lastInsertId();

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, 'create_task', 'task', ?, ?, ?)");
            $audit->execute([$user_id, $role, $taskId, "Assigned task '{$title}' to User ID {$assigned_to} with deadline {$deadline_date}", $_SERVER['REMOTE_ADDR']]);

            header("Location: /public/tasks/index.php?msg=created");
            exit;

        } elseif ($action === 'update_task_progress') {
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
            $status = htmlspecialchars(trim($_POST['status'] ?? 'Pending'), ENT_QUOTES);
            $progress = filter_input(INPUT_POST, 'progress', FILTER_VALIDATE_INT);

            if ($progress === false || $progress < 0 || $progress > 100 || !$task_id) {
                throw new Exception("Progress must be an integer between 0% and 100%.");
            }

            if ($progress === 100 && $status !== 'Completed') {
                $status = 'Completed';
            } elseif ($progress < 100 && $status === 'Completed') {
                $status = 'In Progress';
            }

            if (!$isSuperAdmin) {
                $permCheck = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND assigned_to = ? AND deleted_at IS NULL");
                $permCheck->execute([$task_id, $user_id]);
                if (!$permCheck->fetch()) {
                    throw new Exception("Unauthorized: You can only update your own assigned tasks.");
                }
            }

            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, progress = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$status, $progress, $task_id]);

            header("Location: /public/tasks/index.php?msg=updated");
            exit;

        } elseif ($action === 'edit_task' && $isSuperAdmin) {
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
            $title = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES);
            $desc = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES);
            $assigned_to = filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT);
            $priority = htmlspecialchars(trim($_POST['priority'] ?? 'Medium'), ENT_QUOTES);
            $status = htmlspecialchars(trim($_POST['status'] ?? 'Pending'), ENT_QUOTES);
            $progress = filter_input(INPUT_POST, 'progress', FILTER_VALIDATE_INT);
            $deadline_date = htmlspecialchars(trim($_POST['deadline_date'] ?? ''), ENT_QUOTES);
            $deadline_time = htmlspecialchars(trim($_POST['deadline_time'] ?? '18:00'), ENT_QUOTES);

            if (!$task_id || empty($title) || empty($deadline_date) || !$assigned_to) {
                throw new Exception("Invalid parameters for task update.");
            }

            $stmt = $pdo->prepare("UPDATE tasks SET title = ?, description = ?, assigned_to = ?, priority = ?, status = ?, progress = ?, deadline_date = ?, deadline_time = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$title, $desc, $assigned_to, $priority, $status, $progress, $deadline_date, $deadline_time, $task_id]);

            header("Location: /public/tasks/index.php?msg=updated");
            exit;

        } elseif ($action === 'delete_task') {
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);

            if (!$isSuperAdmin) {
                $checkDel = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND (assigned_by = ? OR assigned_to = ?) AND deleted_at IS NULL");
                $checkDel->execute([$task_id, $user_id, $user_id]);
                if (!$checkDel->fetch()) {
                    throw new Exception("Unauthorized: You cannot remove this task.");
                }
            }

            $stmt = $pdo->prepare("UPDATE tasks SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$task_id]);

            header("Location: /public/tasks/index.php?msg=deleted");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Filters & Query Assembly
$filter_status   = htmlspecialchars(trim($_GET['status'] ?? ''), ENT_QUOTES);
$filter_priority = htmlspecialchars(trim($_GET['priority'] ?? ''), ENT_QUOTES);
$filter_user     = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
$search          = htmlspecialchars(trim($_GET['q'] ?? ''), ENT_QUOTES);

$where = ["t.deleted_at IS NULL"];
$params = [];

if (!$isSuperAdmin) {
    $where[] = "t.assigned_to = ?";
    $params[] = $user_id;
} elseif ($isSuperAdmin && $filter_user) {
    $where[] = "t.assigned_to = ?";
    $params[] = $filter_user;
}

if ($filter_status) {
    $where[] = "t.status = ?";
    $params[] = $filter_status;
}

if ($filter_priority) {
    $where[] = "t.priority = ?";
    $params[] = $filter_priority;
}

if ($search) {
    $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(' AND ', $where);

$tasks = [];
$usersList = [];
$stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0];

try {
    if ($isSuperAdmin) {
        $usersList = $pdo->query("SELECT id, name, role FROM users WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $query = "
        SELECT 
            t.*,
            u_assigned.name AS assignee_name,
            u_assigned.role AS assignee_role,
            u_creator.name AS creator_name
        FROM tasks t
        LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
        LEFT JOIN users u_creator ON t.assigned_by = u_creator.id
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN t.status = 'Completed' THEN 2 
                WHEN t.status = 'Cancelled' THEN 3 
                ELSE 1 
            END,
            t.deadline_date ASC, 
            t.deadline_time ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nowTimestamp = time();
    foreach ($tasks as $tk) {
        $stats['total']++;
        if ($tk['status'] === 'Pending') $stats['pending']++;
        if ($tk['status'] === 'In Progress' || $tk['status'] === 'Under Review') $stats['in_progress']++;
        if ($tk['status'] === 'Completed') $stats['completed']++;

        $deadlineTs = strtotime($tk['deadline_date'] . ' ' . $tk['deadline_time']);
        if ($deadlineTs < $nowTimestamp && $tk['status'] !== 'Completed' && $tk['status'] !== 'Cancelled') {
            $stats['overdue']++;
        }
    }

} catch (PDOException $e) {
    $db_error = "Query Execution Failed: " . $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; }
    .card-hover:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.08) !important; transition: transform 0.2s, box-shadow 0.2s; }
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; display: inline-block; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 600; color: #0f172a; height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    .ultra-search-wrapper { position: relative; display: flex; align-items: center; }
    .ultra-search-icon { position: absolute; left: 16px; color: #64748b; font-size: 1.1rem; }
    .ultra-search-input { padding-left: 45px !important; }
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; border: none; cursor: pointer; }
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 950px; }
    .app-table thead th { border: none; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-radius: 20px; transition: transform 0.2s, box-shadow 0.2s; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; flex-shrink: 0; }
    .badge { padding: 0.4rem 0.85rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; }
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
    .bg-gradient-danger  { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
    .mobile-task-item { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    .mobile-task-item:active { transform: scale(0.98); background: #f8fafc; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-check2-square fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Manage Tasks & Progress</h3>
                <p class="text-muted small mb-0 fw-medium">
                    <?= $isSuperAdmin ? 'Central task dispatcher for Super Admins, Developers & Admins.' : 'Personal task board, live progress tracking & deadlines.' ?>
                </p>
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-ultra-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                <i class="bi bi-plus-circle me-2"></i> <?= $isSuperAdmin ? 'Assign New Task' : 'Add Personal Task' ?>
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-primary mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Total Assigned</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace"><?= number_format($stats['total']) ?></h2>
                <small class="opacity-75">All Logged Tasks</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-warning text-dark mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Pending / In Progress</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace"><?= number_format($stats['pending'] + $stats['in_progress']) ?></h2>
                <small class="fw-bold opacity-75"><?= $stats['pending'] ?> Pending &bull; <?= $stats['in_progress'] ?> Active</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-success text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Completed</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace"><?= number_format($stats['completed']) ?></h2>
                <small class="opacity-75">Finished Deliverables</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-danger text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Past Deadline</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace"><?= number_format($stats['overdue']) ?></h2>
                <small class="opacity-75"><?= $stats['overdue'] > 0 ? 'Requires Immediate Action' : 'All Deadlines on Track' ?></small>
            </div>
        </div>
    </div>

    <!-- Search & Filter Card -->
    <div class="ultra-card mb-4">
        <form method="GET" action="/public/tasks/index.php" class="row g-3 align-items-end">
            <div class="col-12 col-md-4 col-xl-<?= $isSuperAdmin ? '4' : '6' ?>">
                <label class="ultra-label">Search Tasks</label>
                <div class="ultra-search-wrapper">
                    <i class="bi bi-search ultra-search-icon"></i>
                    <input type="text" name="q" class="form-control ultra-input ultra-search-input" placeholder="Search by title or keywords" value="<?= $search ?>">
                </div>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <label class="ultra-label">Status</label>
                <select name="status" class="form-select ultra-input" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="In Progress" <?= $filter_status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Under Review" <?= $filter_status === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
                    <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Cancelled" <?= $filter_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <label class="ultra-label">Priority</label>
                <select name="priority" class="form-select ultra-input" onchange="this.form.submit()">
                    <option value="">All Priorities</option>
                    <option value="Low" <?= $filter_priority === 'Low' ? 'selected' : '' ?>>Low</option>
                    <option value="Medium" <?= $filter_priority === 'Medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="High" <?= $filter_priority === 'High' ? 'selected' : '' ?>>High</option>
                    <option value="Urgent" <?= $filter_priority === 'Urgent' ? 'selected' : '' ?>>🔥 Urgent</option>
                </select>
            </div>

            <?php if ($isSuperAdmin): ?>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="ultra-label">Filter Assignee</label>
                <select name="user_id" class="form-select ultra-input" onchange="this.form.submit()">
                    <option value="">All Assignees</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filter_user === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['name'], ENT_QUOTES) ?> (<?= ucfirst(str_replace('_', ' ', $u['role'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-12 col-md-6 col-xl-2 d-flex gap-2 justify-content-end align-items-end">
                <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-funnel me-1"></i> Filter</button>
                <?php if ($search || $filter_status || $filter_priority || $filter_user): ?>
                    <a href="/public/tasks/index.php" class="btn btn-ultra-secondary shadow-sm fw-bold">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Total Records Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-2">
        <h6 class="fw-bold text-dark mb-0">Task Records: <span class="text-primary"><?= count($tasks) ?> items found</span></h6>
        <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap task to inspect & update</span>-->
    </div>

    <!-- Mobile Card List -->
    <div class="d-block d-md-none">
        <?php if (empty($tasks)): ?>
            <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                <i class="bi bi-clipboard2-check text-muted fs-1 d-block mb-2"></i>
                <h5 class="fw-bold text-dark mb-1">No tasks assigned</h5>
                <p class="text-muted small mb-0">Use the button above to create a new task.</p>
            </div>
        <?php else: ?>
            <?php foreach ($tasks as $t): 
                $deadlineTs = strtotime($t['deadline_date'] . ' ' . $t['deadline_time']);
                $isOverdue = ($deadlineTs < time() && $t['status'] !== 'Completed' && $t['status'] !== 'Cancelled');
                $dateFormatted = date('d M, Y', strtotime($t['deadline_date']));
                $timeFormatted = date('h:i A', strtotime($t['deadline_time']));
                
                $prioClass = 'bg-secondary';
                if ($t['priority'] === 'Urgent') $prioClass = 'bg-danger text-white';
                if ($t['priority'] === 'High') $prioClass = 'bg-warning text-dark';
                if ($t['priority'] === 'Medium') $prioClass = 'bg-primary text-white';
                if ($t['priority'] === 'Low') $prioClass = 'bg-info text-dark';

                $statusClass = 'bg-secondary';
                if ($t['status'] === 'Completed') $statusClass = 'bg-success text-white';
                if ($t['status'] === 'In Progress') $statusClass = 'bg-primary text-white';
                if ($t['status'] === 'Under Review') $statusClass = 'bg-info text-dark';
                if ($t['status'] === 'Pending') $statusClass = 'bg-warning text-dark';
            ?>
                <div class="mobile-task-item"
                     onclick="openTaskModal(
                         <?= (int)$t['id'] ?>,
                         '<?= htmlspecialchars(addslashes($t['title']), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($t['description'] ?? ''), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($t['assignee_name'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                         '<?= ucfirst(str_replace('_', ' ', $t['assignee_role'] ?? '')) ?>',
                         '<?= htmlspecialchars(addslashes($t['creator_name'] ?? 'System'), ENT_QUOTES) ?>',
                         '<?= $t['priority'] ?>',
                         '<?= $prioClass ?>',
                         '<?= $t['status'] ?>',
                         '<?= $statusClass ?>',
                         <?= (int)$t['progress'] ?>,
                         '<?= $dateFormatted ?>',
                         '<?= $timeFormatted ?>',
                         '<?= $t['deadline_date'] ?>',
                         '<?= $t['deadline_time'] ?>',
                         <?= $isOverdue ? '1' : '0' ?>,
                         <?= (int)$t['assigned_to'] ?>
                     )">
                    <div class="d-flex align-items-center">
                        <div class="app-avatar-fallback me-3">
                            <?= strtoupper(substr($t['title'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($t['title'], ENT_QUOTES) ?></div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge <?= $prioClass ?> px-2 py-0.5" style="font-size: 0.7rem;"><?= $t['priority'] ?></span>
                                <span class="badge <?= $statusClass ?> px-2 py-0.5" style="font-size: 0.7rem;"><?= $t['status'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold <?= $isOverdue ? 'text-danger' : 'text-primary' ?> font-monospace fs-6">
                            <?= (int)$t['progress'] ?>%
                        </div>
                        <small class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>">
                            <?= $dateFormatted ?> <?= $isOverdue ? '⚠️' : '' ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Desktop Data Table -->
    <div class="app-table-wrapper d-none d-md-block">
        <table class="app-table">
            <thead>
                <tr>
                    <th>Task & Details</th>
                    <th>Assigned Employee</th>
                    <th>Priority</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Deadline</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr style="box-shadow: none; background: transparent;">
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard2-check fs-1 d-block mb-2 text-secondary"></i>
                            No task assignments found matching your filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $t): 
                        $deadlineTs = strtotime($t['deadline_date'] . ' ' . $t['deadline_time']);
                        $isOverdue = ($deadlineTs < time() && $t['status'] !== 'Completed' && $t['status'] !== 'Cancelled');
                        $dateFormatted = date('d M, Y', strtotime($t['deadline_date']));
                        $timeFormatted = date('h:i A', strtotime($t['deadline_time']));
                        
                        $prioClass = 'bg-secondary';
                        if ($t['priority'] === 'Urgent') $prioClass = 'bg-danger text-white';
                        if ($t['priority'] === 'High') $prioClass = 'bg-warning text-dark';
                        if ($t['priority'] === 'Medium') $prioClass = 'bg-primary text-white';
                        if ($t['priority'] === 'Low') $prioClass = 'bg-info text-dark';

                        $statusClass = 'bg-secondary';
                        if ($t['status'] === 'Completed') $statusClass = 'bg-success text-white';
                        if ($t['status'] === 'In Progress') $statusClass = 'bg-primary text-white';
                        if ($t['status'] === 'Under Review') $statusClass = 'bg-info text-dark';
                        if ($t['status'] === 'Pending') $statusClass = 'bg-warning text-dark';
                    ?>
                        <tr <?= $isOverdue ? 'style="border-left: 4px solid #ef4444;"' : '' ?>>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="app-avatar-fallback me-3">
                                        <?= strtoupper(substr($t['title'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($t['title'], ENT_QUOTES) ?></div>
                                        <?php if (!empty($t['description'])): ?>
                                            <small class="text-muted d-inline-block text-truncate" style="max-width: 260px;" title="<?= htmlspecialchars($t['description'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($t['description'], ENT_QUOTES) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill">
                                    <i class="bi bi-person-circle me-1 text-primary"></i> <?= htmlspecialchars($t['assignee_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                </span>
                                <small class="text-muted d-block mt-1 font-monospace">(<?= ucfirst(str_replace('_', ' ', $t['assignee_role'] ?? '')) ?>)</small>
                            </td>
                            <td>
                                <span class="badge <?= $prioClass ?> px-3 py-1.5 shadow-sm"><?= $t['priority'] ?></span>
                            </td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="font-monospace fw-bold small"><?= (int)$t['progress'] ?>%</span>
                                    <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                                        <div class="progress-bar <?= $t['progress'] == 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= (int)$t['progress'] ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?> px-3 py-1.5 shadow-sm"><?= $t['status'] ?></span>
                            </td>
                            <td>
                                <span class="fw-bold <?= $isOverdue ? 'text-danger' : 'text-dark' ?> d-block font-monospace">
                                    <?= $dateFormatted ?>
                                </span>
                                <small class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>">
                                    <?= $timeFormatted ?> <?= $isOverdue ? '(Overdue)' : '' ?>
                                </small>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-ultra-secondary py-1.5 px-3 fw-bold"
                                            onclick="openQuickUpdateModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['title']), ENT_QUOTES) ?>', '<?= $t['status'] ?>', <?= (int)$t['progress'] ?>)">
                                        <i class="bi bi-sliders me-1"></i> Update
                                    </button>

                                    <?php if ($isSuperAdmin): ?>
                                        <button type="button" class="app-btn-icon bg-info bg-opacity-10 text-info" title="Full Edit"
                                                onclick="openSuperAdminEditModal(
                                                    <?= (int)$t['id'] ?>,
                                                    '<?= htmlspecialchars(addslashes($t['title']), ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars(addslashes($t['description'] ?? ''), ENT_QUOTES) ?>',
                                                    <?= (int)$t['assigned_to'] ?>,
                                                    '<?= $t['priority'] ?>',
                                                    '<?= $t['status'] ?>',
                                                    <?= (int)$t['progress'] ?>,
                                                    '<?= $t['deadline_date'] ?>',
                                                    '<?= $t['deadline_time'] ?>'
                                                )">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>

                                        <form method="POST" action="/public/tasks/index.php" onsubmit="return confirm('Permanently remove this task?');" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_task">
                                            <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                            <button type="submit" class="app-btn-icon bg-danger bg-opacity-10 text-danger" title="Delete Task">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- Include Modular Modals -->
<?php require_once __DIR__ . '/modals.php'; ?>

<!-- Include Tasks Client Script -->
<script src="/public/assets/js/tasks.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>