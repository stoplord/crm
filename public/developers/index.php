<?php





require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();

$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');
$isDeveloper = ($role === 'developer');

// Role Authorization Check
if (!$isSuperAdmin && !$isDeveloper) {
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

    // If logged in as developer, scope projects strictly to their assignment
    if ($isDeveloper) {
        $query .= " AND c.assigned_admin_id = ?";
        $params[] = $user_id;
    }

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
    .ultra-card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03); padding: 1.5rem; margin-bottom: 1.5rem; }
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; display: inline-block; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 14px 18px; font-weight: 600; color: #0f172a; height: auto; }
    .ultra-search-wrapper { position: relative; display: flex; align-items: center; }
    .ultra-search-icon { position: absolute; left: 16px; color: #64748b; font-size: 1.1rem; }
    .ultra-search-input { padding-left: 45px !important; }
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
    .app-table-wrapper { overflow-x: auto; padding-bottom: 1rem; }
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; min-width: 900px; }
    .app-table thead th { border: none; background: transparent; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; padding: 0 1.5rem; }
    .app-table tbody tr { background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-radius: 20px; transition: transform 0.2s; }
    .app-table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    .app-table tbody td { padding: 1.25rem 1.5rem; border: none; vertical-align: middle; }
    .app-table tbody td:first-child { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .app-table tbody td:last-child { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }
    .app-badge-soft { padding: 0.5rem 1rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; letter-spacing: 0.5px; text-transform: uppercase; }
    .app-avatar-fallback { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-kanban fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Project Management</h3>
                <p class="text-muted small mb-0 fw-medium">Track step-by-step progress for converted client projects.</p>
            </div>
        </div>
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/projects/index.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg-8">
                    <label class="ultra-label">Search Projects</label>
                    <div class="ultra-search-wrapper">
                        <i class="bi bi-search ultra-search-icon"></i>
                        <input type="text" name="q" class="form-control ultra-input ultra-search-input" placeholder="Client Name or Code..." value="<?= $search ?>">
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
        </div>

        <div class="app-table-wrapper">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Project / Client</th>
                        <th>Assigned Admin / Dev</th>
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
                                    <a href="/public/developers/manage.php?id=<?= $p['id'] ?>" class="btn btn-ultra-primary rounded-pill px-4 py-2" style="font-size: 0.85rem;">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>