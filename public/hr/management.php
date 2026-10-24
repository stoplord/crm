<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'HR Management Central';
$success_msg = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'msg_sent') $success_msg = "Message broadcasted successfully.";
    if ($_GET['msg'] === 'leave_updated') $success_msg = "Leave status updated.";
    if ($_GET['msg'] === 'payslip_issued') $success_msg = "Payslip generated and issued successfully.";
}

// 1. Process Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'send_message') {
            $msg = htmlspecialchars(trim($_POST['message']), ENT_QUOTES);
            $target = filter_input(INPUT_POST, 'target_admin', FILTER_VALIDATE_INT) ?: 0;
            
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, 'super_admin', 'system_message', 'admin_msg', ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $target, $msg, $_SERVER['REMOTE_ADDR']]);
            header("Location: /public/hr/management.php?msg=msg_sent"); exit;

        } elseif ($action === 'update_leave') {
            $log_id = filter_input(INPUT_POST, 'log_id', FILTER_VALIDATE_INT);
            $new_status = htmlspecialchars($_POST['status'], ENT_QUOTES); // Approved or Rejected
            
            // Fetch old description to append
            $oldStmt = $pdo->prepare("SELECT description FROM audit_logs WHERE id = ?");
            $oldStmt->execute([$log_id]);
            $oldDesc = $oldStmt->fetchColumn();
            
            $parts = explode('|', $oldDesc);
            $parts[0] = $new_status; // Update status part
            $newDesc = implode('|', $parts);

            $stmt = $pdo->prepare("UPDATE audit_logs SET description = ? WHERE id = ?");
            $stmt->execute([$newDesc, $log_id]);
            header("Location: /public/hr/management.php?msg=leave_updated"); exit;

        } elseif ($action === 'issue_payslip') {
            $admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
            $month = htmlspecialchars(trim($_POST['month']), ENT_QUOTES);
            $base = filter_input(INPUT_POST, 'base_pay', FILTER_VALIDATE_FLOAT) ?: 0;
            $bonus = filter_input(INPUT_POST, 'bonus', FILTER_VALIDATE_FLOAT) ?: 0;
            $ded = filter_input(INPUT_POST, 'deductions', FILTER_VALIDATE_FLOAT) ?: 0;
            $note = htmlspecialchars(trim($_POST['notes']), ENT_QUOTES);
            
            $net = $base + $bonus - $ded;
            $desc = "{$base}|{$bonus}|{$ded}|{$net}|{$note}";
            $target_type = "payslip_{$month}";

            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, 'super_admin', 'payslip_issued', ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $target_type, $admin_id, $desc, $_SERVER['REMOTE_ADDR']]);
            header("Location: /public/hr/management.php?msg=payslip_issued"); exit;
        }
    } catch (Exception $e) {}
}

// 2. Fetch Data
$admins = [];
$pendingLeaves = [];
$allLeaves = [];

