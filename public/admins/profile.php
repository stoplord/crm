<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Fallback constants in case they are missing from config.php
$profileDir = defined('PROFILE_PIC_DIR') ? PROFILE_PIC_DIR : __DIR__ . '/../../uploads/profiles/';
$documentDir = defined('DOCUMENT_DIR') ? DOCUMENT_DIR : __DIR__ . '/../../uploads/documents/';

$target_admin_id = isset($_GET['id']) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : $_SESSION['user_id'];
if (!$target_admin_id) {
    $target_admin_id = $_SESSION['user_id'];
}

// Strict Manual Role Check (Prevents crashes from missing helper functions)
$isSuperAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin');
if (!$isSuperAdmin && $_SESSION['user_id'] != $target_admin_id) {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = sanitizeInput($_POST['action'] ?? '');
    
    try {
        if ($action === 'update_profile') {
            $alt_mobile      = sanitizeInput($_POST['alternative_number'] ?? '');
            $dob             = sanitizeInput($_POST['dob'] ?? '');
            $address         = sanitizeInput($_POST['address'] ?? '');
            $city            = sanitizeInput($_POST['city'] ?? '');
            $state           = sanitizeInput($_POST['state'] ?? '');
            $pin_code        = sanitizeInput($_POST['pin_code'] ?? '');
            
            $bank_name       = sanitizeInput($_POST['bank_name'] ?? '');
            $account_number  = sanitizeInput($_POST['account_number'] ?? '');
            $ifsc_code       = strtoupper(sanitizeInput($_POST['ifsc_code'] ?? ''));
            $account_owner   = sanitizeInput($_POST['account_owner'] ?? '');
            $date_of_joining = sanitizeInput($_POST['date_of_joining'] ?? '');

            $pdo->beginTransaction();

            $sql = "UPDATE admin_profiles SET alternative_number = ?, dob = ?, address = ?, city = ?, state = ?, pin_code = ?, bank_name = ?, account_number = ?, ifsc_code = ?, account_owner = ?, date_of_joining = ?";
            $params = [$alt_mobile, $dob, $address, $city, $state, $pin_code, $bank_name, $account_number, $ifsc_code, $account_owner, $date_of_joining];

            if (!empty($_FILES['profile_photo']['name'])) {
                if (!is_dir($profileDir)) mkdir($profileDir, 0755, true);
                $profile_photo = time() . '_profile_' . basename($_FILES['profile_photo']['name']);
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], $profileDir . $profile_photo);
                
                $sql .= ", profile_photo = ?";
                $params[] = $profile_photo;
            }

            if (!empty($_FILES['passbook_photo']['name'])) {
                if (!is_dir($documentDir)) mkdir($documentDir, 0755, true);
                $passbook_photo = time() . '_passbook_' . basename($_FILES['passbook_photo']['name']);
                move_uploaded_file($_FILES['passbook_photo']['tmp_name'], $documentDir . $passbook_photo);
                
                $sql .= ", passbook_photo = ?";
                $params[] = $passbook_photo;
            }

            $sql .= " WHERE user_id = ?";
            $params[] = $target_admin_id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$_SESSION['user_id'], $_SESSION['role'], 'update_profile', 'user', $target_admin_id, "Updated admin profile details", $_SERVER['REMOTE_ADDR']]);

            $pdo->commit();
            $success_msg = 'Profile updated successfully.';
            
        } elseif ($action === 'change_password') {
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (strlen($new_password) < 8) throw new Exception("Password must be at least 8 characters long.");
            if ($new_password !== $confirm_password) throw new Exception("New passwords do not match.");

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $target_admin_id]);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([$_SESSION['user_id'], $_SESSION['role'], 'change_password', 'user', $target_admin_id, "Changed account password", $_SERVER['REMOTE_ADDR']]);

            $success_msg = 'Password changed successfully.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = strpos($e->getMessage(), 'Unknown column') !== false 
            ? "Database Schema Error: Missing column. Did you run the ALTER TABLE SQL command?" 
            : "Database Error: " . $e->getMessage();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = $e->getMessage();
    }
}

