<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pageTitle = 'Schedule Meeting';
$role = $_SESSION['role'] ?? 'admin';
$current_user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$error_msg = '';
$success_msg = '';
$db_error = '';

// Check if a client is pre-selected from the URL (e.g., clicking "Schedule" from a client profile)
$preselected_client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

// Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'scheduled') {
    $success_msg = 'Meeting successfully scheduled and added to the timeline.';
}

// 1. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inline CSRF Validation to prevent function crashes
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed. Please refresh and try again.");
    }

    $client_id     = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $meeting_date  = htmlspecialchars(trim($_POST['meeting_date'] ?? ''), ENT_QUOTES);
    $meeting_time  = htmlspecialchars(trim($_POST['meeting_time'] ?? ''), ENT_QUOTES);
    $meeting_type  = htmlspecialchars(trim($_POST['meeting_type'] ?? 'Online'), ENT_QUOTES);
    $location      = htmlspecialchars(trim($_POST['location'] ?? ''), ENT_QUOTES);
    $meeting_link  = htmlspecialchars(trim($_POST['meeting_link'] ?? ''), ENT_QUOTES);
    $notes         = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES);
    
    // Assign to an Admin (Super Admin can choose, normal Admin defaults to self)
    $assigned_admin_id = $current_user_id;
    if ($isSuperAdmin && !empty($_POST['admin_id'])) {
        $assigned_admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
    }

    if (!$client_id || empty($meeting_date) || empty($meeting_time)) {
        $error_msg = 'Client, Meeting Date, and Meeting Time are required fields.';
    } else {
        try {
            $pdo->beginTransaction();

            // Insert into Meetings Table
            $stmtM = $pdo->prepare("
                INSERT INTO meetings 
                (client_id, admin_id, meeting_date, meeting_time, meeting_type, location, meeting_link, notes, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled')
            ");
            $stmtM->execute([$client_id, $assigned_admin_id, $meeting_date, $meeting_time, $meeting_type, $location, $meeting_link, $notes]);

            // Automatically log this into the Client's Activity Timeline
            $activityNotes = "Meeting Scheduled ({$meeting_type}) for " . date('d M, Y', strtotime($meeting_date)) . " at " . date('h:i A', strtotime($meeting_time));
            if ($notes) $activityNotes .= " - Agenda: " . $notes;

            $stmtAct = $pdo->prepare("
                INSERT INTO client_activities 
                (client_id, admin_id, activity_type, activity_date, activity_time, notes) 
                VALUES (?, ?, 'Meeting', CURDATE(), CURTIME(), ?)
            ");
            $stmtAct->execute([$client_id, $assigned_admin_id, $activityNotes]);

            // Audit Log
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $current_user_id, $role, 'schedule_meeting', 'client', $client_id, 
                "Scheduled meeting with client ID {$client_id}", $_SERVER['REMOTE_ADDR']
            ]);

            $pdo->commit();
            
            // PRG Redirect to prevent double submission
            header("Location: /public/meetings/schedule.php?msg=scheduled");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db_error = strpos($e->getMessage(), 'Unknown column') !== false 
                ? "Database Schema Error: Missing column. " . $e->getMessage() 
                : "Database Error: " . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

// 2. Fetch Dropdown Data Safely
$clientsList = [];
$adminsList = [];

try {
    // Fetch Clients (Admins only see their own, Super Admins see all)
    $clientQuery = "SELECT id, client_code, name FROM clients WHERE deleted_at IS NULL ";
    $clientParams = [];
    
    if (!$isSuperAdmin) {
        $clientQuery .= " AND (assigned_admin_id = ? OR created_by = ?) ";
        $clientParams[] = $current_user_id;
        $clientParams[] = $current_user_id;
    }
    $clientQuery .= " ORDER BY name ASC";
    
    $stmtC = $pdo->prepare($clientQuery);
    $stmtC->execute($clientParams);
    $clientsList = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Admins (For Super Admin reassignment dropdown)
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $db_error = "Database Fetch Error: " . $e->getMessage() . "<br>Please ensure the `clients` table exists.";
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
    .btn-ultra-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 100px; padding: 16px 32px; font-weight: 600; font-size: 1.05rem; letter-spacing: 0.5px; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35); color: white; }
    .btn-ultra-primary:active { transform: translateY(0); }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 12px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Section Icons */
    .section-icon-wrapper { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin-right: 14px; }
    
    .required-star { color: #ef4444; font-size: 1.1em; line-height: 0; position: relative; top: 3px; margin-left: 2px; }

    /* Custom Check List */
    .ultra-check-list { list-style: none; padding: 0; margin: 0; }
    .ultra-check-list li { display: flex; align-items: flex-start; margin-bottom: 16px; font-weight: 500; color: #475569; line-height: 1.5; font-size: 0.9rem; }
    .ultra-check-list li i { color: #3b82f6; font-size: 1.2rem; margin-right: 12px; margin-top: -2px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-calendar-plus-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Schedule A Meeting</h3>
                <p class="text-muted small mb-0 fw-medium">Book a new demo, negotiation or follow-up meeting with a client.</p>
            </div>
        </div>
        <div>
            <a href="/public/meetings/index.php" class="btn btn-ultra-secondary">
                <i class="bi bi-arrow-left me-2"></i> Back to Meetings
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1">
                <?= htmlspecialchars($success_msg, ENT_QUOTES) ?>
                <a href="/public/meetings/index.php" class="btn btn-sm btn-success rounded-pill fw-bold ms-2 px-3">View Calendar</a>
            </div>
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

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <div class="row g-4">
            <!-- ========================================== -->
            <!-- LEFT COLUMN: MEETING FORM                  -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-8">
                <div class="ultra-card h-100 p-4 p-md-5">
                    <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                        <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Meeting Details</h5>
                    </div>
                    
                    <form method="POST" action="/public/meetings/schedule.php" class="needs-validation">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div class="row g-4">
                            <!-- Target Client Selection -->
                            <div class="col-12">
                                <label class="ultra-label">Select Client <span class="required-star">*</span></label>
                                <select name="client_id" class="form-select ultra-input select2-enable" required>
                                    <option value="">-- Choose a Client --</option>
                                    <?php foreach ($clientsList as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $preselected_client_id === (int)$c['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['client_code'], ENT_QUOTES) ?> - <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($clientsList)): ?>
                                    <div class="text-danger small mt-2 fw-medium ms-2">
                                        <i class="bi bi-exclamation-circle me-1"></i> No clients available. Please register a client first.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Date & Time -->
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Meeting Date <span class="required-star">*</span></label>
                                <input type="date" name="meeting_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Meeting Time <span class="required-star">*</span></label>
                                <input type="time" name="meeting_time" class="form-control ultra-input font-monospace" required>
                            </div>

                            <!-- Meeting Type & Location -->
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Meeting Format</label>
                                <select name="meeting_type" class="form-select ultra-input" required>
                                    <option value="Online">Online Video Call</option>
                                    <option value="In-Person">In-Person Meeting</option>
                                    <option value="Phone Call">Phone Call</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="ultra-label">Meeting Link Or Office Meeting</label>
                                <input type="text" name="meeting_link" class="form-control ultra-input">
                            </div>

                            <!-- Super Admin Reassignment -->
                            <?php if ($isSuperAdmin): ?>
                            <div class="col-12 pt-2">
                                <label class="ultra-label text-primary">Assign Host Administrator</label>
                                <select name="admin_id" class="form-select ultra-input bg-primary bg-opacity-10 text-primary border-0">
                                    <option value="<?= (int)$current_user_id ?>">Assign to Super Admin</option>
                                    <?php foreach ($adminsList as $adm): ?>
                                        <option value="<?= $adm['id'] ?>">
                                            <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <!-- Notes -->
                            <div class="col-12">
                                <label class="ultra-label">Meeting Agenda / Notes</label>
                                <textarea name="notes" class="form-control ultra-input" rows="4"></textarea>
                            </div>
                        </div>
                        
                        <div class="mt-5 text-end border-top pt-4">
                            <button type="submit" class="btn-ultra-primary w-100 w-md-auto" <?= empty($clientsList) ? 'disabled' : '' ?>>
                                <i class="bi bi-calendar-check-fill me-2 fs-5"></i> Confirm Schedule
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- RIGHT COLUMN: QUICK TIPS & CONTEXT         -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-4">
                <div class="ultra-card h-100 p-4 p-md-5" style="background: linear-gradient(145deg, #ffffff, #f8fafc);">
                    <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                        <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-info-circle-fill"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">Quick Tips</h5>
                    </div>
                    
                    <ul class="ultra-check-list mb-0">
                        <li>
                            <i class="bi bi-clock-history"></i>
                            <div>Scheduled appointments are automatically recorded in the client's historical timeline for compliance and tracking.</div>
                        </li>
                        <li>
                            <i class="bi bi-camera-video-fill"></i>
                            <div>If you select <strong>Online Video Call</strong>, paste the join link so the assigned admin can launch it right from their dashboard.</div>
                        </li>
                        <li>
                            <i class="bi bi-shield-lock-fill"></i>
                            <div>Super Admins can assign meetings to other administrators. Regular admins can only schedule for their own client portfolio.</div>
                        </li>
                        <li>
                            <i class="bi bi-bell-fill"></i>
                            <div>Meetings automatically highlight as past-due if unattended once the scheduled timestamp expires.</div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    <?php endif; ?>
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