<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Sales & Revenue Report';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

// 1. Filter Parameters
$filter_admin = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
$start_date   = htmlspecialchars($_GET['start_date'] ?? date('Y-m-01'), ENT_QUOTES); // Default: 1st of current month
$end_date     = htmlspecialchars($_GET['end_date'] ?? date('Y-m-d'), ENT_QUOTES);     // Default: Today

// 2. Build Query Constraints
$where = ["c.deleted_at IS NULL"];
$params = [];

if (!$isSuperAdmin) {
    // Admins see only their own sales metrics
    $where[] = "(c.assigned_admin_id = ? OR c.created_by = ?)";
    $params[] = $user_id;
    $params[] = $user_id;
} elseif ($isSuperAdmin && $filter_admin) {
    // Super Admin filtering by specific admin
    $where[] = "c.assigned_admin_id = ?";
    $params[] = $filter_admin;
}

if ($start_date && $end_date) {
    $where[] = "DATE(c.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

$whereClause = implode(' AND ', $where);

// 3. Prepare Strict-Mode Compliant Queries
$queryStats = "
    SELECT 
        COUNT(c.id) AS total_clients,
        SUM(CASE WHEN c.deal_status = 'Converted' THEN 1 ELSE 0 END) AS converted_count,
        SUM(CASE WHEN c.deal_status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.deal_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(COALESCE(c.quotation_amount, 0)) AS total_quotation_value,
        SUM(COALESCE(c.final_price, 0)) AS total_converted_revenue,
        SUM(CASE WHEN c.temperature = 'HOT' THEN 1 ELSE 0 END) AS hot_leads,
        SUM(CASE WHEN c.temperature = 'COLD' THEN 1 ELSE 0 END) AS cold_leads
    FROM clients c
    WHERE $whereClause
";

$queryConverted = "
    SELECT c.*, u.name AS assigned_admin_name
    FROM clients c
    LEFT JOIN users u ON c.assigned_admin_id = u.id
    WHERE $whereClause AND c.deal_status = 'Converted'
    ORDER BY c.updated_at DESC
";

$stats = [];
$convertedDeals = [];
$adminsList = [];
$db_error = '';

// 4. Execute Queries with Graceful Error Catching
try {
    // Fetch Admins List for Super Admin
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch Aggregated Statistics
    $stmtStats = $pdo->prepare($queryStats);
    $stmtStats->execute($params);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    // Fetch Converted Deals Table
    $stmtConverted = $pdo->prepare($queryConverted);
    $stmtConverted->execute($params);
    $convertedDeals = $stmtConverted->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Gracefully catch missing financial columns instead of crashing
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br><br>
                 <strong>Please ensure you have run this SQL command:</strong><br>
                 <code>ALTER TABLE `clients` ADD COLUMN `deal_status` VARCHAR(50) DEFAULT 'Open', ADD COLUMN `quotation_amount` DECIMAL(12,2) DEFAULT NULL, ADD COLUMN `final_price` DECIMAL(12,2) DEFAULT NULL;</code>";
}

// 5. Calculate Rates Safely
$totalClients = (int)($stats['total_clients'] ?? 0);
$convertedCount = (int)($stats['converted_count'] ?? 0);
$conversionRate = $totalClients > 0 ? round(($convertedCount / $totalClients) * 100, 1) : 0;

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
    .app-btn-icon { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.1rem; transition: all 0.2s; border: none; text-decoration: none; }
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

    /* Gradients for KPI Cards */
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
    .bg-gradient-info { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white; }

    /* Avatar Fallback */
    .app-avatar-fallback { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; background: linear-gradient(135deg, #e2e8f0, #f8fafc); color: #475569; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0; }

    /* Mobile Deal Item */
    .mobile-deal-item { background: #ffffff; border-radius: 20px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all 0.2s ease; }
    .mobile-deal-item:active { transform: scale(0.98); background: #f8fafc; }

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
                <i class="bi bi-graph-up-arrow fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Sales & Revenue Report</h3>
                <p class="text-muted small mb-0 fw-medium">Analyze pipelines, converted deal values & conversion ratios.</p>
            </div>
        </div>
        <!--<div class="d-print-none">-->
        <!--    <button onclick="window.print()" class="btn btn-ultra-secondary shadow-sm">-->
        <!--        <i class="bi bi-printer me-2"></i> Print Report-->
        <!--    </button>-->
        <!--</div>-->
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4 d-print-none">
            <h5 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Report Execution Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Filter Card -->
        <div class="ultra-card mb-4 d-print-none">
            <form method="GET" action="/public/reports/sales.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control ultra-input" value="<?= $start_date ?>" required>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">End Date</label>
                    <input type="date" name="end_date" class="form-control ultra-input" value="<?= $end_date ?>" required>
                </div>
                
                <?php if ($isSuperAdmin): ?>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Filter by Employee</label>
                    <select name="admin_id" class="form-select ultra-input">
                        <option value="">All Employees</option>
                        <?php foreach ($adminsList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $filter_admin === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-12 col-lg-3 d-flex gap-2 justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1"><i class="bi bi-funnel me-1"></i> Generate</button>
                    <a href="/public/reports/sales.php" class="btn btn-ultra-secondary shadow-sm fw-bold">Reset</a>
                </div>
            </form>
        </div>

        <!-- Overview Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-6 col-lg-3">
                <div class="ultra-card bg-gradient-primary h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Total Leads / Clients</span>
                    <h2 class="fw-bold mt-2 mb-0 text-white"><?= number_format($totalClients) ?></h2>
                    <div class="mt-2 small opacity-75 fw-medium">🔥 <?= (int)($stats['hot_leads'] ?? 0) ?> Hot | ❄️ <?= (int)($stats['cold_leads'] ?? 0) ?> Cold</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="ultra-card bg-gradient-warning h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Total Quotation Value</span>
                    <h2 class="fw-bold mt-2 mb-0 font-monospace text-white">₹<?= number_format((float)($stats['total_quotation_value'] ?? 0), 2) ?></h2>
                    <div class="mt-2 small opacity-75 fw-medium">Open Deals: <?= number_format((int)($stats['open_count'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="ultra-card bg-gradient-success h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Converted Revenue</span>
                    <h2 class="fw-bold mt-2 mb-0 font-monospace text-white">₹<?= number_format((float)($stats['total_converted_revenue'] ?? 0), 2) ?></h2>
                    <div class="mt-2 small opacity-75 fw-medium">Successful Deals: <?= number_format($convertedCount) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="ultra-card bg-gradient-info h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Conversion Rate</span>
                    <h2 class="fw-bold mt-2 mb-0 text-white"><?= $conversionRate ?>%</h2>
                    <div class="mt-2 small opacity-75 fw-medium">Rejected Deals: <?= number_format((int)($stats['rejected_count'] ?? 0)) ?></div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h6 class="fw-bold text-dark mb-0">Converted Deals Breakdown: <span class="text-primary"><?= count($convertedDeals) ?> records</span></h6>
            <!--<span class="d-md-none text-muted small fw-medium"><i class="bi bi-hand-index-thumb me-1"></i> Tap for details</span>-->
        </div>

        <!-- ============================================================ -->
        <!-- 1. MOBILE VIEW (Deal List with Popup Details)                -->
        <!-- ============================================================ -->
        <div class="d-block d-md-none">
            <?php if (empty($convertedDeals)): ?>
                <div class="bg-white rounded-4 text-center py-5 shadow-sm">
                    <i class="bi bi-receipt text-muted fs-1 d-block mb-2"></i>
                    <h6 class="fw-bold text-dark mb-1">No converted deals found</h6>
                    <p class="text-muted small mb-0">No deals converted within the selected date range.</p>
                </div>
            <?php else: ?>
                <?php foreach ($convertedDeals as $deal): 
                    $fmtQuote = number_format((float)($deal['quotation_amount'] ?? 0), 2);
                    $fmtFinal = number_format((float)($deal['final_price'] ?? 0), 2);
                    $fmtDate = date('d M, Y', strtotime($deal['updated_at']));
                ?>
                    <div class="mobile-deal-item"
                         onclick="openDealDetailsModal(
                             <?= (int)$deal['id'] ?>,
                             '<?= htmlspecialchars(addslashes($deal['client_code']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($deal['name']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($deal['phone']), ENT_QUOTES) ?>',
                             '<?= htmlspecialchars(addslashes($deal['assigned_admin_name'] ?? 'Unassigned'), ENT_QUOTES) ?>',
                             '₹<?= $fmtQuote ?>',
                             '₹<?= $fmtFinal ?>',
                             '<?= $fmtDate ?>'
                         )">
                        <div class="d-flex align-items-center">
                            <div class="app-avatar-fallback me-3">
                                <?= strtoupper(substr($deal['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($deal['name'], ENT_QUOTES) ?></div>
                                <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1">
                                    #<?= htmlspecialchars($deal['client_code'], ENT_QUOTES) ?>
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

        <!-- ============================================================ -->
        <!-- 2. DESKTOP VIEW (Full Data Table)                            -->
        <!-- ============================================================ -->
        <div class="app-table-wrapper d-none d-md-block">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Client Profile</th>
                        <th>Contact Details</th>
                        <th>Assigned Employee</th>
                        <th>Quotation Amount</th>
                        <th>Final Converted Price</th>
                        <th>Conversion Date</th>
                        <th class="text-end pe-4 d-print-none">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($convertedDeals)): ?>
                        <tr style="box-shadow: none; background: transparent;">
                            <td colspan="7" class="text-center py-5">
                                <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-receipt text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No converted deals found</h5>
                                <p class="text-muted small">No deals converted within the selected date range.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($convertedDeals as $deal): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="app-avatar-fallback me-3">
                                            <?= strtoupper(substr($deal['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($deal['name'], ENT_QUOTES) ?></div>
                                            <span class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-1 rounded d-inline-block mt-1 fw-bold">
                                                <i class="bi bi-hash"></i><?= htmlspecialchars($deal['client_code'], ENT_QUOTES) ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-medium text-dark font-monospace"><i class="bi bi-telephone text-muted me-1"></i> <?= htmlspecialchars($deal['phone'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill">
                                        <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($deal['assigned_admin_name'] ?? 'Unassigned', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-muted font-monospace">₹<?= number_format((float)($deal['quotation_amount'] ?? 0), 2) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success font-monospace fs-5 bg-success bg-opacity-10 px-3 py-1 rounded-3">
                                        ₹<?= number_format((float)($deal['final_price'] ?? 0), 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-muted small"><i class="bi bi-clock-history me-1"></i> <?= date('d M, Y', strtotime($deal['updated_at'])) ?></span>
                                </td>
                                <td class="text-end pe-4 d-print-none">
                                    <a href="/public/clients/view.php?id=<?= (int)$deal['id'] ?>" class="app-btn-icon bg-primary bg-opacity-10 text-primary" title="View Client">
                                        <i class="bi bi-eye-fill"></i>
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
<!-- MOBILE CONVERTED DEAL DETAILS MODAL POPUP                    -->
<!-- ============================================================ -->
<div class="modal fade" id="dealDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4">
                
                <!-- Client Info Header -->
                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div id="mAvatar" class="app-avatar-fallback me-3 shadow-sm" style="width: 52px; height: 52px; font-size: 1.3rem;"></div>
                        <div>
                            <h5 id="mName" class="fw-bold text-dark mb-0"></h5>
                            <span id="mCode" class="text-primary font-monospace small bg-primary bg-opacity-10 px-2 py-0.5 rounded d-inline-block fw-bold mt-1"></span>
                        </div>
                    </div>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>

                <!-- Financial Metrics Breakdown -->
                <h6 class="text-muted small fw-bold text-uppercase mb-3 letter-spacing-1">Deal Valuation</h6>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="metric-stat-card">
                            <span class="metric-stat-label text-muted">Quoted Amount</span>
                            <h5 id="mQuotation" class="fw-semibold text-muted mb-0 font-monospace"></h5>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-stat-card bg-success bg-opacity-10 border-success border-opacity-25">
                            <span class="metric-stat-label text-success">Final Price</span>
                            <h4 id="mFinalPrice" class="fw-bold text-success mb-0 font-monospace"></h4>
                        </div>
                    </div>
                </div>

                <!-- Operational Details -->
                <h6 class="text-muted small fw-bold text-uppercase mb-3 letter-spacing-1">Operations & Management</h6>
                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-person-badge text-primary me-2"></i> Assigned To</span>
                        <span id="mAdmin" class="badge bg-secondary bg-opacity-10 text-dark border px-3 py-2 fw-bold rounded-pill"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-telephone text-primary me-2"></i> Contact</span>
                        <span id="mPhone" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                        <span class="small fw-semibold text-muted"><i class="bi bi-calendar-check text-primary me-2"></i> Conversion Date</span>
                        <span id="mDate" class="fw-semibold text-dark"></span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2">
                    <a id="mViewClientBtn" href="#" class="btn btn-ultra-primary w-100 py-3">
                        <i class="bi bi-eye-fill me-2"></i> View Client's Profile
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Populates and triggers the Converted Deal Details modal for mobile view.
 */
function openDealDetailsModal(id, code, name, phone, admin, quotation, finalPrice, dateFormatted) {
    document.getElementById('mAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('mName').textContent = name;
    document.getElementById('mCode').textContent = '#' + code;
    document.getElementById('mPhone').textContent = phone;
    document.getElementById('mAdmin').textContent = admin;
    document.getElementById('mQuotation').textContent = quotation;
    document.getElementById('mFinalPrice').textContent = finalPrice;
    document.getElementById('mDate').textContent = dateFormatted;
    
    document.getElementById('mViewClientBtn').href = '/public/clients/view.php?id=' + id;

    const modal = new bootstrap.Modal(document.getElementById('dealDetailsModal'));
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