// Fetch Display Data
$stmt = $pdo->prepare("
    SELECT u.id, u.role, u.name, u.email, u.mobile, u.status, u.created_at, p.*
    FROM users u
    LEFT JOIN admin_profiles p ON u.id = p.user_id
    WHERE u.id = ? AND u.deleted_at IS NULL
");
$stmt->execute([$target_admin_id]);
$profile = $stmt->fetch();

if (!$profile) {
    header("Location: /public/dashboard.php");
    exit;
}

$statStmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = ?) as total_clients,
        (SELECT SUM(total_seconds) FROM daily_work_logs WHERE admin_id = ?) as lifetime_seconds
");
$statStmt->execute([$target_admin_id, $target_admin_id]);
$stats = $statStmt->fetch();

// Safely Calculate Day Count
$day_count = 0;
if (!empty($profile['date_of_joining']) && $profile['date_of_joining'] !== '0000-00-00') {
    try {
        $joinDate = new DateTime($profile['date_of_joining']);
        $today = new DateTime();
        $day_count = $joinDate->diff($today)->days;
    } catch (Exception $e) {
        $day_count = 'Invalid Date';
    }
}

$pageTitle = ($target_admin_id == $_SESSION['user_id']) ? 'My Profile' : "Employee Profile Details"; // {$profile['name']}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- Android App-like UI Styling -->
<style>
    body { background-color: #f4f6f9; }
    .app-card { border-radius: 1.25rem; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.04); background: #fff; overflow: hidden; }
    .app-card-header { background: transparent; border-bottom: 1px solid #f0f2f5; padding: 1.25rem 1.5rem; }
    
    /* Touch-friendly inputs */
    .app-input { border-radius: 0.75rem; background-color: #f8f9fa; border: 1px solid #e9ecef; padding: 0.75rem 1rem; transition: all 0.2s; }
    .app-input:focus { background-color: #fff; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13,110,253,0.1); outline: none; }
    .app-label { font-size: 0.85rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; }
    
    /* Action Buttons */
    .app-btn { border-radius: 2rem; padding: 0.75rem 1.75rem; font-weight: 600; letter-spacing: 0.3px; transition: transform 0.1s; }
    .app-btn:active { transform: scale(0.97); }
    
    /* Material Sliding Tabs */
    .app-tabs-container { background: #f0f2f5; border-radius: 2rem; padding: 0.35rem; display: inline-flex; overflow-x: auto; white-space: nowrap; max-width: 100%; margin-bottom: 1rem; }
    .app-tabs-container::-webkit-scrollbar { display: none; }
    .app-tabs-container .nav-link { border-radius: 1.5rem; color: #6c757d; font-weight: 600; padding: 0.6rem 1.25rem; border: none; margin-right: 0.25rem; }
    .app-tabs-container .nav-link.active { background: #fff; color: #0d6efd; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
    
    /* Highlighted / Required blocks */
    .confidential-block { background-color: #fff5f5; border: 1px solid #ffe3e3; border-radius: 1rem; }
    .required-marker { color: #dc3545; font-weight: bold; margin-left: 2px; }
    
    /* App List Items */
    .app-list-item { display: flex; align-items: center; padding: 1rem 0; border-bottom: 1px solid #f0f2f5; }
    .app-list-item:last-child { border-bottom: none; }
    .app-list-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-right: 1rem; background: #f0f4ff; color: #0d6efd; }
</style>

<div class="container-fluid px-3 px-md-4 py-4">
    <div class="mb-4 d-flex align-items-center">
        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 40px; height: 40px;">
            <i class="bi bi-person-badge fs-5"></i>
        </div>
        <h4 class="fw-bold text-dark mb-0"><?= escapeOutput($pageTitle) ?></h4>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4"><i class="bi bi-check-circle-fill me-2"></i><?= escapeOutput($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= escapeOutput($error_msg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- LEFT COLUMN: PROFILE CARD -->
        <div class="col-12 col-xl-4">
            <div class="card app-card text-center pt-4 pb-3 mb-4">
                <div class="position-relative d-inline-block mx-auto mb-3">
                    <?php if (!empty($profile['profile_photo'])): ?>
                        <img src="/uploads/profiles/<?= escapeOutput($profile['profile_photo']) ?>" alt="Photo" class="rounded-circle shadow-sm" style="width: 130px; height: 130px; object-fit: cover; border: 4px solid #fff;">
                    <?php else: ?>
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-secondary fw-bold shadow-sm" style="width: 130px; height: 130px; font-size: 3.5rem; border: 4px solid #fff;">
                            <i class="bi bi-person"></i>
                        </div>
                    <?php endif; ?>
                    <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-white border-2 rounded-circle shadow-sm"></span>
                </div>
                <h4 class="fw-bold mb-1 text-dark"><?= escapeOutput($profile['name']) ?></h4>
                <p class="text-muted mb-3 text-uppercase small tracking-wide fw-bold">
                    <?= $profile['role'] === 'super_admin' ? '<span class="text-danger"><i class="bi bi-shield-lock-fill me-1"></i> Super Admin</span>' : '<span class="text-primary"><i class="bi bi-person-check-fill me-1"></i> Employee</span>' ?>
                </p>
                
                <div class="d-flex justify-content-center gap-3 px-4 mt-2">
                    <div class="bg-light rounded-4 p-3 flex-fill">
                        <h4 class="fw-bold text-primary mb-0"><?= number_format($stats['total_clients'] ?? 0) ?></h4>
                        <small class="text-muted fw-semibold">Clients</small>
                    </div>
                    <div class="bg-light rounded-4 p-3 flex-fill">
                        <h4 class="fw-bold text-primary mb-0 font-monospace fs-5 mt-1"><?= formatWorkingHours($stats['lifetime_seconds'] ?? 0) ?></h4>
                        <small class="text-muted fw-semibold">Work Time</small>
                    </div>
                </div>
            </div>

            <!-- CONFIDENTIAL DETAILS (HIGHLIGHTED BLOCK) -->
            <div class="card app-card confidential-block mb-4">
                <div class="app-card-header pb-2 border-0">
                    <h6 class="fw-bold text-danger mb-0"><i class="bi bi-shield-lock-fill me-2"></i> Confidential Records</h6>
                </div>
                <div class="card-body pt-2">
                    <div class="app-list-item pt-0 border-0">
                        <div class="app-list-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-card-text"></i></div>
                        <div>
                            <small class="text-danger fw-bold d-block">PAN Number</small>
                            <span class="fw-bold text-dark font-monospace fs-6"><?= escapeOutput($profile['pan_number'] ?? 'Not Provided') ?></span>
                        </div>
                    </div>
                    <div class="app-list-item border-0">
                        <div class="app-list-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-person-vcard"></i></div>
                        <div>
                            <small class="text-danger fw-bold d-block">Aadhaar Number</small>
                            <span class="fw-bold text-dark font-monospace fs-6">
                                <?= !empty($profile['aadhaar_number']) ? '********' . substr(escapeOutput($profile['aadhaar_number']), -4) : 'Not Provided' ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($isSuperAdmin): ?>
                    <div class="mt-3 pt-3 border-top border-danger border-opacity-25">
                        <small class="text-danger fw-bold d-block mb-3"><i class="bi bi-file-earmark-lock me-1"></i> Document Attachments</small>
                        <div class="d-flex flex-column gap-2">
                            <?php if(!empty($profile['pan_photo'])): ?>
                                <a href="/uploads/documents/<?= escapeOutput($profile['pan_photo']) ?>"  class="btn btn-sm btn-danger rounded-pill w-100 fw-semibold"><i class="bi bi-eye me-1"></i> View PAN</a>
                            <?php endif; ?>
                            <?php if(!empty($profile['aadhaar_photo'])): ?>
                                <a href="/uploads/documents/<?= escapeOutput($profile['aadhaar_photo']) ?>"  class="btn btn-sm btn-danger rounded-pill w-100 fw-semibold"><i class="bi bi-eye me-1"></i> View Aadhaar</a>
                            <?php endif; ?>
                            <?php if(!empty($profile['passbook_photo'])): ?>
                                <a href="/uploads/documents/<?= escapeOutput($profile['passbook_photo']) ?>" class="btn btn-sm btn-danger rounded-pill w-100 fw-semibold"><i class="bi bi-eye me-1"></i> View Passbook</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: MAIN APP CONTENT -->
        <div class="col-12 col-xl-8">
            <!-- App-style Tabs -->
            <ul class="nav app-tabs-container mb-3 shadow-sm" id="profileTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button"><i class="bi bi-info-circle me-1"></i> Overview</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit" type="button"><i class="bi bi-pencil-square me-1"></i> Edit Profile</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button"><i class="bi bi-shield-lock me-1"></i> Security</button>
                </li>
            </ul>

            <div class="card app-card">
                <div class="card-body p-4">
                    <div class="tab-content">
                        
                        <!-- TAB 1: OVERVIEW (Native Android App Settings Style) -->
                        <div class="tab-pane fade show active" id="overview">
                            <h6 class="fw-bold text-primary mb-2 text-uppercase tracking-wide small">Employment & Contact</h6>
                            
                            <div class="app-list-item">
                                <div class="app-list-icon"><i class="bi bi-calendar-check"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Date of Joining</small>
                                    <div class="fw-bold text-dark fs-6">
                                        <?= (!empty($profile['date_of_joining']) && $profile['date_of_joining'] !== '0000-00-00') ? formatDate($profile['date_of_joining']) : '-' ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary ms-2 rounded-pill px-2">Tenure: <?= $day_count ?> Days</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="app-list-item">
                                <div class="app-list-icon"><i class="bi bi-envelope"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Email Address</small>
                                    <div class="fw-bold text-dark fs-6"><?= escapeOutput($profile['email']) ?></div>
                                </div>
                            </div>
                            
                            <div class="app-list-item mb-4">
                                <div class="app-list-icon"><i class="bi bi-telephone"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Mobile Number</small>
                                    <div class="fw-bold text-dark fs-6"><?= escapeOutput($profile['mobile']) ?></div>
                                </div>
                            </div>

                            <h6 class="fw-bold text-primary mb-2 mt-4 text-uppercase tracking-wide small">Bank & Payroll</h6>
                            
                            <div class="app-list-item">
                                <div class="app-list-icon bg-success bg-opacity-10 text-success"><i class="bi bi-bank"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Bank Name</small>
                                    <div class="fw-bold text-dark fs-6"><?= escapeOutput($profile['bank_name'] ?? '-') ?></div>
                                </div>
                            </div>
                            
                            <div class="app-list-item">
                                <div class="app-list-icon bg-success bg-opacity-10 text-success"><i class="bi bi-123"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Account Number & IFSC</small>
                                    <div class="fw-bold text-dark font-monospace fs-6">
                                        <?= escapeOutput($profile['account_number'] ?? '-') ?> 
                                        <span class="text-muted ms-2">| <?= escapeOutput($profile['ifsc_code'] ?? '-') ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="app-list-item mb-4">
                                <div class="app-list-icon bg-success bg-opacity-10 text-success"><i class="bi bi-person-badge"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Account Owner</small>
                                    <div class="fw-bold text-dark fs-6"><?= escapeOutput($profile['account_owner'] ?? '-') ?></div>
                                </div>
                            </div>

                            <h6 class="fw-bold text-primary mb-2 mt-4 text-uppercase tracking-wide small">Location</h6>
                            <div class="app-list-item border-0">
                                <div class="app-list-icon bg-info bg-opacity-10 text-info"><i class="bi bi-geo-alt"></i></div>
                                <div>
                                    <small class="text-muted fw-bold d-block">Full Address</small>
                                    <div class="fw-bold text-dark fs-6"><?= nl2br(escapeOutput($profile['address'] ?? '-')) ?></div>
                                    <div class="text-muted small mt-1">
                                        <?= escapeOutput($profile['city'] ?? '-') ?>, <?= escapeOutput($profile['state'] ?? '-') ?> - <?= escapeOutput($profile['pin_code'] ?? '-') ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: EDIT PROFILE -->
                        <div class="tab-pane fade" id="edit">
                            <form method="POST" action="/public/admins/profile.php<?= isset($_GET['id']) ? '?id='.(int)$_GET['id'] : '' ?>" enctype="multipart/form-data">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_profile">
                                
                                <h6 class="fw-bold mb-3 text-primary bg-primary bg-opacity-10 p-2 rounded-3"><i class="bi bi-person me-2"></i> Personal Details</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">Date of Joining</label>
                                        <input type="date" name="date_of_joining" class="form-control app-input" value="<?= escapeOutput($profile['date_of_joining'] ?? '') ?>" <?= $isSuperAdmin ? '' : 'readonly' ?>>
                                        <?php if(!$isSuperAdmin): ?><small class="text-muted">Only Super Admin can edit.</small><?php endif; ?>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">Alternative Mobile</label>
                                        <input type="text" name="alternative_number" class="form-control app-input" pattern="[6-9][0-9]{9}" placeholder="10-digit number" value="<?= escapeOutput($profile['alternative_number'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">Date of Birth</label>
                                        <input type="date" name="dob" class="form-control app-input" value="<?= escapeOutput($profile['dob'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="app-label">Update Profile Photo</label>
                                        <input type="file" name="profile_photo" class="form-control app-input" accept=".jpg,.jpeg,.png,.webp">
                                    </div>
                                </div>

                                <h6 class="fw-bold mb-3 text-success bg-success bg-opacity-10 p-2 rounded-3"><i class="bi bi-bank me-2"></i> Bank Details</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12 col-md-6">
                                        <label class="app-label">Bank Name</label>
                                        <input type="text" name="bank_name" class="form-control app-input" placeholder="e.g. State Bank of India" value="<?= escapeOutput($profile['bank_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="app-label">Account Number</label>
                                        <input type="text" name="account_number" class="form-control app-input font-monospace" placeholder="Account Number" value="<?= escapeOutput($profile['account_number'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="app-label">IFSC Code</label>
                                        <input type="text" name="ifsc_code" class="form-control app-input text-uppercase font-monospace" placeholder="e.g. SBIN0001234" value="<?= escapeOutput($profile['ifsc_code'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="app-label">Account Owner Name</label>
                                        <input type="text" name="account_owner" class="form-control app-input" placeholder="Name as per bank" value="<?= escapeOutput($profile['account_owner'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="app-label">Update Passbook / Cheque Image</label>
                                        <input type="file" name="passbook_photo" class="form-control app-input" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                    </div>
                                </div>

                                <h6 class="fw-bold mb-3 text-info bg-info bg-opacity-10 p-2 rounded-3"><i class="bi bi-geo-alt me-2"></i> Address Information</h6>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="app-label">Full Address</label>
                                        <textarea name="address" class="form-control app-input" rows="2" placeholder="Street, Apt, Area..."><?= escapeOutput($profile['address'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">City</label>
                                        <input type="text" name="city" class="form-control app-input" placeholder="City" value="<?= escapeOutput($profile['city'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">State</label>
                                        <input type="text" name="state" class="form-control app-input" placeholder="State" value="<?= escapeOutput($profile['state'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="app-label">PIN Code</label>
                                        <input type="text" name="pin_code" class="form-control app-input" pattern="[0-9]{6}" placeholder="6-digit PIN" value="<?= escapeOutput($profile['pin_code'] ?? '') ?>">
                                    </div>
                                </div>
                                
                                <div class="mt-4 pt-3 border-top text-end">
                                    <button type="submit" class="btn btn-primary app-btn w-100 w-md-auto shadow-sm"><i class="bi bi-save me-2"></i> Save Profile Details</button>
                                </div>
                            </form>
                        </div>

                        <!-- TAB 3: SECURITY (PASSWORD) -->
                        <div class="tab-pane fade" id="security">
                            <div class="text-center mb-4">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; font-size: 2.5rem;">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                                <h5 class="fw-bold">Update Account Security</h5>
                                <p class="text-muted small">Ensure your new password is at least 8 characters long.</p>
                            </div>
                            
                            <form method="POST" action="/public/admins/profile.php<?= isset($_GET['id']) ? '?id='.(int)$_GET['id'] : '' ?>" class="mx-auto" style="max-width: 400px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_password">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="app-label">New Password <span class="required-marker">*</span></label>
                                        <input type="password" name="new_password" class="form-control app-input" placeholder="••••••••" required minlength="8">
                                    </div>
                                    <div class="col-12">
                                        <label class="app-label">Confirm New Password <span class="required-marker">*</span></label>
                                        <input type="password" name="confirm_password" class="form-control app-input" placeholder="••••••••" required minlength="8">
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-danger app-btn w-100 shadow-sm"><i class="bi bi-key me-2"></i> Update Password</button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
    });

    document.addEventListener('keydown', function (e) {
    if (e.key === "F12") {
        e.preventDefault();
    }
    if (e.ctrlKey && e.key.toLowerCase() === "u") {
        e.preventDefault();
    }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) {
        e.preventDefault();
    }
    if (e.ctrlKey && e.key.toLowerCase() === "s") {
        e.preventDefault();
    }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>