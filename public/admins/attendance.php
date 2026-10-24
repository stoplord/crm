<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$role = $_SESSION['role'] ?? 'admin';
$current_user_id = $_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

$pageTitle = 'Attendance & Timesheet';
$db_error = '';

// 1. Filter Parameters
$filter_month = htmlspecialchars(trim($_GET['month'] ?? date('Y-m')), ENT_QUOTES);
$target_admin = $current_user_id;

if ($isSuperAdmin && !empty($_GET['admin_id'])) {
    $target_admin = filter_input(INPUT_GET, 'admin_id', FILTER_VALIDATE_INT);
}

// 2. Initialize Calendar Array for Selected Month
$daysInMonth = (int)date('t', strtotime($filter_month . '-01'));
$attendanceSheet = [];
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dateStr = $filter_month . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
    $attendanceSheet[$dateStr] = [
        'punch_in'  => null,
        'punch_out' => null,
        'seconds'   => 0,
        'status'    => 'Absent' // Default assumption
    ];
}

$adminsList = [];
$adminName = 'My';

$monthly_total_seconds = 0;
$days_present = 0;

try {
    // Fetch Admins List for Super Admin Filter
    if ($isSuperAdmin) {
        $adminsList = $pdo->query("SELECT id, name FROM users WHERE role = 'admin' AND deleted_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        if ($target_admin != $current_user_id) {
            $stmtN = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $stmtN->execute([$target_admin]);
            $adminName = $stmtN->fetchColumn() . "'s";
        }
    }

    // A. Fetch First Login & Last Logout per day (Punch In / Punch Out)
    $stmtSessions = $pdo->prepare("
        SELECT 
            session_date, 
            MIN(login_time) as first_login, 
            MAX(logout_time) as last_logout 
        FROM work_sessions 
        WHERE admin_id = ? AND session_date LIKE ? 
        GROUP BY session_date
    ");
    $stmtSessions->execute([$target_admin, $filter_month . '%']);
    $sessionsData = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sessionsData as $sd) {
        if (isset($attendanceSheet[$sd['session_date']])) {
            $attendanceSheet[$sd['session_date']]['punch_in'] = $sd['first_login'];
            $attendanceSheet[$sd['session_date']]['punch_out'] = $sd['last_logout'];
        }
    }

    // B. Fetch Total Validated Hours and Status from Daily Work Logs
    $stmtLogs = $pdo->prepare("
        SELECT work_date, total_seconds, status 
        FROM daily_work_logs 
        WHERE admin_id = ? AND work_date LIKE ?
    ");
    $stmtLogs->execute([$target_admin, $filter_month . '%']);
    $logsData = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logsData as $ld) {
        if (isset($attendanceSheet[$ld['work_date']])) {
            $sec = (int)$ld['total_seconds'];
            $attendanceSheet[$ld['work_date']]['seconds'] = $sec;
            
            // Re-evaluate status based on actual seconds worked
            if ($sec >= 21600) { // 6+ hours
                $attendanceSheet[$ld['work_date']]['status'] = 'Present';
                $days_present++;
            } elseif ($sec > 0) {
                $attendanceSheet[$ld['work_date']]['status'] = 'Half Day';
                $days_present += 0.5;
            }
            
            $monthly_total_seconds += $sec;
        }
    }

} catch (PDOException $e) {
    $db_error = "Database Schema Error: " . $e->getMessage() . "<br>Ensure `work_sessions` and `daily_work_logs` tables exist.";
}

// Formatting Monthly Totals
$total_hrs = floor($monthly_total_seconds / 3600);
$total_mins = floor(($monthly_total_seconds % 3600) / 60);

