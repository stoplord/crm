<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

$role = $_SESSION['role'] ?? 'admin';
$current_user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

if (!$isSuperAdmin) {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

// 1. Download CSV Template Handler
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="crm_leads_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Phone', 'Email', 'City', 'State', 'Requirement']);
    fputcsv($output, ['John Doe', '9876543210', 'john@example.com', 'Mumbai', 'Maharashtra', 'Looking for software services']);
    fputcsv($output, ['Jane Smith', '9123456789', 'jane@example.com', 'Delhi', 'Delhi', 'Interested in a demo']);
    fclose($output);
    exit;
}

$pageTitle = 'Bulk Import Leads';
$success_msg = '';
$error_msg = '';
$import_results = '';

// Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'imported') {
    $sc = (int)($_GET['success'] ?? 0);
    $ec = (int)($_GET['errors'] ?? 0);
    $success_msg = "Import completed! Successfully imported: <strong>{$sc} leads</strong>. Failed/Skipped: <strong>{$ec} rows</strong>.";
}

// 2. Process File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $assigned_admin_id = filter_input(INPUT_POST, 'assigned_admin_id', FILTER_VALIDATE_INT) ?: $current_user_id;

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Please select a valid CSV file to upload.";
    } else {
        $fileTmpPath = $_FILES['import_file']['tmp_name'];
        $fileName = $_FILES['import_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExtension !== 'csv') {
            $error_msg = "Invalid file format. Please save your Excel file as a .csv and try again.";
        } else {
            try {
                $handle = fopen($fileTmpPath, 'r');
                $header = fgetcsv($handle, 1000, ","); // Read header row

                if (!$header || count($header) < 2) {
                    throw new Exception("Invalid CSV format. Please use the provided template.");
                }

                $successCount = 0;
                $errorCount = 0;

                // Get current MAX ID to generate sequence of Client Codes safely
                $stmtMax = $pdo->query("SELECT MAX(id) FROM clients");
                $currentMaxId = (int)$stmtMax->fetchColumn();

                // Prepare Insertion Statements
                $stmtInsertClient = $pdo->prepare("
                    INSERT INTO clients 
                    (client_code, name, phone, email, city, state, requirement, temperature, status, deal_status, assigned_admin_id, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'COLD', 'New', 'Open', ?, ?)
                ");

                $stmtInsertActivity = $pdo->prepare("
                    INSERT INTO client_activities (client_id, admin_id, activity_type, activity_date, activity_time, notes) 
                    VALUES (?, ?, 'Other', CURDATE(), CURTIME(), 'Lead automatically imported via Bulk Upload tool.')
                ");

                $pdo->beginTransaction();

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $name  = htmlspecialchars(trim($data[0] ?? ''), ENT_QUOTES);
                    $phone = htmlspecialchars(trim($data[1] ?? ''), ENT_QUOTES);
                    $email = htmlspecialchars(trim($data[2] ?? ''), ENT_QUOTES);
                    $city  = htmlspecialchars(trim($data[3] ?? ''), ENT_QUOTES);
                    $state = htmlspecialchars(trim($data[4] ?? ''), ENT_QUOTES);
                    $req   = htmlspecialchars(trim($data[5] ?? ''), ENT_QUOTES);

                    // Skip empty rows or rows without mandatory phone numbers
                    if (empty($name) || empty($phone)) {
                        $errorCount++;
                        continue;
                    }

                    // Clean phone number (strip non-numeric)
                    $phone = preg_replace('/[^0-9]/', '', $phone);
                    if (strlen($phone) > 10) $phone = substr($phone, -10);

                    $currentMaxId++;
                    $client_code = 'CL-' . str_pad($currentMaxId, 6, '0', STR_PAD_LEFT);

                    try {
                        $stmtInsertClient->execute([
                            $client_code, $name, $phone, $email, $city, $state, $req, 
                            $assigned_admin_id, $current_user_id
                        ]);
                        
                        $new_client_id = $pdo->lastInsertId();
                        $stmtInsertActivity->execute([$new_client_id, $current_user_id]);
                        
                        $successCount++;
                    } catch (Exception $e) {
                        $errorCount++; // Silently skip duplicates/db errors to continue batch
                    }
                }

                fclose($handle);

                // Audit Log
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([
                    $current_user_id, $role, 'bulk_import_leads', 'client', 0,
                    "Imported {$successCount} leads via CSV bulk upload", $_SERVER['REMOTE_ADDR']
                ]);

                $pdo->commit();
                header("Location: /public/clients/import.php?msg=imported&success={$successCount}&errors={$errorCount}");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// Fetch Admin list for assignment dropdown
$adminsList = [];
try {
    $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignore safely
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
    
    /* Custom File Input */
    .ultra-file-input { border-radius: 16px; background: #f8fafc; border: 2px dashed #cbd5e1; padding: 10px; transition: all 0.2s; cursor: pointer; }
    .ultra-file-input:hover { border-color: #3b82f6; background: #f0f9ff; }
    .ultra-file-input::file-selector-button { background: #e2e8f0; border: none; border-radius: 10px; padding: 10px 20px; margin-right: 16px; font-weight: 700; color: #475569; transition: all 0.2s; cursor: pointer; }
    .ultra-file-input::file-selector-button:hover { background: #cbd5e1; color: #0f172a; }

    /* Section Icons */
    .section-icon-wrapper { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin-right: 14px; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 100px; padding: 16px 32px; font-weight: 600; font-size: 1.05rem; letter-spacing: 0.5px; box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35); color: white; }
    .btn-ultra-primary:active { transform: translateY(0); }
    
    .btn-ultra-success { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 100px; padding: 14px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.25); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; width: 100%; }
    .btn-ultra-success:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.35); color: white; }

    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }
    
    .required-star { color: #ef4444; font-size: 1.1em; line-height: 0; position: relative; top: 3px; margin-left: 2px; }

    /* Custom Check List */
    .ultra-check-list { list-style: none; padding: 0; margin: 0; }
    .ultra-check-list li { display: flex; align-items: flex-start; margin-bottom: 12px; font-weight: 500; color: #475569; line-height: 1.5; }
    .ultra-check-list li i { color: #10b981; font-size: 1.2rem; margin-right: 12px; margin-top: -2px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-cloud-arrow-up-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Bulk Import Leads</h3>
                <p class="text-muted small mb-0 fw-medium">Upload an Excel/CSV file to instantly import hundreds of clients.</p>
            </div>
        </div>
        <a href="/public/clients/index.php" class="btn btn-ultra-secondary">
            <i class="bi bi-arrow-left me-2"></i> Back to Client's List
        </a>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= $success_msg ?></div> <!-- Preserved raw echo for <strong> tags -->
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ========================================== -->
        <!-- LEFT COLUMN: UPLOAD FORM                   -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-7">
            <div class="ultra-card h-100 p-4 p-md-5">
                <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                    <div class="section-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-file-earmark-spreadsheet-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Upload Data File</h5>
                </div>
                
                <form method="POST" action="/public/clients/import.php" enctype="multipart/form-data" class="needs-validation">
                    <?= csrf_field() ?>
                    
                    <div class="mb-4 pb-2">
                        <label class="ultra-label">Select Lead Assigner <span class="required-star">*</span></label>
                        <select name="assigned_admin_id" class="form-select ultra-input" required>
                            <option value="<?= $current_user_id ?>">Super Admin - StopLord</option>
                            <?php foreach ($adminsList as $adm): ?>
                                <option value="<?= $adm['id'] ?>">Employee: <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-2 fw-medium ms-2">
                            <i class="bi bi-info-circle me-1"></i> All leads in the uploaded file will be assigned to the selected value.
                        </div>
                    </div>

                    <div class="mb-5">
                        <label class="ultra-label">Upload CSV File <span class="required-star">*</span></label>
                        <input type="file" name="import_file" class="form-control ultra-file-input" accept=".csv" required>
                        <div class="text-muted small mt-2 fw-medium ms-2">
                            <i class="bi bi-lightbulb text-warning me-1"></i> If you are using Microsoft Excel, click <strong>File > Save As</strong> and choose <strong>CSV (Comma delimited) (*.csv)</strong>.
                        </div>
                    </div>

                    <div class="border-top pt-4 mt-2">
                        <button type="submit" class="btn-ultra-primary w-100 d-flex align-items-center justify-content-center">
                            <i class="bi bi-cloud-arrow-up-fill me-2 fs-5"></i> Process & Import Leads
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- RIGHT COLUMN: INSTRUCTIONS & TEMPLATE      -->
        <!-- ========================================== -->
        <div class="col-12 col-xl-5">
            <div class="ultra-card h-100 p-4 p-md-5" style="background: linear-gradient(145deg, #ffffff, #f8fafc);">
                <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                    <div class="section-icon-wrapper bg-success bg-opacity-10 text-success">
                        <i class="bi bi-info-square-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Import Instructions</h5>
                </div>
                
                <p class="text-dark fw-bold mb-4 fs-6">To ensure a successful import, your file must match our exact structure.</p>
                
                <ul class="ultra-check-list mb-5">
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        <div><strong>Name</strong> and <strong>Phone</strong> are mandatory fields. Rows missing these will be skipped.</div>
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        <div>Do not change the header names in the template file. Keep row 1 exactly as downloaded.</div>
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        <div>Phone numbers should be exactly 10 digits. Symbols (like +91 or -91) will be stripped automatically by the system.</div>
                    </li>
                    <li>
                        <i class="bi bi-check-circle-fill"></i>
                        <div>All new leads imported via this tool will automatically be tagged as <strong>COLD</strong> with the status <strong>New</strong>.</div>
                    </li>
                </ul>

                <div class="mt-auto">
                    <a href="/public/clients/import.php?action=download_template" class="btn-ultra-success">
                        <i class="bi bi-file-earmark-arrow-down-fill me-2 fs-5"></i> Download CSV Template
                    </a>
                </div>
            </div>
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