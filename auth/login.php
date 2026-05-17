<?php
// auth/login.php - Professional Login Page with Back Arrow & Bottom Navigation
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/audit.php';
require_once '../includes/functions.php';

// Get database connection
$conn = getDB();

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    switch($_SESSION['role']) {
        case 'super_admin':
            header("Location: ../admin/dashboard.php");
            break;
        case 'claimant':
            header("Location: ../claimant/dashboard.php");
            break;
        case 'valuer':
            header("Location: ../valuer/dashboard.php");
            break;
        case 'legal_officer':
            header("Location: ../legal/dashboard.php");
            break;
        case 'finance_officer':
            header("Location: ../finance/dashboard.php");
            break;
        case 'commissioner':
            header("Location: ../commissioner/dashboard.php");
            break;
        default:
            header("Location: ../dashboard.php");
            break;
    }
    exit();
}

$error_message = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']) ? true : false;
    
    if (empty($email) || empty($password)) {
        $error_message = "Please enter your email/phone and password.";
    } else {
        
        // Search by email OR phone
        $query = "SELECT id, full_name, email, phone, role, password, status, nin, created_at 
                  FROM users 
                  WHERE email = ? OR phone = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ss", $email, $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($user = mysqli_fetch_assoc($result)) {
            
            if ($user['status'] !== 'active') {
                $error_message = "Your account is inactive. Please contact the administrator.";
            }
            else if (password_verify($password, $user['password'])) {
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['nin'] = $user['nin'];
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                
                // Update last login
                $check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_login'");
                if (mysqli_num_rows($check_column) > 0) {
                    $update_stmt = mysqli_prepare($conn, "UPDATE users SET last_login = NOW() WHERE id = ?");
                    mysqli_stmt_bind_param($update_stmt, "i", $user['id']);
                    mysqli_stmt_execute($update_stmt);
                }
                
                // Log audit
                if (function_exists('logAudit')) {
                    logAudit($conn, $user['id'], 'LOGIN_SUCCESS', 'users', $user['id'], null, [
                        'email' => $email,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                    ]);
                }
                
                // Remember me
                if ($remember_me) {
                    $check_token_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'remember_token'");
                    if (mysqli_num_rows($check_token_column) > 0) {
                        $token = bin2hex(random_bytes(32));
                        $expiry = time() + (86400 * 7);
                        $token_stmt = mysqli_prepare($conn, "UPDATE users SET remember_token = ?, token_expiry = FROM_UNIXTIME(?) WHERE id = ?");
                        mysqli_stmt_bind_param($token_stmt, "sii", $token, $expiry, $user['id']);
                        mysqli_stmt_execute($token_stmt);
                        setcookie('remember_token', $token, $expiry, '/', '', false, true);
                    }
                }
                
                $_SESSION['login_success'] = "Welcome back, " . $user['full_name'] . "!";
                
                // Redirect based on role
                $redirect_url = '';
                switch($user['role']) {
                    case 'super_admin':
                        $redirect_url = '../admin/dashboard.php';
                        break;
                    case 'claimant':
                        $redirect_url = '../claimant/dashboard.php';
                        break;
                    case 'valuer':
                        $redirect_url = '../valuer/dashboard.php';
                        break;
                    case 'legal_officer':
                        $redirect_url = '../legal/dashboard.php';
                        break;
                    case 'finance_officer':
                        $redirect_url = '../finance/dashboard.php';
                        break;
                    case 'commissioner':
                        $redirect_url = '../commissioner/dashboard.php';
                        break;
                    default:
                        $redirect_url = '../dashboard.php';
                        break;
                }
                
                header("Location: " . $redirect_url);
                exit();
                
            } else {
                $error_message = "Invalid email/phone or password.";
                if (function_exists('logAudit')) {
                    logAudit($conn, 0, 'LOGIN_FAILED', 'users', null, null, ['email' => $email]);
                }
            }
        } else {
            $error_message = "Invalid email/phone or password.";
            if (function_exists('logAudit')) {
                logAudit($conn, 0, 'LOGIN_FAILED', 'users', null, null, ['email' => $email]);
            }
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#006e2c"/>
<title>Login | HCS - House Compensation System</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "on-surface": "#161d16",
                        "secondary-fixed": "#ffe07f",
                        "on-tertiary-fixed": "#400014",
                        "surface-container-lowest": "#ffffff",
                        "tertiary-fixed-dim": "#ffb2bd",
                        "tertiary": "#ad2c4e",
                        "surface-container-low": "#eef6ea",
                        "surface-container-high": "#e3eadf",
                        "on-secondary-container": "#6f5900",
                        "inverse-primary": "#5be079",
                        "surface": "#f4fcef",
                        "primary-fixed-dim": "#5be079",
                        "on-secondary-fixed-variant": "#564500",
                        "on-tertiary-container": "#690025",
                        "secondary-container": "#fed000",
                        "on-tertiary": "#ffffff",
                        "on-primary-fixed-variant": "#005320",
                        "inverse-surface": "#2b322a",
                        "on-error": "#ffffff",
                        "primary-fixed": "#79fd92",
                        "primary-container": "#1eb050",
                        "on-secondary": "#ffffff",
                        "primary": "#006e2c",
                        "on-surface-variant": "#3d4a3d",
                        "on-primary-fixed": "#002108",
                        "on-background": "#161d16",
                        "error": "#ba1a1a",
                        "tertiary-fixed": "#ffd9dd",
                        "surface-container-highest": "#dde5d9",
                        "on-error-container": "#93000a",
                        "outline-variant": "#bccab9",
                        "background": "#f4fcef",
                        "secondary-fixed-dim": "#edc200",
                        "surface-variant": "#dde5d9",
                        "on-secondary-fixed": "#231b00",
                        "on-tertiary-fixed-variant": "#8d0f38",
                        "error-container": "#ffdad6",
                        "on-primary-container": "#003a14",
                        "outline": "#6d7b6c",
                        "surface-container": "#e8f0e4",
                        "tertiary-container": "#fb6787",
                        "surface-tint": "#006e2c",
                        "on-primary": "#ffffff",
                        "secondary": "#725c00",
                        "surface-dim": "#d4dcd1",
                        "surface-bright": "#f4fcef",
                        "inverse-on-surface": "#ebf3e7"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    "spacing": {
                        "margin-mobile": "16px",
                        "sm": "12px",
                        "max-width": "1280px",
                        "md": "24px",
                        "xs": "4px",
                        "base": "8px",
                        "lg": "48px",
                        "gutter": "24px",
                        "margin-desktop": "64px",
                        "xl": "80px"
                    },
                    "fontFamily": {
                        "label-md": ["Inter"],
                        "headline-lg-mobile": ["Inter"],
                        "headline-md": ["Inter"],
                        "headline-lg": ["Inter"],
                        "display-lg-mobile": ["Inter"],
                        "body-lg": ["Inter"],
                        "display-lg": ["Inter"],
                        "body-md": ["Inter"],
                        "label-sm": ["Inter"],
                        "headline-sm": ["Inter"],
                        "body-sm": ["Inter"]
                    },
                    "fontSize": {
                        "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                        "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                        "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}],
                        "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                        "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                        "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}],
                        "headline-sm": ["20px", {"lineHeight": "1.4", "fontWeight": "600"}],
                        "body-sm": ["14px", {"lineHeight": "1.5", "fontWeight": "400"}]
                    }
                },
            },
        }
    </script>
