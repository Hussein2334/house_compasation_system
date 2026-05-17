<?php
// auth/register.php - Complete Registration Page with SweetAlert2

session_start();

// Correct include paths - from auth/ folder, go up one level to root, then into config/
require_once __DIR__ . '/../config/db.php';      // Provides getDB() function
require_once __DIR__ . '/../includes/audit.php'; // Provides logAudit() function
require_once __DIR__ . '/../includes/functions.php'; // Provides utility functions

$error = '';
$success = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Token ya usalama ni batili. Tafadhali jaribu tena.';
    } else {
        // Get and sanitize inputs
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $nin = sanitizeInput($_POST['nin'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $terms = isset($_POST['terms']);

        // Validation
        if (empty($full_name) || empty($email) || empty($phone) || empty($nin) || empty($password)) {
            $error = 'Tafadhali jaza sehemu zote zinazohitajika.';
        } elseif (!$terms) {
            $error = 'Tafadhali kubali Vigezo na Masharti na Sera ya Faragha.';
        } elseif (!validateEmail($email)) {
            $error = 'Barua pepe si sahihi. Tafadhali ingiza barua pepe halali.';
        } elseif (!validatePhone($phone)) {
            $error = 'Namba ya simu si sahihi. Tumia muundo: 0712345678 (Anza na 0 ikifuatiwa na 6 au 7, jumla ya tarakimu 10).';
        } elseif (strlen($nin) < 8) {
            $error = 'Namba ya utambulisho (NIN) lazima iwe na angalau herufi 8.';
        } elseif (strlen($password) < 6) {
            $error = 'Nywila lazima iwe na angalau herufi 6.';
        } elseif ($password !== $confirm_password) {
            $error = 'Nywila na uthibitisho wa nywila hazifanani.';
        } else {
            // Connect to database
            $conn = getDB();
            
            // Check if email already exists
            $check_email = "SELECT id FROM users WHERE email = ?";
            $stmt = mysqli_prepare($conn, $check_email);
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $error = 'Barua pepe tayari imesajiliwa. Tafadhali tumia barua pepe nyingine au ingia kwenye akaunti yako.';
            } else {
                // Check if phone already exists
                $check_phone = "SELECT id FROM users WHERE phone = ?";
                $stmt_phone = mysqli_prepare($conn, $check_phone);
                mysqli_stmt_bind_param($stmt_phone, "s", $phone);
                mysqli_stmt_execute($stmt_phone);
                mysqli_stmt_store_result($stmt_phone);
                
                if (mysqli_stmt_num_rows($stmt_phone) > 0) {
                    $error = 'Namba ya simu tayari imesajiliwa. Tafadhali tumia namba nyingine au ingia kwenye akaunti yako.';
                } else {
                    // Check if NIN already exists
                    $check_nin = "SELECT id FROM users WHERE nin = ?";
                    $stmt_nin = mysqli_prepare($conn, $check_nin);
                    mysqli_stmt_bind_param($stmt_nin, "s", $nin);
                    mysqli_stmt_execute($stmt_nin);
                    mysqli_stmt_store_result($stmt_nin);
                    
                    if (mysqli_stmt_num_rows($stmt_nin) > 0) {
                        $error = 'Namba ya utambulisho (NIN) tayari imesajiliwa. Tafadhali hakikisha umeweka namba sahihi.';
                    } else {
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert new user
                        $role = 'claimant'; // Default role for new registrations
                        $status = 'active';
                        $created_at = date('Y-m-d H:i:s');
                        
                        $insert_query = "INSERT INTO users (full_name, email, phone, nin, role, password, status, created_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = mysqli_prepare($conn, $insert_query);
                        mysqli_stmt_bind_param($insert_stmt, "ssssssss", $full_name, $email, $phone, $nin, $role, $hashed_password, $status, $created_at);
                        
                        if (mysqli_stmt_execute($insert_stmt)) {
                            $new_user_id = mysqli_insert_id($conn);
                            
                            // Log the registration
                            logAudit($conn, $new_user_id, 'REGISTER', 'users', $new_user_id, null, [
                                'full_name' => $full_name,
                                'email' => $email,
                                'phone' => $phone,
                                'nin' => $nin,
                                'role' => $role
                            ]);
                            
                            // Create welcome notification
                            if (function_exists('createNotification')) {
                                createNotification($conn, $new_user_id, 'Karibu HCS!', 'Asante kwa kujisajili katika Mfumo wa Fidia ya Makazi. Unaweza sasa kuwasilisha maombi yako ya fidia.', 'welcome');
                            }
                            
                            $success = 'Usajili wako umefanikiwa! Sasa unaweza kuingia kwenye akaunti yako.';
                            
                            // Clear CSRF token after successful registration
                            unset($_SESSION['csrf_token']);
                            
                        } else {
                            $error = 'Kuna hitilafu katika mfumo. Tafadhali jaribu tena baadaye.';
                            error_log("Registration failed: " . mysqli_error($conn));
                        }
                        mysqli_stmt_close($insert_stmt);
                    }
                    mysqli_stmt_close($stmt_nin);
                }
                mysqli_stmt_close($stmt_phone);
            }
            mysqli_stmt_close($stmt);
            mysqli_close($conn);
        }
    }
}

