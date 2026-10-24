<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'admin';
$pageTitle = 'My HR Portal';

$success_msg = '';
$error_msg = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'leave_applied') {
    $success_msg = "Leave application submitted successfully.";
}

// 1. Process Leave Application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    if (isset($_POST['action']) && $_POST['action'] === 'apply_leave') {
        $leave_date = htmlspecialchars(trim($_POST['leave_date']), ENT_QUOTES);
        $leave_type = htmlspecialchars(trim($_POST['leave_type']), ENT_QUOTES);
        $reason     = htmlspecialchars(trim($_POST['reason']), ENT_QUOTES);
        
        $desc = "Pending|{$leave_type}|{$reason}";
        $target_type = "leave_{$leave_date}";

        try {
            // Store leave request in audit_logs
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, 'leave_request', ?, ?, ?, ?)");
            $stmt->execute([$user_id, $role, $target_type, $user_id, $desc, $_SERVER['REMOTE_ADDR']]);
            
            header("Location: /public/hr/my_portal.php?msg=leave_applied");
            exit;
        } catch (Exception $e) {
            $error_msg = "Error submitting leave: " . $e->getMessage();
        }
    }
}

// 2. Fetch HR Data (Messages, Payslips, Leaves) from Audit Logs
$messages = [];
$payslips = [];
$leaves = [];

