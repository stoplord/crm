<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

// If a user is already logged in, redirect them to the dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    header("Location: /public/dashboard.php");
    exit;
}

$error_message = '';
$success_message = '';

// Catch timeout message (Rule 20)
if (isset($_GET['msg']) && $_GET['msg'] === 'timeout') {
    $error_message = 'You have been logged out due to 5 minutes of inactivity.';
}
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success_message = 'You have successfully logged out.';
}

$step = $_GET['step'] ?? 'login';

// Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token']);

    // ==========================================
    // STEP 1: Email/Mobile & Password Verification
    // ==========================================
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error_message = 'Please enter both your email/mobile and password.';
        } else {
            // Fetch user (Admin or Super Admin)
            $stmt = $pdo->prepare("SELECT id, role, password, status FROM users WHERE (email = ? OR mobile = ?) AND deleted_at IS NULL");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                
                // Block Inactive accounts
                if ($user['status'] === 'inactive') {
                    $error_message = 'Your account has been deactivated. Please contact the system administrator.';
                } 
                // Super Admin requires Step 2 (PIN)
                else if ($user['role'] === 'super_admin') {
                    $_SESSION['temp_super_admin_id'] = $user['id'];
                    header("Location: /public/login.php?step=pin");
                    exit;
                } 
                // Admin logs in directly
                else {
                    session_regenerate_id(true); // Prevent Session Fixation
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['last_activity'] = time();

                    // Rule 19: Start Working-Time Tracking
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $ua = $_SERVER['HTTP_USER_AGENT'];
                    $now = date('Y-m-d H:i:s');
                    
                    $wsStmt = $pdo->prepare("INSERT INTO work_sessions (admin_id, login_time, last_activity, session_date, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
                    $wsStmt->execute([$user['id'], $now, $now, date('Y-m-d'), $ip, $ua]);
                    $_SESSION['current_session_id'] = $pdo->lastInsertId();

                    $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $audit->execute([$user['id'], $user['role'], 'login', 'user', $user['id'], 'Admin logged in', $ip]);

                    header("Location: /public/dashboard.php");
                    exit;
                }
            } else {
                $error_message = 'Invalid credentials. Please try again.';
            }
        }
    } 
    // ==========================================
    // STEP 2: Super Admin PIN Verification
    // ==========================================
    elseif (isset($_POST['action']) && $_POST['action'] === 'verify_pin') {
        $pin = $_POST['pin'] ?? '';
        
        if (empty($pin)) {
            $error_message = 'Please enter your Secure PIN.';
        } elseif (!isset($_SESSION['temp_super_admin_id'])) {
            // Fallback if session expired or accessed directly
            header("Location: /public/login.php");
            exit;
        } else {
            $stmt = $pdo->prepare("SELECT id, role, pin_hash FROM users WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$_SESSION['temp_super_admin_id']]);
            $user = $stmt->fetch();

            if ($user && password_verify($pin, $user['pin_hash'])) {
                session_regenerate_id(true); // Prevent Session Fixation
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                unset($_SESSION['temp_super_admin_id']); // Cleanup temp session

                // Insert Audit Log for Super Admin
                $ip = $_SERVER['REMOTE_ADDR'];
                $ua = $_SERVER['HTTP_USER_AGENT'];
                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $audit->execute([$user['id'], $user['role'], 'login', 'user', $user['id'], 'Super Admin logged in', $ip, $ua]);

                header("Location: /public/dashboard.php");
                exit;
            } else {
                $error_message = 'Invalid PIN. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- RESTRICT ZOOM-IN & ZOOM-OUT ON ALL MOBILE DEVICES WITH VIEWPORT COVER -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no, viewport-fit=cover">
    <title>Sign In &bull; StopLord CRM</title>
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <!-- ULTRA DESIGN SYSTEM CSS -->
    <style>
        /* 1. Global Viewport Lock & Scroll Removal */
        html, body {
            background-color: #f0f2f5;
            font-family: 'Inter', -apple-system, sans-serif;
            width: 100vw;
            height: 100vh;
            height: 100dvh; /* Exact dynamic viewport height */
            margin: 0;
            padding: 0;
            overflow: hidden !important; /* Strips all native scrollbars */
            overscroll-behavior: none !important; /* Disables rubberband / elastic bounce */
            -webkit-overflow-scrolling: auto;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            touch-action: none; /* Stops gesture drag/pan on screen background */
        }

        /* Hide all scrollbar indicators across all engines */
        * {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        *::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        /* 2. Dead-Center Viewport Positioning */
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 1rem;
            box-sizing: border-box;
        }

        /* Ambient Radial Accents */
        body::before {
            content: '';
            position: absolute;
            top: -10%;
            left: -10%;
            width: 50vw;
            height: 50vw;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.08) 0%, rgba(240, 242, 245, 0) 70%);
            z-index: -1;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            bottom: -10%;
            right: -10%;
            width: 50vw;
            height: 50vw;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.06) 0%, rgba(240, 242, 245, 0) 70%);
            z-index: -1;
            pointer-events: none;
        }

        /* 3. Fully Contained Login Box (Proportionally Scaled for Zero Overflow) */
        .ultra-login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-radius: 28px;
            border: 1px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(0, 0, 0, 0.02);
            width: 100%;
            max-width: 400px;
            padding: 2rem 1.75rem;
            box-sizing: border-box;
            position: relative;
            touch-action: pan-y; /* Allows normal tap & interactions within form */
            margin: auto;
        }

        /* Brand Avatar */
        .brand-avatar {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.28);
            margin: 0 auto 0.75rem;
        }

        /* Security Pill */
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border-radius: 100px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .card-title-text {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 2px;
        }

        .card-subtitle-text {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 0;
        }

        /* Inputs — strictly 16px font-size to prevent mobile iOS auto-zooming */
        .ultra-label { 
            font-size: 0.72rem; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            color: #475569; 
            margin-bottom: 5px; 
            margin-left: 3px; 
            display: inline-block; 
        }
        
        .ultra-input-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .ultra-input-group i {
            position: absolute;
            left: 15px;
            color: #64748b;
            font-size: 1.1rem;
            pointer-events: none;
            z-index: 4;
        }
        .ultra-input { 
            border-radius: 15px; 
            background: #f8fafc; 
            border: 2px solid transparent; 
            padding: 11px 16px 11px 44px; 
            font-weight: 600; 
            font-size: 16px !important; /* Critical: Stops iOS zoom on focus */
            color: #0f172a; 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); 
            height: 48px;
            width: 100%;
            touch-action: manipulation;
        }
        .ultra-input:focus { 
            background: #ffffff; 
            border-color: #3b82f6; 
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12); 
            outline: none; 
        }

        /* PIN Input */
        .ultra-pin-input {
            border-radius: 18px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            padding: 10px 8px;
            font-weight: 800;
            font-family: monospace;
            font-size: 1.7rem !important;
            letter-spacing: 12px;
            text-align: center;
            color: #0f172a;
            transition: all 0.2s;
            max-width: 220px;
            margin: 0 auto;
            height: 54px;
            touch-action: manipulation;
        }
        .ultra-pin-input:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
            outline: none;
        }

        /* Buttons */
        .btn-ultra-primary { 
            background: linear-gradient(135deg, #0f172a, #334155); 
            color: #ffffff; 
            border: none; 
            border-radius: 100px; 
            padding: 11px 24px; 
            font-weight: 600; 
            font-size: 0.95rem;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18); 
            transition: all 0.2s; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            width: 100%;
            height: 46px;
            touch-action: manipulation;
        }
        .btn-ultra-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 12px 25px rgba(15, 23, 42, 0.28); 
            color: #ffffff; 
        }
        .btn-ultra-primary:active {
            transform: translateY(0);
        }

        .btn-ultra-secondary { 
            background: #ffffff; 
            color: #475569; 
            border: 1px solid #cbd5e1; 
            border-radius: 100px; 
            padding: 11px 24px; 
            font-weight: 600; 
            font-size: 0.95rem;
            transition: all 0.2s; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02); 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            width: 100%;
            height: 46px;
            text-decoration: none;
            touch-action: manipulation;
        }
        .btn-ultra-secondary:hover { 
            background: #f8fafc; 
            color: #0f172a; 
            border-color: #94a3b8; 
        }

        /* Alerts */
        .ultra-alert {
            border-radius: 14px;
            border: none;
            padding: 10px 14px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Screen-height responsive compacting for shorter devices (e.g., iPhone SE / 667px) */
        @media (max-height: 680px) {
            .ultra-login-card {
                padding: 1.5rem 1.4rem;
            }
            .brand-avatar {
                width: 44px;
                height: 44px;
                font-size: 1.25rem;
                margin-bottom: 0.5rem;
            }
            .card-title-text {
                font-size: 1.15rem;
            }
            .mb-4 {
                margin-bottom: 0.75rem !important;
            }
            .mb-3 {
                margin-bottom: 0.65rem !important;
            }
            .ultra-input {
                height: 44px;
                padding-top: 8px;
                padding-bottom: 8px;
            }
            .btn-ultra-primary, .btn-ultra-secondary {
                height: 42px;
            }
        }
    </style>
</head>
<body>

    <div class="ultra-login-card">
        
        <!-- Header Branding & Subtitles -->
        <div class="text-center mb-3">
            <div class="brand-avatar">
                <i class="bi bi-briefcase-fill"></i>
            </div>
            <div>
                <div class="security-badge">
                    <i class="bi bi-shield-check"></i> Enterprise Access
                </div>
            </div>
            <h3 class="card-title-text">StopLord Admin Panel</h3>
            <p class="card-subtitle-text">Secure Employee & Admin Gateway</p>
        </div>

        <!-- Alert Notifications -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger ultra-alert shadow-sm d-flex align-items-center mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5 me-2 text-danger flex-shrink-0"></i>
                <div><?= escapeOutput($error_message) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success ultra-alert shadow-sm d-flex align-items-center mb-3" role="alert">
                <i class="bi bi-check-circle-fill fs-5 me-2 text-success flex-shrink-0"></i>
                <div><?= escapeOutput($success_message) ?></div>
            </div>
        <?php endif; ?>

        <!-- ========================================== -->
        <!-- STEP 1: STANDARD LOGIN FORM                -->
        <!-- ========================================== -->
        <?php if ($step === 'login' || !isset($_SESSION['temp_super_admin_id'])): ?>
            <form method="POST" action="/public/login.php" id="loginForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                
                <div class="mb-3">
                    <label for="username" class="ultra-label">Email or Mobile</label>
                    <div class="ultra-input-group">
                        <i class="bi bi-person"></i>
                        <input type="text" class="form-control ultra-input" id="username" name="username" placeholder="enter your email or mobile" required autofocus autocomplete="username">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="ultra-label">Password</label>
                    <div class="ultra-input-group">
                        <i class="bi bi-lock"></i>
                        <input type="password" class="form-control ultra-input" id="password" name="password" placeholder="enter your password" required autocomplete="current-password">
                    </div>
                </div>
                
                <div class="pt-1">
                    <button type="submit" class="btn btn-ultra-primary">
                        Sign In <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        
        <!-- ========================================== -->
        <!-- STEP 2: SUPER ADMIN PIN FORM               -->
        <!-- ========================================== -->
        <?php elseif ($step === 'pin' && isset($_SESSION['temp_super_admin_id'])): ?>
            <div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-4 p-2 text-center mb-3">
                <span class="badge bg-primary rounded-pill px-3 py-1 mb-1 shadow-sm fw-bold">Super Admin Verification</span>
                <p class="text-dark small mb-0 fw-medium" style="font-size: 0.78rem;">Two-Step PIN verification is required to access the central console.</p>
            </div>

            <form method="POST" action="/public/login.php?step=pin" id="pinForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_pin">
                
                <div class="mb-3 text-center">
                    <label for="pin" class="ultra-label d-block mb-1">Enter 6-Digit Secure PIN</label>
                    <input type="password" class="form-control ultra-pin-input shadow-sm" id="pin" name="pin" placeholder="••••••" required autofocus maxlength="6" inputmode="numeric" pattern="[0-9]*">
                </div>
                
                <div class="d-flex gap-2 pt-1">
                    <a href="/public/login.php" class="btn btn-ultra-secondary">Cancel</a>
                    <button type="submit" class="btn btn-ultra-primary">
                        Verify <i class="bi bi-shield-lock-fill ms-1"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- Footer Notice -->
        <div class="text-center mt-3 pt-1 text-muted small fw-medium" style="font-size: 0.72rem;">
            stoplord.com &copy; <?= date('Y') ?> &bull; Authorized Personnel Only
        </div>
    </div>

    <script src="assets/js/script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- COMPLETE ZERO-SCROLL & HARDCORE GESTURE CONTROLS -->
    <script>
        // 1. Prevent Screen Bouncing, Pull-to-Refresh & Touch Drag Outside Form Controls
        document.addEventListener('touchmove', function(e) {
            // Only allow touch interaction inside inputs/buttons, completely freeze document drag
            if (!e.target.closest('input, button, a')) {
                e.preventDefault();
            }
        }, { passive: false });

        // 2. Prevent Pinch & Gesture Zooming
        document.addEventListener('gesturestart', function(e) { e.preventDefault(); }, { passive: false });
        document.addEventListener('gesturechange', function(e) { e.preventDefault(); }, { passive: false });
        document.addEventListener('gestureend', function(e) { e.preventDefault(); }, { passive: false });

        // 3. Prevent Double Tap Zoom
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // 4. Prevent Multi-finger Touch
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // 5. Prevent Mouse Wheel Zoom on Desktop
        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        // 6. Prevent Zoom Keys & Security Combinations
        document.addEventListener('keydown', function (e) {
            // Zoom combinations
            if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0' || e.key === '_')) {
                e.preventDefault();
            }
            // Inspection restrictions
            if (e.key === "F12") { e.preventDefault(); }
            if (e.ctrlKey && e.key.toLowerCase() === "u") { e.preventDefault(); }
            if (e.ctrlKey && e.shiftKey && ["i", "j", "c"].includes(e.key.toLowerCase())) { e.preventDefault(); }
            if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); }
        });

        // 7. Prevent Context Menu
        document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    </script>
</body>
</html>