// For Calendar UI alignment
$firstDayOfWeek = date('N', strtotime($filter_month . '-01'));

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
    
    .btn-ultra-outline { background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 100px; padding: 12px 24px; font-weight: 600; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .btn-ultra-outline:hover { background: #f8fafc; border-color: #94a3b8; }

    /* Gradients for KPI Cards */
    .bg-gradient-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
    .bg-gradient-info { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white; }

    /* Custom App-like Calendar Grid */
    .ultra-calendar-wrapper { border-radius: 20px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0; width: 100%; }
    .ultra-calendar-header { display: grid; grid-template-columns: repeat(7, 1fr); background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .ultra-calendar-header > div { padding: 12px 0; text-align: center; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; }
    .ultra-calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; }
    
    .calendar-cell { background: #ffffff; min-height: 100px; padding: 10px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; cursor: pointer; transition: all 0.2s; position: relative; }
    .calendar-cell:hover { background: #f8fafc; }
    .calendar-cell:active { transform: scale(0.97); }
    .calendar-cell.empty { background: #f8fafc; cursor: default; }
    .calendar-cell.weekend { background: #fafafa; }
    
    .date-num { font-size: 1.1rem; font-weight: 700; color: #334155; margin-bottom: 8px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    .date-num.today { background: #2563eb; color: #fff; }
    
    .cal-badge { font-size: 0.7rem; font-weight: 700; padding: 4px 8px; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; text-align: center; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-badge.present { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .cal-badge.half-day { background: rgba(245, 158, 11, 0.15); color: #d97706; }
    .cal-badge.absent { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
    .cal-badge.future { background: rgba(148, 163, 184, 0.1); color: #94a3b8; }

    /* Modals */
    .app-modal-content { border-radius: 28px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.15); overflow: hidden; }

    /* ========================================= */
    /* MOBILE RESPONSIVE FIXES FOR CALENDAR      */
    /* ========================================= */
    @media (max-width: 768px) {
        .ultra-calendar-header > div { font-size: 0.7rem; padding: 8px 0; }
        .calendar-cell { min-height: 80px; padding: 6px; }
        .date-num { width: 28px; height: 28px; font-size: 0.95rem; }
        .cal-badge { font-size: 0.6rem; padding: 3px 6px; }
    }

    @media (max-width: 576px) {
        .ultra-calendar-header > div { font-size: 0.6rem; padding: 8px 0; letter-spacing: 0; }
        .calendar-cell { min-height: 65px; padding: 4px; }
        .date-num { width: 24px; height: 24px; font-size: 0.85rem; margin-bottom: auto; }
        
        /* Convert text badges to native app-style colored dots on small screens */
        .cal-badge { 
            width: 8px; 
            height: 8px; 
            padding: 0; 
            color: transparent !important; 
            border-radius: 50%; 
            margin: auto auto 4px auto;
            display: block;
            overflow: hidden;
            white-space: nowrap;
            text-indent: 100%;
        }
        
        /* Ensure background colors for the dots apply correctly */
        .cal-badge.present { background-color: #10b981; }
        .cal-badge.half-day { background-color: #f59e0b; }
        .cal-badge.absent { background-color: #ef4444; }
        .cal-badge.future { background-color: #cbd5e1; }
    }
</style>

<div class="container-fluid px-3 px-md-4 py-4 pb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 52px; height: 52px;">
                <i class="bi bi-calendar-check fs-4"></i>
            </div>
            <div>
                <h3 class="fw-bold text-dark mb-0 tracking-tight">Timesheet</h3>
                <p class="text-muted small mb-0 fw-medium">Employee's attendance & working hours</p>
            </div>
        </div>
        <!--<div class="d-print-none">-->
        <!--    <button onclick="window.print()" class="btn btn-ultra-outline shadow-sm d-inline-flex align-items-center">-->
        <!--        <i class="bi bi-printer me-2"></i> Print Register-->
        <!--    </button>-->
        <!--</div>-->
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 fw-medium p-4 mb-4"><h5 class="fw-bold text-danger"><i class="bi bi-database-exclamation me-2"></i> Query Failed</h5><p class="mb-0"><?= $db_error ?></p></div>
    <?php else: ?>

        <!-- Filters Card -->
        <div class="ultra-card mb-4 d-print-none">
            <form method="GET" action="/public/admins/attendance.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="ultra-label">Select Month</label>
                    <input type="month" name="month" class="form-control ultra-input" value="<?= $filter_month ?>" onchange="this.form.submit()">
                </div>
                
                <?php if ($isSuperAdmin): ?>
                <div class="col-12 col-md-5 col-lg-4">
                    <label class="ultra-label">View Employee</label>
                    <select name="admin_id" class="form-select ultra-input" onchange="this.form.submit()">
                        <option value="<?= $_SESSION['user_id'] ?>">Myself</option>
                        <?php foreach ($adminsList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= $target_admin === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-12 col-md-3 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-ultra-primary w-100 m-0"><i class="bi bi-search me-1"></i> View</button>
                </div>
            </form>
            
            <br />
                <!--<div class="col-12 col-md-3 col-lg-2 d-flex gap-2">            -->
        <!--<div class="d-print-none">-->
        <!--    <button onclick="window.print()" class="btn btn-ultra-outline shadow-sm w-100 m-0 d-inline-flex align-items-center">-->
        <!--        <i class="bi bi-printer me-2"></i> Print Register-->
        <!--    </button>-->
        <!--</div>-->
            
        </div>



        <!-- KPI Cards -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-primary h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Attendance For</span>
                    <h3 class="fw-bold mt-1 mb-0 text-white"><?= htmlspecialchars($adminName, ENT_QUOTES) ?> Timesheet</h3>
                    <div class="mt-2 fw-medium opacity-75"><i class="bi bi-calendar-event me-1"></i> <?= date('F Y', strtotime($filter_month . '-01')) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-success h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Days Present</span>
                    <h2 class="fw-bold mt-1 mb-0 font-monospace text-white"><?= $days_present ?> <span class="fs-5 opacity-75">/ <?= $daysInMonth ?></span></h2>
                    <div class="mt-2 fw-medium opacity-75"><i class="bi bi-info-circle me-1"></i> Includes Half-Days</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="ultra-card bg-gradient-info h-100 d-flex flex-column justify-content-center p-4">
                    <span class="small text-uppercase fw-bold opacity-75 letter-spacing-1">Monthly Active Hours</span>
                    <h2 class="fw-bold mt-1 mb-0 font-monospace text-white"><?= $total_hrs ?>h <?= $total_mins ?>m</h2>
                    <div class="mt-2 fw-medium opacity-75"><i class="bi bi-stopwatch me-1"></i> Total system time</div>
                </div>
            </div>
        </div>

        <!-- Interactive Calendar Register -->
        <div class="ultra-card p-0 overflow-hidden mb-5">
            <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-white">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-month me-2 text-primary"></i> <?= date('F Y', strtotime($filter_month . '-01')) ?> Details</h5>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 fw-medium d-none d-sm-inline-block">Tap a date for details</span>
            </div>
            
            <div class="ultra-calendar-wrapper">
                <div class="ultra-calendar-header">
                    <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div>
                    <div class="text-danger">Sat</div><div class="text-danger">Sun</div>
                </div>
                <div class="ultra-calendar-grid">
                    <?php 
                    $todayDate = date('Y-m-d');
                    
                    // Empty cells for the start of the month
                    for ($i = 1; $i < $firstDayOfWeek; $i++) {
                        echo '<div class="calendar-cell empty"></div>';
                    }
                    
                    // Days of the month
                    foreach ($attendanceSheet as $date => $data) {
                        $isWeekend = (date('N', strtotime($date)) >= 6);
                        $isFuture = ($date > $todayDate);
                        $isToday = ($date === $todayDate);
                        
                        $dayNum = date('j', strtotime($date));
                        
                        // Status Badge Processing
                        $sec = $data['seconds'];
                        $statusClass = 'absent';
                        $statusText = 'Absent';
                        
                        if ($data['status'] === 'Present') { $statusClass = 'present'; $statusText = 'Present'; }
                        elseif ($data['status'] === 'Half Day') { $statusClass = 'half-day'; $statusText = 'Half Day'; }
                        
                        if ($isFuture) { $statusClass = 'future'; $statusText = '-'; }
                        elseif ($isWeekend && $sec == 0) { $statusClass = 'future'; $statusText = 'Weekend'; }
                        
                        $cellClass = $isWeekend ? 'weekend' : '';
                        $todayClass = $isToday ? 'today shadow-sm' : '';
                        
                        // Prepare data for the JS Modal
                        $inTime = $data['punch_in'] ? date('h:i A', strtotime($data['punch_in'])) : 'Not Logged';
                        $outTime = $data['punch_out'] ? date('h:i A', strtotime($data['punch_out'])) : 'Not Logged';
                        $fmtHours = $sec > 0 ? sprintf('%02d Hrs %02d Mins', ($sec/3600), ($sec/60%60)) : '0 Hrs 0 Mins';
                        $displayDate = date('l, d F Y', strtotime($date));
                        
                        // Render Cell
                        echo "<div class='calendar-cell {$cellClass}' onclick=\"openDayDetails('{$displayDate}', '{$inTime}', '{$outTime}', '{$fmtHours}', '{$statusText}', '{$statusClass}')\">
                                <span class='date-num {$todayClass}'>{$dayNum}</span>
                                <span class='cal-badge {$statusClass} mt-auto'>{$statusText}</span>
                              </div>";
                    }
                    ?>
                </div>
            </div>
        </div>

    <?php endif; ?>
    
    
    
</div>



<!-- App-Style Bottom Sheet Modal for Day Details -->
<div class="modal fade" id="dayDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content app-modal-content">
            <div class="modal-body p-4 p-md-5 text-center">
                <div id="modalStatusIcon" class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 70px; height: 70px; font-size: 2rem;">
                    <i class="bi bi-clock-history"></i>
                </div>
                
                <h5 id="modalDateLabel" class="fw-bold text-dark mb-1"></h5>
                <span id="modalStatusBadge" class="badge rounded-pill mb-4 px-3 py-2 fw-bold text-uppercase"></span>
                
                <div class="bg-light rounded-4 p-3 text-start mb-3 border">
                    <div class="d-flex justify-content-between mb-2">
                        <small class="text-muted fw-bold text-uppercase">Punch In</small>
                        <span id="modalPunchIn" class="fw-bold text-dark font-monospace"></span>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2">
                        <small class="text-muted fw-bold text-uppercase">Punch Out</small>
                        <span id="modalPunchOut" class="fw-bold text-dark font-monospace"></span>
                    </div>
                </div>
                
                <div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-4 p-3 text-center">
                    <small class="text-primary fw-bold text-uppercase d-block mb-1">Total Active Time</small>
                    <h4 id="modalTotalTime" class="fw-bold text-primary mb-0 font-monospace"></h4>
                </div>
                
                <button type="button" class="btn btn-light border rounded-pill w-100 fw-bold mt-4 py-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openDayDetails(dateLabel, punchIn, punchOut, totalTime, statusText, statusClass) {
    document.getElementById('modalDateLabel').innerText = dateLabel;
    document.getElementById('modalPunchIn').innerText = punchIn;
    document.getElementById('modalPunchOut').innerText = punchOut;
    document.getElementById('modalTotalTime').innerText = totalTime;
    
    const badge = document.getElementById('modalStatusBadge');
    const icon = document.getElementById('modalStatusIcon');
    
    badge.innerText = statusText;
    badge.className = 'badge rounded-pill mb-4 px-3 py-2 fw-bold text-uppercase';
    icon.className = 'rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm';
    
    if (statusClass === 'present') {
        badge.classList.add('bg-success');
        icon.classList.add('bg-success', 'bg-opacity-10', 'text-success');
        icon.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
    } else if (statusClass === 'half-day') {
        badge.classList.add('bg-warning', 'text-dark');
        icon.classList.add('bg-warning', 'bg-opacity-10', 'text-warning');
        icon.innerHTML = '<i class="bi bi-brightness-alt-high-fill"></i>';
    } else if (statusClass === 'absent') {
        badge.classList.add('bg-danger');
        icon.classList.add('bg-danger', 'bg-opacity-10', 'text-danger');
        icon.innerHTML = '<i class="bi bi-x-circle-fill"></i>';
    } else {
        badge.classList.add('bg-secondary');
        icon.classList.add('bg-secondary', 'bg-opacity-10', 'text-secondary');
        icon.innerHTML = '<i class="bi bi-calendar-minus"></i>';
    }
    
    new bootstrap.Modal(document.getElementById('dayDetailsModal')).show();
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

<style>
    /* Print optimizations for Calendar Grid */
    @media print {
        body { background-color: #fff !important; }
        .sidebar, .navbar, .d-print-none, .ultra-tabs-wrapper { display: none !important; }
        .container-fluid { padding: 0 !important; width: 100% !important; margin: 0 !important; }
        .ultra-card { border: 1px solid #ddd !important; box-shadow: none !important; padding: 0 !important; }
        .calendar-cell { border: 1px solid #ccc !important; min-height: 80px !important; }
        .calendar-cell:active, .calendar-cell:hover { transform: none !important; background: transparent !important; }
        .ultra-calendar-grid { gap: 0 !important; }
        .cal-badge { border: 1px solid #000; color: #000 !important; background: transparent !important; }
    }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>