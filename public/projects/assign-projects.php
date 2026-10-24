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

$pageTitle = 'System Settings & Project Assignments';
$success_msg = '';
$error_msg = '';
$db_error = '';

// Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'reassigned') {
    $success_msg = 'Project successfully reassigned to the selected developer.';
}

// 1. Handle Project Reassignment POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);

    try {
        if ($action === 'reassign_project') {
            $client_id     = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
            $new_admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);

            if (!$client_id || !$new_admin_id) {
                throw new Exception("Invalid client or developer selection.");
            }

            // Verify selected user is a developer (or active user with developer role)
            $checkDev = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'developer' AND deleted_at IS NULL");
            $checkDev->execute([$new_admin_id]);
            if (!$checkDev->fetch()) {
                throw new Exception("Selected user must have the Developer role assigned.");
            }

            $pdo->beginTransaction();

            // Update assigned admin/developer for the project (Converted Client)
            $stmt = $pdo->prepare("UPDATE clients SET assigned_admin_id = ?, updated_at = NOW() WHERE id = ? AND deal_status = 'Converted'");
            $stmt->execute([$new_admin_id, $client_id]);

            // Audit Log Entry
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $_SESSION['user_id'], $_SESSION['role'], 'reassign_project', 'client', $client_id,
                "Reassigned converted project ID {$client_id} to Developer ID {$new_admin_id}", $_SERVER['REMOTE_ADDR']
            ]);

            $pdo->commit();
            header("Location: /public/settings/index.php?msg=reassigned");
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = $e->getMessage();
    }
}

// 2. Fetch Data Safely with Try-Catch
$projectsList = [];
$developersList = [];