try {
    // Messages from Super Admin (target_id = 0 for all, or specific user)
    $msgStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'system_message' AND (target_id = 0 OR target_id = ?) ORDER BY created_at DESC LIMIT 5");
    $msgStmt->execute([$user_id]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    // Payslips
    $payStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'payslip_issued' AND target_id = ? ORDER BY created_at DESC");
    $payStmt->execute([$user_id]);
    $payslips = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // Leave History
    $leaveStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'leave_request' AND target_id = ? ORDER BY created_at DESC");
    $leaveStmt->execute([$user_id]);
    $leaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {}

// 3. Generate Calendar Data
$month = date('m');
$year = date('Y');
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfMonth = date('N', strtotime("$year-$month-01"));

// Fetch Work Logs for Calendar
$workLogs = [];
try {
    $wlStmt = $pdo->prepare("SELECT work_date, status FROM daily_work_logs WHERE admin_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ?");
    $wlStmt->execute([$user_id, $month, $year]);
    while ($row = $wlStmt->fetch(PDO::FETCH_ASSOC)) {
        $workLogs[$row['work_date']] = $row['status'];
    }
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

    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Section Icons */
    .section-icon-wrapper { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-right: 14px; }

    /* Floating Card Table (Android List View Style) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 100%; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 16px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 16px; border-bottom-left-radius: 16px; }
    .app-table tbody td:last-child { border-top-right-radius: 16px; border-bottom-right-radius: 16px; }

    /* Soft Badges */
    .app-badge-soft { padding: 0.4rem 0.9rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.5px; text-transform: uppercase; }

    /* Calendar Grid Styling */
    .calendar-day-cell { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; border-radius: 14px; transition: all 0.2s; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-person-workspace fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">HR & Operations Portal</h3>
                <p class="text-muted small mb-0 fw-medium">Track attendance, submit leaves, and view payslips.</p>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- System Messages -->
    <?php if (!empty($messages)): ?>
        <div class="ultra-card border-start border-primary border-4 p-4 mb-4 bg-primary bg-opacity-10">
            <div class="d-flex align-items-center mb-2">
                <i class="bi bi-megaphone-fill text-primary fs-5 me-2"></i>
                <h6 class="fw-bold text-dark mb-0">Announcements from Management</h6>
            </div>
            <hr class="my-2 border-primary border-opacity-25">
            <ul class="mb-0 list-unstyled">
                <?php foreach ($messages as $msg): ?>
                    <li class="mb-2 fw-medium text-dark">
                        <span class="badge bg-primary rounded-pill px-2 py-1 me-2"><?= date('d M', strtotime($msg['created_at'])) ?></span> 
                        <?= htmlspecialchars($msg['description'], ENT_QUOTES) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ========================================== -->
        <!-- ATTENDANCE CALENDAR COLUMN                 -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-4">
            <div class="ultra-card h-100 p-4">
                <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                    <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-calendar-grid-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Attendance (<?= date('F Y') ?>)</h5>
                </div>
                
                <div class="d-grid gap-2 text-center mb-4" style="grid-template-columns: repeat(7, 1fr);">
                    <div class="fw-bold text-muted small py-1">Mon</div><div class="fw-bold text-muted small py-1">Tue</div><div class="fw-bold text-muted small py-1">Wed</div>
                    <div class="fw-bold text-muted small py-1">Thu</div><div class="fw-bold text-muted small py-1">Fri</div><div class="fw-bold text-danger small py-1">Sat</div><div class="fw-bold text-danger small py-1">Sun</div>
                    
                    <?php 
                    for ($i = 1; $i < $firstDayOfMonth; $i++) { echo '<div></div>'; }
                    
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $day);
                        $status = $workLogs[$dateStr] ?? null;
                        $dayOfWeek = date('N', strtotime($dateStr));
                        
                        $bgClass = 'bg-light text-dark border';
                        if ($dayOfWeek >= 6) $bgClass = 'bg-secondary bg-opacity-15 text-muted opacity-50';
                        elseif ($status === 'Present' || $status === 'Excellent' || $status === 'Good') $bgClass = 'bg-success text-white shadow-sm';
                        elseif ($status === 'Half Day' || $status === 'Below Average') $bgClass = 'bg-warning text-dark shadow-sm';
                        elseif ($status === 'Low') $bgClass = 'bg-danger text-white shadow-sm';
                        elseif ($dateStr > date('Y-m-d')) $bgClass = 'bg-white text-muted border border-dashed';

                        echo "<div class='calendar-day-cell {$bgClass}' title='{$status}'>{$day}</div>";
                    }
                    ?>
                </div>

                <div class="d-flex justify-content-between align-items-center small fw-semibold text-muted bg-light p-3 rounded-4 border mt-auto">
                    <span><i class="bi bi-circle-fill text-success me-1"></i> Present</span>
                    <span><i class="bi bi-circle-fill text-warning me-1"></i> Half/Late</span>
                    <span><i class="bi bi-circle-fill text-danger me-1"></i> Absent</span>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- LEAVE MANAGEMENT COLUMN                    -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-4">
            <div class="ultra-card h-100 p-4">
                <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                    <div class="section-icon-wrapper bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-calendar2-minus-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Leave Management</h5>
                </div>
                
                <!-- Application Form -->
                <form method="POST" class="mb-4 pb-4 border-bottom">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_leave">
                    
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Date</label>
                            <input type="date" name="leave_date" class="form-control ultra-input" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Type</label>
                            <select name="leave_type" class="form-select ultra-input" required>
                                <option value="Sick Leave">Sick Leave</option>
                                <option value="Casual Leave">Casual Leave</option>
                                <option value="Half Day">Half Day</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Reason</label>
                            <input type="text" name="reason" class="form-control ultra-input" placeholder="Brief reason..." required>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn-ultra-primary w-100 py-2">Submit Request</button>
                        </div>
                    </div>
                </form>

                <!-- History -->
                <h6 class="fw-bold text-dark mb-3">Recent Requests</h6>
                <div class="d-flex flex-column gap-2">
                    <?php if (empty($leaves)): ?>
                        <div class="text-muted small fst-italic py-2">No leaves applied yet.</div>
                    <?php else: ?>
                        <?php foreach (array_slice($leaves, 0, 4) as $l): 
                            $parts = explode('|', $l['description']);
                            $status = $parts[0] ?? 'Pending';
                            $lDate = str_replace('leave_', '', $l['target_type']);
                            
                            $badgeClass = 'badge-warning';
                            if ($status === 'Approved') $badgeClass = 'badge-success';
                            if ($status === 'Rejected') $badgeClass = 'badge-danger';
                        ?>
                            <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                <div>
                                    <strong class="text-dark d-block"><?= date('d M Y', strtotime($lDate)) ?></strong>
                                    <span class="text-muted small fw-medium"><?= htmlspecialchars($parts[1] ?? '', ENT_QUOTES) ?></span>
                                </div>
                                <span class="app-badge-soft <?= $badgeClass ?>"><?= $status ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- SALARY SLIPS COLUMN                        -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-4">
            <div class="ultra-card h-100 p-4">
                <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                    <div class="section-icon-wrapper bg-success bg-opacity-10 text-success">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Salary Slips</h5>
                </div>
                
                <div class="d-flex flex-column gap-3">
                    <?php if (empty($payslips)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                            <p class="fst-italic mb-0 small">No payslips issued yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($payslips as $ps): 
                            $monthStr = str_replace('payslip_', '', $ps['target_type']);
                            $data = explode('|', $ps['description']);
                            $netPay = $data[3] ?? '0';
                        ?>
                            <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                <div>
                                    <div class="fw-bold text-dark mb-1">Salary: <?= date('F Y', strtotime($monthStr . '-01')) ?></div>
                                    <span class="text-success fw-bold font-monospace fs-6">₹<?= number_format((float)$netPay, 2) ?></span>
                                </div>
                                <button class="btn btn-sm btn-light border shadow-sm rounded-pill fw-bold text-primary px-3 py-2" onclick="viewPayslip('<?= $monthStr ?>', '<?= $data[0] ?>', '<?= $data[1] ?>', '<?= $data[2] ?>', '<?= $data[3] ?>', '<?= addslashes($data[4] ?? '') ?>')">
                                    <i class="bi bi-eye-fill me-1"></i> View Slip
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Payslip Modal -->
<div class="modal fade" id="payslipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4 p-md-5 bg-light" id="printablePayslip">
                <div class="text-center mb-4 border-bottom pb-4">
                    <h4 class="fw-bold text-dark mb-1">StopLord Technologies</h4>
                    <p class="text-muted small mb-0 fw-medium">Payslip for the month of <strong id="psMonth" class="text-dark"></strong></p>
                </div>
                <div class="d-flex justify-content-between mb-3 text-muted fw-semibold"><span>Basic Pay</span><span class="text-dark font-monospace" id="psBase"></span></div>
                <div class="d-flex justify-content-between mb-3 text-muted fw-semibold"><span>Bonus / Allowances</span><span class="text-success font-monospace" id="psBonus"></span></div>
                <div class="d-flex justify-content-between mb-4 pb-3 border-bottom text-muted fw-semibold"><span>Deductions</span><span class="text-danger font-monospace" id="psDed"></span></div>
                <div class="d-flex justify-content-between mb-4 bg-white p-3 rounded-4 border"><span class="fw-bold fs-5 text-dark align-self-center">NET PAY</span><span class="fw-bold text-primary fs-4 font-monospace" id="psNet"></span></div>
                <div class="alert alert-secondary small mb-0 rounded-3 border-0 fw-medium" id="psNote"></div>
            </div>
            <div class="modal-footer bg-white border-0 p-4 pt-0">
                <button type="button" class="btn btn-light rounded-pill flex-grow-1 fw-bold py-3 border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-ultra-primary flex-grow-1 py-3 m-0 shadow-sm" onclick="window.print()"><i class="bi bi-printer-fill me-2"></i> Print Slip</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewPayslip(month, base, bonus, ded, net, note) {
    const d = new Date(month + '-01');
    document.getElementById('psMonth').textContent = d.toLocaleString('default', { month: 'long', year: 'numeric' });
    document.getElementById('psBase').textContent = '₹' + parseFloat(base).toLocaleString();
    document.getElementById('psBonus').textContent = '+ ₹' + parseFloat(bonus).toLocaleString();
    document.getElementById('psDed').textContent = '- ₹' + parseFloat(ded).toLocaleString();
    document.getElementById('psNet').textContent = '₹' + parseFloat(net).toLocaleString();
    document.getElementById('psNote').textContent = note ? 'HR Note: ' + note : 'System generated payslip.';
    new bootstrap.Modal(document.getElementById('payslipModal')).show();
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

<style> @media print { body * { visibility: hidden; } #printablePayslip, #printablePayslip * { visibility: visible; } #printablePayslip { position: absolute; left: 0; top: 0; width: 100%; } } </style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>