<style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4fcef;
            min-height: 100vh;
            padding-bottom: 65px;
        }
        @media (min-width: 768px) {
            body {
                padding-bottom: 0;
            }
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 110, 44, 0.15);
        }
        .pattern-bg {
            background-image: radial-gradient(#6d7b6c 0.5px, transparent 0.5px);
            background-size: 24px 24px;
            opacity: 0.05;
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .back-arrow {
            transition: transform 0.2s ease;
        }
        .back-arrow:hover {
            transform: translateX(-4px);
        }
        .bottom-nav-item {
            transition: all 0.2s ease;
        }
        .bottom-nav-item:active {
            transform: scale(0.9);
        }
        @supports (padding-bottom: env(safe-area-inset-bottom)) {
            .bottom-nav {
                padding-bottom: env(safe-area-inset-bottom);
            }
        }
    </style>
</head>
<body class="bg-background min-h-screen flex flex-col">

<!-- Top Navigation Bar with Back Arrow -->
<header class="bg-surface border-b-2 border-secondary sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto h-16 md:h-20">
        <div class="flex items-center gap-3">
            <a href="../index.php" class="hover:opacity-80 transition-opacity back-arrow">
                <span class="material-symbols-outlined text-primary">arrow_back</span>
            </a>
            <a href="../index.php" class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-headline-md">account_balance</span>
                <h1 class="font-headline-md text-headline-md-mobile md:text-headline-md text-primary font-bold">HCS</h1>
            </a>
        </div>
        <a href="help.php" class="font-label-md text-label-md text-primary hover:underline transition-all">Help</a>
    </div>
</header>

<!-- Main Content -->
<main class="flex-grow flex items-center justify-center px-margin-mobile md:px-lg py-xl relative overflow-hidden">
<!-- Abstract Tanzanian Themed Background -->
<div class="absolute inset-0 pattern-bg pointer-events-none"></div>
<div class="absolute top-0 right-0 w-1/3 h-full bg-secondary-container opacity-10 -skew-x-12 translate-x-1/4 z-0"></div>
<div class="absolute -bottom-24 -left-24 w-96 h-96 bg-primary opacity-5 rounded-full blur-3xl z-0"></div>
<div class="w-full max-w-[1100px] grid grid-cols-1 lg:grid-cols-12 gap-gutter items-center z-10">
<!-- Left Side: Branding & Context (Hidden on Mobile) -->
<div class="hidden lg:flex lg:col-span-7 flex-col pr-xl">
<div class="mb-xl">
<div class="flex items-center gap-sm mb-md">
<div class="w-14 h-14 bg-primary rounded flex items-center justify-center shadow-lg">
<span class="material-symbols-outlined text-on-primary text-3xl">account_balance</span>
</div>
<h1 class="font-display-lg text-[32px] text-primary tracking-tight">HCS</h1>
</div>
<h2 class="font-display-lg text-display-lg text-inverse-surface mb-md">House Compensation System</h2>
<p class="font-body-lg text-body-lg text-on-surface-variant max-w-lg">
                        Providing a secure, transparent, and efficient platform for managing institutional claims and government affairs compensation within the United Republic.
                    </p>
</div>
<div class="grid grid-cols-2 gap-md mt-lg">
<div class="p-md border border-outline-variant rounded-xl bg-surface-container-lowest shadow-sm hover:shadow-md transition-shadow">
<span class="material-symbols-outlined text-primary mb-sm text-3xl">verified_user</span>
<h3 class="font-label-md text-label-md text-on-surface">Secure Access</h3>
<p class="font-body-sm text-body-sm text-on-surface-variant">Multi-factor authentication for national security protocols.</p>
</div>
<div class="p-md border border-outline-variant rounded-xl bg-surface-container-lowest shadow-sm hover:shadow-md transition-shadow">
<span class="material-symbols-outlined text-secondary mb-sm text-3xl">gavel</span>
<h3 class="font-label-md text-label-md text-on-surface">Official Portal</h3>
<p class="font-body-sm text-body-sm text-on-surface-variant">Authorized executive compensation regulatory framework.</p>
</div>
</div>
</div>
<!-- Right Side: Login Card -->
<div class="lg:col-span-5 w-full">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl shadow-2xl p-xl md:p-xxl relative overflow-hidden">
<!-- Accent Gold Bar at top -->
<div class="absolute top-0 left-0 right-0 h-1.5 bg-secondary-container"></div>
<!-- Mobile Logo (Visible on Mobile Only) -->
<div class="lg:hidden flex flex-col items-center mb-xl">
<div class="w-16 h-16 bg-primary rounded flex items-center justify-center mb-sm shadow-md">
<span class="material-symbols-outlined text-on-primary text-4xl">account_balance</span>
</div>
<h1 class="font-headline-sm text-headline-sm text-primary">House Compensation System</h1>
</div>
<div class="mb-xl">
<h2 class="font-headline-md text-headline-md text-on-surface mb-xs">Sign In</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Enter your portal credentials to proceed.</p>
</div>

<!-- Error Message Area (Hidden initially, shown via SweetAlert) -->
<?php if (!empty($error_message)): ?>
<input type="hidden" id="php-error" value="<?php echo htmlspecialchars($error_message); ?>">
<?php endif; ?>

<form method="POST" action="" id="loginForm" class="space-y-lg">
<!-- CSRF Token -->
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

<!-- Email/Phone Field -->
<div class="space-y-xs">
<label class="font-label-md text-label-md text-on-surface-variant block" for="email">Email Address or Phone Number</label>
<div class="relative">
<span class="material-symbols-outlined absolute left-md top-1/2 -translate-y-1/2 text-outline">mail</span>
<input class="w-full h-[52px] pl-[48px] pr-md border border-outline rounded-lg bg-surface-bright text-on-surface font-body-md focus:border-primary focus:ring-1 focus:ring-primary transition-all" 
       id="email" 
       name="email" 
       placeholder="e.g., admin@hcs.gov.tz or 0712345678" 
       required="" 
       type="text"
       value="admin@hcs.go.tz">
</div>
</div>

<!-- Password Field -->
<div class="space-y-xs">
<div class="flex justify-between items-center">
<label class="font-label-md text-label-md text-on-surface-variant" for="password">Password</label>
<a class="font-label-sm text-label-sm text-primary hover:text-primary-container transition-colors" href="forgot-password.php">Forgot Password?</a>
</div>
<div class="relative">
<span class="material-symbols-outlined absolute left-md top-1/2 -translate-y-1/2 text-outline">lock</span>
<input class="w-full h-[52px] pl-[48px] pr-[48px] border border-outline rounded-lg bg-surface-bright text-on-surface font-body-md focus:border-primary focus:ring-1 focus:ring-primary transition-all" 
       id="password" 
       name="password" 
       placeholder="••••••••" 
       required="" 
       type="password"
       value="admin123">
<button type="button" id="togglePassword" class="absolute right-md top-1/2 -translate-y-1/2 text-outline hover:text-primary transition">
<span class="material-symbols-outlined">visibility_off</span>
</button>
</div>
</div>

<!-- Remember Me -->
<div class="flex items-center">
<label class="flex items-center gap-2 cursor-pointer">
<input type="checkbox" name="remember_me" class="w-4 h-4 text-primary border-outline rounded focus:ring-primary">
<span class="font-body-sm text-body-sm text-on-surface-variant">Remember me for 7 days</span>
</label>
</div>

<!-- Login Button -->
<button type="submit" name="login" id="loginBtn" 
        class="w-full h-[52px] bg-primary text-on-primary font-label-md rounded-lg flex items-center justify-center gap-sm hover:bg-primary-container active:scale-[0.98] transition-all shadow-md">
<span id="btnText">Login to Portal</span>
<span id="btnIcon" class="material-symbols-outlined">login</span>
<span id="btnSpinner" class="hidden material-symbols-outlined animate-spin">sync</span>
</button>

<div class="relative flex py-sm items-center">
<div class="flex-grow border-t border-outline-variant"></div>
<span class="flex-shrink mx-md text-outline font-label-sm uppercase tracking-widest">or</span>
<div class="flex-grow border-t border-outline-variant"></div>
</div>

<!-- Secondary Action -->
<div class="text-center">
<p class="font-body-sm text-body-sm text-on-surface-variant mb-sm">Are you a new claimant?</p>
<a href="register.php" class="inline-flex items-center gap-xs font-label-md text-primary hover:text-primary-container group transition-all">
<span>Register Account</span>
<span class="material-symbols-outlined text-[18px] group-hover:translate-x-1 transition-transform">arrow_forward</span>
</a>
</div>
</form>
</div>
</div>
</div>
</main>

<!-- Secure Footer -->
<footer class="bg-surface-container-high py-md border-t border-outline-variant">
<div class="max-w-[1100px] mx-auto px-margin-mobile flex flex-col md:flex-row justify-between items-center gap-md">
<div class="flex items-center gap-sm">
<div class="w-10 h-10 rounded-full bg-primary-fixed-dim flex items-center justify-center">
<span class="material-symbols-outlined text-[20px] text-on-primary-fixed-variant">security</span>
</div>
<div>
<p class="font-label-sm text-on-surface leading-tight">Official Government Portal</p>
<p class="text-[11px] text-on-surface-variant">AES-256 Encrypted Session</p>
</div>
</div>
<div class="flex gap-lg">
<a class="font-label-sm text-label-sm text-on-surface-variant hover:text-primary transition-colors" href="../privacy.php">Privacy Policy</a>
<a class="font-label-sm text-label-sm text-on-surface-variant hover:text-primary transition-colors" href="../terms.php">Terms of Service</a>
<a class="font-label-sm text-label-sm text-on-surface-variant hover:text-primary transition-colors" href="../support.php">Support</a>
</div>
</div>
</footer>

<!-- BOTTOM NAVIGATION BAR - Mobile Only (Same as register page) -->
<nav class="bottom-nav fixed bottom-0 left-0 right-0 bg-surface border-t border-outline-variant flex justify-around items-center px-2 py-1 shadow-lg z-50 md:hidden" style="padding-bottom: env(safe-area-inset-bottom, 0.5rem);">
    
    <a href="../index.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">home</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Home</span>
    </a>
    
    <a href="../track-claim.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">track_changes</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Track</span>
    </a>
    
    <a href="../notices.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">campaign</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Notices</span>
    </a>
    
    <a href="register.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">person_add</span>
        <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Register</span>
    </a>
    
    <a href="login.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">login</span>
        <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Login</span>
    </a>
    
</nav>

<script>
    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            const icon = this.querySelector('.material-symbols-outlined');
            icon.textContent = type === 'password' ? 'visibility_off' : 'visibility';
        });
    }
    
    // Check for PHP error
    const phpError = document.getElementById('php-error');
    if (phpError) {
        Swal.fire({
            icon: 'error',
            title: 'Login Failed',
            text: phpError.value,
            confirmButtonColor: '#006e2c',
            confirmButtonText: 'Try Again'
        });
    }
    
    // Form submission with SweetAlert
    const loginForm = document.getElementById('loginForm');
    const loginBtn = document.getElementById('loginBtn');
    const btnText = document.getElementById('btnText');
    const btnIcon = document.getElementById('btnIcon');
    const btnSpinner = document.getElementById('btnSpinner');
    
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const rememberMe = document.querySelector('input[name="remember_me"]')?.checked || false;
            
            // Validation
            if (!email || !password) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Information Required',
                    text: 'Please enter your email/phone and password.',
                    confirmButtonColor: '#006e2c',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            // Email or phone validation
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const phonePattern = /^0[67][0-9]{8}$/;
            if (!emailPattern.test(email) && !phonePattern.test(email)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Format',
                    text: 'Please enter a valid email address or phone number (e.g., 0712345678).',
                    confirmButtonColor: '#006e2c'
                });
                return;
            }
            
            // Show loading state
            loginBtn.disabled = true;
            loginBtn.classList.add('opacity-70', 'cursor-not-allowed');
            btnText.classList.add('hidden');
            btnIcon.classList.add('hidden');
            btnSpinner.classList.remove('hidden');
            
            // Submit form via AJAX
            try {
                const formData = new URLSearchParams({
                    login: '1',
                    csrf_token: document.querySelector('input[name="csrf_token"]').value,
                    email: email,
                    password: password,
                    remember_me: rememberMe ? 'on' : ''
                });
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                });
                
                const text = await response.text();
                
                // Check if redirected (successful login)
                if (response.redirected || text.includes('dashboard') || text.includes('Location')) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Welcome!',
                        text: 'Redirecting to your dashboard...',
                        timer: 1500,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    window.location.href = response.url || '../dashboard.php';
                } else if (text.includes('error_message') || text.includes('Invalid') || text.includes('inactive')) {
                    let errorMsg = 'Invalid email/phone or password.';
                    const errorMatch = text.match(/error_message[^>]*>([^<]+)</);
                    if (errorMatch) {
                        errorMsg = errorMatch[1];
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Login Failed',
                        text: errorMsg,
                        confirmButtonColor: '#006e2c',
                        confirmButtonText: 'Try Again'
                    });
                    
                    // Reset button
                    loginBtn.disabled = false;
                    loginBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                    btnText.classList.remove('hidden');
                    btnIcon.classList.remove('hidden');
                    btnSpinner.classList.add('hidden');
                } else {
                    throw new Error('Login failed');
                }
            } catch (error) {
                console.error('Login error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'System Error',
                    text: 'Network error occurred. Please check your connection and try again.',
                    confirmButtonColor: '#006e2c'
                });
                
                // Reset button
                loginBtn.disabled = false;
                loginBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                btnText.classList.remove('hidden');
                btnIcon.classList.remove('hidden');
                btnSpinner.classList.add('hidden');
            }
        });
    }
    
    // Input focus effects
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', () => {
            const icon = input.parentElement?.querySelector('.material-symbols-outlined:first-child');
            if (icon && !input.parentElement?.querySelector('button')) {
                icon.style.color = '#006e2c';
            }
        });
        input.addEventListener('blur', () => {
            const icon = input.parentElement?.querySelector('.material-symbols-outlined:first-child');
            if (icon && !input.parentElement?.querySelector('button')) {
                icon.style.color = '#6d7b6c';
            }
        });
    });
    
    // Active bottom nav highlight
    const currentPage = window.location.pathname.split('/').pop() || 'login.php';
    const bottomNavLinks = document.querySelectorAll('.bottom-nav-item');
    
    bottomNavLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage || (currentPage === 'login.php' && href === 'login.php')) {
            const icon = link.querySelector('.material-symbols-outlined');
            const text = link.querySelector('span:last-child');
            if (icon) {
                icon.style.fontVariationSettings = "'FILL' 1";
                icon.classList.add('text-primary');
            }
            if (text) {
                text.classList.add('text-primary', 'font-bold');
            }
        }
    });
</script>
</body>
</html>