<?php


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
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $success_msg = 'Client details successfully updated!';
}

// 1. Fetch Client Data & Enforce Native Access Control
$client = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        header("Location: /public/clients/index.php");
        exit;
    }

    // Strict Access Control: Admin can only edit their own assigned or created clients
    if (!$isSuperAdmin && $client['assigned_admin_id'] != $current_user_id && $client['created_by'] != $current_user_id) {
        header("Location: /public/dashboard.php?error=unauthorized");
        exit;
    }

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Please ensure the `clients` table exists with correct columns.";
}

// Fetch Admin list for Super Admin reassignment capability
$adminsList = [];
try {
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $db_error = "Database Error (Users): " . $e->getMessage();
}

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$db_error) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security token validation failed.");
    }

    // Sanitize Inputs
    $name             = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES);
    $phone            = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES);
    $whatsapp         = htmlspecialchars(trim($_POST['whatsapp'] ?? ''), ENT_QUOTES);
    $email            = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES);
    $city             = htmlspecialchars(trim($_POST['city'] ?? ''), ENT_QUOTES);
    $state            = htmlspecialchars(trim($_POST['state'] ?? ''), ENT_QUOTES);
    $pin_code         = htmlspecialchars(trim($_POST['pin_code'] ?? ''), ENT_QUOTES);
    $address          = htmlspecialchars(trim($_POST['address'] ?? ''), ENT_QUOTES);
    
    $temperature      = htmlspecialchars(trim($_POST['temperature'] ?? 'COLD'), ENT_QUOTES);
    $requirement      = htmlspecialchars(trim($_POST['requirement'] ?? ''), ENT_QUOTES);

    $status           = htmlspecialchars(trim($_POST['status'] ?? 'New'), ENT_QUOTES);
    $deal_status      = htmlspecialchars(trim($_POST['deal_status'] ?? 'Open'), ENT_QUOTES);
    $quotation_amount = filter_input(INPUT_POST, 'quotation_amount', FILTER_VALIDATE_FLOAT) ?: null;
    $final_price      = filter_input(INPUT_POST, 'final_price', FILTER_VALIDATE_FLOAT) ?: null;
    $rejection_reason = htmlspecialchars(trim($_POST['rejection_reason'] ?? ''), ENT_QUOTES);

    // Reassignment (Super Admin only)
    $assigned_admin_id = $client['assigned_admin_id']; // Default to existing
    if ($isSuperAdmin && isset($_POST['assigned_admin_id'])) {
        $assigned_admin_id = filter_input(INPUT_POST, 'assigned_admin_id', FILTER_VALIDATE_INT);
    }

    // Inline Native Validation
    if (empty($name) || empty($phone)) {
        $error_msg = 'Client Name and Phone Number are mandatory fields.';
    } elseif (!preg_match('/^[1-9]\d{9}$/', $phone)) {
        $error_msg = 'Invalid primary phone number format.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Invalid email address format.';
    } elseif (!in_array($temperature, ['HOT', 'COLD'])) {
        $error_msg = 'Invalid lead temperature selection.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE clients 
                SET name = ?, phone = ?, whatsapp = ?, email = ?, address = ?, city = ?, state = ?, pin_code = ?, 
                    requirement = ?, temperature = ?, status = ?, deal_status = ?, quotation_amount = ?, 
                    final_price = ?, rejection_reason = ?, assigned_admin_id = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ? AND deleted_at IS NULL
            ");
            
            $stmt->execute([
                $name, $phone, $whatsapp, $email, $address, $city, $state, $pin_code,
                $requirement, $temperature, $status, $deal_status, $quotation_amount, $final_price,
                $rejection_reason, $assigned_admin_id, $current_user_id, $client_id
            ]);

            // Audit Log
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $current_user_id, $role, 'update_client', 'client', $client_id,
                "Updated client ID {$client_id} ({$name})", $_SERVER['REMOTE_ADDR']
            ]);

            $pdo->commit();
            
            // PRG Redirect to prevent double submission
            header("Location: /public/clients/edit.php?id=" . $client_id . "&msg=updated");
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

