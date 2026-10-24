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

$pageTitle = 'Admin Portfolios & Conversions';
$success_msg = '';
$error_msg = '';
$db_error = '';

// Catch PRG Success Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'updated') $success_msg = 'Conversion details successfully updated.';
    if ($_GET['msg'] === 'reverted') $success_msg = 'Client status reverted successfully.';
}

// 1. Handle Form Actions (Edit Conversion Details / Revert Deal)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = htmlspecialchars(trim($_POST['action'] ?? ''), ENT_QUOTES);
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);

    try {
        if ($action === 'update_conversion' && $client_id) {
            $new_price = filter_input(INPUT_POST, 'final_price', FILTER_VALIDATE_FLOAT);
            $new_deal_status = htmlspecialchars(trim($_POST['deal_status'] ?? 'Converted'), ENT_QUOTES);
            $new_admin_id = filter_input(INPUT_POST, 'assigned_admin_id', FILTER_VALIDATE_INT);

            if ($new_price === false || $new_price < 0) {
                throw new Exception("Invalid final price amount provided.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE clients 
                SET final_price = ?, deal_status = ?, assigned_admin_id = ?, updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$new_price, $new_deal_status, $new_admin_id, $client_id]);

            // Audit log
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $_SESSION['user_id'], $_SESSION['role'], 'update_conversion_record', 'client', $client_id,
                "Updated conversion metrics (Price: ₹{$new_price}, Status: {$new_deal_status}) for Client ID {$client_id}", $_SERVER['REMOTE_ADDR']
            ]);

            $pdo->commit();
            header("Location: /public/reports/conversions.php?msg=updated");
            exit;

        } elseif ($action === 'revert_conversion' && $client_id) {
            $pdo->beginTransaction();

            // Revert deal status back to Open and clear final price
            $stmt = $pdo->prepare("
                UPDATE clients 
                SET deal_status = 'Open', final_price = NULL, updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$client_id]);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $audit->execute([
                $_SESSION['user_id'], $_SESSION['role'], 'revert_conversion_record', 'client', $client_id,
                "Reverted conversion status to Open for Client ID {$client_id}", $_SERVER['REMOTE_ADDR']
            ]);

            $pdo->commit();
            header("Location: /public/reports/conversions.php?msg=reverted");
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = $e->getMessage();
    }
}

// 2. Fetch Data with Try-Catch Protection
$portfolioSummary = [];
$convertedClients = [];
$adminsList = [];

