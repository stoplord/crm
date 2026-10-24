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

$pageTitle = 'Project Management';
$search = htmlspecialchars(trim($_GET['q'] ?? ''), ENT_QUOTES);
$projects = [];
$db_error = '';

try {
    $query = "
        SELECT 
            c.id, c.client_code, c.name, c.phone, c.status, c.final_price, c.updated_at,
            u.name AS assigned_admin
        FROM clients c
        LEFT JOIN users u ON c.assigned_admin_id = u.id
        WHERE c.deal_status = 'Converted' AND c.deleted_at IS NULL
    ";
    
    $params = [];
    if ($search) {
        $query .= " AND (c.name LIKE ? OR c.client_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $query .= " ORDER BY c.updated_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Error: " . $e->getMessage();
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
    .ultra-search-icon { position: absolute; left: 16px; color: #64748b; font-size: 1.1rem; }
    .ultra-search-input { padding-left: 45px !important; }

    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Floating Card Table (Desktop View) */
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 900px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s; border-radius: 20px; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }

    /* Soft Badges */
    .app-badge-soft { padding: 0.5rem 1rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.5px; text-transform: uppercase; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Project Card Item */
    .mobile-project-item { background: #ffffff; border-radius: 20px; padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-project-item:active { transform: scale(0.98); background: #f8fafc; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; display: block; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-kanban fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Project Management</h3>
                <p class="text-muted small mb-0 fw-medium">Track progress & payments for all converted clients.</p>
            </div>
        </div>
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Search & Filter Card -->
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/projects/index.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-8">
                    <label class="ultra-label">Search Projects</label>
                    <div class="ultra-search-wrapper">
                        <i class="bi bi-search ultra-search-icon"></i>
                        <input type="text" name="q" class="form-control ultra-input ultra-search-input" placeholder="Enter Client's Name" value="<?= $search ?>">
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-4 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 flex-md-grow-0 px-4"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <?php if ($search): ?>
                        <a href="/public/projects/index.php" class="btn btn-ultra-secondary fw-bold shadow-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Active Projects: <span class="text-primary"><?= count($projects) ?></span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap project for details</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Card List with Popup Modal)                  -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($projects)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-kanban text-muted fs-1 d-block mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No active projects found</h5>
                    <p class="text-muted small">Converted clients will automatically appear here as projects.</p>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $p): 
                    $fmtPrice = number_format((float)$p['final_price'], 2);
                    $dateFmt = date('d M, Y', strtotime($p['updated_at']));
                ?>
                    <div class="mobile-project-item"
                         onclick="openProjectDetailsModal(
                             <?= (int)$p['id'] ?>,
                             '<?= htmlspecialchars(addslashes($p['client_code']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($p['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($p['phone'] ?? ''), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($p['assigned_admin'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                             '₹<?= $fmtPrice ?>',
                             '<?= htmlspecialchars(addslashes($p['status']), ENT_QUOTES) ?>',
                             '<?= $dateFmt ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($p['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($p['name'], ENT_QUOTES) ?></div>
                                <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                    #<?= htmlspecialchars($p['client_code'], ENT_QUOTES) ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success font-monospace fs-6">₹<?= $fmtPrice ?></div>
                            <span class="badge bg-dark bg-opacity-10 text-dark border px-2 py-0.5 mt-1 small">
                                <?= htmlspecialchars($p['status'], ENT_QUOTES) ?>
                            </span>
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
                        <th>Project / Client</th>
                        <th>Assigned Employee</th>
                        <th>Total Project Value</th>
                        <th>Current Phase</th>
                        <th>Last Updated</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="6" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-kanban text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No active projects found</h5>
                                <p class="text-muted small">Converted clients will automatically appear here as projects.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects as $p): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($p['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($p['name'], ENT_QUOTES) ?></div>
                                            <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-1 rounded d-inline-block mt-1 fw-bold">
                                                <i class="bi bi-hash"></i><?= htmlspecialchars($p['client_code'], ENT_QUOTES) ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($p['assigned_admin'] ?? 'Unassigned', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success font-monospace fs-5 bg-success bg-opacity-10 px-3 py-1 rounded-3">
                                        ₹<?= number_format((float)$p['final_price'], 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="app-badge-soft bg-dark bg-opacity-10 text-dark border border-secondary border-opacity-25">
                                        <?= htmlspecialchars($p['status'], ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-muted small"><i class="bi bi-clock-history me-1"></i> <?= date('d M, Y', strtotime($p['updated_at'])) ?></span>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="/public/projects/manage.php?id=<?= $p['id'] ?>" class="btn btn-ultra-primary rounded-pill px-4 py-2" style="font-size: 0.85rem;">
                                        Manage <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
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
<!-- MODAL: MOBILE PROJECT DETAILS & MANAGEMENT POPUP             -->
<!-- ============================================================ -->
<div class="modal fade" id="projectDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mProjAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mProjName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mProjCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Value Metric Card -->
                <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25 mb-4 text-center p-3">
                    <span class="metric-stat-label text-success">Total Project Value</span>
                    <h3 id="mProjValue" class="fw-bold text-success mb-0 font-monospace"></h3>
                </div>

                <!-- Info Grid -->
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-badge text-primary me-2"></i> Assigned Employee</span>
                        <span id="mProjAdmin" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-1.5 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-kanban text-primary me-2"></i> Current Phase</span>
                        <span id="mProjPhase" class="badge bg-dark bg-opacity-10 text-dark border px-2.5 py-1"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history text-primary me-2"></i> Last Updated</span>
                        <span id="mProjUpdated" class="fw-semibold text-dark small"></span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="d-flex gap-2">
                    <a id="mProjManageBtn" href="#" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-kanban me-2"></i> Open Project Workspace
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Opens and Populates Mobile Project Details Modal.
 */
function openProjectDetailsModal(id, code, name, phone, admin, valueStr, phase, updatedStr) {
    document.getElementById('mProjAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mProjName').textContent = name;
    document.getElementById('mProjCode').textContent = '#' + code;
    document.getElementById('mProjValue').textContent = valueStr;
    document.getElementById('mProjAdmin').textContent = admin;
    document.getElementById('mProjPhase').textContent = phase;
    document.getElementById('mProjUpdated').textContent = updatedStr;
    
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