<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pageTitle = 'My Activity History';
$user_id = $_SESSION['user_id'];
$db_error = '';

// Filter Parameters
$filter_date = isset($_GET['date']) ? htmlspecialchars(trim($_GET['date']), ENT_QUOTES) : '';

$sessions = [];
$activities = [];

try {
    // 1. Fetch Login Sessions (work_sessions)
    $sessionQuery = "SELECT * FROM work_sessions WHERE admin_id = ?";
    $sessionParams = [$user_id];
    
    if ($filter_date) {
        $sessionQuery .= " AND session_date = ?";
        $sessionParams[] = $filter_date;
    }
    $sessionQuery .= " ORDER BY login_time DESC LIMIT 100";
    
    $stmtS = $pdo->prepare($sessionQuery);
    $stmtS->execute($sessionParams);
    $sessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Client Action Timeline (client_activities)
    $actQuery = "
        SELECT ca.*, c.name AS client_name, c.client_code 
        FROM client_activities ca
        LEFT JOIN clients c ON ca.client_id = c.id
        WHERE ca.admin_id = ?
    ";
    $actParams = [$user_id];
    
    if ($filter_date) {
        $actQuery .= " AND ca.activity_date = ?";
        $actParams[] = $filter_date;
    }
    $actQuery .= " ORDER BY ca.activity_date DESC, ca.activity_time DESC LIMIT 150";
    
    $stmtA = $pdo->prepare($actQuery);
    $stmtA->execute($actParams);
    $activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Gracefully catch missing tables/columns to prevent a 500 Server Error crash
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br><br>
                 <strong>Please ensure both <code>work_sessions</code> and <code>client_activities</code> tables exist in your database.</strong>";
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
    .ultra-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 8px; margin-left: 4px; }
    .ultra-input { border-radius: 16px; background: #f8fafc; border: 2px solid transparent; padding: 12px 18px; font-weight: 600; color: #0f172a; transition: all 0.2s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); height: auto; }
    .ultra-input:focus { background: #ffffff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); outline: none; }
    
    /* Buttons */
    .btn-ultra-primary { background: linear-gradient(135deg, #0f172a, #334155); color: white; border: none; border-radius: 100px; padding: 12px 28px; font-weight: 600; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); transition: all 0.2s; }
    .btn-ultra-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25); color: white; }
    
    .btn-ultra-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; border-radius: 100px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .btn-ultra-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* iOS Segmented Tabs */
    .ultra-tabs-wrapper { display: flex; justify-content: flex-start; margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
    .ultra-tabs-wrapper::-webkit-scrollbar { display: none; }
    .ultra-tabs { background: #e2e8f0; border-radius: 100px; padding: 6px; display: inline-flex; min-width: max-content; }
    .ultra-tabs .nav-item { flex: 1; text-align: center; }
    .ultra-tabs .nav-link { border-radius: 100px; color: #64748b; font-weight: 600; font-size: 0.95rem; padding: 10px 24px; border: none; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap; }
    .ultra-tabs .nav-link.active { background: #ffffff; color: #0f172a; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }

    /* Floating Card Table (Android List View Style) */
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
    .badge-active { background: rgba(59, 130, 246, 0.15); color: #2563eb; }
    .badge-manual { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .badge-timeout { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .badge-other { background: rgba(100, 116, 139, 0.15); color: #475569; }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-person-lines-fill fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">My Activity History</h3>
                <p class="text-muted small mb-0 fw-medium">Review login sessions & client timeline</p>
            </div>
        </div>
        <!--<div>-->
        <!--    <a href="/public/admins/profile.php" class="btn btn-ultra-secondary d-inline-flex align-items-center shadow-sm">-->
        <!--        <i class="bi bi-person-badge me-2 text-primary"></i> View My Profile-->
        <!--    </a>-->
        <!--</div>-->
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5>
            <p class="mb-0"><?= $db_error ?></p>
        </div>
    <?php else: ?>

        <!-- Search & Filter Card -->
        <div class="ultra-card mb-4">
            <form method="GET" action="/public/admins/my-history.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-5 col-lg-4">
                    <label class="ultra-label">Filter Activity by Date</label>
                    <input type="date" name="date" class="form-control ultra-input" value="<?= htmlspecialchars($filter_date, ENT_QUOTES) ?>">
                </div>
                <div class="col-12 col-md-7 col-lg-8 d-flex gap-2 justify-content-md-start justify-content-end align-items-end">
                    <button type="submit" class="btn btn-ultra-primary flex-grow-1 flex-md-grow-0"><i class="bi bi-funnel me-1"></i> Apply Filter</button>
                    <?php if ($filter_date): ?>
                        <a href="/public/admins/my-history.php" class="btn btn-ultra-secondary fw-bold shadow-sm">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Android Segmented Tabs -->
        <div class="ultra-tabs-wrapper">
            <ul class="nav ultra-tabs shadow-sm" id="historyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="sessions-tab" data-bs-toggle="tab" data-bs-target="#sessions" type="button" role="tab">
                        <i class="bi bi-laptop me-1"></i> Sessions (<?= count($sessions) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="interactions-tab" data-bs-toggle="tab" data-bs-target="#interactions" type="button" role="tab">
                        <i class="bi bi-chat-left-text me-1"></i> Interactions (<?= count($activities) ?>)
                    </button>
                </li>
            </ul>
        </div>
        

        <!-- Tab Content Area -->
        <div class="tab-content" id="historyTabsContent">
            
            <!-- ========================================== -->
            <!-- TAB 1: LOGIN SESSIONS                      -->
            <!-- ========================================== -->
            <div class="tab-pane fade show active" id="sessions" role="tabpanel">
                <div class="app-table-wrapper">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Session Date</th>
                                <th>Timeline (In / Out)</th>
                                <th>Active Duration</th>
                                <th>Status / Reason</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sessions)): ?>
                                <tr style="box-shadow: none; background: transparent;">
                                    <td colspan="5" class="text-center py-5">
                                        <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                            <i class="bi bi-clock-history text-muted" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h5 class="fw-bold text-dark mb-1">No session logs found</h5>
                                        <p class="text-muted small">You don't have any recorded sessions for the selected date.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sessions as $session): 
                                    // Determine Badge for Logout Reason
                                    $badgeClass = 'badge-active';
                                    $reasonText = 'Active Session';
                                    $icon = '<i class="bi bi-activity me-1"></i>';

                                    if ($session['logout_reason'] === 'manual') {
                                        $badgeClass = 'badge-manual';
                                        $reasonText = 'Manual Logout';
                                        $icon = '<i class="bi bi-box-arrow-right me-1"></i>';
                                    } elseif ($session['logout_reason'] === 'inactivity') {
                                        $badgeClass = 'badge-timeout';
                                        $reasonText = 'Timeout';
                                        $icon = '<i class="bi bi-hourglass-bottom me-1"></i>';
                                    } elseif ($session['logout_reason']) {
                                        $badgeClass = 'badge-other';
                                        $reasonText = htmlspecialchars(ucfirst($session['logout_reason']), ENT_QUOTES);
                                        $icon = '<i class="bi bi-info-circle me-1"></i>';
                                    }

                                    // Format Duration safely (Hours:Minutes:Seconds)
                                    $duration = (int)$session['duration_seconds'];
                                    $formatted_duration = sprintf('%02d:%02d:%02d', ($duration / 3600), ($duration / 60 % 60), $duration % 60);
                                ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark fs-6 d-block"><?= date('d M, Y', strtotime($session['session_date'])) ?></span>
                                            <span class="text-muted small fw-medium"><?= date('l', strtotime($session['session_date'])) ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column gap-1 font-monospace small">
                                                <div class="text-success fw-bold"><i class="bi bi-box-arrow-in-right me-1"></i> <?= date('h:i:s A', strtotime($session['login_time'])) ?></div>
                                                <?php if ($session['logout_time']): ?>
                                                    <div class="text-danger fw-bold"><i class="bi bi-box-arrow-left me-1"></i> <?= date('h:i:s A', strtotime($session['logout_time'])) ?></div>
                                                <?php else: ?>
                                                    <div class="text-muted fst-italic"><i class="bi bi-arrow-repeat me-1"></i> In progress...</div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-primary font-monospace fs-5 bg-primary bg-opacity-10 px-3 py-2 rounded-3">
                                                <?= $formatted_duration ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="app-badge-soft <?= $badgeClass ?>">
                                                <?= $icon ?> <?= $reasonText ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold font-monospace text-dark small"><i class="bi bi-router me-1 text-muted"></i> <?= htmlspecialchars($session['ip_address'] ?? 'Unknown', ENT_QUOTES) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- TAB 2: CLIENT INTERACTIONS                 -->
            <!-- ========================================== -->
            <div class="tab-pane fade" id="interactions" role="tabpanel">
                <div class="app-table-wrapper">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Target Client</th>
                                <th>Activity Type</th>
                                <th>Notes & Summary</th>
                                <th>Next Follow-up</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activities)): ?>
                                <tr style="box-shadow: none; background: transparent;">
                                    <td colspan="5" class="text-center py-5">
                                        <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 80px; height: 80px;">
                                            <i class="bi bi-card-text text-muted" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h5 class="fw-bold text-dark mb-1">No interactions found</h5>
                                        <p class="text-muted small">No client activities recorded for the selected criteria.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td style="min-width: 140px;">
                                            <span class="fw-bold text-dark d-block"><?= date('d M, Y', strtotime($act['activity_date'])) ?></span>
                                            <span class="text-muted small fw-bold font-monospace"><i class="bi bi-clock me-1"></i><?= date('h:i A', strtotime($act['activity_time'])) ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($act['client_name'])): ?>
                                                <a href="/public/clients/view.php?id=<?= (int)$act['client_id'] ?>" class="text-decoration-none d-block">
                                                    <span class="fw-bold text-primary"><?= htmlspecialchars($act['client_name'], ENT_QUOTES) ?></span>
                                                    <span class="d-block small text-muted font-monospace mt-1">ID: <?= htmlspecialchars($act['client_code'], ENT_QUOTES) ?></span>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Deleted Client</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="app-badge-soft bg-dark bg-opacity-10 text-dark border border-secondary border-opacity-25">
                                                <?= htmlspecialchars($act['activity_type'], ENT_QUOTES) ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 350px;">
                                            <div class="bg-light p-2 rounded-3 text-dark small fw-medium border" style="white-space: pre-wrap;"><?= htmlspecialchars($act['notes'], ENT_QUOTES) ?></div>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <?php if (!empty($act['next_followup_date'])): ?>
                                                <span class="app-badge-soft bg-warning bg-opacity-25 text-dark border border-warning border-opacity-50">
                                                    <i class="bi bi-bell-fill me-2 text-warning"></i> <?= date('d M, Y', strtotime($act['next_followup_date'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic fw-medium">- No follow-up -</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        
    <?php endif; ?>
</div>

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