try {
    // Fetch all active admins for dropdowns and portfolio analysis
    $adminsList = $pdo->query("SELECT id, name, email FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Compute Portfolio, Conversion Ratio, and Performance Ratio per Admin
    foreach ($adminsList as $adm) {
        $aid = $adm['id'];

        // Total assigned clients
        $cAssigned = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE assigned_admin_id = ? AND deleted_at IS NULL");
        $cAssigned->execute([$aid]);
        $totalAssigned = (int)$cAssigned->fetchColumn();

        // Converted clients & Total Revenue
        $cConv = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(final_price), 0) FROM clients WHERE assigned_admin_id = ? AND deal_status = 'Converted' AND deleted_at IS NULL");
        $cConv->execute([$aid]);
        $convRow = $cConv->fetch(PDO::FETCH_NUM);
        $totalConverted = (int)$convRow[0];
        $totalRevenue = (float)$convRow[1];

        // Conversion Ratio
        $conversionRatio = $totalAssigned > 0 ? round(($totalConverted / $totalAssigned) * 100, 1) : 0;

        // Activity & Work Performance Metrics for Performance Ratio calculation
        $actStmt = $pdo->prepare("SELECT COUNT(*) FROM client_activities WHERE admin_id = ?");
        $actStmt->execute([$aid]);
        $totalActivities = (int)$actStmt->fetchColumn();

        // Performance Ratio Score (Weighted formula combining conversion ratio & activity output)
        $performanceRatio = round(min(100, ($conversionRatio * 0.7) + min(30, $totalActivities * 0.5)), 1);

        $portfolioSummary[] = [
            'id' => $aid,
            'name' => $adm['name'],
            'email' => $adm['email'],
            'total_assigned' => $totalAssigned,
            'total_converted' => $totalConverted,
            'conversion_ratio' => $conversionRatio,
            'total_revenue' => $totalRevenue,
            'total_activities' => $totalActivities,
            'performance_ratio' => $performanceRatio
        ];
    }

    // Fetch all converted clients for the Management Table
    $convQuery = "
        SELECT 
            c.id, c.client_code, c.name, c.phone, c.final_price, c.deal_status, c.updated_at,
            c.assigned_admin_id, u.name AS assigned_admin_name
        FROM clients c
        LEFT JOIN users u ON c.assigned_admin_id = u.id
        WHERE c.deal_status = 'Converted' AND c.deleted_at IS NULL
        ORDER BY c.updated_at DESC
    ";
    $convertedClients = $pdo->query($convQuery)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Please ensure `clients` and `users` tables exist with correct columns.";
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

    /* Soft Badges */
    .app-badge-soft { padding: 0.4rem 0.8rem; border-radius: 100px; font-weight: 700; font-size: 0.75rem; letter-spacing: 0.3px; display: inline-flex; align-items: center; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e0eafc, #cfdef3); color: #2563eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Cards / List Items */
    .mobile-item-card { background: #ffffff; border-radius: 20px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-item-card:active { transform: scale(0.98); background: #f8fafc; }

    /* Modal Styling */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }
    .metric-stat-card { background: #f8fafc; border-radius: 18px; padding: 1rem; border: 1px solid #e2e8f0; height: 100%; display: flex; flex-direction: column; justify-content: center; }
    .metric-stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-award fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Portfolios & Conversions</h3>
                <p class="text-muted small mb-0 fw-medium">Evaluate conversion ratios, revenue performance & manage converted client portfolios.</p>
            </div>
        </div>
        <!--<div class="d-print-none">-->
        <!--    <button onclick="window.print()" class="btn btn-ultra-secondary shadow-sm">-->
        <!--        <i class="bi bi-printer me-2"></i> Print Report-->
        <!--    </button>-->
        <!--</div>-->
    </div>

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
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4 d-print-none">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- ============================================================ -->
        <!-- SECTION 1: ADMIN PORTFOLIO & PERFORMANCE MATRIX              -->
        <!-- ============================================================ -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-speedometer2 text-primary me-2"></i> Employee Performance Matrix</h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap admin for full stats</span>-->
        </div>

        <!-- Mobile View: Admin Portfolio Cards -->
        <div class="d-block d-md-none mb-4">
            <?php if (empty($portfolioSummary)): ?>
                <div class="bg-white rounded-4 text-center py-4 text-muted shadow-sm">No Employee portfolios found.</div>
            <?php else: ?>
                <?php foreach ($portfolioSummary as $port): 
                    $perfColor = 'bg-success';
                    if ($port['performance_ratio'] < 40) $perfColor = 'bg-danger';
                    elseif ($port['performance_ratio'] < 70) $perfColor = 'bg-warning text-dark';
                ?>
                    <div class="mobile-item-card" 
                         onclick="openPortfolioModal(
                             '<?= htmlspecialchars(addslashes($port['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($port['email']), ENT_QUOTES) ?>',
                             '<?= number_format($port['total_assigned']) ?>',
                             '<?= number_format($port['total_converted']) ?>',
                             '<?= $port['conversion_ratio'] ?>%',
                             '₹<?= number_format($port['total_revenue'], 2) ?>',
                             '<?= $port['performance_ratio'] ?> / 100',
                             '<?= $port['total_activities'] ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($port['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($port['name'], ENT_QUOTES) ?></div>
                                <span class="text-success fw-bold font-monospace small">₹<?= number_format($port['total_revenue'], 2) ?></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= $perfColor ?> rounded-pill px-2.5 py-1 mb-1 d-inline-block">
                                <i class="bi bi-star-fill me-1"></i> <?= $port['performance_ratio'] ?>
                            </span>
                            <small class="text-muted d-block fw-semibold"><?= $port['conversion_ratio'] ?>% conv.</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Desktop View: Admin Portfolio Table -->
        <div class="app-table-wrapper d-none d-md-block mb-5">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Employee Name</th>
                        <th>Assigned Portfolio</th>
                        <th>Converted Clients</th>
                        <th>Conversion Ratio</th>
                        <th>Total Revenue Generated</th>
                        <th>Performance Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($portfolioSummary)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="6" class="text-center py-4 text-muted">No employ portfolios found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($portfolioSummary as $port): 
                            $perfColor = 'bg-success';
                            if ($port['performance_ratio'] < 40) $perfColor = 'bg-danger';
                            elseif ($port['performance_ratio'] < 70) $perfColor = 'bg-warning text-dark';
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($port['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($port['name'], ENT_QUOTES) ?></div>
                                            <small class="text-muted fw-medium"><?= htmlspecialchars($port['email'], ENT_QUOTES) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill"><?= number_format($port['total_assigned']) ?> clients</span></td>
                                <td><span class="fw-bold text-success fs-6"><?= number_format($port['total_converted']) ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold"><?= $port['conversion_ratio'] ?>%</span>
                                        <div class="progress flex-grow-1" style="height: 6px; width: 80px; border-radius: 10px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= min(100, $port['conversion_ratio']) ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="font-monospace fw-bold text-success fs-6">₹<?= number_format($port['total_revenue'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $perfColor ?> px-3 py-2 rounded-pill shadow-sm">
                                        <i class="bi bi-star-fill me-1"></i> <?= $port['performance_ratio'] ?> / 100
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- SECTION 2: CONVERTED CLIENTS MANAGEMENT                      -->
        <!-- ============================================================ -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-check2-all text-success me-2"></i> Converted Clients: <?= count($convertedClients) ?> records found</h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap deal to manage</span>-->
        </div>

        <!-- Mobile View: Converted Deals Card List -->
        <div class="d-block d-md-none">
            <?php if (empty($convertedClients)): ?>
                <div class="bg-white rounded-4 text-center py-4 text-muted shadow-sm">No converted client records found.</div>
            <?php else: ?>
                <?php foreach ($convertedClients as $cc): 
                    $fmtFinal = number_format((float)$cc['final_price'], 2);
                    $fmtDate = date('d M, Y', strtotime($cc['updated_at']));
                    $clientTitle = htmlspecialchars(addslashes($cc['client_code'] . ' - ' . $cc['name']), ENT_QUOTES);
                ?>
                    <div class="mobile-item-card"
                         onclick="openConvertedClientModal(
                             <?= (int)$cc['id'] ?>,
                             '<?= htmlspecialchars(addslashes($cc['client_code']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($cc['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($cc['phone']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($cc['assigned_admin_name'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                             '₹<?= $fmtFinal ?>',
                             <?= (float)$cc['final_price'] ?>,
                             '<?= $fmtDate ?>',
                             <?= (int)$cc['assigned_admin_id'] ?>,
                             '<?= $clientTitle ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($cc['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($cc['name'], ENT_QUOTES) ?></div>
                                <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                    #<?= htmlspecialchars($cc['client_code'], ENT_QUOTES) ?>
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-success font-monospace fs-6">₹<?= $fmtFinal ?></div>
                            <small class="text-muted d-block"><?= $fmtDate ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Desktop View: Converted Clients Table -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Client Code & Name</th>
                        <th>Converted By Employee</th>
                        <th>Contact</th>
                        <th>Final Price (Revenue)</th>
                        <th>Conversion Date</th>
                        <th class="text-end pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($convertedClients)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="6" class="text-center py-5 text-muted">No converted client records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($convertedClients as $cc): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($cc['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6">
                                                <a href="/public/clients/view.php?id=<?= (int)$cc['id'] ?>" class="text-decoration-none text-dark">
                                                    <?= htmlspecialchars($cc['name'], ENT_QUOTES) ?>
                                                </a>
                                            </div>
                                            <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                                #<?= htmlspecialchars($cc['client_code'], ENT_QUOTES) ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($cc['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-medium text-dark font-monospace"><i class="bi bi-telephone text-muted me-1"></i> <?= htmlspecialchars($cc['phone'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace fw-bold text-success fs-5 bg-success bg-opacity-10 px-3 py-1 rounded-3">
                                        ₹<?= number_format((float)$cc['final_price'], 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-muted small"><i class="bi bi-clock-history me-1"></i> <?= date('d M, Y', strtotime($cc['updated_at'])) ?></span>
                                </td>
                                <td class="text-end pe-4 d-print-none">
                                    <div class="d-flex justify-content-end gap-2">
                                        <!-- Edit Trigger Modal -->
                                        <button type="button" class="btn btn-sm btn-ultra-secondary py-2 px-3" title="Edit Conversion Details"
                                                onclick="openEditModal(<?= (int)$cc['id'] ?>, '<?= htmlspecialchars(addslashes($cc['client_code'] . ' - ' . $cc['name']), ENT_QUOTES) ?>', <?= (float)$cc['final_price'] ?>, <?= (int)$cc['assigned_admin_id'] ?>)">
                                            <i class="bi bi-pencil-square me-1"></i> Edit
                                        </button>
                                        
                                        <!-- Revert/Delete Deal Trigger -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to revert this client status back to Open and remove the conversion record?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revert_conversion">
                                            <input type="hidden" name="client_id" value="<?= (int)$cc['id'] ?>">
                                            <button type="submit" class="app-btn-icon bg-danger bg-opacity-10 text-danger shadow-sm" title="Revert Conversion">
                                                <i class="bi bi-arrow-counterclockwise"></i>
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

    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE ADMIN PORTFOLIO DETAILS POPUP                  -->
<!-- ============================================================ -->
<div class="modal fade" id="portfolioDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="portAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="portName" class="fw-bold text-dark mb-0"></h5>
                            <small id="portEmail" class="text-muted fw-medium"></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Portfolio Size</span>
                            <h4 id="portAssigned" class="fw-bold text-dark mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Converted Clients</span>
                            <h4 id="portConverted" class="fw-bold text-success mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Conversion Ratio</span>
                            <h4 id="portRatio" class="fw-bold text-primary mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Total Activities</span>
                            <h4 id="portActivities" class="fw-bold text-dark mb-0 font-monospace"></h4>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25">
                            <span class="metric-stat-label text-success">Total Revenue Generated</span>
                            <h3 id="portRevenue" class="fw-bold text-success mb-0 font-monospace"></h3>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label">Performance Score</span>
                            <h5 id="portScore" class="fw-bold text-dark mb-0"></h5>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-ultra-secondary w-100 py-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: MOBILE CONVERTED CLIENT DETAIL POPUP                  -->
<!-- ============================================================ -->
<div class="modal fade" id="convertedClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mDealAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 50px; height: 50px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mDealName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mDealCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25 mb-4 text-center">
                    <span class="metric-stat-label text-success">Final Converted Price</span>
                    <h3 id="mDealPrice" class="fw-bold text-success mb-0 font-monospace"></h3>
                </div>

                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-badge text-primary me-2"></i> Converted By</span>
                        <span id="mDealAdmin" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-telephone text-primary me-2"></i> Phone</span>
                        <span id="mDealPhone" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-check text-primary me-2"></i> Conversion Date</span>
                        <span id="mDealDate" class="fw-semibold text-dark"></span>
                    </div>
                </div>

                <div class="d-flex flex-column gap-2">
                    <button id="mEditTriggerBtn" type="button" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-pencil-square me-2"></i> Edit Conversion Metrics
                    </button>
                    <a id="mViewClientBtn" href="#" class="btn btn-ultra-secondary w-100 py-3">
                        <i class="bi bi-eye me-2 text-primary"></i> View Client's Profile
                    </a>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to revert this client status back to Open and remove the conversion record?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revert_conversion">
                        <input type="hidden" name="client_id" id="mRevertClientId" value="">
                        <!--<button type="submit" class="btn btn-light border text-danger w-100 rounded-pill py-3 fw-bold mt-2">-->
                        <!--    <i class="bi bi-arrow-counterclockwise me-1"></i> Revert Status to Open-->
                        <!--</button>-->
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: EDIT CONVERSION DETAILS (Refactored Ultra Style)       -->
<!-- ============================================================ -->
<div class="modal fade" id="editConversionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content app-modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_conversion">
            <input type="hidden" name="client_id" id="modalClientId">

            <div class="modal-body p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; font-size: 1.5rem;">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold text-dark mb-0">Edit Conversion</h4>
                            <small class="text-muted fw-medium">Update pricing and deal assignment</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <div class="bg-light rounded-4 p-3 mb-4 border">
                    <span class="small text-muted text-uppercase fw-bold d-block mb-1">Target Client</span>
                    <h6 id="modalClientTitle" class="text-primary font-monospace fw-bold mb-0"></h6>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="ultra-label">Final Converted Price (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="final_price" id="modalFinalPrice" class="form-control ultra-input font-monospace text-success fs-5" required>
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Deal Status</label>
                        <select name="deal_status" class="form-select ultra-input">
                            <option value="Converted" selected>Converted</option>
                            <option value="Open">Open (Revert)</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="ultra-label">Assigned Employee</label>
                        <select name="assigned_admin_id" id="modalAdminId" class="form-select ultra-input">
                            <?php foreach ($adminsList as $adm): ?>
                                <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-5 d-flex gap-2">
                    <button type="button" class="btn btn-ultra-secondary flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-save me-2"></i> Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Opens Edit Modal with populated data.
 */
function openEditModal(clientId, clientTitle, finalPrice, adminId) {
    document.getElementById('modalClientId').value = clientId;
    document.getElementById('modalClientTitle').textContent = clientTitle;
    document.getElementById('modalFinalPrice').value = finalPrice;
    document.getElementById('modalAdminId').value = adminId;

    var editModal = new bootstrap.Modal(document.getElementById('editConversionModal'));
    editModal.show();
}

/**
 * Opens Mobile Admin Portfolio Details Modal.
 */
function openPortfolioModal(name, email, assigned, converted, ratio, revenue, score, activities) {
    document.getElementById('portAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('portName').textContent = name;
    document.getElementById('portEmail').textContent = email;
    document.getElementById('portAssigned').textContent = assigned;
    document.getElementById('portConverted').textContent = converted;
    document.getElementById('portRatio').textContent = ratio;
    document.getElementById('portRevenue').textContent = revenue;
    document.getElementById('portScore').textContent = score;
    document.getElementById('portActivities').textContent = activities;

    var pModal = new bootstrap.Modal(document.getElementById('portfolioDetailsModal'));
    pModal.show();
}

/**
 * Opens Mobile Converted Client Details Modal.
 */
function openConvertedClientModal(id, code, name, phone, admin, priceStr, priceNum, dateStr, adminId, clientTitle) {
    document.getElementById('mDealAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mDealName').textContent = name;
    document.getElementById('mDealCode').textContent = '#' + code;
    document.getElementById('mDealPrice').textContent = priceStr;
    document.getElementById('mDealAdmin').textContent = admin;
    document.getElementById('mDealPhone').textContent = phone;
    document.getElementById('mDealDate').textContent = dateStr;
    document.getElementById('mRevertClientId').value = id;
    
    document.getElementById('mViewClientBtn').href = '/public/clients/view.php?id=' + id;
    
    // Connect Edit Trigger button inside the modal to the edit modal
    document.getElementById('mEditTriggerBtn').onclick = function() {
        var currentModal = bootstrap.Modal.getInstance(document.getElementById('convertedClientModal'));
        if (currentModal) currentModal.hide();
        openEditModal(id, clientTitle, priceNum, adminId);
    };

    var dModal = new bootstrap.Modal(document.getElementById('convertedClientModal'));
    dModal.show();
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

<!-- Print Styles -->
<style>
    @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .d-print-none { display: none !important; }
        .container-fluid { padding: 0 !important; width: 100% !important; margin: 0 !important; }
        .ultra-card { border: 1px solid #ddd !important; box-shadow: none !important; background: #fff !important; color: #000 !important; }
    }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>