try {
    // Fetch all active developers for assignment dropdowns (Strictly role = 'developer')
    $developersList = $pdo->query("SELECT id, name, email FROM users WHERE role = 'developer' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all Converted Clients (Projects) and their current assigned developer/admin
    $query = "
        SELECT 
            c.id, c.client_code, c.name AS client_name, c.final_price, c.status, c.updated_at,
            c.assigned_admin_id, u.name AS assigned_admin_name
        FROM clients c
        LEFT JOIN users u ON c.assigned_admin_id = u.id
        WHERE c.deal_status = 'Converted' AND c.deleted_at IS NULL
        ORDER BY c.updated_at DESC
    ";
    $projectsList = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Please ensure the `clients` and `users` tables exist.";
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
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Action Icons */
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; cursor: pointer; }
    .app-btn-icon:hover { transform: scale(1.05); }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 900px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* iOS Segmented Tabs */
    .ultra-tabs-wrapper { display: flex; justify-content: flex-start; margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
    .ultra-tabs-wrapper::-webkit-scrollbar { display: none; }
    .ultra-tabs { background: #e2e8f0; border-radius: 100px; padding: 6px; display: inline-flex; min-width: max-content; }
    .ultra-tabs .nav-item { flex: 1; text-align: center; }
    .ultra-tabs .nav-link { border-radius: 100px; color: #64748b; font-weight: 600; font-size: 0.95rem; padding: 10px 24px; border: none; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap; }
    .ultra-tabs .nav-link.active { background: #ffffff; color: #0f172a; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }

    /* Mobile Cards / List Items */
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
                <i class="bi bi-gear-wide-connected fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Project Settings & Control Center</h3>
                <p class="text-muted small mb-0 fw-medium">Manage project ownership reassignments to developers and system parameters.</p>
            </div>
        </div>
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

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- NAVIGATION TABS -->
        <div class="ultra-tabs-wrapper px-2">
            <ul class="nav ultra-tabs shadow-sm" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="projects-tab" data-bs-toggle="tab" data-bs-target="#projectsPane" type="button" role="tab">
                        <i class="bi bi-kanban me-1"></i> Developer Assignments (<?= count($projectsList) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="configs-tab" data-bs-toggle="tab" data-bs-target="#configsPane" type="button" role="tab">
                        <i class="bi bi-sliders me-1"></i> Configs
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="settingsTabsContent">
            
            <!-- ============================================================ -->
            <!-- TAB 1: PROJECT & DEVELOPER ASSIGNMENTS                       -->
            <!-- ============================================================ -->
            <div class="tab-pane fade show active" id="projectsPane" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                    <h6 class="fw-bold text-dark mb-0">Active Project Ownerships: <span class="text-primary"><?= count($projectsList) ?></span></h6>
                    <span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap project to assign developer</span>
                </div>

                <!-- 1. Mobile View: Project Cards List -->
                <div class="d-block d-md-none">
                    <?php if (empty($projectsList)): ?>
                        <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                            <i class="bi bi-kanban text-muted fs-1 d-block mb-2"></i>
                            <h6 class="fw-bold text-dark mb-1">No converted projects found</h6>
                            <p class="text-muted small mb-0">No converted projects available for developer assignment.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($projectsList as $proj): 
                            $clientTitle = htmlspecialchars(addslashes($proj['client_code'] . ' - ' . $proj['client_name']), ENT_QUOTES);
                            $fmtPrice = number_format((float)$proj['final_price'], 2);
                        ?>
                            <div class="mobile-item-card"
                                 onclick="openProjectDetailsModal(
                                     <?= (int)$proj['id'] ?>,
                                     '<?= htmlspecialchars(addslashes($proj['client_code']), ENT_QUOTES) ?>',
                                     '<?= htmlspecialchars(addslashes($proj['client_name']), ENT_QUOTES) ?>',
                                     '₹<?= $fmtPrice ?>',
                                     '<?= htmlspecialchars(addslashes($proj['status']), ENT_QUOTES) ?>',
                                     '<?= htmlspecialchars(addslashes($proj['assigned_admin_name'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                                     <?= (int)$proj['assigned_admin_id'] ?>,
                                     '<?= $clientTitle ?>'
                                 )">
                                <div class="d-flex align-items-center">
                                    <div class="app-avatar-fallback me-3">
                                        <?= strtoupper(substr($proj['client_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($proj['client_name'], ENT_QUOTES) ?></div>
                                        <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                            #<?= htmlspecialchars($proj['client_code'], ENT_QUOTES) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success font-monospace fs-6">₹<?= $fmtPrice ?></div>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-2 py-1 mt-1 small">
                                        <?= htmlspecialchars($proj['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- 2. Desktop View: Full Interactive Table -->
                <div class="app-table-wrapper d-none d-md-block">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Project Code & Client</th>
                                <th>Project Value</th>
                                <th>Current Phase</th>
                                <th>Currently Assigned Developer</th>
                                <th class="text-end pe-4">Assign Developer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($projectsList)): ?>
                                <tr style="box-shadow: none; background: transparent;">
                                    <td colspan="5" class="text-center py-5 text-muted">No converted projects available for developer assignment.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($projectsList as $proj): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="app-avatar-fallback me-3">
                                                    <?= strtoupper(substr($proj['client_name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-primary">
                                                        <a href="/public/projects/manage.php?id=<?= (int)$proj['id'] ?>" class="text-decoration-none text-dark">
                                                            <?= htmlspecialchars($proj['client_name'], ENT_QUOTES) ?>
                                                        </a>
                                                    </div>
                                                    <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                                        #<?= htmlspecialchars($proj['client_code'], ENT_QUOTES) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="font-monospace fw-bold text-success fs-5 bg-success bg-opacity-10 px-3 py-1 rounded-3">
                                                ₹<?= number_format((float)$proj['final_price'], 2) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-3 py-2 fw-semibold">
                                                <?= htmlspecialchars($proj['status'], ENT_QUOTES) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                                <i class="bi bi-code-slash me-1 text-primary"></i> <?= htmlspecialchars($proj['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <form method="POST" action="/public/settings/index.php" class="d-inline-flex gap-2 align-items-center justify-content-end">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reassign_project">
                                                <input type="hidden" name="client_id" value="<?= (int)$proj['id'] ?>">
                                                
                                                <select name="admin_id" class="form-select form-select-sm ultra-input py-1 px-3" style="width: 190px; height: 40px;" required>
                                                    <?php foreach ($developersList as $dev): ?>
                                                        <option value="<?= $dev['id'] ?>" <?= $proj['assigned_admin_id'] == $dev['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($dev['name'], ENT_QUOTES) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-ultra-primary py-2 px-3" title="Save Assignment">
                                                    <i class="bi bi-check-lg me-1"></i> Update
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- TAB 2: SYSTEM CONFIGURATIONS                                 -->
            <!-- ============================================================ -->
            <div class="tab-pane fade" id="configsPane" role="tabpanel">
                <div class="row g-4">
                    <div class="col-12 col-xl-6">
                        <div class="ultra-card h-100 p-4 p-md-5">
                            <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.3rem;">
                                    <i class="bi bi-hdd-rack-fill"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">Server & Environment</h5>
                            </div>
                            
                            <div class="d-flex flex-column gap-3">
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">PHP Version</span>
                                    <span class="font-monospace fw-bold text-dark bg-white px-3 py-1 rounded-pill border"><?= PHP_VERSION ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Server Software</span>
                                    <span class="font-monospace fw-bold text-dark small text-truncate" style="max-width: 220px;" title="<?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', ENT_QUOTES) ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Database Driver</span>
                                    <span class="font-monospace fw-bold text-dark">PDO (MySQL)</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Session Status</span>
                                    <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-shield-check me-1"></i> Active & Secure</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="ultra-card h-100 p-4 p-md-5">
                            <div class="d-flex align-items-center border-bottom pb-4 mb-4">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.3rem;">
                                    <i class="bi bi-shield-lock-fill"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-0">Security & Targets</h5>
                            </div>
                            
                            <div class="d-flex flex-column gap-3">
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Daily Work Target</span>
                                    <span class="font-monospace fw-bold text-dark">6 Hours (21,600s)</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Session Timeout</span>
                                    <span class="font-monospace fw-bold text-dark">Enforced (auth.php)</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">Upload Directories</span>
                                    <span class="font-monospace text-muted small text-truncate" style="max-width: 220px;" title="/uploads/profiles/, /uploads/documents/">
                                        /uploads/profiles/, /docs/
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                                    <span class="text-muted fw-semibold small">CSRF Token Protection</span>
                                    <span class="badge bg-primary rounded-pill px-3 py-2"><i class="bi bi-lock-fill me-1"></i> Enabled (HMAC)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE PROJECT DETAILS & REASSIGNMENT POPUP           -->
<!-- ============================================================ -->
<div class="modal fade" id="projectDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Project Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mProjAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mProjName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mProjCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Project Metrics Card -->
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25 text-center">
                            <span class="metric-stat-label text-success">Total Value</span>
                            <h4 id="mProjValue" class="fw-bold text-success mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card text-center">
                            <span class="metric-stat-label">Phase Status</span>
                            <h6 id="mProjPhase" class="fw-bold text-dark mb-0"></h6>
                        </div>
                    </div>
                </div>

                <!-- Reassignment Form -->
                <div class="bg-light p-3 rounded-4 border mb-4">
                    <span class="small fw-bold text-uppercase text-muted d-block mb-2">Assign Project to Developer</span>
                    <form method="POST" action="/public/settings/index.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reassign_project">
                        <input type="hidden" name="client_id" id="mReassignClientId">
                        
                        <div class="mb-3">
                            <select name="admin_id" id="mReassignAdminSelect" class="form-select ultra-input" required>
                                <?php foreach ($developersList as $dev): ?>
                                    <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-ultra-primary w-100 py-3 shadow-sm">
                            <i class="bi bi-check2-circle me-2"></i> Confirm Assignment
                        </button>
                    </form>
                </div>

                <a id="mProjManageBtn" href="#" class="btn btn-ultra-secondary w-100 py-3">
                    <i class="bi bi-kanban me-2 text-primary"></i> Open Project Workspace
                </a>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Populates and triggers the mobile Project Details & Reassignment modal.
 */
function openProjectDetailsModal(id, code, name, value, phase, currentAdmin, currentAdminId, clientTitle) {
    document.getElementById('mProjAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mProjName').textContent = name;
    document.getElementById('mProjCode').textContent = '#' + code;
    document.getElementById('mProjValue').textContent = value;
    document.getElementById('mProjPhase').textContent = phase;
    
    document.getElementById('mReassignClientId').value = id;
    document.getElementById('mReassignAdminSelect').value = currentAdminId;
    document.getElementById('mProjManageBtn').href = '/public/projects/manage.php?id=' + id;

    const modal = new bootstrap.Modal(document.getElementById('projectDetailsModal'));
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