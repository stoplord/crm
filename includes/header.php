<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure CSRF token is available for AJAX requests
require_once __DIR__ . '/csrf.php';
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!--<meta name="viewport" content="width=device-width, initial-scale=1.0">-->
    
    <!-- 1. Strict Mobile Viewport Restriction -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">

<!-- 2. Global Anti-Zoom & Touch Normalization Styles -->
<style>
    /* Disable gesture zooming and auto text resizing */
    html, body {
        touch-action: manipulation;
        -webkit-text-size-adjust: 100%;
        -ms-text-size-adjust: 100%;
        text-size-adjust: 100%;
    }

    /* Enforce min 16px font-size to stop iOS Safari from auto-zooming on focus */
    input, select, textarea, button {
        font-size: 16px !important;
        touch-action: manipulation;
    }

    /* Prevent mobile blue highlight flash on tap */
    * {
        -webkit-tap-highlight-color: transparent;
    }
</style>
    
    
    
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - StopLord Technologies' : 'StopLord Technologies' ?></title>
    
    <!-- CSRF Token for JavaScript AJAX Requests -->
    <meta name="csrf-token" content="<?= $csrf_token ?>">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="public/assets/css/style.css" rel="stylesheet">

    <style>
        /* Navy Blue Theme */
        .bg-navy { background-color: #0a2540 !important; }

        /* Basic layout styling to support sidebar and topnav */
        body {
            background-color: #f8f9fa;
            overflow-x: hidden;
        }
        #wrapper {
            display: flex;
            width: 100%;
            align-items: stretch;
            margin-top: 56px; 
        }
        
        /* FIX: Prevents content from shrinking or overflowing next to the sidebar */
        #page-content-wrapper {
            flex: 1;
            min-width: 0;
            padding: 0.01rem; 
            overflow-x: hidden;
        }
        
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,.08);
        }
        
        /* Working Timer Styling */
        .time-display {
            font-family: monospace;
            font-size: 1.05rem;
            font-weight: bold;
            white-space: nowrap;
        }

        /* Mobile specific centering for Working Timer */
        @media (max-width: 767.98px) {
            #working-time-display {
                position: absolute;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                font-size: 0.85rem;
                padding: 0.35rem 0.6rem !important;
                margin: 0 !important;
            }
        }

        /* Sidebar Mobile Overlay - Touch anywhere to hide */
        #sidebar-overlay {
            display: none;
            position: fixed;
            top: 56px; 
            left: 0; 
            width: 100vw; 
            height: calc(100vh - 56px);
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(2px);
            z-index: 1030;
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar -->
<nav class="navbar navbar-dark bg-navy fixed-top" style="height: 56px; z-index: 1050;">
    <div class="container-fluid position-relative d-flex justify-content-between align-items-center">
        
        <!-- LEFT: Sidebar Toggle & Desktop Company Name -->
        <div class="d-flex align-items-center">
            <!-- Mobile Toggle -->
            <button class="btn btn-outline-light d-md-none border-0 me-3" id="sidebarToggleBtn" style="padding: 0.25rem 0.5rem;">
                <i class="bi bi-list fs-4"></i>
            </button>
            
            <!-- Desktop Brand (Hidden on Mobile) -->
            <a class="navbar-brand fw-bold d-none d-md-flex align-items-center m-0" href="/public/dashboard.php">
                <i class="bi bi-briefcase-fill me-2 text-primary"></i> StopLord Technologies
            </a>
        </div>
        
        <!-- RIGHT: Timer & Profile Dropdown -->
        <div class="d-flex align-items-center ms-auto">
            <?php if (isset($_SESSION['user_id'])): ?>
                
                <!-- RULE 19 & 32: Admin Working Time Display -->
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <span class="text-warning time-display bg-dark bg-opacity-25 px-3 py-1 rounded shadow-sm border border-light border-opacity-10 d-flex align-items-center me-md-3" id="working-time-display" title="Active Working Time">
                        <i class="bi bi-stopwatch me-1 me-md-2"></i> Working Time: --:--:--
                    </span>
                <?php endif; ?>
                
                <!-- Profile & Logout Dropdown (Top Right for both Desktop & Mobile) -->
                <div class="dropdown">
                    <a href="#" class="text-light text-decoration-none dropdown-toggle d-flex align-items-center" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle fs-5"></i>
                        <!-- Hide role text on mobile to save space, show on desktop -->
                        <span class="d-none d-md-inline ms-2 fw-semibold">
                            <?= $_SESSION['role'] === 'super_admin' ? 'Super Admin' : 'Admin Profile' ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow position-absolute mt-2" aria-labelledby="userDropdown">
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="/public/admins/profile.php"><i class="bi bi-person me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item text-danger" href="/public/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>

            <?php endif; ?>
        </div>
    </div>
</nav>

<div id="wrapper">
    <!-- Mobile Overlay for closing sidebar -->
    <div id="sidebar-overlay"></div>

<div class="modal fade" id="inactivityWarningModal" tabindex="-1" aria-labelledby="inactivityWarningModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="inactivityWarningModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Inactivity Warning</h5>
            </div>
            <div class="modal-body fs-5 text-center py-4">
                You have been inactive for 4 minutes. <br><br>
                <strong>Move your mouse or interact with the CRM to continue working.</strong>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">I'm still here</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts placed at the top for UI initialization before full page load -->
<script>
    // System Clock Logic (Independent of Tracker.js)
    function updateSystemClock() {
        const now = new Date();
        let hours = now.getHours();
        let minutes = now.getMinutes();
        let seconds = now.getSeconds();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        
        hours = hours % 12;
        hours = hours ? hours : 12; 
        
        hours = hours.toString().padStart(2, '0');
        minutes = minutes.toString().padStart(2, '0');
        seconds = seconds.toString().padStart(2, '0');
        
        const timeString = `<i class="bi bi-clock"></i> ${hours}:${minutes}:${seconds} ${ampm}`;
        const clockElement = document.getElementById('system-time-display');
        if (clockElement) {
            clockElement.innerHTML = timeString;
        }
    }
    
    updateSystemClock();
    setInterval(updateSystemClock, 1000);

    // Mobile Sidebar Toggle & "Touch Anywhere to Hide" Logic
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const toggleBtn = document.getElementById('sidebarToggleBtn');

        if (toggleBtn) {
            toggleBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (sidebar.classList.contains('show-sidebar')) {
                    sidebar.classList.remove('show-sidebar');
                    if (overlay) overlay.style.display = 'none';
                    document.body.style.overflow = 'auto';
                } else {
                    sidebar.classList.add('show-sidebar');
                    if (overlay) overlay.style.display = 'block';
                    document.body.style.overflow = 'hidden'; 
                }
            };
        }

        if (overlay) {
            overlay.onclick = function(e) {
                if (sidebar) sidebar.classList.remove('show-sidebar');
                this.style.display = 'none';
                document.body.style.overflow = 'auto';
            };
        }
    });
</script>