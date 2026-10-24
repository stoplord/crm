<?php


require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

// Strict Super Admin Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'Expense & Outflow Tracker';
$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';
$db_error = '';

// Catch PRG Success Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $success_msg = 'Expense record created successfully.';
    if ($_GET['msg'] === 'updated') $success_msg = 'Expense details updated successfully.';
    if ($_GET['msg'] === 'deleted') $success_msg = 'Expense record securely removed.';
}

// 1. Auto-Provision Schema (Crash-Proof Guarantee)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `expenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `category` VARCHAR(100) NOT NULL DEFAULT 'Operational',
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `expense_date` DATE NOT NULL,
            `expense_time` TIME NOT NULL,
            `location_vendor` VARCHAR(255) NULL,
            `description` TEXT NULL,
            `document_path` VARCHAR(255) NULL,
            `created_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            INDEX (`expense_date`),
            INDEX (`category`),
            INDEX (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    $db_error = "Database initialization error: " . $e->getMessage();
}

// Ensure Document Upload Folder Exists
$uploadDir = __DIR__ . '/../../uploads/documents/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Helper: Secure Document Upload
function handleDocumentUpload($file, $uploadDir) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        throw new Exception("Invalid file type. Allowed formats: PDF, JPG, PNG, WEBP, DOC, XLS.");
    }
    if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
        throw new Exception("File size exceeds the 10MB limit.");
    }
    $fileName = 'expense_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return '/uploads/documents/' . $fileName;
    }
    throw new Exception("Failed to upload the attached document.");
}

// 2. Handle POST Actions (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);

    try {
        if ($action === 'add_expense') {
            $title    = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES);
            $category = htmlspecialchars(trim($_POST['category'] ?? 'Operational'), ENT_QUOTES);
            $amount   = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $date     = htmlspecialchars(trim($_POST['expense_date'] ?? ''), ENT_QUOTES);
            $time     = htmlspecialchars(trim($_POST['expense_time'] ?? date('H:i:s')), ENT_QUOTES);
            $vendor   = htmlspecialchars(trim($_POST['location_vendor'] ?? ''), ENT_QUOTES);
            $desc     = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES);

            if (empty($title) || $amount === false || $amount <= 0 || empty($date)) {
                throw new Exception("Please provide a valid title, amount, and date.");
            }

            $docPath = null;
            if (!empty($_FILES['document']['name'])) {
                $docPath = handleDocumentUpload($_FILES['document'], $uploadDir);
            }

            $stmt = $pdo->prepare("
                INSERT INTO expenses 
                (title, category, amount, expense_date, expense_time, location_vendor, description, document_path, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $category, $amount, $date, $time, $vendor, $desc, $docPath, $user_id]);
            $newId = $pdo->lastInsertId();

            // Audit Trail
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $user_id, 'super_admin', 'create_expense', 'expense', $newId,
                "Logged expense '{$title}' (₹" . number_format($amount, 2) . ") in category {$category}",
                $_SERVER['REMOTE_ADDR']
            ]);

            header("Location: /public/expenses/index.php?msg=added");
            exit;

        } elseif ($action === 'edit_expense') {
            $expense_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);
            $title      = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES);
            $category   = htmlspecialchars(trim($_POST['category'] ?? 'Operational'), ENT_QUOTES);
            $amount     = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $date       = htmlspecialchars(trim($_POST['expense_date'] ?? ''), ENT_QUOTES);
            $time       = htmlspecialchars(trim($_POST['expense_time'] ?? date('H:i:s')), ENT_QUOTES);
            $vendor     = htmlspecialchars(trim($_POST['location_vendor'] ?? ''), ENT_QUOTES);
            $desc       = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES);

            if (!$expense_id || empty($title) || $amount === false || $amount <= 0 || empty($date)) {
                throw new Exception("Please provide valid expense update data.");
            }

            // Check if existing document needs retention or replacement
            $currStmt = $pdo->prepare("SELECT document_path FROM expenses WHERE id = ? AND deleted_at IS NULL");
            $currStmt->execute([$expense_id]);
            $existingDoc = $currStmt->fetchColumn();

            $docPath = $existingDoc;
            if (!empty($_FILES['document']['name'])) {
                $docPath = handleDocumentUpload($_FILES['document'], $uploadDir);
            }

            $stmt = $pdo->prepare("
                UPDATE expenses 
                SET title = ?, category = ?, amount = ?, expense_date = ?, expense_time = ?, 
                    location_vendor = ?, description = ?, document_path = ?, updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$title, $category, $amount, $date, $time, $vendor, $desc, $docPath, $expense_id]);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $user_id, 'super_admin', 'update_expense', 'expense', $expense_id,
                "Updated expense ID {$expense_id} ('{$title}', ₹" . number_format($amount, 2) . ")",
                $_SERVER['REMOTE_ADDR']
            ]);

            header("Location: /public/expenses/index.php?msg=updated");
            exit;

        } elseif ($action === 'delete_expense') {
            $expense_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);
            if (!$expense_id) {
                throw new Exception("Invalid expense record specified.");
            }

            $stmt = $pdo->prepare("UPDATE expenses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$expense_id]);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $user_id, 'super_admin', 'delete_expense', 'expense', $expense_id,
                "Soft-deleted expense ID {$expense_id}",
                $_SERVER['REMOTE_ADDR']
            ]);

            header("Location: /public/expenses/index.php?msg=deleted");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// 3. Filters & Query Formulation
$filter_category = htmlspecialchars(trim($_GET['category'] ?? ''), ENT_QUOTES);
$filter_start    = htmlspecialchars(trim($_GET['start_date'] ?? date('Y-m-01')), ENT_QUOTES);
$filter_end      = htmlspecialchars(trim($_GET['end_date'] ?? date('Y-m-d')), ENT_QUOTES);
$search_term     = htmlspecialchars(trim($_GET['q'] ?? ''), ENT_QUOTES);

$where = ["e.deleted_at IS NULL"];
$params = [];

if ($filter_category) {
    $where[] = "e.category = ?";
    $params[] = $filter_category;
}
if ($filter_start && $filter_end) {
    $where[] = "e.expense_date BETWEEN ? AND ?";
    $params[] = $filter_start;
    $params[] = $filter_end;
}
if ($search_term) {
    $where[] = "(e.title LIKE ? OR e.location_vendor LIKE ? OR e.description LIKE ?)";
    $like = '%' . $search_term . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = implode(' AND ', $where);

// 4. Fetch Operational Expenses & Admin Salary Disbursals
$expenses = [];
$salaryExpenses = [];
$totalDirectExpenses = 0;
$totalSalaryExpenses = 0;
$monthDirectExpenses = 0;
$monthSalaryExpenses = 0;

try {
    // A. Fetch Direct Expenses
    $expQuery = "
        SELECT e.*, u.name AS creator_name 
        FROM expenses e 
        LEFT JOIN users u ON e.created_by = u.id 
        WHERE $whereClause 
        ORDER BY e.expense_date DESC, e.expense_time DESC
    ";
    $stmtE = $pdo->prepare($expQuery);
    $stmtE->execute($params);
    $expenses = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    // Sum overall & current month direct expenses
    $totalDirectExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE deleted_at IS NULL")->fetchColumn();
    $monthDirectExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE deleted_at IS NULL AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn();

    // B. Fetch Admin Salary Payouts from audit_logs
    $salQuery = "
        SELECT a.id, a.target_id, a.target_type, a.description, a.created_at, u.name AS admin_name, u.email AS admin_email 
        FROM audit_logs a 
        LEFT JOIN users u ON a.target_id = u.id 
        WHERE a.action = 'payslip_issued' 
        ORDER BY a.created_at DESC
    ";
    $rawSalaries = $pdo->query($salQuery)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawSalaries as $sal) {
        $parts = explode('|', $sal['description']);
        $base = (float)($parts[0] ?? 0);
        $bonus = (float)($parts[1] ?? 0);
        $ded = (float)($parts[2] ?? 0);
        $net = (float)($parts[3] ?? ($base + $bonus - $ded));
        $note = $parts[4] ?? '';
        $monthStr = str_replace('payslip_', '', $sal['target_type']);

        $totalSalaryExpenses += $net;

        // Check if payslip is for the current month
        $salDate = strtotime($sal['created_at']);
        if (date('Y-m', $salDate) === date('Y-m')) {
            $monthSalaryExpenses += $net;
        }

        $salaryExpenses[] = [
            'id' => $sal['id'],
            'admin_name' => $sal['admin_name'] ?? 'Employee',
            'admin_email' => $sal['admin_email'] ?? '',
            'month' => $monthStr,
            'net_pay' => $net,
            'base_pay' => $base,
            'bonus' => $bonus,
            'deductions' => $ded,
            'notes' => $note,
            'disbursed_at' => $sal['created_at']
        ];
    }

} catch (PDOException $e) {
    $db_error = "Database Query Error: " . $e->getMessage();
}

$grandTotalExpenses = $totalDirectExpenses + $totalSalaryExpenses;
$grandMonthExpenses = $monthDirectExpenses + $monthSalaryExpenses;

$categories = [
    'Operational', 'Office Rent & Utilities', 'Server & Infrastructure', 
    'Software & Subscriptions', 'Travel & Fuel', 'Marketing & Ads', 
    'Vendor Payout', 'Legal & Professional', 'Miscellaneous'
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- ULTRA DESIGN SYSTEM CSS -->
<style>
    body { background-color: #f0f2f5; font-family: 'Inter', -apple-system, sans-serif; }
    
    /* Ultra Glass/Neumorphic Cards */
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; transition: transform 0.3s ease; }
    .card-hover { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .card-hover:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(0,0,0,0.08) !important; }

    /* Ultra Inputs */
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; display: inline-block; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; cursor: pointer; }
    .app-btn-icon:hover { transform: scale(1.05); }

    /* Floating Card Table */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 950px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Gradients for Outflow Cards */
    .bg-gradient-danger  { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); color: white; }
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-dark    { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; }

    /* Mobile Item Cards */
    .mobile-item-card { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-item-card:active { transform: scale(0.98); background: #f8fafc; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* iOS Segmented Tabs */
    .ultra-tabs-wrapper { display: flex; justify-content: flex-start; margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
    .ultra-tabs-wrapper::-webkit-scrollbar { display: none; }
    .ultra-tabs { background: #e2e8f0; border-radius: 100px; padding: 6px; display: inline-flex; min-width: max-content; }
    .ultra-tabs .nav-item { flex: 1; text-align: center; }
    .ultra-tabs .nav-link { border-radius: 100px; color: #64748b; font-weight: 600; font-size: 0.95rem; padding: 10px 24px; border: none; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap; }
    .ultra-tabs .nav-link.active { background: #ffffff; color: #0f172a; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }

    /* Modal Styling */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-wallet-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Expense & Outflow Tracker</h3>
                <p class="text-muted small mb-0 fw-medium">Record operational expenditures, vendor payouts & admin payroll costs.</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-ultra-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                <i class="bi bi-plus-circle me-2"></i> Add Expense
            </button>
            <!--<button onclick="window.print()" class="btn btn-ultra-secondary shadow-sm d-print-none">-->
            <!--    <i class="bi bi-printer me-1"></i> Print-->
            <!--</button>-->
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($success_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= htmlspecialchars($error_msg, ENT_QUOTES) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Schema Alert</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php endif; ?>

    <!-- 1. High-Level Outflow Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-danger text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Direct Expenses</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace">₹<?= number_format($totalDirectExpenses, 0) ?></h2>
                <small class="opacity-75">Operational & Vendors</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-warning text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Payroll</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace">₹<?= number_format($totalSalaryExpenses, 0) ?></h2>
                <small class="opacity-75">Disbursed Staff Salaries</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-dark text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">Combined Outflows</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace text-white">₹<?= number_format($grandTotalExpenses, 0) ?></h2>
                <small class="text-warning fw-bold">Cumulative Total</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ultra-card bg-gradient-primary text-white mb-0 h-100 card-hover p-4">
                <span class="small fw-bold text-uppercase opacity-75 letter-spacing-1">This Month</span>
                <h2 class="fw-bold mb-0 mt-2 font-monospace">₹<?= number_format($grandMonthExpenses, 0) ?></h2>
                <small class="opacity-75"><?= date('F Y') ?> Total</small>
            </div>
        </div>
    </div>

    <!-- 2. Segmented Tabs: Operational Expenses vs Payroll Outflows -->
    <div class="ultra-tabs-wrapper px-2">
        <ul class="nav ultra-tabs shadow-sm" id="expenseTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="operational-tab" data-bs-toggle="tab" data-bs-target="#operationalPane" type="button" role="tab">
                    <i class="bi bi-receipt-cutoff me-1"></i> Expenses (<?= count($expenses) ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="salary-tab" data-bs-toggle="tab" data-bs-target="#salaryPane" type="button" role="tab">
                    <i class="bi bi-people-fill me-1"></i> Salaries (<?= count($salaryExpenses) ?>)
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="expenseTabsContent">
        
        <!-- ============================================================ -->
        <!-- TAB 1: DIRECT EXPENSES & RECEIPTS                            -->
        <!-- ============================================================ -->
        <div class="tab-pane fade show active" id="operationalPane" role="tabpanel">
            
            <!-- Filters & Search -->
            <div class="ultra-card mb-4 d-print-none">
                <form method="GET" action="/public/expenses/index.php" class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="ultra-label">Search Expense / Vendor</label>
                        <input type="text" name="q" class="form-control ultra-input" placeholder="Title, Vendor or Details" value="<?= $search_term ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="ultra-label">Category</label>
                        <select name="category" class="form-select ultra-input" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="ultra-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control ultra-input" value="<?= $filter_start ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="ultra-label">End Date</label>
                        <input type="date" name="end_date" class="form-control ultra-input" value="<?= $filter_end ?>">
                    </div>
                    <div class="col-6 col-md-1 d-flex gap-2 align-items-end">
                        <button type="submit" class="btn btn-ultra-primary w-100"><i class="bi bi-funnel"></i></button>
                        <a href="/public/expenses/index.php" class="btn btn-ultra-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                    </div>
                </form>
            </div>

            <!-- List Count & Mobile Instruction -->
            <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                <h6 class="fw-bold text-dark mb-0">Direct Expenditures: <span class="text-danger"><?= count($expenses) ?> records</span></h6>
                <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap record to inspect & manage</span>-->
            </div>

            <!-- Mobile View: Card List with Popup Modal -->
            <div class="d-block d-md-none">
                <?php if (empty($expenses)): ?>
                    <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                        <i class="bi bi-receipt text-muted fs-1 d-block mb-2"></i>
                        <h6 class="fw-bold text-dark mb-0">No expenses recorded</h6>
                        <small class="text-muted">Use the "Add Expense" button above to add one.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($expenses as $exp): 
                        $expDateFmt = date('d M, Y', strtotime($exp['expense_date']));
                        $expTimeFmt = date('h:i A', strtotime($exp['expense_time']));
                        $amtFmt = number_format((float)$exp['amount'], 2);
                    ?>
                        <div class="mobile-item-card"
                             onclick="openExpenseDetailModal(
                                 <?= (int)$exp['id'] ?>,
                                 '<?= htmlspecialchars(addslashes($exp['title']), ENT_QUOTES) ?>',
                                 '<?= htmlspecialchars(addslashes($exp['category']), ENT_QUOTES) ?>',
                                 '₹<?= $amtFmt ?>',
                                 <?= (float)$exp['amount'] ?>,
                                 '<?= $expDateFmt ?>',
                                 '<?= $expTimeFmt ?>',
                                 '<?= $exp['expense_date'] ?>',
                                 '<?= $exp['expense_time'] ?>',
                                 '<?= htmlspecialchars(addslashes($exp['location_vendor'] ?? 'N/A'), ENT_QUOTES) ?>',
                                 '<?= htmlspecialchars(addslashes($exp['description'] ?? ''), ENT_QUOTES) ?>',
                                 '<?= htmlspecialchars(addslashes($exp['document_path'] ?? ''), ENT_QUOTES) ?>'
                             )">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3 fw-bold" style="width: 44px; height: 44px; font-size: 1.1rem; flex-shrink: 0;">
                                    <i class="bi bi-wallet2"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($exp['title'], ENT_QUOTES) ?></div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge bg-light text-dark border px-2 py-0.5" style="font-size: 0.72rem;">
                                            <?= htmlspecialchars($exp['category'], ENT_QUOTES) ?>
                                        </span>
                                        <?php if (!empty($exp['document_path'])): ?>
                                            <span class="text-success small"><i class="bi bi-paperclip"></i> Receipt</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-danger font-monospace fs-6">₹<?= $amtFmt ?></div>
                                <small class="text-muted d-block"><?= $expDateFmt ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Desktop View: Full Responsive Table -->
            <div class="app-table-wrapper d-none d-md-block">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Expense / Reason (Why)</th>
                            <th>Category</th>
                            <th>When (Date & Time)</th>
                            <th>Where (Vendor / Location)</th>
                            <th>Amount</th>
                            <th>Document Receipt</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr style="box-shadow: none; background: transparent;">
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary"></i>
                                    No expense records found matching criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $exp): 
                                $expDateFmt = date('d M, Y', strtotime($exp['expense_date']));
                                $expTimeFmt = date('h:i A', strtotime($exp['expense_time']));
                                $amtFmt = number_format((float)$exp['amount'], 2);
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($exp['title'], ENT_QUOTES) ?></div>
                                        <?php if (!empty($exp['description'])): ?>
                                            <small class="text-muted d-inline-block text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($exp['description'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($exp['description'], ENT_QUOTES) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill">
                                            <?= htmlspecialchars($exp['category'], ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark d-block"><?= $expDateFmt ?></span>
                                        <small class="text-muted font-monospace"><?= $expTimeFmt ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-medium text-dark"><i class="bi bi-geo-alt text-danger me-1"></i><?= htmlspecialchars($exp['location_vendor'] ?: 'General Office', ENT_QUOTES) ?></span>
                                    </td>
                                    <td>
                                        <span class="font-monospace fw-bold text-danger fs-5 bg-danger bg-opacity-10 px-3 py-1 rounded-3">
                                            ₹<?= $amtFmt ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($exp['document_path'])): ?>
                                            <a href="<?= htmlspecialchars($exp['document_path'], ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-light border rounded-pill shadow-sm px-3 fw-bold text-primary">
                                                <i class="bi bi-file-earmark-arrow-down-fill me-1"></i> View Doc
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">- No Document -</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="app-btn-icon bg-info bg-opacity-10 text-info" title="Edit Expense"
                                                    onclick="openEditExpenseModal(
                                                        <?= (int)$exp['id'] ?>,
                                                        '<?= htmlspecialchars(addslashes($exp['title']), ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars(addslashes($exp['category']), ENT_QUOTES) ?>',
                                                        <?= (float)$exp['amount'] ?>,
                                                        '<?= $exp['expense_date'] ?>',
                                                        '<?= $exp['expense_time'] ?>',
                                                        '<?= htmlspecialchars(addslashes($exp['location_vendor'] ?? ''), ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars(addslashes($exp['description'] ?? ''), ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars(addslashes($exp['document_path'] ?? ''), ENT_QUOTES) ?>'
                                                    )">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            
                                            <form method="POST" onsubmit="return confirm('Delete this expense permanently?');" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_expense">
                                                <input type="hidden" name="expense_id" value="<?= (int)$exp['id'] ?>">
                                                <button type="submit" class="app-btn-icon bg-danger bg-opacity-10 text-danger" title="Delete Record">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- ============================================================ -->
        <!-- TAB 2: ADMIN PAYROLL & SALARY OUTFLOWS                       -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="salaryPane" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                <h6 class="fw-bold text-dark mb-0">Salaries Disbursed: <span class="text-warning"><?= count($salaryExpenses) ?> payouts</span></h6>
                <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap salary for details</span>-->
            </div>

            <!-- Mobile View: Admin Salary Outflow Card List -->
            <div class="d-block d-md-none">
                <?php if (empty($salaryExpenses)): ?>
                    <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                        <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                        <h6 class="fw-bold text-dark mb-0">No staff salaries issued yet</h6>
                        <small class="text-muted">Generate slips via the HR Central module.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($salaryExpenses as $sal): 
                        $monthLabel = date('F Y', strtotime($sal['month'] . '-01'));
                        $netFmt = number_format((float)$sal['net_pay'], 2);
                        $baseFmt = number_format((float)$sal['base_pay'], 2);
                        $bonusFmt = number_format((float)$sal['bonus'], 2);
                        $dedFmt = number_format((float)$sal['deductions'], 2);
                        $disbDateFmt = date('d M, Y', strtotime($sal['disbursed_at']));
                    ?>
                        <div class="mobile-item-card"
                             onclick="openSalaryDetailModal(
                                 '<?= htmlspecialchars(addslashes($sal['admin_name']), ENT_QUOTES) ?>',
                                 '<?= htmlspecialchars(addslashes($sal['admin_email']), ENT_QUOTES) ?>',
                                 '<?= $monthLabel ?>',
                                 '₹<?= $netFmt ?>',
                                 '₹<?= $baseFmt ?>',
                                 '₹<?= $bonusFmt ?>',
                                 '₹<?= $dedFmt ?>',
                                 '<?= $disbDateFmt ?>',
                                 '<?= htmlspecialchars(addslashes($sal['notes'] ?: 'Standard Monthly Salary'), ENT_QUOTES) ?>'
                             )">
                            <div class="d-flex align-items-center">
                                <div class="app-avatar-fallback me-3">
                                    <?= strtoupper(substr($sal['admin_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($sal['admin_name'], ENT_QUOTES) ?></div>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 rounded-pill small mt-1">
                                        <?= $monthLabel ?>
                                    </span>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success font-monospace fs-6">₹<?= $netFmt ?></div>
                                <small class="text-muted d-block"><?= $disbDateFmt ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Desktop View: Full Responsive Table -->
            <div class="app-table-wrapper d-none d-md-block">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Salary Month</th>
                            <th>Basic Pay</th>
                            <th>Bonus / Deductions</th>
                            <th>Net Paid Amount</th>
                            <th>Disbursed Date</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($salaryExpenses)): ?>
                            <tr style="box-shadow: none; background: transparent;">
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                                    No staff salaries issued yet. Generate slips via the HR Central module.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salaryExpenses as $sal): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($sal['admin_name'], ENT_QUOTES) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($sal['admin_email'], ENT_QUOTES) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-1.5 fw-bold rounded-pill">
                                            <?= date('F Y', strtotime($sal['month'] . '-01')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-monospace fw-semibold text-dark">₹<?= number_format((float)$sal['base_pay'], 2) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-success fw-bold font-monospace">+₹<?= number_format((float)$sal['bonus'], 2) ?></small> / 
                                        <small class="text-danger fw-bold font-monospace">-₹<?= number_format((float)$sal['deductions'], 2) ?></small>
                                    </td>
                                    <td>
                                        <span class="font-monospace fw-bold text-success fs-5 bg-success bg-opacity-10 px-3 py-1 rounded-3">
                                            ₹<?= number_format((float)$sal['net_pay'], 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-dark small"><i class="bi bi-clock-history me-1"></i> <?= date('d M, Y', strtotime($sal['disbursed_at'])) ?></span>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <small class="text-muted text-truncate d-inline-block" style="max-width: 200px;"><?= htmlspecialchars($sal['notes'] ?: 'Standard Monthly Salary', ENT_QUOTES) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- ============================================================ -->
<!-- MODAL: ADD NEW EXPENSE                                       -->
<!-- ============================================================ -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="/public/expenses/index.php" enctype="multipart/form-data" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_expense">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Add Expense Record</h4>
                            <small class="text-muted fw-medium">Record when, where, why and upload physical receipt</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="ultra-label">Expense Reason / Title (Why) <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control ultra-input" required>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="ultra-label">Amount (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control ultra-input font-monospace text-danger fs-5"  required>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Category</label>
                        <select name="category" class="form-select ultra-input" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Where (Vendor / Place / Location)</label>
                        <input type="text" name="location_vendor" class="form-control ultra-input">
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">When (Date) <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" class="form-control ultra-input" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">When (Time)</label>
                        <input type="time" name="expense_time" class="form-control ultra-input font-monospace" value="<?= date('H:i') ?>">
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Detailed Notes / Objective</label>
                        <textarea name="description" class="form-control ultra-input" rows="3" ></textarea>
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Attach Bill / Invoice / Voucher</label>
                        <input type="file" name="document" class="form-control ultra-input py-2">
                        <div class="text-muted small mt-1 ms-1"><i class="bi bi-info-circle me-1"></i> Accepted: PDF, JPG, PNG, WEBP, DOCX, XLSX (Max 10MB)</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-check2-circle me-1"></i> Save Expense</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: EDIT EXPENSE RECORD                                   -->
<!-- ============================================================ -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="/public/expenses/index.php" enctype="multipart/form-data" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_expense">
            <input type="hidden" name="expense_id" id="editExpenseId">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.4rem;">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Edit Expense Details</h4>
                            <small class="text-muted fw-medium">Modify outflow metadata or replace invoice document</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="ultra-label">Expense Reason / Title (Why) <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editExpenseTitle" class="form-control ultra-input" required>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="ultra-label">Amount (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="editExpenseAmount" class="form-control ultra-input font-monospace text-danger fs-5" required>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Category</label>
                        <select name="category" id="editExpenseCategory" class="form-select ultra-input" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="ultra-label">Where (Vendor / Location)</label>
                        <input type="text" name="location_vendor" id="editExpenseVendor" class="form-control ultra-input">
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">When (Date) <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" id="editExpenseDate" class="form-control ultra-input" required>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="ultra-label">When (Time)</label>
                        <input type="time" name="expense_time" id="editExpenseTime" class="form-control ultra-input font-monospace">
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Detailed Notes / Objective</label>
                        <textarea name="description" id="editExpenseDesc" class="form-control ultra-input" rows="3"></textarea>
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Replace Document / Bill (Optional)</label>
                        <input type="file" name="document" class="form-control ultra-input py-2">
                        <div id="editExistingDocPreview" class="mt-2 small"></div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-save me-1"></i> Update Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE INSPECT EXPENSE DETAILS POPUP                  -->
<!-- ============================================================ -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div>
                            <h5 id="viewExpTitle" class="fw-bold text-dark mb-0"></h5>
                            <span id="viewExpCategory" class="badge bg-secondary bg-opacity-10 text-dark border px-2 py-0.5 rounded-pill small mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Amount Metric -->
                <div class="metric-stat-card bg-danger bg-opacity-10 border-danger border-opacity-25 mb-3 text-center p-3">
                    <span class="metric-stat-label text-danger">Total Outflow Amount</span>
                    <h3 id="viewExpAmount" class="fw-bold text-danger mb-0 font-monospace"></h3>
                </div>

                <!-- When & Where Grid -->
                <div class="d-flex flex-column gap-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history text-primary me-2"></i> When</span>
                        <span id="viewExpDateTime" class="fw-bold text-dark small font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-geo-alt text-danger me-2"></i> Where (Vendor)</span>
                        <span id="viewExpVendor" class="fw-bold text-dark small"></span>
                    </div>
                </div>

                <!-- Description / Why -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Expense Justification (Why)</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-3">
                    <div id="viewExpDesc" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.5;"></div>
                </div>

                <!-- Receipt Document View -->
                <div id="viewExpDocContainer" class="mb-4 d-none">
                    <a id="viewExpDocLink" href="#"  class="btn btn-outline-success w-100 py-2.5 rounded-pill shadow-sm fw-bold">
                        <i class="bi bi-file-earmark-arrow-down-fill me-1"></i> Open Receipt Document
                    </a>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2">
                    <button type="button" id="viewEditTriggerBtn" class="btn btn-ultra-primary flex-grow-1 py-2.5">
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <form method="POST" action="/public/expenses/index.php" onsubmit="return confirm('Delete this expense permanently?');" class="flex-grow-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_expense">
                        <input type="hidden" name="expense_id" id="viewDeleteExpId">
                        <button type="submit" class="btn btn-light border text-danger w-100 py-2.5 rounded-pill fw-bold">
                            <i class="bi bi-trash3-fill me-1"></i> Delete
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE INSPECT ADMIN SALARY DETAILS POPUP             -->
<!-- ============================================================ -->
<div class="modal fade" id="viewSalaryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Admin Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="salAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="salAdminName" class="fw-bold text-dark mb-0"></h5>
                            <small id="salAdminEmail" class="text-muted fw-medium"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Net Payout Metric -->
                <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25 mb-4 text-center p-3">
                    <span class="metric-stat-label text-success">Net Salary Disbursed</span>
                    <h3 id="salNetPay" class="fw-bold text-success mb-1 font-monospace"></h3>
                    <span id="salMonthBadge" class="badge bg-primary rounded-pill px-3 py-1 font-monospace small"></span>
                </div>

                <!-- Salary Breakdown Grid -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">Earnings & Deductions</h6>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="metric-stat-card text-center p-2">
                            <span class="metric-stat-label">Basic Pay</span>
                            <div id="salBasePay" class="fw-bold text-dark small font-monospace"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-stat-card text-center p-2">
                            <span class="metric-stat-label text-success">+ Bonus</span>
                            <div id="salBonus" class="fw-bold text-success small font-monospace"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-stat-card text-center p-2">
                            <span class="metric-stat-label text-danger">- Deduct</span>
                            <div id="salDeductions" class="fw-bold text-danger small font-monospace"></div>
                        </div>
                    </div>
                </div>

                <!-- Disbursed Date -->
                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border mb-3">
                    <span class="small fw-semibold text-muted"><i class="bi bi-clock-history text-primary me-2"></i> Disbursed On</span>
                    <span id="salDisbursedAt" class="fw-bold text-dark small font-monospace"></span>
                </div>

                <!-- Notes / Remarks -->
                <h6 class="text-muted small fw-bold text-uppercase mb-2 letter-spacing-1">HR Remarks / Notes</h6>
                <div class="metric-stat-card bg-light border p-3 rounded-4 mb-4">
                    <div id="salNotes" class="text-dark small fw-medium" style="white-space: pre-wrap; line-height: 1.5;"></div>
                </div>

                <button type="button" class="btn btn-ultra-secondary w-100 py-3" data-bs-dismiss="modal">Close</button>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Opens and Populates Edit Expense Modal
 */
function openEditExpenseModal(id, title, category, amount, date, time, vendor, desc, docPath) {
    document.getElementById('editExpenseId').value = id;
    document.getElementById('editExpenseTitle').value = title;
    document.getElementById('editExpenseCategory').value = category;
    document.getElementById('editExpenseAmount').value = amount;
    document.getElementById('editExpenseDate').value = date;
    document.getElementById('editExpenseTime').value = time;
    document.getElementById('editExpenseVendor').value = vendor;
    document.getElementById('editExpenseDesc').value = desc;

    const preview = document.getElementById('editExistingDocPreview');
    if (docPath && docPath.trim() !== '') {
        preview.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i> Current document attached: <a href="' + docPath + '" target="_blank" class="fw-bold">View Existing File</a></span>';
    } else {
        preview.innerHTML = '<span class="text-muted"><i class="bi bi-dash-circle me-1"></i> No document currently attached.</span>';
    }

    const editModal = new bootstrap.Modal(document.getElementById('editExpenseModal'));
    editModal.show();
}

/**
 * Opens and Populates Mobile Inspection Modal for Direct Expense
 */
function openExpenseDetailModal(id, title, category, amountFormatted, amountNum, dateFmt, timeFmt, dateRaw, timeRaw, vendor, desc, docPath) {
    document.getElementById('viewExpTitle').textContent = title;
    document.getElementById('viewExpCategory').textContent = category;
    document.getElementById('viewExpAmount').textContent = amountFormatted;
    document.getElementById('viewExpDateTime').textContent = dateFmt + ' • ' + timeFmt;
    document.getElementById('viewExpVendor').textContent = vendor && vendor.trim() !== '' ? vendor : 'General Office';
    document.getElementById('viewExpDesc').textContent = desc && desc.trim() !== '' ? desc : 'No justification or notes logged for this expense.';
    document.getElementById('viewDeleteExpId').value = id;

    const docContainer = document.getElementById('viewExpDocContainer');
    const docLink = document.getElementById('viewExpDocLink');
    if (docPath && docPath.trim() !== '') {
        docLink.href = docPath;
        docContainer.classList.remove('d-none');
    } else {
        docContainer.classList.add('d-none');
    }

    // Connect edit trigger
    document.getElementById('viewEditTriggerBtn').onclick = function() {
        const vModal = bootstrap.Modal.getInstance(document.getElementById('viewExpenseModal'));
        if (vModal) vModal.hide();
        openEditExpenseModal(id, title, category, amountNum, dateRaw, timeRaw, vendor, desc, docPath);
    };

    const modal = new bootstrap.Modal(document.getElementById('viewExpenseModal'));
    modal.show();
}

/**
 * Opens and Populates Mobile Inspection Modal for Admin Salary Outflow
 */
function openSalaryDetailModal(adminName, adminEmail, monthLabel, netPay, basePay, bonus, deductions, disbursedAt, notes) {
    document.getElementById('salAvatar').textContent = adminName.charAt(0).toUpperCase();
    document.getElementById('salAdminName').textContent = adminName;
    document.getElementById('salAdminEmail').textContent = adminEmail ? adminEmail : 'No email logged';
    document.getElementById('salNetPay').textContent = netPay;
    document.getElementById('salMonthBadge').textContent = monthLabel;
    document.getElementById('salBasePay').textContent = basePay;
    document.getElementById('salBonus').textContent = '+' + bonus;
    document.getElementById('salDeductions').textContent = '-' + deductions;
    document.getElementById('salDisbursedAt').textContent = disbursedAt;
    document.getElementById('salNotes').textContent = notes ? notes : 'Standard Monthly Salary Disbursal';

    const modal = new bootstrap.Modal(document.getElementById('viewSalaryModal'));
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