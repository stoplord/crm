<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

// Strict Manual Role Check (Prevents crashes if helper function is missing)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: /public/dashboard.php?error=unauthorized");
    exit;
}

$pageTitle = 'Admin Management Directory';
$success_msg = '';
$error_msg = '';

// Catch PRG Success Messages
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success_msg = "Admin account has been successfully removed.";
}

// Handle Form Submissions (Delete Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $action = sanitizeInput($_POST['action'] ?? '');
    $admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
    
    if ($action === 'delete_admin' && $admin_id) {
        // Prevent deleting oneself
        if ($admin_id === $_SESSION['user_id']) {
            $error_msg = "You cannot delete your own Super Admin account.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Soft delete the admin
                $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ? AND role = 'admin'");
                $stmt->execute([$admin_id]);
                
                // Log the action
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([
                    $_SESSION['user_id'], 
                    $_SESSION['role'], 
                    'delete_admin', 
                    'user', 
                    $admin_id, 
                    "Soft-deleted Admin ID {$admin_id}", 
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $pdo->commit();
                
                // Redirect to prevent form resubmission on refresh (PRG Pattern)
                header("Location: /public/admins/index.php?msg=deleted");
                exit;
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Setup Filters
$search = sanitizeInput($_GET['q'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? '');

$where = ["u.role = 'admin'", "u.deleted_at IS NULL"];
$params = [];

if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter && in_array($status_filter, ['active', 'inactive'])) {
    $where[] = "u.status = ?";
    $params[] = $status_filter;
}

$whereClause = implode(' AND ', $where);

// Fetch Admins securely with a Try-Catch to prevent schema-related 500 crashes
$admins = [];
$db_error = '';

try {
    $query = "
        SELECT 
            u.id, u.name, u.email, u.mobile, u.status, u.created_at,
            p.profile_photo, p.date_of_joining,
            (SELECT COUNT(*) FROM clients WHERE assigned_admin_id = u.id AND deleted_at IS NULL) as client_count
        FROM users u
        LEFT JOIN admin_profiles p ON u.id = p.user_id
        WHERE $whereClause
        ORDER BY u.name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // If the database schema is missing tables/columns, catch it gracefully
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Please ensure the <code>admin_profiles</code> and <code>clients</code> tables exist with the correct columns.";
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
    
    /* Search Bar Specific */
    .ultra-search-wrapper { position: relative; display: flex; align-items: center; }
    .ultra-search-icon { position: absolute; left: 18px; color: #64748b; font-size: 1.1rem; }
    .ultra-search-input { padding-left: 48px !important; }

    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
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

    /* Avatars & Badges */
    .app-avatar { width: 46px; height: 46px; border-radius: 14px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
    .app-avatar-fallback { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }
    .app-badge-soft { padding: 0.4rem 0.9rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.3px; }
    .app-badge-success { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .app-badge-secondary { background: rgba(100, 116, 139, 0.15); color: #475569; }

    /* Mobile Admin List Card */
    .mobile-admin-item { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-admin-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Modal Styling */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; height: 100%; display: flex; flex-direction: column; justify-content: center; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-people-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Employee Directory</h3>
                <p class="text-muted small mb-0 fw-medium">Manage Employees.</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="/public/reports/performance.php" class="btn btn-ultra-secondary shadow-sm">
                <i class="bi bi-graph-up text-primary me-2"></i> Performance
            </a>
            <a href="/public/admins/create.php" class="btn btn-ultra-primary shadow-sm">
                <i class="bi bi-person-plus-fill me-2"></i> Add Employee
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i> 
            <div class="flex-grow-1"><?= escapeOutput($success_msg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-3 d-flex align-items-center alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i> 
            <div class="flex-grow-1"><?= escapeOutput($error_msg) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Search & Filter Card -->
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/admins/index.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-6">
                    <label class="ultra-label">Search Employees</label>
                    <div class="ultra-search-wrapper">
                        <i class="bi bi-search ultra-search-icon"></i>
                        <input type="text" name="q" class="form-control ultra-input ultra-search-input" placeholder="Search by name, email, mobile" value="<?= escapeOutput($search) ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3 col-lg-3">
                    <label class="ultra-label">Status Filter</label>
                    <select name="status" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                    </select>
                </div>
                <div class="col-12 col-md-3 col-lg-3 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1">Search</button>
                    <?php if ($search || $status_filter): ?>
                        <a href="/public/admins/index.php" class="btn btn-ultra-secondary shadow-sm fw-bold">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Total Employees: <span class="text-primary"><?= count($admins) ?> records</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap employee for options</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card List with Popup Modal)                  -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($admins)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-person-x text-muted fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No employees found</h5>
                    <p class="text-muted small">Adjust your search or add a new employee.</p>
                </div>
            <?php else: ?>
                <?php foreach ($admins as $admin): 
                    $joinDate = (!empty($admin['date_of_joining']) && $admin['date_of_joining'] !== '0000-00-00') ? date('d M, Y', strtotime($admin['date_of_joining'])) : 'Not Set';
                    $statusBadge = ($admin['status'] === 'active') ? 'app-badge-success' : 'app-badge-secondary';
                    $statusText = ucfirst($admin['status']);
                ?>
                    <div class="mobile-admin-item"
                         onclick="openAdminModal(
                             <?= (int)$admin['id'] ?>,
                             '<?= escapeOutput(addslashes($admin['name'])) ?>',
                             '<?= escapeOutput(addslashes($admin['email'])) ?>',
                             '<?= escapeOutput(addslashes($admin['mobile'])) ?>',
                             '<?= number_format($admin['client_count'] ?? 0) ?>',
                             '<?= $joinDate ?>',
                             '<?= $statusText ?>',
                             '<?= $statusBadge ?>',
                             '<?= !empty($admin['profile_photo']) ? '/uploads/profiles/' . escapeOutput($admin['profile_photo']) : '' ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <?php if (!empty($admin['profile_photo'])): ?>
                                <img src="/uploads/profiles/<?= escapeOutput($admin['profile_photo']) ?>" alt="Avatar" class="app-avatar me-3">
                            <?php else: ?>
                                <div class="app-avatar-fallback me-3">
                                    <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= escapeOutput($admin['name']) ?></div>
                                <small class="text-muted font-monospace"><?= escapeOutput($admin['mobile']) ?></small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="app-badge-soft <?= $statusBadge ?> mb-1"><?= $statusText ?></span>
                            <small class="text-muted d-block fw-semibold"><?= number_format($admin['client_count'] ?? 0) ?> clients</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================ -->
        <!-- 2. DESKTOP VIEW (Full Data Table)                            -->
        <!-- ============================================================ -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Employee Profile</th>
                        <th>Contact Details</th>
                        <th>Assigned Clients</th>
                        <th>Joining Date</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="6" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-person-x text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No employees found</h5>
                                <p class="text-muted small">Adjust your search or add a new employee.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($admin['profile_photo'])): ?>
                                            <img src="/uploads/profiles/<?= escapeOutput($admin['profile_photo']) ?>" alt="Avatar" class="app-avatar me-3">
                                        <?php else: ?>
                                            <div class="app-avatar-fallback me-3">
                                                <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= escapeOutput($admin['name']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-medium text-dark"><i class="bi bi-envelope me-2 text-muted"></i><?= escapeOutput($admin['email']) ?></div>
                                    <div class="small text-muted mt-1 font-monospace"><i class="bi bi-telephone me-2"></i><?= escapeOutput($admin['mobile']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-people-fill me-1 text-primary"></i> <?= number_format($admin['client_count'] ?? 0) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark">
                                        <?= (!empty($admin['date_of_joining']) && $admin['date_of_joining'] !== '0000-00-00') ? date('d M, Y', strtotime($admin['date_of_joining'])) : '<span class="text-muted fst-italic fw-normal">Not Set</span>' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($admin['status'] === 'active'): ?>
                                        <span class="app-badge-soft app-badge-success"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> Active</span>
                                    <?php else: ?>
                                        <span class="app-badge-soft app-badge-secondary"><i class="bi bi-pause-circle-fill me-1"></i> Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="/public/admins/profile.php?id=<?= (int)$admin['id'] ?>" class="app-btn-icon bg-primary bg-opacity-10 text-primary" title="View Profile">
                                            <i class="bi bi-person-bounding-box"></i>
                                        </a>
                                        <a href="/public/admins/activity.php?admin_id=<?= (int)$admin['id'] ?>" class="app-btn-icon bg-info bg-opacity-10 text-info" title="Login Activity">
                                            <i class="bi bi-journal-text"></i>
                                        </a>
                                        <a href="/public/admins/working-hours.php?admin_id=<?= (int)$admin['id'] ?>" class="app-btn-icon bg-success bg-opacity-10 text-success" title="Working Hours">
                                            <i class="bi bi-clock-history"></i>
                                        </a>
                                        <button type="button" class="app-btn-icon bg-danger bg-opacity-10 text-danger" title="Remove Admin" 
                                                onclick="confirmDelete(<?= (int)$admin['id'] ?>, '<?= escapeOutput(addslashes($admin['name'])) ?>')">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE ADMIN DETAILS & QUICK ACTIONS POPUP            -->
<!-- ============================================================ -->
<div class="modal fade" id="adminDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Admin Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mModalAvatarContainer" class="me-3"></div>
                        <div>
                            <h5 id="mModalName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mModalStatusBadge" class="badge rounded-pill px-2.5 py-1 small mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Contact Info Grid -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-envelope text-primary me-2"></i> Email</span>
                        <span id="mModalEmail" class="fw-bold text-dark small font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-telephone text-primary me-2"></i> Mobile</span>
                        <span id="mModalMobile" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-people text-primary me-2"></i> Clients Assigned</span>
                        <span id="mModalClients" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-event text-primary me-2"></i> Date of Joining</span>
                        <span id="mModalJoining" class="fw-semibold text-dark"></span>
                    </div>
                </div>

                <!-- Navigation Action Links -->
                <div class="d-flex flex-column gap-2">
                    <a id="mModalProfileLink" href="#" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-person-bounding-box me-2"></i> View Full Profile
                    </a>
                    <div class="row g-2">
                        <div class="col-6">
                            <a id="mModalActivityLink" href="#" class="btn btn-ultra-secondary w-100 py-2.5">
                                <i class="bi bi-journal-text me-1 text-info"></i> Activity
                            </a>
                        </div>
                        <div class="col-6">
                            <a id="mModalHoursLink" href="#" class="btn btn-ultra-secondary w-100 py-2.5">
                                <i class="bi bi-clock-history me-1 text-success"></i> Hours
                            </a>
                        </div>
                    </div>
                    <button type="button" id="mModalDeleteBtn" class="btn btn-light border text-danger w-100 rounded-pill py-3 fw-bold mt-2">
                        <i class="bi bi-trash3-fill me-1"></i> Remove Account
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: DELETE CONFIRMATION POPUP                             -->
<!-- ============================================================ -->
<div class="modal fade" id="deleteAdminModal" tabindex="-1" aria-labelledby="deleteAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <form method="POST" action="/public/admins/index.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_admin">
                <input type="hidden" name="admin_id" id="modalDeleteAdminId" value="">
                
                <div class="modal-body p-4 p-md-5 text-center">
                    <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px; font-size: 2.5rem;">
                        <i class="bi bi-person-x-fill"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Remove Account ?</h4>
                    <p class="text-muted mb-4">You are about to deactivate and soft-delete the account for <strong id="modalDeleteAdminName" class="text-dark"></strong>.</p>
                    
                    <div class="bg-light rounded-4 p-3 text-start small text-muted mb-4 border">
                        <i class="bi bi-info-circle-fill text-warning me-2"></i> Clients assigned to this employee will remain in the database but will need to be manually reassigned.
                    </div>
                    
                    <div class="d-flex gap-3 mt-4">
                        <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill flex-grow-1 fw-bold py-3 shadow-sm">Confirm Removal</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Opens Mobile Admin Details Modal Popup.
 */
function openAdminModal(id, name, email, mobile, clients, joining, statusText, statusBadgeClass, photoUrl) {
    document.getElementById('mModalName').textContent = name;
    document.getElementById('mModalEmail').textContent = email;
    document.getElementById('mModalMobile').textContent = mobile;
    document.getElementById('mModalClients').textContent = clients;
    document.getElementById('mModalJoining').textContent = joining;

    const badge = document.getElementById('mModalStatusBadge');
    badge.textContent = statusText;
    badge.className = 'badge rounded-pill px-2.5 py-1 small mt-1 ' + (statusBadgeClass.includes('success') ? 'bg-success' : 'bg-secondary');

    const avatarContainer = document.getElementById('mModalAvatarContainer');
    if (photoUrl && photoUrl.trim() !== '') {
        avatarContainer.innerHTML = '<img src="' + photoUrl + '" alt="Avatar" class="app-avatar shadow-sm">';
    } else {
        avatarContainer.innerHTML = '<div class="app-avatar-fallback shadow-sm">' + name.charAt(0).toUpperCase() + '</div>';
    }

    document.getElementById('mModalProfileLink').href = '/public/admins/profile.php?id=' + id;
    document.getElementById('mModalActivityLink').href = '/public/admins/activity.php?admin_id=' + id;
    document.getElementById('mModalHoursLink').href = '/public/admins/working-hours.php?admin_id=' + id;

    // Connect delete button inside mobile modal
    document.getElementById('mModalDeleteBtn').onclick = function() {
        const detailModal = bootstrap.Modal.getInstance(document.getElementById('adminDetailsModal'));
        if (detailModal) detailModal.hide();
        confirmDelete(id, name);
    };

    const modal = new bootstrap.Modal(document.getElementById('adminDetailsModal'));
    modal.show();
}

/**
 * Triggers the Delete Confirmation Modal and injects the specific Admin's data.
 */
function confirmDelete(adminId, adminName) {
    document.getElementById('modalDeleteAdminId').value = adminId;
    document.getElementById('modalDeleteAdminName').textContent = adminName;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteAdminModal'));
    deleteModal.show();
}

// Security features preserved
document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
document.addEventListener('keydown', function (e) {
    if (e.key === "F12") { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
    if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
    if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>