// Generate CSRF token for the form
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Jisajili | House Compensation System (HCS)</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
    <!-- SweetAlert2 CSS & JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "surface-bright": "#f4fcef",
                        "on-tertiary-container": "#690025",
                        "primary-fixed": "#79fd92",
                        "on-background": "#161d16",
                        "on-error-container": "#93000a",
                        "on-surface-variant": "#3d4a3d",
                        "on-primary-fixed": "#002108",
                        "on-error": "#ffffff",
                        "secondary-container": "#fed000",
                        "inverse-primary": "#5be079",
                        "on-secondary-container": "#6f5900",
                        "outline-variant": "#bccab9",
                        "inverse-surface": "#2b322a",
                        "tertiary-fixed-dim": "#ffb2bd",
                        "surface": "#f4fcef",
                        "on-tertiary-fixed": "#400014",
                        "surface-tint": "#006e2c",
                        "primary-container": "#1eb050",
                        "on-secondary-fixed": "#231b00",
                        "surface-variant": "#dde5d9",
                        "secondary-fixed": "#ffe07f",
                        "primary-fixed-dim": "#5be079",
                        "background": "#f4fcef",
                        "primary": "#006e2c",
                        "surface-container": "#e8f0e4",
                        "on-primary-fixed-variant": "#005320",
                        "on-secondary": "#ffffff",
                        "on-tertiary": "#ffffff",
                        "secondary": "#725c00",
                        "on-primary": "#ffffff",
                        "inverse-on-surface": "#ebf3e7",
                        "surface-container-high": "#e3eadf",
                        "outline": "#6d7b6c",
                        "on-tertiary-fixed-variant": "#8d0f38",
                        "on-primary-container": "#003a14",
                        "error-container": "#ffdad6",
                        "error": "#ba1a1a",
                        "tertiary": "#ad2c4e",
                        "surface-container-lowest": "#ffffff",
                        "on-secondary-fixed-variant": "#564500",
                        "surface-container-low": "#eef6ea",
                        "surface-container-highest": "#dde5d9",
                        "on-surface": "#161d16",
                        "tertiary-container": "#fb6787",
                        "tertiary-fixed": "#ffd9dd",
                        "secondary-fixed-dim": "#edc200",
                        "surface-dim": "#d4dcd1"
                    },
                    borderRadius: {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    spacing: {
                        "margin-desktop": "64px",
                        "base": "8px",
                        "xl": "80px",
                        "xs": "4px",
                        "lg": "48px",
                        "sm": "12px",
                        "gutter": "24px",
                        "md": "24px",
                        "max-width": "1280px",
                        "margin-mobile": "16px"
                    },
                    fontFamily: {
                        "body-lg": ["Inter"],
                        "label-md": ["Inter"],
                        "headline-md": ["Inter"],
                        "headline-lg": ["Inter"],
                        "display-lg": ["Inter"],
                        "body-md": ["Inter"],
                        "headline-lg-mobile": ["Inter"],
                        "display-lg-mobile": ["Inter"],
                        "label-sm": ["Inter"]
                    },
                    fontSize: {
                        "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                        "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                        "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}],
                        "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                        "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                        "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}]
                    }
                },
            },
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .form-input-focus:focus {
            border-color: #1eb050;
            outline: none;
            box-shadow: 0 0 0 2px rgba(30, 176, 80, 0.2);
        }
        body {
            min-height: max(884px, 100dvh);
        }
        /* Loading spinner animation */
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-surface text-on-surface font-body-md selection:bg-primary-fixed selection:text-on-primary-fixed min-h-screen flex flex-col">
    <!-- Top Navigation Bar -->
    <header class="bg-surface border-b-2 border-secondary sticky top-0 z-50">
        <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto h-16 md:h-20">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-headline-md">account_balance</span>
                <h1 class="font-headline-md text-headline-md-mobile md:text-headline-md text-primary font-bold">HCS</h1>
            </div>
            <a class="font-label-md text-label-md text-primary hover:underline transition-all" href="help.php">Msaada</a>
        </div>
    </header>

    <main class="flex-grow flex items-center justify-center py-lg px-margin-mobile">
        <div class="w-full max-w-2xl bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden flex flex-col md:flex-row shadow-sm">
            <!-- Left Branding Column -->
            <div class="hidden md:flex md:w-1/3 bg-on-background p-md flex-col justify-between relative overflow-hidden">
                <div class="z-10">
                    <img alt="HCS Branding" class="absolute inset-0 w-full h-full object-cover opacity-20 grayscale brightness-125" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDHCjB9OGxwR1zXmlpopdusfTJd9-Ita31WzQ8KVpxoHIdM-S0IwkwJjCvZrbtQL1eRXR5yB-twDbPRrcVh3voMQPJFU6c_U42lJ3u82D7tz7WG6Jt2bo6XCgHk1HiRqADja8LIZKoo7pkcGsfBywltBlD5w6hFslZA-c4_88WK_LULHN54XdXgB66MrfGqbc2fqD40SY6fP69hSe1mKYZgezhD6d19zVDAfq7E0hgMdJnz1OAa8yVnk2IwrKelYxjUUnjqgYze4PWL">
                    <div class="relative z-20">
                        <h2 class="font-headline-md text-primary-fixed-dim font-bold mb-xs">Uzalendo na Haki</h2>
                        <p class="font-label-sm text-outline-variant">Mfumo wa Fidia ya Makazi (HCS)</p>
                    </div>
                </div>
                <div class="relative z-20">
                    <p class="font-body-md text-surface-variant italic">"Kuhakikisha kila mwananchi anapata haki yake kwa wakati na uwazi."</p>
                </div>
            </div>

            <!-- Right Form Column -->
            <div class="flex-1 p-md md:p-lg">
                <div class="mb-lg">
                    <h2 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface font-bold">Jisajili</h2>
                    <p class="font-body-md text-on-surface-variant mt-xs">Unda akaunti yako ili uweze kuanza mchakato wa maombi ya fidia.</p>
                </div>

                <!-- Hidden error/success divs for SweetAlert (we'll use JS to show alerts) -->
                <?php if (!empty($error)): ?>
                <input type="hidden" id="php-error" value="<?php echo htmlspecialchars($error); ?>">
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                <input type="hidden" id="php-success" value="<?php echo htmlspecialchars($success); ?>">
                <?php endif; ?>

                <form class="space-y-sm" method="POST" action="" id="registerForm">
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <!-- Full Name -->
                    <div class="space-y-xs">
                        <label class="font-label-md text-label-md text-on-surface">Jina Kamili <span class="text-error">*</span></label>
                        <input class="w-full h-12 px-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                               placeholder="Mfano: John Juma" 
                               type="text" 
                               name="full_name" 
                               id="full_name"
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <!-- Email -->
                        <div class="space-y-xs">
                            <label class="font-label-md text-label-md text-on-surface">Barua Pepe <span class="text-error">*</span></label>
                            <input class="w-full h-12 px-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                                   placeholder="barua@mfano.tz" 
                                   type="email" 
                                   name="email" 
                                   id="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   required>
                        </div>
                        <!-- Phone -->
                        <div class="space-y-xs">
                            <label class="font-label-md text-label-md text-on-surface">Namba ya Simu <span class="text-error">*</span></label>
                            <div class="relative flex items-center">
                                <span class="absolute left-sm font-label-md text-on-surface-variant border-r border-outline-variant pr-xs">+255</span>
                                <input class="w-full h-12 pl-[60px] pr-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                                       placeholder="712345678" 
                                       type="tel" 
                                       name="phone" 
                                       id="phone"
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       required>
                            </div>
                        </div>
                    </div>

                    <!-- National ID -->
                    <div class="space-y-xs">
                        <label class="font-label-md text-label-md text-on-surface">Namba ya Utambulisho wa Taifa (NIN) <span class="text-error">*</span></label>
                        <input class="w-full h-12 px-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                               placeholder="Ingiza namba ya NIDA (Angalau herufi 8)" 
                               type="text" 
                               name="nin" 
                               id="nin"
                               value="<?php echo htmlspecialchars($_POST['nin'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <!-- Password -->
                        <div class="space-y-xs">
                            <label class="font-label-md text-label-md text-on-surface">Nywila <span class="text-error">*</span></label>
                            <input class="w-full h-12 px-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                                   placeholder="Angalau herufi 6" 
                                   type="password" 
                                   name="password" 
                                   id="password"
                                   required>
                            <p class="text-label-sm text-on-surface-variant mt-1">Nywila lazima iwe na angalau herufi 6</p>
                        </div>
                        <!-- Confirm Password -->
                        <div class="space-y-xs">
                            <label class="font-label-md text-label-md text-on-surface">Thibitisha Nywila <span class="text-error">*</span></label>
                            <input class="w-full h-12 px-sm bg-surface border border-outline rounded-lg font-body-md text-on-surface form-input-focus transition-all" 
                                   placeholder="********" 
                                   type="password" 
                                   name="confirm_password" 
                                   id="confirm_password"
                                   required>
                        </div>
                    </div>

                    <!-- Password Strength Indicator -->
                    <div id="password-strength" class="hidden mt-1">
                        <div class="flex gap-1">
                            <div class="h-1 flex-1 rounded-full bg-outline-variant" id="strength-1"></div>
                            <div class="h-1 flex-1 rounded-full bg-outline-variant" id="strength-2"></div>
                            <div class="h-1 flex-1 rounded-full bg-outline-variant" id="strength-3"></div>
                            <div class="h-1 flex-1 rounded-full bg-outline-variant" id="strength-4"></div>
                            <div class="h-1 flex-1 rounded-full bg-outline-variant" id="strength-5"></div>
                        </div>
                        <p class="text-label-sm mt-1" id="strength-text"></p>
                    </div>

                    <!-- T&C -->
                    <div class="flex items-start gap-sm py-xs">
                        <input class="mt-1 w-5 h-5 rounded text-primary focus:ring-primary border-outline transition-all cursor-pointer" 
                               type="checkbox" 
                               id="terms" 
                               name="terms"
                               value="1">
                        <label class="font-label-sm text-label-sm text-on-surface-variant cursor-pointer" for="terms">
                            Ninakubali <a class="text-primary font-bold hover:underline" href="terms.php">Vigezo na Masharti</a> pamoja na <a class="text-primary font-bold hover:underline" href="privacy.php">Sera ya Faragha</a> ya mfumo huu. <span class="text-error">*</span>
                        </label>
                    </div>

                    <!-- Action Button -->
                    <button class="w-full h-12 bg-primary-container text-on-primary font-bold rounded-lg hover:brightness-110 active:scale-[0.98] transition-all flex items-center justify-center gap-2" 
                            type="submit" 
                            id="submitBtn">
                        <span id="btnText">Jisajili Sasa</span>
                        <span class="material-symbols-outlined text-[20px]" id="btnIcon">person_add</span>
                        <span id="btnSpinner" class="hidden loading-spinner material-symbols-outlined">progress_activity</span>
                    </button>

                    <!-- Login Redirect -->
                    <div class="text-center pt-md">
                        <p class="font-label-md text-on-surface-variant">Tayari una akaunti? 
                            <a class="text-secondary font-bold hover:underline" href="login.php">Ingia Hapa</a>
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-on-background text-primary-fixed border-t border-outline-variant mt-auto">
        <div class="w-full py-xl px-margin-mobile md:px-margin-desktop grid grid-cols-1 md:grid-cols-2 gap-gutter max-w-max-width mx-auto">
            <div>
                <h3 class="font-black text-headline-sm text-primary-fixed mb-sm">HCS</h3>
                <p class="font-label-sm text-surface-variant max-w-sm">© 2025 House Compensation System (HCS). United Republic of Tanzania. Haki zote zimehifadhiwa.</p>
            </div>
            <div class="grid grid-cols-2 gap-sm">
                <div class="space-y-xs">
                    <h4 class="font-label-md font-bold text-secondary-fixed">Huduma</h4>
                    <ul class="space-y-xs">
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="#">Kufuatilia Madai</a></li>
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="#">Mwongozo wa Mchakato</a></li>
                    </ul>
                </div>
                <div class="space-y-xs">
                    <h4 class="font-label-md font-bold text-secondary-fixed">Msaada</h4>
                    <ul class="space-y-xs">
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="#">Wasiliana Nasi</a></li>
                        <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="privacy.php">Sera ya Faragha</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bottom Nav for Mobile -->
    <nav class="md:hidden fixed bottom-0 w-full z-50 bg-surface border-t border-outline-variant flex justify-around items-center h-16 px-4 shadow-sm">
        <a class="flex flex-col items-center justify-center text-on-surface-variant font-medium" href="../index.php">
            <span class="material-symbols-outlined">home</span>
            <span class="font-label-sm text-label-sm">Nyumbani</span>
        </a>
        <a class="flex flex-col items-center justify-center text-primary font-bold" href="register.php">
            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">person_add</span>
            <span class="font-label-sm text-label-sm">Jisajili</span>
        </a>
        <a class="flex flex-col items-center justify-center text-on-surface-variant" href="help.php">
            <span class="material-symbols-outlined">help_outline</span>
            <span class="font-label-sm text-label-sm">Msaada</span>
        </a>
    </nav>

    <script>
        // Check for PHP error/success messages
        const phpError = document.getElementById('php-error');
        const phpSuccess = document.getElementById('php-success');
        
        if (phpError) {
            Swal.fire({
                icon: 'error',
                title: 'Hitilafu!',
                text: phpError.value,
                confirmButtonColor: '#006e2c',
                confirmButtonText: 'Jaribu Tena',
                backdrop: true
            });
        }
        
        if (phpSuccess) {
            Swal.fire({
                icon: 'success',
                title: 'Usajili Umefanikiwa!',
                text: phpSuccess.value,
                confirmButtonColor: '#006e2c',
                confirmButtonText: 'Ingia Sasa',
                backdrop: true,
                showClass: {
                    popup: 'animate__animated animate__fadeInUp'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login.php';
                }
            });
            
            // Auto redirect after 5 seconds
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 5000);
        }
        
        // Form validation and submission with SweetAlert
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');
        const btnSpinner = document.getElementById('btnSpinner');
        
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Get all values
            const fullName = document.getElementById('full_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const nin = document.getElementById('nin').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms').checked;
            
            // Validation
            if (!fullName) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Taarifa Inahitajika',
                    text: 'Tafadhali ingiza jina lako kamili.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('full_name').focus();
                return false;
            }
            
            if (fullName.length < 3) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Jina Fupi Sana',
                    text: 'Jina kamili lazima liwe na angalau herufi 3.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('full_name').focus();
                return false;
            }
            
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Barua Pepe Inahitajika',
                    text: 'Tafadhali ingiza barua pepe yako.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('email').focus();
                return false;
            }
            
            if (!emailPattern.test(email)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Barua Pepe Si Sahihi',
                    text: 'Tafadhali ingiza barua pepe halali (mfano: jina@mfano.tz).',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('email').focus();
                return false;
            }
            
            const phonePattern = /^0[67][0-9]{8}$/;
            if (!phone) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Namba ya Simu Inahitajika',
                    text: 'Tafadhali ingiza namba yako ya simu.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('phone').focus();
                return false;
            }
            
            if (!phonePattern.test(phone)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Namba ya Simu Si Sahihi',
                    text: 'Tumia muundo sahihi: 0712345678 (Anza na 0, ikifuatiwa na 6 au 7, jumla ya tarakimu 10).',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('phone').focus();
                return false;
            }
            
            if (!nin) {
                Swal.fire({
                    icon: 'warning',
                    title: 'NIN Inahitajika',
                    text: 'Tafadhali ingiza namba yako ya utambulisho (NIN).',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('nin').focus();
                return false;
            }
            
            if (nin.length < 8) {
                Swal.fire({
                    icon: 'warning',
                    title: 'NIN Fupi Sana',
                    text: 'Namba ya utambulisho lazima iwe na angalau herufi 8.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('nin').focus();
                return false;
            }
            
            if (!password) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Nywila Inahitajika',
                    text: 'Tafadhali weka nywila yako.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('password').focus();
                return false;
            }
            
            if (password.length < 6) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Nywila Fupi Sana',
                    text: 'Nywila lazima iwe na angalau herufi 6 kwa usalama.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('password').focus();
                return false;
            }
            
            if (!confirmPassword) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thibitisha Nywila',
                    text: 'Tafadhali thibitisha nywila yako.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('confirm_password').focus();
                return false;
            }
            
            if (password !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Nywila Hazifanani',
                    text: 'Nywila na uthibitisho wake hazifanani. Tafadhali hakikisha zinalingana.',
                    confirmButtonColor: '#006e2c'
                });
                document.getElementById('confirm_password').focus();
                return false;
            }
            
            if (!terms) {
                Swal.fire({
                    icon: 'info',
                    title: 'Kubali Masharti',
                    text: 'Tafadhali kubali Vigezo na Masharti na Sera ya Faragha ili kuendelea na usajili.',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            // Confirm registration with SweetAlert
            const confirmResult = await Swal.fire({
                title: 'Thibitisha Usajili',
                text: 'Je, una uhakika unataka kujisajili kwenye mfumo wa HCS?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Jisajili',
                cancelButtonText: 'Hapana, Ghairi'
            });
            
            if (confirmResult.isConfirmed) {
                // Show loading state
                btnText.classList.add('hidden');
                btnIcon.classList.add('hidden');
                btnSpinner.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                
                // Submit the form
                form.submit();
            }
        });
        
        // Real-time phone formatting
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) value = value.slice(0, 10);
                this.value = value;
            });
        }
        
        // Real-time NIN formatting (uppercase)
        const ninInput = document.getElementById('nin');
        if (ninInput) {
            ninInput.addEventListener('input', function(e) {
                this.value = this.value.toUpperCase();
            });
        }
        
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthDiv = document.getElementById('password-strength');
        const strength1 = document.getElementById('strength-1');
        const strength2 = document.getElementById('strength-2');
        const strength3 = document.getElementById('strength-3');
        const strength4 = document.getElementById('strength-4');
        const strength5 = document.getElementById('strength-5');
        const strengthText = document.getElementById('strength-text');
        
        function checkPasswordStrength(password) {
            let score = 0;
            if (password.length >= 6) score++;
            if (password.length >= 8) score++;
            if (password.match(/[a-z]/)) score++;
            if (password.match(/[A-Z]/)) score++;
            if (password.match(/[0-9]/)) score++;
            if (password.match(/[$@$!%*#?&]/)) score++;
            return Math.min(score, 5);
        }
        
        function updateStrengthMeter() {
            const password = passwordInput.value;
            if (password.length === 0) {
                strengthDiv.classList.add('hidden');
                return;
            }
            
            strengthDiv.classList.remove('hidden');
            const strength = checkPasswordStrength(password);
            
            const bars = [strength1, strength2, strength3, strength4, strength5];
            const colors = ['#ef4444', '#ef4444', '#f59e0b', '#22c55e', '#10b981'];
            const texts = ['Dhaifu Sana', 'Dhaifu', 'Wastani', 'Nzuri', 'Imara'];
            
            bars.forEach((bar, index) => {
                if (index < strength) {
                    bar.style.backgroundColor = colors[strength - 1];
                } else {
                    bar.style.backgroundColor = '#bccab9';
                }
            });
            
            strengthText.textContent = `Nguvu: ${texts[strength - 1] || 'Dhaifu Sana'}`;
            strengthText.style.color = colors[strength - 1] || '#ef4444';
        }
        
        if (passwordInput) {
            passwordInput.addEventListener('input', updateStrengthMeter);
        }
        
        // Input focus effects
        const inputs = document.querySelectorAll('input:not([type="hidden"])');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                const label = input.parentElement.querySelector('label');
                if (label) label.classList.add('text-primary');
            });
            input.addEventListener('blur', () => {
                const label = input.parentElement.querySelector('label');
                if (label) label.classList.remove('text-primary');
            });
        });
    </script>
</body>
</html>