try {
    $admins = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Pending Leaves for immediate action
    $leaveStmt = $pdo->query("
        SELECT a.*, u.name AS admin_name 
        FROM audit_logs a 
        JOIN users u ON a.target_id = u.id 
        WHERE a.action = 'leave_request' AND a.description LIKE 'Pending%' 
        ORDER BY a.created_at DESC
    ");
    $pendingLeaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch All Admin Leaves (History & Full Details across all statuses)
    $allLeavesStmt = $pdo->query("
        SELECT a.*, u.name AS admin_name, u.email AS admin_email
        FROM audit_logs a 
        JOIN users u ON a.target_id = u.id 
        WHERE a.action = 'leave_request' 
        ORDER BY a.created_at DESC 
        LIMIT 150
    ");
    $allLeaves = $allLeavesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {}

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
    
    .btn-ultra-success { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-success:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.35); color: white; }

    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Section Icons */
    .section-icon-wrapper { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-right: 14px; }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 800px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Soft Badges */
    .app-badge-soft { padding: 0.5rem 1rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.5px; text-transform: uppercase; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Leave Card */
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
                <i class="bi bi-briefcase-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">HR Management Central</h3>
                <p class="text-muted small mb-0 fw-medium">Broadcast notices, release payroll & manage employee leave details.</p>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- MOBILE ACTION BUTTONS (VISIBLE ON MOBILE ONLY)               -->
    <!-- ============================================================ -->
    <div class="d-flex d-xl-none gap-2 mb-4">
        <button type="button" class="btn btn-ultra-primary flex-grow-1 py-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#mobileBroadcastModal">
            <i class="bi bi-send-fill me-2"></i> Broadcast Notice
        </button>
        <button type="button" class="btn btn-ultra-success flex-grow-1 py-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#mobilePayslipModal">
            <i class="bi bi-cash-stack me-2"></i> Issue Salary
        </button>
    </div>

    <!-- ============================================================ -->
    <!-- DESKTOP INLINE CARDS (KEPT UNCHANGED FOR DESKTOP ONLY)       -->
    <!-- ============================================================ -->
    <div class="row g-4 mb-4 d-none d-xl-flex">
        
        <!-- COMMUNICATION & MESSAGING -->
        <div class="col-12 col-xl-6">
            <div class="ultra-card h-100 p-4 p-md-5">
                <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                    <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-send-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Broadcast Announcement</h5>
                </div>
                
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_message">
                    
                    <div class="mb-4">
                        <label class="ultra-label">Target Recipient</label>
                        <select name="target_admin" class="form-select ultra-input">
                            <option value="0">Broadcast to All Administrators</option>
                            <?php foreach ($admins as $adm): ?>
                                <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="ultra-label">Message Content</label>
                        <textarea name="message" class="form-control ultra-input" rows="3" required></textarea>
                    </div>
                    <div class="text-end border-top pt-4">
                        <button type="submit" class="btn-ultra-primary w-100 d-flex align-items-center justify-content-center py-3">
                            <i class="bi bi-send-fill me-2"></i> Broadcast Notice
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ISSUE PAYSLIP -->
        <div class="col-12 col-xl-6">
            <div class="ultra-card h-100 p-4 p-md-5">
                <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                    <div class="section-icon-wrapper bg-success bg-opacity-10 text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Issue Monthly Salary Slip</h5>
                </div>
                
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="issue_payslip">
                    
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Employee</label>
                            <select name="admin_id" class="form-select ultra-input" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($admins as $adm): ?>
                                    <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Salary Month</label>
                            <input type="month" name="month" class="form-control ultra-input font-monospace" value="<?= date('Y-m', strtotime('-1 month')) ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="ultra-label">Basic Pay (₹)</label>
                            <input type="number" step="0.01" name="base_pay" class="form-control ultra-input font-monospace text-primary" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="ultra-label text-success">Bonus / Allow. (₹)</label>
                            <input type="number" step="0.01" name="bonus" class="form-control ultra-input font-monospace text-success" value="0.00">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="ultra-label text-danger">Deductions (₹)</label>
                            <input type="number" step="0.01" name="deductions" class="form-control ultra-input font-monospace text-danger" value="0.00">
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Remarks / Notes</label>
                            <input type="text" name="notes" class="form-control ultra-input" placeholder="e.g. Performance bonus included">
                        </div>
                        <div class="col-12 text-end mt-4 pt-3 border-top">
                            <button type="submit" class="btn-ultra-success w-100 py-3">
                                <i class="bi bi-file-earmark-pdf-fill me-2"></i> Generate & Release Payslip
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- ========================================== -->
    <!-- SECTION 1: PENDING LEAVE APPROVALS         -->
    <!-- ========================================== -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-2">
        <h6 class="fw-bold text-dark mb-0">Pending Leave Requests: <span class="text-warning"><?= count($pendingLeaves) ?> total records</span></h6>
        <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap request to decide</span>-->
    </div>

    <!-- Mobile View: Pending Leaves -->
    <div class="d-block d-md-none mb-4">
        <?php if (empty($pendingLeaves)): ?>
            <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                <i class="bi bi-calendar-check text-muted fs-1 d-block mb-2"></i>
                <h5 class="fw-bold text-dark mb-1">No pending leave requests</h5>
                <p class="text-muted small mb-0">All employee leave applications have been processed.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendingLeaves as $lv): 
                $parts = explode('|', $lv['description']);
                $lDate = str_replace('leave_', '', $lv['target_type']);
                $leaveType = htmlspecialchars($parts[1] ?? 'General', ENT_QUOTES);
                $leaveReason = htmlspecialchars($parts[2] ?? 'No reason provided', ENT_QUOTES);
                $dateFormatted = date('d M Y', strtotime($lDate));
            ?>
                <div class="mobile-item-card"
                     onclick="openLeaveModal(
                         <?= (int)$lv['id'] ?>,
                         '<?= htmlspecialchars(addslashes($lv['admin_name']), ENT_QUOTES) ?>',
                         '<?= $dateFormatted ?>',
                         '<?= htmlspecialchars(addslashes($leaveType), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($leaveReason), ENT_QUOTES) ?>'
                     )">
                    <div class="d-flex align-items-center">
                        <div class="app-avatar-fallback me-3">
                            <?= strtoupper(substr($lv['admin_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($lv['admin_name'], ENT_QUOTES) ?></div>
                            <span class="badge bg-secondary bg-opacity-10 text-dark border px-2 py-0.5 mt-1 small">
                                <?= $leaveType ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="fw-bold text-dark font-monospace d-block small"><?= $dateFormatted ?></span>
                        <span class="badge bg-warning text-dark mt-1">Pending</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Desktop View: Pending Leaves -->
    <div class="app-table-wrapper d-none d-md-block mb-5">
        <table class="app-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Leave Date</th>
                    <th>Leave Type</th>
                    <th>Reason</th>
                    <th class="text-end pe-4">Decision</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingLeaves)): ?>
                    <tr style="box-shadow: none; background: transparent;">
                        <td colspan="5" class="text-center py-5">
                            <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                <i class="bi bi-calendar-check text-muted" style="font-size: 2.5rem;"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1">No pending leave requests</h5>
                            <p class="text-muted small">All employee leave applications have been processed.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingLeaves as $lv): 
                        $parts = explode('|', $lv['description']);
                        $lDate = str_replace('leave_', '', $lv['target_type']);
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="app-avatar-fallback me-3">
                                        <?= strtoupper(substr($lv['admin_name'], 0, 1)) ?>
                                    </div>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($lv['admin_name'], ENT_QUOTES) ?></div>
                                </div>
                            </td>
                            <td>
                                <span class="fw-bold text-dark font-monospace"><?= date('d M Y', strtotime($lDate)) ?></span>
                            </td>
                            <td>
                                <span class="app-badge-soft bg-secondary bg-opacity-10 text-dark border">
                                    <?= htmlspecialchars($parts[1] ?? 'General', ENT_QUOTES) ?>
                                </span>
                            </td>
                            <td style="max-width: 300px;">
                                <div class="text-muted small fw-medium text-truncate" title="<?= htmlspecialchars($parts[2] ?? '', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($parts[2] ?? 'No reason provided', ENT_QUOTES) ?>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <form method="POST" class="d-inline-flex gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_leave">
                                    <input type="hidden" name="log_id" value="<?= $lv['id'] ?>">
                                    <button type="submit" name="status" value="Approved" class="btn btn-sm btn-success rounded-pill px-3 py-2 fw-bold shadow-sm">
                                        <i class="bi bi-check-lg me-1"></i> Approve
                                    </button>
                                    <button type="submit" name="status" value="Rejected" class="btn btn-sm btn-danger rounded-pill px-3 py-2 fw-bold shadow-sm">
                                        <i class="bi bi-x-lg me-1"></i> Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ========================================== -->
    <!-- SECTION 2: ALL ADMIN LEAVE DETAILS & LOGS  -->
    <!-- ========================================== -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-2">
        <h6 class="fw-bold text-dark mb-0">Employee Leave History: <span class="text-primary"><?= count($allLeaves) ?> total records</span></h6>
        <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap record for details</span>-->
    </div>

    <!-- Mobile View: All Leaves -->
    <div class="d-block d-md-none">
        <?php if (empty($allLeaves)): ?>
            <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                <i class="bi bi-calendar2-x text-muted fs-1 d-block mb-2"></i>
                <h5 class="fw-bold text-dark mb-1">No leave records found</h5>
                <p class="text-muted small mb-0">No historical leave logs recorded in the system.</p>
            </div>
        <?php else: ?>
            <?php foreach ($allLeaves as $al): 
                $parts = explode('|', $al['description']);
                $status = $parts[0] ?? 'Pending';
                $leaveType = htmlspecialchars($parts[1] ?? 'General', ENT_QUOTES);
                $leaveReason = htmlspecialchars($parts[2] ?? 'No reason provided', ENT_QUOTES);
                $lDate = str_replace('leave_', '', $al['target_type']);
                $dateFormatted = date('d M Y', strtotime($lDate));
                $appliedOnFormatted = date('d M Y, h:i A', strtotime($al['created_at']));

                $badgeClass = 'bg-warning text-dark';
                if ($status === 'Approved') $badgeClass = 'bg-success text-white';
                if ($status === 'Rejected') $badgeClass = 'bg-danger text-white';
            ?>
                <div class="mobile-item-card"
                     onclick="openAllLeaveDetailModal(
                         <?= (int)$al['id'] ?>,
                         '<?= htmlspecialchars(addslashes($al['admin_name']), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($al['admin_email'] ?? 'No email'), ENT_QUOTES) ?>',
                         '<?= $dateFormatted ?>',
                         '<?= htmlspecialchars(addslashes($leaveType), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($leaveReason), ENT_QUOTES) ?>',
                         '<?= htmlspecialchars(addslashes($status), ENT_QUOTES) ?>',
                         '<?= $badgeClass ?>',
                         '<?= $appliedOnFormatted ?>'
                     )">
                    <div class="d-flex align-items-center">
                        <div class="app-avatar-fallback me-3">
                            <?= strtoupper(substr($al['admin_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($al['admin_name'], ENT_QUOTES) ?></div>
                            <span class="badge bg-secondary bg-opacity-10 text-dark border px-2 py-0.5 mt-1 small">
                                <?= $leaveType ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="fw-bold text-dark font-monospace d-block small"><?= $dateFormatted ?></span>
                        <span class="badge <?= $badgeClass ?> mt-1"><?= $status ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Desktop View: All Leaves Table -->
    <div class="app-table-wrapper d-none d-md-block">
        <table class="app-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Leave Date</th>
                    <th>Leave Type</th>
                    <th>Status</th>
                    <th>Reason / Justification</th>
                    <th class="text-end pe-4">Applied On</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allLeaves)): ?>
                    <tr style="box-shadow: none; background: transparent;">
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-calendar2-x fs-1 d-block mb-2 text-secondary"></i>
                            No leave applications on file.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allLeaves as $al): 
                        $parts = explode('|', $al['description']);
                        $status = $parts[0] ?? 'Pending';
                        $leaveType = htmlspecialchars($parts[1] ?? 'General', ENT_QUOTES);
                        $leaveReason = htmlspecialchars($parts[2] ?? 'No reason provided', ENT_QUOTES);
                        $lDate = str_replace('leave_', '', $al['target_type']);

                        $badgeClass = 'bg-warning text-dark';
                        if ($status === 'Approved') $badgeClass = 'bg-success text-white';
                        if ($status === 'Rejected') $badgeClass = 'bg-danger text-white';
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="app-avatar-fallback me-3">
                                        <?= strtoupper(substr($al['admin_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($al['admin_name'], ENT_QUOTES) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($al['admin_email'] ?? '', ENT_QUOTES) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="fw-bold text-dark font-monospace"><?= date('d M Y', strtotime($lDate)) ?></span>
                            </td>
                            <td>
                                <span class="app-badge-soft bg-secondary bg-opacity-10 text-dark border">
                                    <?= $leaveType ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?> px-3 py-2 shadow-sm">
                                    <?= $status ?>
                                </span>
                            </td>
                            <td style="max-width: 320px;">
                                <div class="text-muted small fw-medium text-truncate" title="<?= $leaveReason ?>">
                                    <?= $leaveReason ?>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <span class="fw-semibold text-dark small font-monospace">
                                    <?= date('d M Y, h:i A', strtotime($al['created_at'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE BROADCAST ANNOUNCEMENT POPUP                   -->
<!-- ============================================================ -->
<div class="modal fade" id="mobileBroadcastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/public/hr/management.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_message">

            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary me-3">
                            <i class="bi bi-send-fill"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-0">Broadcast Notice</h5>
                            <small class="text-muted fw-medium">Send alert to all or specific employee</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="mb-3">
                    <label class="ultra-label">Target Recipient</label>
                    <select name="target_admin" class="form-select ultra-input">
                        <option value="0">Broadcast to All Administrators</option>
                        <?php foreach ($admins as $adm): ?>
                            <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="ultra-label">Message Content</label>
                    <textarea name="message" class="form-control ultra-input" rows="4" required></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 py-3">
                        <i class="bi bi-send-fill me-2"></i> Broadcast
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE ISSUE PAYSLIP POPUP                            -->
<!-- ============================================================ -->
<div class="modal fade" id="mobilePayslipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/public/hr/management.php" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="issue_payslip">

            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="section-icon-wrapper bg-success bg-opacity-10 text-success me-3">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-0">Issue Salary Slip</h5>
                            <small class="text-muted fw-medium">Generate staff payroll slip</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="ultra-label">Employee</label>
                        <select name="admin_id" class="form-select ultra-input" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($admins as $adm): ?>
                                <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Salary Month</label>
                        <input type="month" name="month" class="form-control ultra-input font-monospace" value="<?= date('Y-m', strtotime('-1 month')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Basic Pay (₹)</label>
                        <input type="number" step="0.01" name="base_pay" class="form-control ultra-input font-monospace text-primary" required>
                    </div>
                    <div class="col-6">
                        <label class="ultra-label text-success">Bonus (₹)</label>
                        <input type="number" step="0.01" name="bonus" class="form-control ultra-input font-monospace text-success">
                    </div>
                    <div class="col-6">
                        <label class="ultra-label text-danger">Deductions (₹)</label>
                        <input type="number" step="0.01" name="deductions" class="form-control ultra-input font-monospace text-danger">
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Remarks / Notes</label>
                        <input type="text" name="notes" class="form-control ultra-input">
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4 pt-2 border-top">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1 py-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-success flex-grow-1 py-3">
                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> Release
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE PENDING LEAVE DETAIL & DECISION POPUP          -->
<!-- ============================================================ -->
<div class="modal fade" id="leaveDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mLeaveAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mLeaveAdminName" class="fw-bold text-dark mb-0"></h5>
                            <span class="badge bg-warning text-dark mt-1">Pending Approval</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Info Grid -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-event text-primary me-2"></i> Requested Date</span>
                        <span id="mLeaveDate" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-tag text-primary me-2"></i> Leave Type</span>
                        <span id="mLeaveType" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                </div>

                <!-- Reason / Objective -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Application Reason</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mLeaveReason" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.5;"></div>
                </div>

                <!-- Decision Actions Form -->
                <div class="row g-2">
                    <div class="col-6">
                        <form method="POST" action="/public/hr/management.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_leave">
                            <input type="hidden" name="log_id" id="mLeaveLogIdApprove" value="">
                            <button type="submit" name="status" value="Approved" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow-sm">
                                <i class="bi bi-check-lg me-1"></i> Approve
                            </button>
                        </form>
                    </div>
                    <div class="col-6">
                        <form method="POST" action="/public/hr/management.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_leave">
                            <input type="hidden" name="log_id" id="mLeaveLogIdReject" value="">
                            <button type="submit" name="status" value="Rejected" class="btn btn-danger w-100 rounded-pill py-3 fw-bold shadow-sm">
                                <i class="bi bi-x-lg me-1"></i> Reject
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE VIEW FULL LEAVE DETAILS POPUP                  -->
<!-- ============================================================ -->
<div class="modal fade" id="allLeaveHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mHistAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mHistAdminName" class="fw-bold text-dark mb-0"></h5>
                            <small id="mHistAdminEmail" class="text-muted fw-medium"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Status Header Pill -->
                <div class="metric-stat-card mb-3 text-center p-3">
                    <span class="metric-stat-label">Current Application Status</span>
                    <span id="mHistStatusBadge" class="badge px-3 py-2 rounded-pill fs-6 mt-1"></span>
                </div>

                <!-- Info Grid -->
                <div class="d-flex flex-column gap-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-event text-primary me-2"></i> Leave Date</span>
                        <span id="mHistLeaveDate" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-tag text-primary me-2"></i> Leave Type</span>
                        <span id="mHistLeaveType" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history text-primary me-2"></i> Applied On</span>
                        <span id="mHistAppliedOn" class="fw-semibold text-dark small font-monospace"></span>
                    </div>
                </div>

                <!-- Reason / Justification -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Reason Logged</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="mHistLeaveReason" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.5;"></div>
                </div>

                <button type="button" class="btn btn-ultra-secondary w-100 py-3" data-bs-dismiss="modal">Close</button>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Opens and populates the mobile Pending Leave Detail & Decision Modal.
 */
function openLeaveModal(logId, adminName, leaveDate, leaveType, leaveReason) {
    document.getElementById('mLeaveAvatar').textContent = adminName.charAt(0).toUpperCase();
    document.getElementById('mLeaveAdminName').textContent = adminName;
    document.getElementById('mLeaveDate').textContent = leaveDate;
    document.getElementById('mLeaveType').textContent = leaveType;
    document.getElementById('mLeaveReason').textContent = leaveReason;
    
    document.getElementById('mLeaveLogIdApprove').value = logId;
    document.getElementById('mLeaveLogIdReject').value = logId;

    const modal = new bootstrap.Modal(document.getElementById('leaveDetailModal'));
    modal.show();
}

/**
 * Opens and populates the mobile All Leave Records Detail Modal.
 */
function openAllLeaveDetailModal(id, adminName, adminEmail, leaveDate, leaveType, leaveReason, status, badgeClass, appliedOn) {
    document.getElementById('mHistAvatar').textContent = adminName.charAt(0).toUpperCase();
    document.getElementById('mHistAdminName').textContent = adminName;
    document.getElementById('mHistAdminEmail').textContent = adminEmail;
    document.getElementById('mHistLeaveDate').textContent = leaveDate;
    document.getElementById('mHistLeaveType').textContent = leaveType;
    document.getElementById('mHistLeaveReason').textContent = leaveReason && leaveReason.trim() !== '' ? leaveReason : 'No explanation provided.';
    document.getElementById('mHistAppliedOn').textContent = appliedOn;

    const statusBadge = document.getElementById('mHistStatusBadge');
    statusBadge.textContent = status;
    statusBadge.className = 'badge px-3 py-2 rounded-pill fs-6 mt-1 ' + badgeClass;

    const modal = new bootstrap.Modal(document.getElementById('allLeaveHistoryModal'));
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