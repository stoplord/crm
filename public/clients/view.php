<?php
/**
 * CRM SYSTEM - CLIENT PROFILE & TIMELINE VIEW
 * Public: public/clients/view.php
 * Standalone & Crash-Proof Version.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

$client_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$client_id) {
    header("Location: /public/clients/index.php");
    exit;
}

$role = $_SESSION['role'] ?? 'admin';
$current_user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$error_msg = '';
$success_msg = '';
$db_error = '';

// Catch PRG Success Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'activity_added') $success_msg = 'Activity logged successfully.';
    if ($_GET['msg'] === 'meeting_scheduled') $success_msg = 'Meeting scheduled successfully.';
    if ($_GET['msg'] === 'status_updated') $success_msg = 'Client status updated successfully.';
}

// 1. Process Form Submissions (Add Activity / Schedule Meeting)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed.");
    }
    
    $form_action = htmlspecialchars(trim($_POST['form_action'] ?? ''), ENT_QUOTES);

    try {
        if ($form_action === 'add_activity') {
            $activity_type      = htmlspecialchars(trim($_POST['activity_type'] ?? 'Follow Up'), ENT_QUOTES);
            $activity_date      = htmlspecialchars(trim($_POST['activity_date'] ?? date('Y-m-d')), ENT_QUOTES);
            $activity_time      = htmlspecialchars(trim($_POST['activity_time'] ?? date('H:i:s')), ENT_QUOTES);
            $notes              = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES);
            $next_followup_date = !empty($_POST['next_followup_date']) ? htmlspecialchars(trim($_POST['next_followup_date']), ENT_QUOTES) : null;
            $new_status         = htmlspecialchars(trim($_POST['new_status'] ?? ''), ENT_QUOTES);

            if (empty($notes)) throw new Exception("Activity notes cannot be empty.");

            $pdo->beginTransaction();

            // Insert Activity
            $stmtAct = $pdo->prepare("
                INSERT INTO client_activities 
                (client_id, admin_id, activity_type, activity_date, activity_time, notes, next_followup_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtAct->execute([$client_id, $current_user_id, $activity_type, $activity_date, $activity_time, $notes, $next_followup_date]);

            // Update Progress Status if selected
            if (!empty($new_status)) {
                $stmtStatus = $pdo->prepare("UPDATE clients SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmtStatus->execute([$new_status, $current_user_id, $client_id]);
            }

            // Audit
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$current_user_id, $role, 'add_client_activity', 'client', $client_id, "Added activity ({$activity_type})", $_SERVER['REMOTE_ADDR']]);

            $pdo->commit();
            header("Location: /public/clients/view.php?id=" . $client_id . "&msg=activity_added");
            exit;

        } elseif ($form_action === 'schedule_meeting') {
            $meeting_date = htmlspecialchars(trim($_POST['meeting_date'] ?? ''), ENT_QUOTES);
            $meeting_time = htmlspecialchars(trim($_POST['meeting_time'] ?? ''), ENT_QUOTES);
            $meeting_type = htmlspecialchars(trim($_POST['meeting_type'] ?? 'Online'), ENT_QUOTES);
            $location     = htmlspecialchars(trim($_POST['location'] ?? ''), ENT_QUOTES);
            $meeting_link = htmlspecialchars(trim($_POST['meeting_link'] ?? ''), ENT_QUOTES);
            $notes        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES);

            if (empty($meeting_date) || empty($meeting_time)) throw new Exception("Meeting date and time are required.");

            $pdo->beginTransaction();

            // Insert Meeting
            $stmtM = $pdo->prepare("
                INSERT INTO meetings 
                (client_id, admin_id, meeting_date, meeting_time, meeting_type, location, meeting_link, notes, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled')
            ");
            $stmtM->execute([$client_id, $current_user_id, $meeting_date, $meeting_time, $meeting_type, $location, $meeting_link, $notes]);

            // Sync to Timeline
            $stmtAct = $pdo->prepare("
                INSERT INTO client_activities 
                (client_id, admin_id, activity_type, activity_date, activity_time, notes) 
                VALUES (?, ?, 'Meeting', ?, ?, ?)
            ");
            $stmtAct->execute([$client_id, $current_user_id, $meeting_date, $meeting_time, "Meeting Scheduled: {$notes}"]);

            // Audit
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$current_user_id, $role, 'schedule_meeting', 'client', $client_id, "Scheduled meeting", $_SERVER['REMOTE_ADDR']]);

            $pdo->commit();
            header("Location: /public/clients/view.php?id=" . $client_id . "&msg=meeting_scheduled");
            exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Database Error: Missing tables or columns. " . $e->getMessage();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = $e->getMessage();
    }
}

// 2. Fetch Client Data, Activities, and Meetings Safely
$client = null;
$activities = [];
$meetings = [];

try {
    // Fetch Client Details
    $stmt = $pdo->prepare("
        SELECT c.*, u1.name AS assigned_admin_name, u2.name AS created_by_name
        FROM clients c
        LEFT JOIN users u1 ON c.assigned_admin_id = u1.id
        LEFT JOIN users u2 ON c.created_by = u2.id
        WHERE c.id = ? AND c.deleted_at IS NULL
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        header("Location: /public/clients/index.php");
        exit;
    }

    // Strict Access Control: Admin can only see their own assigned/created clients
    if (!$isSuperAdmin && $client['assigned_admin_id'] != $current_user_id && $client['created_by'] != $current_user_id) {
        header("Location: /public/dashboard.php?error=unauthorized");
        exit;
    }

    // Fetch Timeline Activities
    $actStmt = $pdo->prepare("
        SELECT ca.*, u.name AS admin_name
        FROM client_activities ca
        LEFT JOIN users u ON ca.admin_id = u.id
        WHERE ca.client_id = ?
        ORDER BY ca.activity_date DESC, ca.activity_time DESC
    ");
    $actStmt->execute([$client_id]);
    $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Meetings
    $meetStmt = $pdo->prepare("
        SELECT m.*, u.name AS admin_name
        FROM meetings m
        LEFT JOIN users u ON m.admin_id = u.id
        WHERE m.client_id = ?
        ORDER BY m.meeting_date DESC, m.meeting_time DESC
    ");
    $meetStmt->execute([$client_id]);
    $meetings = $meetStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Please ensure the `meetings` and `client_activities` tables exist with correct columns.";
}

$pageTitle = $client ? "Client Profile: " . htmlspecialchars($client['client_code'], ENT_QUOTES) : 'Client Not Found';

// Badges (Inline Fallbacks)
$tempBadge = ($client['temperature'] ?? '') === 'HOT' ? '<span class="badge bg-danger rounded-pill px-3 shadow-sm"><i class="bi bi-fire me-1"></i> HOT</span>' : '<span class="badge bg-info text-dark rounded-pill px-3 shadow-sm"><i class="bi bi-snow me-1"></i> COLD</span>';

$dsClass = 'badge-secondary';
if(($client['deal_status'] ?? '') === 'Converted') $dsClass = 'badge-success';
if(($client['deal_status'] ?? '') === 'Rejected') $dsClass = 'badge-danger';
if(($client['deal_status'] ?? '') === 'In Progress') $dsClass = 'badge-primary';
if(($client['deal_status'] ?? '') === 'On Hold') $dsClass = 'badge-warning';

$dealBadge = "<span class=\"app-badge-soft {$dsClass}\">" . htmlspecialchars($client['deal_status'] ?? 'Open', ENT_QUOTES) . "</span>";

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
    .btn-ultra-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 100px; padding: 14px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35); color: white; }
    
    .btn-ultra-success { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 100px; padding: 14px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-success:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.35); color: white; }

    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Section Icons */
    .section-icon-wrapper { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-right: 14px; }
    
    /* Client Header Avatar */
    .client-avatar { width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 800; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 8px 20px rgba(0,0,0,0.05); }

    /* Timeline Styling */
    .ultra-timeline { position: relative; padding-left: 24px; margin-top: 1rem; }
    .ultra-timeline::before { content: ''; position: absolute; top: 0; bottom: 0; left: 6px; width: 2px; background: #e2e8f0; border-radius: 2px; }
    .timeline-item { position: relative; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid #f1f5f9; }
    .timeline-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
    .timeline-dot { position: absolute; left: -24px; top: 4px; width: 14px; height: 14px; border-radius: 50%; background: white; border: 3px solid #3b82f6; box-shadow: 0 0 0 4px #f8fafc; }
    .timeline-dot.meeting { border-color: #10b981; }

    /* Data Blocks */
    .data-block { background: #f8fafc; border-radius: 16px; padding: 16px; border: 1px solid #f1f5f9; margin-bottom: 1rem; }
    .data-block-label { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b; margin-bottom: 6px; }

    /* iOS Segmented Tabs */
    .ultra-tabs-wrapper { display: flex; justify-content: flex-start; margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
    .ultra-tabs-wrapper::-webkit-scrollbar { display: none; }
    .ultra-tabs { background: #e2e8f0; border-radius: 100px; padding: 6px; display: inline-flex; min-width: max-content; }
    .ultra-tabs .nav-item { flex: 1; text-align: center; }
    .ultra-tabs .nav-link { border-radius: 100px; color: #64748b; font-weight: 600; font-size: 0.95rem; padding: 10px 24px; border: none; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap; }
    .ultra-tabs .nav-link.active { background: #ffffff; color: #0f172a; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }

    /* Soft Badges */
    .app-badge-soft { padding: 0.5rem 1rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.5px; text-transform: uppercase; }
    .badge-primary { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
    .badge-success { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .badge-warning { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .badge-danger { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
    .badge-secondary { background: rgba(100, 116, 139, 0.15); color: #475569; }

    /* Meeting Card (Replaces Table for Mobile Perfection) */
    .meeting-card { background: #ffffff; border-radius: 20px; border: 1px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s; }
    .meeting-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); border-color: #cbd5e1; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
            <a href="/public/clients/index.php" class="btn btn-outline-danger rounded-pill mt-3 fw-bold">Back to Client List</a>
        </div>
    <?php else: ?>

        <!-- Header Bar -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div class="d-flex align-items-center">
                <div class="client-avatar me-3">
                    <?= strtoupper(substr($client['name'], 0, 1)) ?>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h3 class="fw-bold text-dark mb-0 tracking-tight"><?= htmlspecialchars($client['name'], ENT_QUOTES) ?></h3>
                        <span class="badge bg-light border text-muted rounded-pill ms-2 px-2 py-1 font-monospace">#<?= htmlspecialchars($client['client_code'], ENT_QUOTES) ?></span>
                        <?= $tempBadge ?>
                    </div>
                    <p class="text-muted small fw-medium mb-0">
                        Assigned to: <strong class="text-primary"><?= htmlspecialchars($client['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?></strong> <br /> 
                        Created on: <?= date('d M, Y', strtotime($client['created_at'])) ?>
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="/public/clients/edit.php?id=<?= $client_id ?>" class="btn btn-ultra-primary px-4">
                    <i class="bi bi-pencil-fill me-2"></i> Edit Client's Details
                </a>
                <a href="/public/clients/index.php" class="btn btn-ultra-secondary px-3">
                    <i class="bi bi-arrow-left"> Back</i>
                </a>
            </div>
        </div>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> <?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> <?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- ========================================== -->
            <!-- LEFT COLUMN: Quick Info & Sales Information -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-4">
                
                <!-- Contact Card -->
                <div class="ultra-card p-4">
                    <div class="d-flex align-items-center mb-4">
                        <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-person-vcard-fill"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Contact Details</h5>
                    </div>
                    
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center bg-light p-3 rounded-4 border">
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm text-primary me-3" style="width: 40px; height: 40px;"><i class="bi bi-telephone-fill"></i></div>
                            <div>
                                <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 0.7rem;">Primary Phone</small>
                                <span class="fw-bold text-dark font-monospace"><a href="tel:<?= htmlspecialchars($client['phone'], ENT_QUOTES) ?>"><?= htmlspecialchars($client['phone'], ENT_QUOTES) ?></a></span>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center bg-light p-3 rounded-4 border">
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm text-success me-3" style="width: 40px; height: 40px;"><i class="bi bi-whatsapp"></i></div>
                            <div>
                                <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 0.7rem;">WhatsApp</small>
                                <span class="fw-bold text-dark font-monospace"><a href="https://wa.me/91<?= htmlspecialchars($client['whatsapp'] ?: 'Not Provided', ENT_QUOTES) ?>"><?= htmlspecialchars($client['whatsapp'] ?: 'Not Provided', ENT_QUOTES) ?></a></span>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center bg-light p-3 rounded-4 border">
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm text-info me-3" style="width: 40px; height: 40px;"><i class="bi bi-envelope-fill"></i></div>
                            <div>
                                <small class="text-muted fw-bold d-block text-uppercase" style="font-size: 0.7rem;">Email Address</small>
                                <span class="fw-bold text-dark text-break"><a href="mailto:<?= htmlspecialchars($client['email'] ?: 'Not Provided', ENT_QUOTES) ?>"><?= htmlspecialchars($client['email'] ?: 'Not Provided', ENT_QUOTES) ?></a></span>
                            </div>
                        </div>
                        
                        <div class="data-block mt-2">
                            <div class="data-block-label">Registered Address</div>
                            <div class="fw-medium text-dark">
                                <?= nl2br(htmlspecialchars($client['address'] ?: 'No address noted', ENT_QUOTES)) ?><br>
                                <span class="text-muted"><?= htmlspecialchars($client['city'] ?? '', ENT_QUOTES) ?><?= !empty($client['state']) ? ', ' . htmlspecialchars($client['state'], ENT_QUOTES) : '' ?> <?= htmlspecialchars($client['pin_code'] ?? '', ENT_QUOTES) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sales Info Card -->
                <div class="ultra-card p-4">
                    <div class="d-flex align-items-center mb-4">
                        <div class="section-icon-wrapper bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Deal Status</h5>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="data-block h-100 mb-0">
                                <div class="data-block-label">Timeline Stage</div>
                                <span class="badge bg-dark bg-opacity-10 text-dark px-2 py-1 rounded fs-6"><?= htmlspecialchars($client['status'], ENT_QUOTES) ?></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="data-block h-100 mb-0">
                                <div class="data-block-label">Deal Status</div>
                                <?= $dealBadge ?>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="data-block d-flex justify-content-between align-items-center mb-0 border-warning border-opacity-50">
                                <div>
                                    <div class="data-block-label text-warning mb-0">Quotation Value</div>
                                    <span class="fw-bold font-monospace text-dark fs-5">
                                        <?= !empty($client['quotation_amount']) ? '₹' . number_format($client['quotation_amount'], 2) : '-' ?>
                                    </span>
                                </div>
                                <div class="bg-warning bg-opacity-25 text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="bi bi-receipt"></i></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($client['final_price'])): ?>
                        <div class="col-12 mt-3">
                            <div class="data-block d-flex justify-content-between align-items-center mb-0 bg-success bg-opacity-10 border-success border-opacity-25">
                                <div>
                                    <div class="data-block-label text-success mb-0">Final Converted Price</div>
                                    <span class="fw-bold font-monospace text-dark fs-4">₹<?= number_format($client['final_price'], 2) ?></span>
                                </div>
                                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;"><i class="bi bi-check-lg fs-4"></i></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($client['rejection_reason'])): ?>
                        <div class="col-12 mt-3">
                            <div class="data-block bg-danger bg-opacity-10 border-danger border-opacity-25 mb-0">
                                <div class="data-block-label text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Rejection Reason</div>
                                <div class="text-danger fw-semibold small"><?= htmlspecialchars($client['rejection_reason'], ENT_QUOTES) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Action Buttons -->
                <div class="d-flex flex-column gap-3 mb-4">
                    <button type="button" class="btn-ultra-primary w-100" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                        <i class="bi bi-plus-circle-fill me-2 fs-5"></i> Client's New Activity
                    </button>
                    <button type="button" class="btn-ultra-success w-100" data-bs-toggle="modal" data-bs-target="#scheduleMeetingModal">
                        <i class="bi bi-calendar-plus-fill me-2 fs-5"></i> Schedule A Meeting
                    </button>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- RIGHT COLUMN: Requirements, Timeline -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-8">
                
                <!-- Requirement Box -->
                <div class="ultra-card p-4 mb-4">
                    <div class="d-flex align-items-center border-bottom pb-3 mb-3">
                        <div class="section-icon-wrapper bg-secondary bg-opacity-10 text-secondary" style="width: 36px; height: 36px; font-size: 1.1rem;">
                            <i class="bi bi-card-text"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Client Requirements & Scope</h6>
                    </div>
                    <div class="bg-light p-4 rounded-4 border text-dark fw-medium" style="white-space: pre-line; line-height: 1.6;">
                        <?= htmlspecialchars($client['requirement'] ?: 'No detailed requirements entered yet.', ENT_QUOTES) ?>
                    </div>
                </div>

                <!-- Segmented Tabs -->
                <div class="ultra-tabs-wrapper px-2">
                    <ul class="nav ultra-tabs shadow-sm" id="clientTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> Activity Timeline
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="meetings-tab" data-bs-toggle="tab" data-bs-target="#meetings" type="button" role="tab">
                                <i class="bi bi-camera-video-fill me-1"></i> Meetings
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="ultra-card p-4 p-md-5">
                    <div class="tab-content" id="clientTabsContent">
                        
                        <!-- TAB 1: ACTIVITY TIMELINE -->
                        <div class="tab-pane fade show active" id="timeline" role="tabpanel">
                            <?php if (empty($activities)): ?>
                                <div class="text-center py-5">
                                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 80px; height: 80px; font-size: 2.5rem; color: #cbd5e1;">
                                        <i class="bi bi-hourglass"></i>
                                    </div>
                                    <h5 class="fw-bold text-dark">No activities logged</h5>
                                    <p class="text-muted">Start tracking interactions by adding a new activity.</p>
                                </div>
                            <?php else: ?>
                                <div class="ultra-timeline">
                                    <?php foreach ($activities as $act): 
                                        $isMeeting = ($act['activity_type'] === 'Meeting');
                                        $dotClass = $isMeeting ? 'meeting' : '';
                                        $badgeBg = $isMeeting ? 'bg-success' : 'bg-primary';
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot <?= $dotClass ?>"></div>
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <span class="badge <?= $badgeBg ?> rounded-pill px-3 py-1 mb-2 fw-semibold shadow-sm"><?= htmlspecialchars($act['activity_type'], ENT_QUOTES) ?></span>
                                                    <h6 class="fw-bold text-dark mb-1"><?= date('l, d M Y', strtotime($act['activity_date'])) ?></h6>
                                                </div>
                                                <div class="text-end">
                                                    <span class="text-muted small fw-bold font-monospace bg-light border px-2 py-1 rounded-3"><i class="bi bi-clock me-1"></i><?= date('h:i A', strtotime($act['activity_time'])) ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-light p-3 rounded-4 border text-dark mb-3 fw-medium" style="white-space: pre-wrap; font-size: 0.95rem;"><?= htmlspecialchars($act['notes'], ENT_QUOTES) ?></div>
                                            
                                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                                                <div class="d-flex align-items-center text-muted small">
                                                    <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px;"><i class="bi bi-person-fill"></i></div>
                                                    Logged by: <strong class="ms-1 text-dark"><?= htmlspecialchars($act['admin_name'] ?? 'System', ENT_QUOTES) ?></strong>
                                                </div>
                                                
                                                <?php if (!empty($act['next_followup_date'])): ?>
                                                    <span class="app-badge-soft badge-warning border border-warning border-opacity-50 text-dark shadow-sm">
                                                        <i class="bi bi-bell-fill me-2 text-warning"></i> Next Follow-up: <?= date('d M, Y', strtotime($act['next_followup_date'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 2: MEETINGS (Mobile-Optimized Cards) -->
                        <div class="tab-pane fade" id="meetings" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h6 class="fw-bold mb-0">Scheduled Meetings</h6>
                                <button type="button" class="btn btn-sm btn-success shadow-sm rounded-pill px-3 fw-bold d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#scheduleMeetingModal">
                                    <i class="bi bi-plus-lg me-1"></i> New Meeting
                                </button>
                            </div>

                            <?php if (empty($meetings)): ?>
                                <div class="text-center py-5">
                                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 80px; height: 80px; font-size: 2.5rem; color: #cbd5e1;">
                                        <i class="bi bi-calendar-x"></i>
                                    </div>
                                    <h5 class="fw-bold text-dark">No meetings scheduled</h5>
                                    <p class="text-muted">Use the Schedule A Meeting button to set one up.</p>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($meetings as $m): 
                                        $mBadge = 'bg-primary';
                                        if($m['status'] === 'Completed') $mBadge = 'bg-success';
                                        if($m['status'] === 'Cancelled') $mBadge = 'bg-danger';
                                        if($m['status'] === 'Rescheduled') $mBadge = 'bg-warning text-dark';
                                    ?>
                                        <div class="col-12">
                                            <div class="meeting-card p-3 p-md-4">
                                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
                                                    <div class="d-flex align-items-start gap-3">
                                                        <div class="bg-primary bg-opacity-10 text-primary rounded-4 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px;">
                                                            <i class="bi bi-calendar-event-fill fs-4"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="fw-bold text-dark mb-1 fs-5"><?= date('l, d M Y', strtotime($m['meeting_date'])) ?></h6>
                                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                                <span class="text-primary fw-bold font-monospace bg-primary bg-opacity-10 px-2 py-1 rounded small"><i class="bi bi-clock me-1"></i><?= date('h:i A', strtotime($m['meeting_time'])) ?></span>
                                                                <span class="badge bg-dark text-white rounded-pill px-2 py-1 small"><?= htmlspecialchars($m['meeting_type'], ENT_QUOTES) ?></span>
                                                            </div>
                                                            <?php if (!empty($m['meeting_link'])): ?>
                                                                <a href="<?= htmlspecialchars($m['meeting_link'], ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3 py-1 mt-1"><i class="bi bi-camera-video-fill me-1"></i> Join Meeting</a>
                                                            <?php elseif (!empty($m['location'])): ?>
                                                                <div class="small fw-medium text-muted mt-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i> <?= htmlspecialchars($m['location'], ENT_QUOTES) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-md-end text-start mt-2 mt-md-0">
                                                        <span class="badge <?= $mBadge ?> rounded-pill px-3 py-2 shadow-sm fs-6 mb-2"><?= htmlspecialchars($m['status'], ENT_QUOTES) ?></span>
                                                    </div>
                                                </div>
                                                
                                                <div class="bg-light p-3 rounded-4 mt-3 text-dark small fw-medium border" style="white-space: pre-wrap; font-size: 0.95rem;"><?= htmlspecialchars($m['notes'] ?: 'No agenda noted.', ENT_QUOTES) ?></div>
                                                
                                                <div class="d-flex justify-content-end mt-3">
                                                    <small class="text-muted fw-semibold"><i class="bi bi-person-fill text-secondary me-1"></i> Scheduled by: <?= htmlspecialchars($m['admin_name'], ENT_QUOTES) ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL: ADD ACTIVITY / FOLLOW-UP -->
<div class="modal fade" id="addActivityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <form method="POST" action="/public/clients/view.php?id=<?= $client_id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="add_activity">
                
                <div class="modal-body p-4 p-md-5">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                                <i class="bi bi-plus-circle-fill"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-0">Log Activity</h4>
                        </div>
                        <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Activity Type</label>
                            <select name="activity_type" class="form-select ultra-input" required>
                                <option value="Call">Call</option>
                                <option value="WhatsApp">WhatsApp</option>
                                <option value="Email">Email</option>
                                <option value="Demo">Demo</option>
                                <option value="Meeting">Meeting</option>
                                <option value="Follow Up" selected>Follow Up</option>
                                <option value="Quotation">Quotation</option>
                                <option value="Negotiation">Negotiation</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label text-primary">Update Pipeline Status</label>
                            <select name="new_status" class="form-select ultra-input border-primary border-opacity-25 bg-primary bg-opacity-10">
                                <option value="">Keep (<?= htmlspecialchars($client['status'] ?? 'New', ENT_QUOTES) ?>)</option>
                                <?php foreach (['New', 'Contacted', 'Demo Scheduled', 'Demo Completed', 'Follow Up', 'Meeting Scheduled', 'Quotation Sent', 'Negotiation', 'Converted', 'Rejected', 'On Hold', 'Lost'] as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Activity Date</label>
                            <input type="date" name="activity_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Activity Time</label>
                            <input type="time" name="activity_time" class="form-control ultra-input" value="<?= date('H:i') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Next Follow-up <span class="text-muted text-lowercase fw-medium">(Optional)</span></label>
                            <input type="datetime-local" name="next_followup_date" class="form-control ultra-input">
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Notes / Summary <span class="text-danger">*</span></label>
                            <textarea name="notes" class="form-control ultra-input" rows="3" placeholder="What was discussed?" required></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-5">
                        <button type="submit" class="btn-ultra-primary w-100"><i class="bi bi-save2 me-2"></i> Save Activity Record</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: SCHEDULE MEETING -->
<div class="modal fade" id="scheduleMeetingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <form method="POST" action="/public/clients/view.php?id=<?= $client_id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="schedule_meeting">
                
                <div class="modal-body p-4 p-md-5">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="d-flex align-items-center">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                                <i class="bi bi-calendar-plus-fill"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-0">Schedule A Meeting</h4>
                        </div>
                        <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="meeting_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="ultra-label">Time <span class="text-danger">*</span></label>
                            <input type="time" name="meeting_time" class="form-control ultra-input" required>
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Format / Type</label>
                            <select name="meeting_type" class="form-select ultra-input" required>
                                <option value="Online">Online Video Call</option>
                                <option value="In-Person">In-Person Meeting</option>
                                <option value="Phone Call">Phone Call</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Link or Location</label>
                            <input type="text" name="meeting_link" class="form-control ultra-input" placeholder="Zoom link, Google Meet, or physical address">
                        </div>
                        <div class="col-12">
                            <label class="ultra-label">Agenda</label>
                            <textarea name="notes" class="form-control ultra-input" rows="2" placeholder="What is the objective of this meeting?"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-5">
                        <button type="submit" class="btn-ultra-success w-100"><i class="bi bi-calendar-check-fill me-2"></i> Confirm & Schedule</button>
                    </div>
                </div>
            </form>
        </div>
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