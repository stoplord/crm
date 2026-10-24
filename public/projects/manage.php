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

$client_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$client_id) { header("Location: /public/projects/index.php"); exit; }

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'progress_added') $success_msg = 'Project progress updated successfully.';
    if ($_GET['msg'] === 'payment_added') $success_msg = 'Payment recorded securely.';
    if ($_GET['msg'] === 'deleted') $success_msg = 'Record deleted successfully.';
    if ($_GET['msg'] === 'status_updated') $success_msg = 'Overall project status updated.';
}

// 1. Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) die("Security token error.");

    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);

    try {
        $pdo->beginTransaction();

        if ($action === 'add_progress') {
            $step_title = htmlspecialchars(trim($_POST['step_title']), ENT_QUOTES);
            $desc       = htmlspecialchars(trim($_POST['description']), ENT_QUOTES);
            $date       = htmlspecialchars(trim($_POST['p_date']), ENT_QUOTES);
            $time       = htmlspecialchars(trim($_POST['p_time']), ENT_QUOTES);
            
            // Format constraint logic
            $formatted_note = "[Step: {$step_title}] {$desc}";
            
            $stmt = $pdo->prepare("INSERT INTO client_activities (client_id, admin_id, activity_type, activity_date, activity_time, notes) VALUES (?, ?, 'Project Update', ?, ?, ?)");
            $stmt->execute([$client_id, $user_id, $date, $time, $formatted_note]);
            
            $pdo->commit();
            header("Location: /public/projects/manage.php?id=$client_id&msg=progress_added"); exit;

        } elseif ($action === 'add_payment') {
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $method = htmlspecialchars(trim($_POST['method']), ENT_QUOTES);
            $ref    = htmlspecialchars(trim($_POST['reference']), ENT_QUOTES);
            $date   = htmlspecialchars(trim($_POST['p_date']), ENT_QUOTES);
            $time   = htmlspecialchars(trim($_POST['p_time']), ENT_QUOTES);
            $note   = htmlspecialchars(trim($_POST['notes']), ENT_QUOTES);

            // Save embedded logic for calculating sum later without new DB columns
            $formatted_note = "[Amount: {$amount}] [Method: {$method}] [Ref: {$ref}] {$note}";

            $stmt = $pdo->prepare("INSERT INTO client_activities (client_id, admin_id, activity_type, activity_date, activity_time, notes) VALUES (?, ?, 'Payment Received', ?, ?, ?)");
            $stmt->execute([$client_id, $user_id, $date, $time, $formatted_note]);
            
            $pdo->commit();
            header("Location: /public/projects/manage.php?id=$client_id&msg=payment_added"); exit;

        } elseif ($action === 'update_status') {
            $new_status = htmlspecialchars(trim($_POST['new_status']), ENT_QUOTES);
            $stmt = $pdo->prepare("UPDATE clients SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $client_id]);
            
            $pdo->commit();
            header("Location: /public/projects/manage.php?id=$client_id&msg=status_updated"); exit;

        } elseif ($action === 'delete_record') {
            $record_id = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
            $stmt = $pdo->prepare("DELETE FROM client_activities WHERE id = ? AND client_id = ?");
            $stmt->execute([$record_id, $client_id]);
            
            $pdo->commit();
            header("Location: /public/projects/manage.php?id=$client_id&msg=deleted"); exit;
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = $e->getMessage();
    }
}

// 2. Fetch Data
$client = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$client->execute([$client_id]);
$client = $client->fetch(PDO::FETCH_ASSOC);

$actStmt = $pdo->prepare("SELECT * FROM client_activities WHERE client_id = ? AND activity_type IN ('Project Update', 'Payment Received') ORDER BY activity_date DESC, activity_time DESC");
$actStmt->execute([$client_id]);
$records = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Process the Embedded Payment Logic in PHP
$total_paid = 0;
$updates = [];
$payments = [];

foreach ($records as $rec) {
    if ($rec['activity_type'] === 'Payment Received') {
        // Extract embedded amount using Regex
        if (preg_match('/\[Amount: ([\d\.]+)\]/', $rec['notes'], $matches)) {
            $total_paid += (float)$matches[1];
        }
        $payments[] = $rec;
    } else {
        $updates[] = $rec;
    }
}

$project_value = (float)$client['final_price'];
$balance_due = $project_value - $total_paid;
if ($balance_due < 0) $balance_due = 0;

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
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 10px 24px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-success { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 100px; padding: 10px 24px; font-weight: 600; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-success:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.35); color: white; }

    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; cursor: pointer; }
    .app-btn-icon:hover { transform: scale(1.05); }

    /* Floating Card Table (Android List View Style) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 600px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Gradients for KPI Cards */
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
    .bg-gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }

    /* Timeline Styling */
    .ultra-timeline { position: relative; padding-left: 24px; margin-top: 1rem; }
    .ultra-timeline::before { content: ''; position: absolute; top: 0; bottom: 0; left: 6px; width: 2px; background: #e2e8f0; border-radius: 2px; }
    .timeline-item { position: relative; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid #f1f5f9; }
    .timeline-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
    .timeline-dot { position: absolute; left: -24px; top: 4px; width: 14px; height: 14px; border-radius: 50%; background: white; border: 3px solid #3b82f6; box-shadow: 0 0 0 4px #f8fafc; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-laptop fs-4"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <h3 class="fw-bold text-dark mb-0 tracking-tight"><?= htmlspecialchars($client['name'], ENT_QUOTES) ?></h3>
                    <span class="badge bg-light border text-muted rounded-pill ms-2 px-2 py-1 font-monospace">#<?= htmlspecialchars($client['client_code'], ENT_QUOTES) ?></span>
                </div>
                <p class="text-muted small mb-0 fw-medium">Project workspace and financial ledger.</p>
            </div>
        </div>
        <!--<div>-->
        <!--    <a href="/public/projects/index.php" class="btn btn-ultra-secondary shadow-sm">-->
        <!--        <i class="bi bi-arrow-left me-2"></i> Directory-->
        <!--    </a>-->
        <!--</div>-->
    </div>

    <!-- Alert Messages -->
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

    <!-- Project Financial & Status Overview -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-4 col-lg-3">
            <div class="ultra-card bg-gradient-primary h-100 d-flex flex-column justify-content-center p-4">
                <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Total Project Value</span>
                <h2 class="fw-bold mt-2 mb-0 font-monospace text-white">₹<?= number_format($project_value, 2) ?></h2>
            </div>
        </div>
        <div class="col-12 col-md-4 col-lg-3">
            <div class="ultra-card bg-gradient-success h-100 d-flex flex-column justify-content-center p-4">
                <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Total Paid</span>
                <h2 class="fw-bold mt-2 mb-0 font-monospace text-white">₹<?= number_format($total_paid, 2) ?></h2>
            </div>
        </div>
        <div class="col-12 col-md-4 col-lg-3">
            <div class="ultra-card bg-gradient-danger h-100 d-flex flex-column justify-content-center p-4">
                <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Balance Due</span>
                <h2 class="fw-bold mt-2 mb-0 font-monospace text-white">₹<?= number_format($balance_due, 2) ?></h2>
            </div>
        </div>
        <div class="col-12 col-lg-3">
            <div class="ultra-card h-100 p-4">
                <form method="POST" action="/public/projects/manage.php?id=<?= $client_id ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <label class="ultra-label text-dark">Overall Phase Status</label>
                    <div class="d-flex flex-column gap-2 mt-2">
                        <input type="text" name="new_status" class="form-control ultra-input font-monospace text-primary fw-bold px-3 py-2" value="<?= htmlspecialchars($client['status'], ENT_QUOTES) ?>">
                        <button class="btn btn-ultra-primary w-100 py-2" type="submit">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- ========================================== -->
        <!-- PROGRESS TIMELINE SEGMENT                  -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-6">
            <div class="ultra-card h-100 p-4 p-md-5">
                <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="bi bi-list-task"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Project Status</h5>
                    </div>
                    <button class="btn btn-ultra-primary px-3 py-2" style="font-size: 0.9rem;" data-bs-toggle="modal" data-bs-target="#addProgressModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Step
                    </button>
                </div>
                
                <div class="card-body px-0 py-2">
                    <?php if(empty($updates)): ?>
                        <div class="text-center py-5">
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 80px; height: 80px; font-size: 2.5rem; color: #cbd5e1;">
                                <i class="bi bi-diagram-2"></i>
                            </div>
                            <h6 class="fw-bold text-dark">No progress logged yet.</h6>
                            <p class="text-muted small mb-0">Start adding milestones to track delivery.</p>
                        </div>
                    <?php else: ?>
                        <div class="ultra-timeline">
                            <?php foreach ($updates as $up): 
                                preg_match('/\[Step: (.*?)\] (.*)/s', $up['notes'], $m);
                                $title = $m[1] ?? 'Update';
                                $desc = $m[2] ?? $up['notes'];
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="fw-bold text-dark mb-0 fs-5"><?= htmlspecialchars($title, ENT_QUOTES) ?></h6>
                                        <span class="badge bg-light border text-muted px-2 py-1 font-monospace shadow-sm">
                                            <i class="bi bi-clock me-1"></i> <?= date('d M Y', strtotime($up['activity_date'])) ?>
                                        </span>
                                    </div>
                                    <div class="bg-light p-3 rounded-4 border text-dark mb-3 fw-medium" style="white-space: pre-wrap; font-size: 0.95rem;"><?= nl2br(htmlspecialchars($desc, ENT_QUOTES)) ?></div>
                                    
                                    <div class="d-flex justify-content-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this step completely?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="record_id" value="<?= $up['id'] ?>">
                                            <button type="submit" class="btn btn-light btn-sm text-danger border shadow-sm rounded-pill px-3 fw-bold"><i class="bi bi-trash3-fill me-1"></i> Delete Step</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- PAYMENT SEGMENT                            -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-6">
            <div class="ultra-card h-100 p-4 p-md-5">
                <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Payment Ledger</h5>
                    </div>
                    <button class="btn btn-ultra-success px-3 py-2" style="font-size: 0.9rem;" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="bi bi-plus-lg me-1"></i> Add Payment
                    </button>
                </div>
                
                <div class="card-body px-0 py-2">
                    <?php if(empty($payments)): ?>
                        <div class="text-center py-5">
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 80px; height: 80px; font-size: 2.5rem; color: #cbd5e1;">
                                <i class="bi bi-wallet2"></i>
                            </div>
                            <h6 class="fw-bold text-dark">No payments recorded.</h6>
                            <p class="text-muted small mb-0">Use the button above to log received transactions.</p>
                        </div>
                    <?php else: ?>
                        <div class="app-table-wrapper">
                            <table class="app-table" style="min-width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount / Method</th>
                                        <th>Details / Ref</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $pay): 
                                        preg_match('/\[Amount: (.*?)\] \[Method: (.*?)\] \[Ref: (.*?)\] (.*)/s', $pay['notes'], $m);
                                        $p_amt = $m[1] ?? '0.00';
                                        $p_meth = $m[2] ?? 'N/A';
                                        $p_ref = $m[3] ?? '-';
                                        $p_note = $m[4] ?? '';
                                    ?>
                                        <tr>
                                            <td style="min-width: 110px;">
                                                <span class="fw-bold text-dark d-block"><?= date('d M Y', strtotime($pay['activity_date'])) ?></span>
                                                <span class="text-muted small font-monospace fw-bold"><?= date('h:i A', strtotime($pay['activity_time'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-success font-monospace fs-5 bg-success bg-opacity-10 px-2 py-1 rounded d-inline-block mb-1">₹<?= number_format((float)$p_amt, 2) ?></span><br>
                                                <span class="badge bg-secondary bg-opacity-10 text-dark border px-2 py-1"><?= htmlspecialchars($p_meth, ENT_QUOTES) ?></span>
                                            </td>
                                            <td style="max-width: 200px;">
                                                <div class="text-dark small fw-medium mb-1 text-truncate" title="<?= htmlspecialchars($p_note, ENT_QUOTES) ?>"><?= htmlspecialchars($p_note ?: 'No notes', ENT_QUOTES) ?></div>
                                                <span class="font-monospace text-muted small bg-light px-2 py-1 rounded border">Ref: <?= htmlspecialchars($p_ref, ENT_QUOTES) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" onsubmit="return confirm('Delete this payment record? This will alter the balance permanently.');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_record">
                                                    <input type="hidden" name="record_id" value="<?= $pay['id'] ?>">
                                                    <button type="submit" class="app-btn-icon bg-danger bg-opacity-10 text-danger shadow-sm"><i class="bi bi-trash3-fill"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add Progress Step -->
<div class="modal fade" id="addProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_progress">
            
            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                            <i class="bi bi-list-task"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-0">Project Step</h4>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="row g-4">
                    <div class="col-12">
                        <label class="ultra-label">Step Title / Milestone <span class="text-danger">*</span></label>
                        <input type="text" name="step_title" class="form-control ultra-input" required>
                    </div>
                    <div class="col-6">
                        <label class="ultra-label">Date</label>
                        <input type="date" name="p_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="ultra-label">Time</label>
                        <input type="time" name="p_time" class="form-control ultra-input" value="<?= date('H:i') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Detailed Notes <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control ultra-input" rows="3" placeholder="Description of the work completed." required></textarea>
                    </div>
                </div>
                
                <div class="mt-5">
                    <button type="submit" class="btn-ultra-primary w-100"><i class="bi bi-save2 me-2"></i> Save Step</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Payment -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            
            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-0">Payment Records</h4>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Amount Received (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control ultra-input font-monospace fs-5 text-success" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Payment Method <span class="text-danger">*</span></label>
                        <select name="method" class="form-select ultra-input" required>
                            <option value="UPI">UPI</option>
                            <option value="Bank Transfer">Bank Transfer (NEFT/RTGS)</option>
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="ultra-label">Date</label>
                        <input type="date" name="p_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="ultra-label">Time</label>
                        <input type="time" name="p_time" class="form-control ultra-input" value="<?= date('H:i') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Transaction / Reference ID</label>
                        <input type="text" name="reference" class="form-control ultra-input font-monospace">
                    </div>
                    <div class="col-12">
                        <label class="ultra-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control ultra-input" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="mt-5">
                    <button type="submit" class="btn-ultra-success w-100"><i class="bi bi-check-circle-fill me-2"></i> Save Payment Record</button>
                </div>
            </div>
        </form>
    </div>
</div>

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