$pageTitle = $client ? "Edit Client: " . htmlspecialchars($client['client_code'], ENT_QUOTES) : "Client Error";

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
    
    /* Custom Radio Buttons (Temperature) */
    .ultra-radio-btn { border-radius: 14px; padding: 10px 20px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .btn-check:checked + .hot-radio { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-color: #b91c1c; box-shadow: 0 6px 15px rgba(239, 68, 68, 0.3); transform: translateY(-2px); }
    .btn-check:not(:checked) + .hot-radio { background: #fef2f2; color: #ef4444; border-color: #fca5a5; }
    
    .btn-check:checked + .cold-radio { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; border-color: #0369a1; box-shadow: 0 6px 15px rgba(14, 165, 233, 0.3); transform: translateY(-2px); }
    .btn-check:not(:checked) + .cold-radio { background: #f0f9ff; color: #0ea5e9; border-color: #7dd3fc; }

    /* Section Icons */
    .section-icon-wrapper { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin-right: 14px; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 100px; padding: 16px 32px; font-weight: 600; font-size: 1.05rem; letter-spacing: 0.5px; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); transition: all 0.2s; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35); color: white; }
    .btn-ultra-primary:active { transform: translateY(0); }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }
    
    .required-star { color: #ef4444; font-size: 1.1em; line-height: 0; position: relative; top: 3px; margin-left: 2px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
            <a href="/public/clients/index.php" class="btn btn-outline-danger rounded-pill mt-3 fw-bold">Back to Client List</a>
        </div>
    <?php elseif ($client): ?>

        <!-- Top Action Bar -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                    <i class="bi bi-pencil-square fs-4"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h3 class="fw-bold text-dark mb-0 tracking-tight">Edit Client: <?= htmlspecialchars($client['name'], ENT_QUOTES) ?></h3>
                        <span class="badge bg-light border text-muted rounded-pill ms-2 px-2 py-1 font-monospace">#<?= htmlspecialchars($client['client_code'], ENT_QUOTES) ?></span>
                    </div>
                    <p class="text-muted small mb-0 fw-medium">Modify contact information, update lead temperature & adjust deal values.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="/public/clients/view.php?id=<?= $client_id ?>" class="btn btn-ultra-secondary">
                    <i class="bi bi-eye-fill me-2 text-primary"></i> View Profile
                </a>
                <a href="/public/clients/index.php" class="btn btn-ultra-secondary px-3">
                    <i class="bi bi-arrow-left"> Back</i>
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($error_msg): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> <?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> <?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <form method="POST" action="/public/clients/edit.php?id=<?= $client_id ?>" class="needs-validation">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            
            <div class="row g-4">
                
                <!-- ========================================== -->
                <!-- 1. BASIC INFO & LEAD TEMPERATURE           -->
                <!-- ========================================== -->
                <div class="col-12 col-xl-6">
                    <div class="ultra-card h-100 p-4 p-md-5">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-4 mb-4">
                            <div class="d-flex align-items-center">
                                <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                                    <i class="bi bi-person-lines-fill"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">Basic Information</h5>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="ultra-label">Lead Temperature <span class="required-star">*</span></label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="temperature" id="tempHot" value="HOT" <?= ($client['temperature'] ?? 'COLD') === 'HOT' ? 'checked' : '' ?>>
                                        <label class="ultra-radio-btn hot-radio" for="tempHot"><i class="bi bi-fire"></i> HOT LEAD</label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="temperature" id="tempCold" value="COLD" <?= ($client['temperature'] ?? 'COLD') === 'COLD' ? 'checked' : '' ?>>
                                        <label class="ultra-radio-btn cold-radio" for="tempCold"><i class="bi bi-snow"></i> COLD LEAD</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-5">
                                <label class="ultra-label">Client Full Name <span class="required-star">*</span></label>
                                <input type="text" name="name" class="form-control ultra-input" value="<?= htmlspecialchars($client['name'], ENT_QUOTES) ?>" required>
                            </div>
                            
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Primary Phone <span class="required-star">*</span></label>
                                <input type="tel" name="phone" class="form-control ultra-input font-monospace" pattern="[1-9][0-9]{9}" value="<?= htmlspecialchars($client['phone'], ENT_QUOTES) ?>" maxlength="10" required>
                            </div>
                            
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">WhatsApp Number</label>
                                <input type="tel" name="whatsapp" class="form-control ultra-input font-monospace" value="<?= htmlspecialchars($client['whatsapp'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            
                            <div class="col-12">
                                <label class="ultra-label">Email Address</label>
                                <input type="email" name="email" class="form-control ultra-input" value="<?= htmlspecialchars($client['email'] ?? '', ENT_QUOTES) ?>">
                            </div>

                            <?php if ($isSuperAdmin): ?>
                            <div class="col-12 pt-3 border-top mt-4">
                                <label class="ultra-label text-primary">Assigned Employee (Reassign)</label>
                                <select name="assigned_admin_id" class="form-select ultra-input bg-primary bg-opacity-10 text-primary border-0">
                                    <?php foreach ($adminsList as $adm): ?>
                                        <option value="<?= $adm['id'] ?>" <?= $client['assigned_admin_id'] == $adm['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 2. LOCATION & ADDRESS                      -->
                <!-- ========================================== -->
                <div class="col-12 col-xl-6">
                    <div class="ultra-card h-100 p-4 p-md-5">
                        <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                            <div class="section-icon-wrapper bg-info bg-opacity-10 text-info">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Location & Address</h5>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="ultra-label">Full Address</label>
                                <textarea name="address" class="form-control ultra-input" rows="3"><?= htmlspecialchars($client['address'] ?? '', ENT_QUOTES) ?></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">City</label>
                                <input type="text" name="city" class="form-control ultra-input" value="<?= htmlspecialchars($client['city'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">State</label>
                                <input type="text" name="state" class="form-control ultra-input" value="<?= htmlspecialchars($client['state'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">PIN Code</label>
                                <input type="text" name="pin_code" class="form-control ultra-input font-monospace" pattern="[0-9]{6}" value="<?= htmlspecialchars($client['pin_code'] ?? '', ENT_QUOTES) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 3. REQUIREMENTS & SCOPE                    -->
                <!-- ========================================== -->
                <div class="col-12">
                    <div class="ultra-card p-4 p-md-5">
                        <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                            <div class="section-icon-wrapper bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-file-earmark-text-fill"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Client Requirements & Scope</h5>
                        </div>
                        
                        <textarea name="requirement" class="form-control ultra-input" rows="4" placeholder="Bbusiness requirements, requested services, budget expectations, and notes."><?= htmlspecialchars($client['requirement'] ?? '', ENT_QUOTES) ?></textarea>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 4. SALES & PIPELINE STATUS                 -->
                <!-- ========================================== -->
                <div class="col-12">
                    <div class="ultra-card p-4 p-md-5 mb-5">
                        <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                            <div class="section-icon-wrapper bg-success bg-opacity-10 text-success">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Sales & Pipeline Status</h5>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="ultra-label">Progress Status</label>
                                <select name="status" class="form-select ultra-input">
                                    <?php foreach (['New', 'Contacted', 'Demo Scheduled', 'Demo Completed', 'Follow Up', 'Meeting Scheduled', 'Quotation Sent', 'Negotiation', 'Converted', 'Rejected', 'On Hold', 'Lost'] as $st): ?>
                                        <option value="<?= $st ?>" <?= ($client['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="ultra-label">Deal Status</label>
                                <select name="deal_status" class="form-select ultra-input">
                                    <?php foreach (['Open', 'In Progress', 'Converted', 'Rejected', 'On Hold'] as $ds): ?>
                                        <option value="<?= $ds ?>" <?= ($client['deal_status'] ?? '') === $ds ? 'selected' : '' ?>><?= $ds ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="ultra-label">Quotation Amount (₹)</label>
                                <input type="number" step="0.01" name="quotation_amount" class="form-control ultra-input font-monospace text-primary" value="<?= htmlspecialchars($client['quotation_amount'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="ultra-label text-success">Final Converted Price (₹)</label>
                                <input type="number" step="0.01" name="final_price" class="form-control ultra-input font-monospace text-success bg-success bg-opacity-10 border-0" value="<?= htmlspecialchars($client['final_price'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-12 mt-4 pt-4 border-top">
                                <label class="ultra-label text-danger">Rejection Reason <small class="text-muted fw-medium text-lowercase">(If deal is lost/rejected)</small></label>
                                <input type="text" name="rejection_reason" class="form-control ultra-input bg-danger bg-opacity-10 text-danger border-0" value="<?= htmlspecialchars($client['rejection_reason'] ?? '', ENT_QUOTES) ?>">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Floating Action Button Area -->
            <div class="d-flex justify-content-end mt-2">
                <button type="submit" class="btn-ultra-primary w-100 w-md-auto d-flex align-items-center justify-content-center">
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i> Update Client Record
                </button>
            </div>

        </form>

    <?php endif; ?>
</div>

<!-- Security Script Kept Intact -->
<script>
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === "F12") { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
        if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
        if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>