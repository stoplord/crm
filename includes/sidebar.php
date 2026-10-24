<?php
/**
 * CRM SYSTEM - SIDEBAR NAVIGATION
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role'] ?? 'admin';
$current_uri = $_SERVER['REQUEST_URI'];

function is_active($path, $current_uri) {
    return strpos($current_uri, $path) !== false ? 'active bg-primary text-white shadow-sm' : 'text-light';
}
?>

<!-- Sidebar Wrapper -->
<div id="sidebar" class="bg-navy border-end border-secondary border-opacity-25 shadow" style="width: 250px; flex-shrink: 0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1040;">
    
    <div class="list-group list-group-flush mt-3 px-2 pb-5">
        
        <!-- COMMON MENU ITEMS -->
        <a href="/public/dashboard.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/dashboard.php', $current_uri) ?>">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>

        <!-- SUPER ADMIN MENU ITEMS -->
        <?php if ($role === 'super_admin'): ?>
            
            <a href="#adminSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
                <i class="bi bi-shield-lock me-2"></i> Employees
            </a>
            <div class="collapse <?= strpos($current_uri, '/public/admins/') !== false ? 'show' : '' ?> ms-3" id="adminSubmenu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/admins/index.php', $current_uri) ?>" href="/public/admins/index.php"><i class="bi bi-list-ul me-2"></i> All Employees</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/admins/create.php', $current_uri) ?>" href="/public/admins/create.php"><i class="bi bi-person-plus me-2"></i> Add Employee</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/admins/working-hours.php', $current_uri) ?>" href="/public/admins/working-hours.php"><i class="bi bi-clock-history me-2"></i> Working Hours</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/admins/attendance.php', $current_uri) ?>" href="/public/admins/attendance.php"><i class="bi bi-calendar2-check me-2"></i> Attendance</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/admins/activity.php', $current_uri) ?>" href="/public/admins/activity.php"><i class="bi bi-journal-check me-2"></i> Login Activity</a></li>
                </ul>
            </div>

            <a href="#clientSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
                <i class="bi bi-people me-2"></i> Clients
            </a>
            <div class="collapse <?= strpos($current_uri, '/public/clients/') !== false ? 'show' : '' ?> ms-3" id="clientSubmenu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/clients/index.php', $current_uri) ?>" href="/public/clients/index.php"><i class="bi bi-list-ul me-2"></i> All Clients</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/clients/create.php', $current_uri) ?>" href="/public/clients/create.php"><i class="bi bi-person-plus me-2"></i> Add Client</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/clients/import.php', $current_uri) ?>" href="/public/clients/import.php"><i class="bi bi-cloud-arrow-up me-2"></i> Bulk Import Leads</a></li>
                </ul>
            </div>

            <a href="#projectSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
                <i class="bi bi-kanban me-2"></i> Projects
            </a>
            <div class="collapse <?= strpos($current_uri, '/public/projects/') !== false ? 'show' : '' ?> ms-3" id="projectSubmenu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/projects/index.php', $current_uri) ?>" href="/public/projects/index.php"><i class="bi bi-kanban me-2"></i> All Projects</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/projects/assign-projects.php', $current_uri) ?>" href="/public/projects/assign-projects.php"><i class="bi bi-gear me-2"></i> Assign Projects</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/projects/manage-projects.php', $current_uri) ?>" href="/public/projects/projects-overview.php"><i class="bi bi-gear me-2"></i> Projects Overview</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/developers/index.php', $current_uri) ?>" href="/public/developers/index.php"><i class="bi bi-gear me-2"></i> Projects Developer</a></li>
                </ul>
            </div>
            
            <a href="/public/hr/management.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/hr/', $current_uri) ?>">
                <i class="bi bi-briefcase me-2"></i> HR Management
            </a>
            
            <a href="/public/expenses/index.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/expenses/', $current_uri) ?>">
                <i class="bi bi-briefcase me-2"></i> Expenses
            </a>

            <a href="#reportSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
                <i class="bi bi-graph-up-arrow me-2"></i> Reports
            </a>
            <div class="collapse <?= strpos($current_uri, '/public/reports/') !== false ? 'show' : '' ?> ms-3" id="reportSubmenu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/reports/sales.php', $current_uri) ?>" href="/public/reports/sales.php"><i class="bi bi-currency-rupee me-2"></i> Sales Report</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/reports/performance.php', $current_uri) ?>" href="/public/reports/performance.php"><i class="bi bi-bar-chart me-2"></i> Employee Performance</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/reports/conversions.php', $current_uri) ?>" href="/public/reports/conversions.php"><i class="bi bi-award me-2"></i> Employee Conversions</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/reports/audit.php', $current_uri) ?>" href="/public/reports/audit.php"><i class="bi bi-shield-check me-2"></i> Audit Logs</a></li>
                </ul>
            </div>

        <!-- ADMIN MENU ITEMS -->
        <?php else: ?>
            
            <a href="#clientSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
                <i class="bi bi-people me-2"></i> My Clients
            </a>
            <div class="collapse <?= strpos($current_uri, '/public/clients/') !== false ? 'show' : '' ?> ms-3" id="clientSubmenu">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/clients/index.php', $current_uri) ?>" href="/public/clients/index.php"><i class="bi bi-list-ul me-2"></i> Assigned Clients</a></li>
                    <li class="nav-item"><a class="nav-link <?= is_active('/public/clients/create.php', $current_uri) ?>" href="/public/clients/create.php"><i class="bi bi-person-plus me-2"></i> Add Client</a></li>
                </ul>
            </div>
            
            <a href="/public/admins/profile.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/admins/profile.php', $current_uri) ?>">
                <i class="bi bi-person-badge me-2"></i> My Profile
            </a>
            
            <a href="/public/admins/my-history.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/admins/my-history.php', $current_uri) ?>">
                <i class="bi bi-stopwatch me-2"></i> My Work History
            </a>

            <a href="/public/hr/my_portal.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/hr/my_portal.php', $current_uri) ?>">
                <i class="bi bi-person-workspace me-2"></i> My HR Portal
            </a>

        <?php endif; ?>

        <!-- COMMON MEETINGS MENU -->
        <a href="#meetingsSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="list-group-item list-group-item-action bg-transparent text-light rounded mb-1 dropdown-toggle">
            <i class="bi bi-calendar-event me-2"></i> Meetings
        </a>
        <div class="collapse <?= strpos($current_uri, '/public/meetings/') !== false ? 'show' : '' ?> ms-3" id="meetingsSubmenu">
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link <?= is_active('/public/meetings/index.php', $current_uri) ?>" href="/public/meetings/index.php"><i class="bi bi-calendar-event me-2"></i> All Meetings</a></li>
                <li class="nav-item"><a class="nav-link <?= is_active('/public/meetings/schedule.php', $current_uri) ?>" href="/public/meetings/schedule.php"><i class="bi bi-calendar-plus me-2"></i> Schedule A Meeting</a></li>
            </ul>
        </div>
        
        <a href="/public/tasks/index.php" class="list-group-item list-group-item-action bg-transparent rounded mb-1 <?= is_active('/public/tasks/', $current_uri) ?>">
          <i class="bi bi-check2-square me-2"></i> To Do Lists
        </a>
        
        

    </div>
</div>

<!-- Page Content Wrapper Starts Here -->
<div id="page-content-wrapper">

<style>
/* Robust Sidebar Scrollbar & Dynamic Viewport Height Constraint */
#sidebar {
    height: calc(100vh - 56px);
    height: calc(100dvh - 56px);
    max-height: calc(100vh - 56px);
    max-height: calc(100dvh - 56px);
    overflow-y: auto !important;
    overflow-x: hidden !important;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-y: contain;
    touch-action: pan-y;
}

