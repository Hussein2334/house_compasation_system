<?php
// auth/logout.php - Logout Page
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/audit.php';
require_once '../includes/functions.php';

// Get database connection
$conn = getDB();

// Log the logout action if user is logged in
if (isset($_SESSION['user_id']) && function_exists('logAudit')) {
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['full_name'] ?? 'Unknown';
    $user_email = $_SESSION['email'] ?? 'Unknown';
    
    logAudit($conn, $user_id, 'LOGOUT', 'users', $user_id, null, [
        'email' => $user_email,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
    ]);
}

// Clear remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    
    // Clear remember token from database if column exists
    if (isset($_SESSION['user_id']) && $conn) {
        $check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'remember_token'");
        if (mysqli_num_rows($check_column) > 0) {
            $clear_token = "UPDATE users SET remember_token = NULL, token_expiry = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $clear_token);
            mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
        }
    }
}

// Destroy all session variables
$_SESSION = array();

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

// Set success message for login page
session_start(); // Start new session for message
$_SESSION['logout_message'] = "Umefanikiwa kutoka kwenye mfumo. Karibu tena!";
$_SESSION['logout_message_type'] = "success";
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport">
    <meta name="theme-color" content="#006e2c">
    <title>Kutoka | HCS - House Compensation System</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f4fcef 0%, #e8f0e4 100%);
            min-height: 100vh;
        }
        .logout-card {
            animation: fadeInUp 0.5s ease-out;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    
    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden logout-card">
        <!-- Header -->
        <div class="bg-primary p-6 text-center">
            <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-white text-5xl">logout</span>
            </div>
            <h1 class="text-white text-2xl font-bold">Kutoka Mfumo</h1>
            <p class="text-white/80 text-sm mt-1">Unatoka kwenye HCS Portal</p>
        </div>
        
        <!-- Body -->
        <div class="p-6 text-center">
            <div class="mb-6">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
                </div>
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Umefanikiwa Kutoka!</h2>
                <p class="text-gray-500 text-sm">
                    Umeondoka kwenye mfumo wa HCS kwa mafanikio.<br>
                    Tunakuhakikishia usalama wa akaunti yako.
                </p>
            </div>
            
            <div class="space-y-3">
                <a href="login.php" 
                   class="w-full bg-primary hover:bg-primary-container text-white font-medium py-3 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">login</span>
                    Ingia Tena
                </a>
                
                <a href="../index.php" 
                   class="w-full border border-outline-variant text-secondary hover:bg-surface-container-low font-medium py-3 rounded-lg transition duration-200 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">home</span>
                    Rudi Ukurasa wa Mwanzo
                </a>
            </div>
            
            <!-- Timer for auto redirect -->
            <div class="mt-6 pt-4 border-t border-outline-variant">
                <p class="text-xs text-gray-400">
                    Unaelekezwa kwenye ukurasa wa kuingia ndani ya 
                    <span id="countdown" class="font-bold text-primary">5</span> sekunde
                </p>
                <div class="w-full bg-gray-200 rounded-full h-1 mt-2 overflow-hidden">
                    <div id="progress-bar" class="bg-primary h-1 rounded-full" style="width: 100%; transition: width 1s linear;"></div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-surface-container-low px-6 py-3 text-center border-t border-outline-variant">
            <p class="text-xs text-gray-400">
                © <?php echo date('Y'); ?> House Compensation System (HCS). Tanzania.
            </p>
        </div>
    </div>
    
    <script>
        // Auto redirect to login page after 5 seconds
        let seconds = 5;
        const countdownElement = document.getElementById('countdown');
        const progressBar = document.getElementById('progress-bar');
        
        const interval = setInterval(() => {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }
            if (progressBar) {
                progressBar.style.width = (seconds / 5) * 100 + '%';
            }
            
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = 'login.php';
            }
        }, 1000);
        
        // Optional: Show success message
        <?php if (isset($_SESSION['logout_message'])): ?>
        Swal.fire({
            icon: 'success',
            title: 'Kutoka Mfumo',
            text: '<?php echo $_SESSION['logout_message']; ?>',
            confirmButtonColor: '#006e2c',
            timer: 3000,
            showConfirmButton: false
        });
        <?php 
        unset($_SESSION['logout_message']);
        unset($_SESSION['logout_message_type']);
        endif; 
        ?>
    </script>
</body>
</html>