<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Strict RBAC: Fallback native check to prevent undefined function crashes
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

// Fallback constants in case they are missing from config.php
$profileDir = defined('PROFILE_PIC_DIR') ? PROFILE_PIC_DIR : __DIR__ . '/../../uploads/profiles/';
$documentDir = defined('DOCUMENT_DIR') ? DOCUMENT_DIR : __DIR__ . '/../../uploads/documents/';

$pageTitle = 'Add New Admin';
$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $name            = sanitizeInput($_POST['name'] ?? '');
    $email           = sanitizeInput($_POST['email'] ?? '');
    $mobile          = sanitizeInput($_POST['mobile'] ?? '');
    $password        = $_POST['password'] ?? '';
    $alt_mobile      = sanitizeInput($_POST['alternative_number'] ?? '');
    $dob             = sanitizeInput($_POST['dob'] ?? '');
    $address         = sanitizeInput($_POST['address'] ?? '');
    $city            = sanitizeInput($_POST['city'] ?? '');
    $state           = sanitizeInput($_POST['state'] ?? '');
    $pin_code        = sanitizeInput($_POST['pin_code'] ?? '');
    $date_of_joining = sanitizeInput($_POST['date_of_joining'] ?? date('Y-m-d'));
    
    $aadhaar_num     = sanitizeInput($_POST['aadhaar_number'] ?? '');
    $pan_num         = strtoupper(sanitizeInput($_POST['pan_number'] ?? ''));

    $bank_name       = sanitizeInput($_POST['bank_name'] ?? '');
    $account_number  = sanitizeInput($_POST['account_number'] ?? '');
    $ifsc_code       = strtoupper(sanitizeInput($_POST['ifsc_code'] ?? ''));
    $account_owner   = sanitizeInput($_POST['account_owner'] ?? '');

    // Inline regex validations to prevent missing function crashes
    if (empty($name) || empty($email) || empty($mobile) || empty($password) || empty($aadhaar_num) || empty($pan_num) || empty($account_number)) {
        $error_msg = 'All mandatory fields (marked with *) must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Invalid email address format.';
    } elseif (!preg_match('/^[1-9]\d{9}$/', $mobile)) {
        $error_msg = 'Invalid mobile number format (must be 10 digits).';
    } elseif (!preg_match('/^\d{12}$/', $aadhaar_num)) {
        $error_msg = 'Invalid Aadhaar format (must be 12 digits).';
    } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan_num)) {
        $error_msg = 'Invalid PAN format.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR mobile = ?) AND deleted_at IS NULL");
        $stmt->execute([$email, $mobile]);
        
        if ($stmt->fetch()) {
            $error_msg = 'An active user with this email or mobile number already exists.';
        } else {
            $uploadedFiles = [];
            
            try {
                // Ensure directories exist
                if (!is_dir($profileDir)) mkdir($profileDir, 0755, true);
                if (!is_dir($documentDir)) mkdir($documentDir, 0755, true);

                $profile_photo = null;
                if (!empty($_FILES['profile_photo']['name'])) {
                    $profile_photo = time() . '_profile_' . basename($_FILES['profile_photo']['name']);
                    move_uploaded_file($_FILES['profile_photo']['tmp_name'], $profileDir . $profile_photo);
                    $uploadedFiles[] = $profileDir . $profile_photo;
                }

                if (empty($_FILES['aadhaar_photo']['name'])) throw new Exception("Aadhaar document is required.");
                $aadhaar_photo = time() . '_aadhaar_' . basename($_FILES['aadhaar_photo']['name']);
                move_uploaded_file($_FILES['aadhaar_photo']['tmp_name'], $documentDir . $aadhaar_photo);
                $uploadedFiles[] = $documentDir . $aadhaar_photo;

                if (empty($_FILES['pan_photo']['name'])) throw new Exception("PAN document is required.");
                $pan_photo = time() . '_pan_' . basename($_FILES['pan_photo']['name']);
                move_uploaded_file($_FILES['pan_photo']['tmp_name'], $documentDir . $pan_photo);
                $uploadedFiles[] = $documentDir . $pan_photo;

                if (empty($_FILES['passbook_photo']['name'])) throw new Exception("Passbook document is required.");
                $passbook_photo = time() . '_passbook_' . basename($_FILES['passbook_photo']['name']);
                move_uploaded_file($_FILES['passbook_photo']['tmp_name'], $documentDir . $passbook_photo);
                $uploadedFiles[] = $documentDir . $passbook_photo;

                $pdo->beginTransaction();

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmtUser = $pdo->prepare("INSERT INTO users (role, name, email, mobile, password, status) VALUES ('admin', ?, ?, ?, ?, 'active')");
                $stmtUser->execute([$name, $email, $mobile, $password_hash]);
                $new_admin_id = $pdo->lastInsertId();

                $stmtProfile = $pdo->prepare("
                    INSERT INTO admin_profiles 
                    (user_id, alternative_number, dob, address, city, state, pin_code, profile_photo, aadhaar_number, aadhaar_photo, pan_number, pan_photo, date_of_joining, bank_name, account_number, ifsc_code, account_owner, passbook_photo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtProfile->execute([
                    $new_admin_id, $alt_mobile, $dob, $address, $city, $state, $pin_code, 
                    $profile_photo, $aadhaar_num, $aadhaar_photo, $pan_num, $pan_photo,
                    $date_of_joining, $bank_name, $account_number, $ifsc_code, $account_owner, $passbook_photo
                ]);

                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([
                    $_SESSION['user_id'], $_SESSION['role'], 'create_admin', 'user', $new_admin_id, 
                    "Created new Admin account for {$name}", $_SERVER['REMOTE_ADDR']
                ]);

                $pdo->commit();
                $success_msg = 'Admin account created successfully.';
                $_POST = [];

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($uploadedFiles as $file) { if (file_exists($file)) unlink($file); }
                $error_msg = strpos($e->getMessage(), 'Unknown column') !== false 
                    ? "Database Schema Error: Missing new columns. Please run the ALTER TABLE SQL command." 
                    : "Database Error: " . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($uploadedFiles as $file) { if (file_exists($file)) unlink($file); }
                $error_msg = $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    
    /* Ultra Glass/Neumorphic Cards */
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); overflow: hidden; margin-bottom: 1.5rem; transition: transform 0.3s ease; }
    .ultra-card:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(0, 0, 0, 0.05); }
    
    /* Ultra Inputs */
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 6px; margin-left: 4px; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 500; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    .ultra-input::file-selector-button { background: #e2e8f0; border: none; border-radius: 8px; padding: 6px 12px; margin-right: 12px; font-weight: 600; color: #475569; transition: all 0.2s; cursor: pointer; }
    .ultra-input::file-selector-button:hover { background: #cbd5e1; }
    
    /* Section Headers */
    .section-icon-wrapper { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-right: 12px; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 100px; padding: 16px 32px; font-weight: 600; font-size: 1.05rem; letter-spacing: 0.5px; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); transition: all 0.2s; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35); color: white; }
    .btn-ultra-primary:active { transform: translateY(0); }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }
    
    .required-star { color: #ef4444; font-size: 1.1em; line-height: 0; position: relative; top: 3px; margin-left: 2px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-person-plus-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Create Employee</h3>
                <p class="text-muted small mb-0 fw-medium">Onboard a new Employee & set up for KYC</p>
            </div>
        </div>
        <a href="/public/admins/index.php" class="btn btn-ultra-secondary d-inline-flex align-items-center">
            <i class="bi bi-arrow-left me-2"></i> Back To Employee List
        </a>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-exclamation-triangle-fill me-3 fs-4 text-danger"></i> <?= escapeOutput($error_msg) ?></div>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center"><i class="bi bi-check-circle-fill me-3 fs-4 text-success"></i> <?= escapeOutput($success_msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="/public/admins/create.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        
        <div class="row g-4">
            
            <!-- ========================================== -->
            <!-- 1. ACCOUNT CREDENTIALS                     -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-6">
                <div class="ultra-card h-100">
                    <div class="p-4 p-md-5">
                        <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                            <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-shield-lock-fill"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Account Credentials</h5>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="ultra-label">Full Name <span class="required-star">*</span></label>
                                <input type="text" name="name" class="form-control ultra-input" value="<?= escapeOutput($_POST['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Email Address <span class="required-star">*</span></label>
                                <input type="email" name="email" class="form-control ultra-input" value="<?= escapeOutput($_POST['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Mobile Number <span class="required-star">*</span></label>
                                <input type="tel" name="mobile" class="form-control ultra-input font-monospace" pattern="[1-9][0-9]{9}" minlength="10" value="<?= escapeOutput($_POST['mobile'] ?? '') ?>" maxlength="10" required>
                            </div>
                            <div class="col-12">
                                <label class="ultra-label">Initial Password <span class="required-star">*</span></label>
                                <input type="password" name="password" class="form-control ultra-input font-monospace"  required minlength="8">
                                <small class="text-muted d-block mt-2 fw-medium ms-1"><i class="bi bi-info-circle me-1"></i>Must be at least 8 characters.</small>
                            </div>
                            <div class="col-12 mt-4 pt-2 border-top">
                                <label class="ultra-label">Profile Photo</label>
                                <input type="file" name="profile_photo" class="form-control ultra-input bg-white" accept=".jpg,.jpeg,.png,.webp">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 2. IDENTITY VERIFICATION (KYC)             -->
            <!-- ========================================== -->
            <div class="col-12 col-xl-6">
                <div class="ultra-card h-100">
                    <div class="p-4 p-md-5 bg-danger bg-opacity-10 h-100" style="background: linear-gradient(145deg, #fffafa, #fff0f0);">
                        <div class="d-flex align-items-center border-bottom border-danger border-opacity-25 pb-3 mb-4">
                            <div class="section-icon-wrapper bg-danger bg-opacity-25 text-danger">
                                <i class="bi bi-person-vcard-fill"></i>
                            </div>
                            <h5 class="fw-bold text-danger mb-0">Identity Verification (KYC)</h5>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="ultra-label text-danger">Aadhaar Number <span class="required-star">*</span></label>
                                <input type="text" name="aadhaar_number" class="form-control ultra-input font-monospace" pattern="[0-9]{12}" value="<?= escapeOutput($_POST['aadhaar_number'] ?? '') ?>" minlength="12"   maxlength="12" required>
                            </div>
                            <div class="col-12">
                                <label class="ultra-label text-danger">Aadhaar Document Scan <span class="required-star">*</span></label>
                                <input type="file" name="aadhaar_photo" class="form-control ultra-input bg-white" accept=".jpg,.jpeg,.png,.webp" required>
                            </div>
                            
                            <div class="col-12 mt-4 pt-4 border-top border-danger border-opacity-25">
                                <label class="ultra-label text-danger">PAN Number <span class="required-star">*</span></label>
                                <input type="text" name="pan_number" class="form-control ultra-input font-monospace text-uppercase" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]{1}" value="<?= escapeOutput($_POST['pan_number'] ?? '') ?>" minlength="10" maxlength="10" required>
                            </div>
                            <div class="col-12">
                                <label class="ultra-label text-danger">PAN Document Scan <span class="required-star">*</span></label>
                                <input type="file" name="pan_photo" class="form-control ultra-input bg-white" accept=".jpg,.jpeg,.png,.webp" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 3. PAYROLL & BANKING                       -->
            <!-- ========================================== -->
            <div class="col-12">
                <div class="ultra-card">
                    <div class="p-4 p-md-5">
                        <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                            <div class="section-icon-wrapper bg-success bg-opacity-10 text-success">
                                <i class="bi bi-bank2"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Payroll & Bank Information</h5>
                        </div>

                        <div class="row g-4">
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Bank Name <span class="required-star">*</span></label>
                                <input type="text" name="bank_name" class="form-control ultra-input" value="<?= escapeOutput($_POST['bank_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Account Number <span class="required-star">*</span></label>
                                <input type="text" name="account_number" class="form-control ultra-input font-monospace" value="<?= escapeOutput($_POST['account_number'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">IFSC Code <span class="required-star">*</span></label>
                                <input type="text" name="ifsc_code" class="form-control ultra-input font-monospace text-uppercase" value="<?= escapeOutput($_POST['ifsc_code'] ?? '') ?>" minlength="11" maxlength="11" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Account Owner Name <span class="required-star">*</span></label>
                                <input type="text" name="account_owner" class="form-control ultra-input" value="<?= escapeOutput($_POST['account_owner'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="ultra-label">Passbook / Cancelled Cheque <span class="required-star">*</span></label>
                                <input type="file" name="passbook_photo" class="form-control ultra-input bg-white" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 4. PERSONAL INFO & ADDRESS                 -->
            <!-- ========================================== -->
            <div class="col-12">
                <div class="ultra-card mb-5">
                    <div class="p-4 p-md-5">
                        <div class="d-flex align-items-center border-bottom pb-3 mb-4">
                            <div class="section-icon-wrapper bg-info bg-opacity-10 text-info">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-0">Personal Information & Address</h5>
                        </div>

                        <div class="row g-4">
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Date of Joining <span class="required-star">*</span></label>
                                <input type="date" name="date_of_joining" class="form-control ultra-input" value="<?= escapeOutput($_POST['date_of_joining'] ?? date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Alternative Number</label>
                                <input type="text" name="alternative_number" class="form-control ultra-input font-monospace" pattern="[6-9][0-9]{9}" value="<?= escapeOutput($_POST['alternative_number'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">Date of Birth</label>
                                <input type="date" name="dob" class="form-control ultra-input" value="<?= escapeOutput($_POST['dob'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="ultra-label">Full Residential Address</label>
                                <textarea name="address" class="form-control ultra-input" rows="2"><?= escapeOutput($_POST['address'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">City</label>
                                <input type="text" name="city" class="form-control ultra-input" value="<?= escapeOutput($_POST['city'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">State</label>
                                <input type="text" name="state" class="form-control ultra-input" value="<?= escapeOutput($_POST['state'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="ultra-label">PIN Code</label>
                                <input type="text" name="pin_code" class="form-control ultra-input font-monospace" pattern="[0-9]{6}" minlength="6" maxlength="6" value="<?= escapeOutput($_POST['pin_code'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Floating-style Action Bar -->
        <div class="d-flex justify-content-end mt-2">
            <button type="submit" class="btn-ultra-primary w-100 w-md-auto d-flex align-items-center justify-content-center">
                <i class="bi bi-person-plus-fill me-2 fs-5"></i> Create Employee Account
            </button>
        </div>

    </form>
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