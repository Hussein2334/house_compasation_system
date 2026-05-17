<?php
// auth/register.php - COMPLETE FIXED VERSION
session_start();

// Enable ALL error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';

// Create connection using getDB()
$conn = getDB();

// Test connection
if (!$conn) {
    die("Database connection failed!");
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../claimant/dashboard.php");
    exit();
}

$error_message = '';
$success_message = '';
$form_data = [];

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    
    // Get form data
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $nin = !empty($_POST['nin']) ? trim($_POST['nin']) : null;
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms = isset($_POST['terms']) ? true : false;
    
    // Store form data
    $form_data = [
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'nin' => $nin
    ];
    
    // Validation
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = "Jina kamili linahitajika.";
    } elseif (strlen($full_name) < 3) {
        $errors[] = "Jina kamili lazima liwe na herufi angalau 3.";
    }
    
    if (empty($email)) {
        $errors[] = "Barua pepe inahitajika.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Barua pepe si sahihi.";
    }
    
    if (empty($phone)) {
        $errors[] = "Namba ya simu inahitajika.";
    } elseif (!preg_match('/^(0[67][0-9]{8})$/', $phone)) {
        $errors[] = "Namba ya simu si sahihi. Mfano: 0712345678";
    }
    
    if (!empty($nin) && strlen($nin) < 11) {
        $errors[] = "Namba ya utambulisho lazima iwe na tarakimu 11 au zaidi.";
    }
    
    if (empty($password)) {
        $errors[] = "Nenosiri linahitajika.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Nenosiri lazima liwe na herufi angalau 6.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Nenosiri na uthibitisho havifanani.";
    }
    
    if (!$terms) {
        $errors[] = "Lazima ukubali vigezo na masharti.";
    }
    
    // Check if email exists
    if (empty($errors)) {
        $check_query = "SELECT id FROM users WHERE email = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "s", $email);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                $errors[] = "Barua pepe hii tayari imesajiliwa.";
            }
            mysqli_stmt_close($check_stmt);
        }
    }
    
    // Create account
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'claimant';
        $status = 'active';
        
        if (empty($nin)) {
            $query = "INSERT INTO users (full_name, email, phone, role, password, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssss", $full_name, $email, $phone, $role, $hashed_password, $status);
        } else {
            $query = "INSERT INTO users (full_name, email, phone, nin, role, password, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssssss", $full_name, $email, $phone, $nin, $role, $hashed_password, $status);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $user_id = mysqli_insert_id($conn);
            
            $_SESSION['user_id'] = $user_id;
            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $role;
            $_SESSION['logged_in'] = true;
            
            $success_message = "Akaunti imefanikiwa kufunguliwa!";
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Database error: " . mysqli_error($conn);
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#006e2c"/>
<title>Jisajili | HCS - Mfumo wa Fidia ya Nyumba</title>

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    body { font-family: 'Inter', sans-serif; background-color: #f4fcef; padding-bottom: 65px; }
    @media (min-width: 768px) { body { padding-bottom: 0; } }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    input:focus { outline: none; box-shadow: 0 0 0 2px rgba(0, 110, 44, 0.2); border-color: #006e2c; }
</style>
</head>
<body class="min-h-screen flex flex-col">

<!-- Top Navigation Bar -->
<header class="bg-white border-b-2 border-green-700 sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-4 md:px-16 max-w-6xl mx-auto h-16">
        <div class="flex items-center gap-3">
            <a href="../index.php" class="flex items-center gap-2">
                <span class="material-symbols-outlined text-green-700 text-2xl">arrow_back</span>
                <span class="material-symbols-outlined text-green-700 text-2xl">account_balance</span>
                <h1 class="font-bold text-xl text-green-700">HCS</h1>
            </a>
        </div>
        <a href="login.php" class="text-green-700 hover:underline">Tayari Una Akaunti? Ingia</a>
    </div>
</header>

<main class="flex-grow flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white border rounded-xl shadow-sm p-6">
        
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                <span class="material-symbols-outlined text-green-700 text-3xl">person_add</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Unda Akaunti</h2>
            <p class="text-gray-500 text-sm mt-1">Jaza taarifa zako hapa chini</p>
        </div>
        
        <!-- Display PHP errors -->
        <?php if (!empty($error_message)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm border border-red-300">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm border border-green-300">
                <?php echo $success_message; ?>
            </div>
            <meta http-equiv="refresh" content="2;url=../claimant/dashboard.php">
        <?php endif; ?>
        
        <form method="POST" action="" id="registerForm">
            <!-- Full Name -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Jina Kamili <span class="text-red-500">*</span></label>
                <input type="text" name="full_name" required
                       value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>"
                       class="w-full h-11 px-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                       placeholder="Mfano: John Juma">
            </div>
            
            <!-- Email -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Barua Pepe <span class="text-red-500">*</span></label>
                <input type="email" name="email" required
                       value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                       class="w-full h-11 px-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                       placeholder="barua@mfano.tz">
            </div>
            
            <!-- Phone -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Namba ya Simu <span class="text-red-500">*</span></label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">+255</span>
                    <input type="tel" name="phone" required
                           value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                           class="w-full h-11 pl-14 pr-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                           placeholder="712345678">
                </div>
            </div>
            
            <!-- NIN (Optional) -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Namba ya NIN (Si lazima)</label>
                <input type="text" name="nin"
                       value="<?php echo htmlspecialchars($form_data['nin'] ?? ''); ?>"
                       class="w-full h-11 px-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                       placeholder="Ingiza namba ya NIDA">
            </div>
            
            <!-- Password -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Nenosiri <span class="text-red-500">*</span></label>
                <input type="password" name="password" id="password" required
                       class="w-full h-11 px-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                       placeholder="******">
            </div>
            
            <!-- Confirm Password -->
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Thibitisha Nenosiri <span class="text-red-500">*</span></label>
                <input type="password" name="confirm_password" id="confirm_password" required
                       class="w-full h-11 px-3 border border-gray-300 rounded-lg focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none"
                       placeholder="******">
            </div>
            
            <!-- Terms -->
            <div class="flex items-start gap-2 mb-4">
                <input type="checkbox" name="terms" id="terms" required class="mt-1 w-4 h-4 text-green-700 rounded">
                <label for="terms" class="text-xs text-gray-600">
                    Ninakubali <a href="#" class="text-green-700 font-bold">Vigezo na Masharti</a> na 
                    <a href="#" class="text-green-700 font-bold">Sera ya Faragha</a>
                </label>
            </div>
            
            <!-- Submit Button -->
            <button type="submit" name="register" id="registerBtn"
                    class="w-full h-11 bg-green-700 text-white font-bold rounded-lg hover:bg-green-800 transition-all active:scale-95 flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-xl">person_add</span>
                <span>Jisajili Sasa</span>
            </button>
        </form>
        
        <div class="text-center mt-4">
            <p class="text-sm text-gray-600">Tayari una akaunti? <a href="login.php" class="text-green-700 font-bold">Ingia Hapa</a></p>
        </div>
    </div>
</main>

<!-- Bottom Navigation -->
<nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 flex justify-around items-center px-2 py-1 shadow-lg z-50 md:hidden">
    <a href="../index.php" class="flex flex-col items-center justify-center py-1 px-3">
        <span class="material-symbols-outlined text-gray-600 text-xl">home</span>
        <span class="text-xs text-gray-600">Nyumbani</span>
    </a>
    <a href="../track-claim.php" class="flex flex-col items-center justify-center py-1 px-3">
        <span class="material-symbols-outlined text-gray-600 text-xl">track_changes</span>
        <span class="text-xs text-gray-600">Fuatilia</span>
    </a>
    <a href="../notices.php" class="flex flex-col items-center justify-center py-1 px-3">
        <span class="material-symbols-outlined text-gray-600 text-xl">campaign</span>
        <span class="text-xs text-gray-600">Taarifa</span>
    </a>
    <a href="register.php" class="flex flex-col items-center justify-center py-1 px-3">
        <span class="material-symbols-outlined text-green-700 text-xl" style="font-variation-settings: 'FILL' 1;">person_add</span>
        <span class="text-xs text-green-700 font-bold">Jisajili</span>
    </a>
    <a href="login.php" class="flex flex-col items-center justify-center py-1 px-3">
        <span class="material-symbols-outlined text-gray-600 text-xl">login</span>
        <span class="text-xs text-gray-600">Ingia</span>
    </a>
</nav>

<script>
    // Form validation with SweetAlert
    const form = document.getElementById('registerForm');
    const btn = document.getElementById('registerBtn');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const fullName = document.querySelector('input[name="full_name"]').value.trim();
            const email = document.querySelector('input[name="email"]').value.trim();
            const phone = document.querySelector('input[name="phone"]').value.trim();
            const nin = document.querySelector('input[name="nin"]').value.trim();
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms').checked;
            
            // Validation checks
            if (!fullName || fullName.length < 3) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Taarifa Zinahitajika', text: 'Tafadhali jaza jina lako kamili (angalau herufi 3).', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (!email || !email.includes('@')) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Barua Pepe Si Sahihi', text: 'Tafadhali ingiza barua pepe halali.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (!phone || phone.length < 9) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Namba ya Simu Si Sahihi', text: 'Tafadhali ingiza namba sahihi ya simu.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (nin && nin.length > 0 && nin.length < 11) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'NIN Si Sahihi', text: 'Namba ya utambulisho lazima iwe na tarakimu 11 au zaidi.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (!password || password.length < 6) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Nenosiri Fupi', text: 'Nenosiri lazima liwe na angalau herufi 6.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Nenosiri Halifanani', text: 'Nenosiri na uthibitisho wake havifanani.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            if (!terms) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Kubali Masharti', text: 'Lazima ukubali vigezo na masharti ya mfumo.', confirmButtonColor: '#006e2c' });
                return false;
            }
            
            // Show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-xl">sync</span><span>Inajisajili...</span>';
            btn.classList.add('opacity-70');
            
            return true;
        });
    }
</script>

</body>
</html>