/* Custom Sleek Scrollbar */
#sidebar::-webkit-scrollbar {
    width: 5px;
}
#sidebar::-webkit-scrollbar-track {
    background: transparent;
}
#sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.25);
    border-radius: 10px;
}
#sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.4);
}

/* Firefox Support */
#sidebar {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.25) transparent;
}

/* Desktop: Sticky Sidebar so it stays fixed while page scrolls */
@media (min-width: 769px) {
    #sidebar {
        position: sticky;
        top: 56px;
    }
}

/* Mobile: Fixed Off-Canvas with Bound Height */
@media (max-width: 768px) {
    #sidebar {
        position: fixed;
        top: 56px;
        bottom: 0;
        left: -260px; /* Hidden off-screen */
    }
    #sidebar.show-sidebar {
        left: 0;
    }
}
</style>

<script>
/**
 * Sidebar Toggle & Smooth Touch Interaction Logic
 */
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const closeBtn = document.getElementById('closeSidebarBtn');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('show-sidebar');
        if (overlay) overlay.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background body scrolling
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('show-sidebar');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Prevent background touchmove cancellation when scrolling inside the sidebar on mobile
    if (sidebar) {
        sidebar.addEventListener('touchmove', function(e) {
            e.stopPropagation();
        }, { passive: true });
    }
});
</script>