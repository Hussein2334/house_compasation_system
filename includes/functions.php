<?php
// includes/functions.php - Helper Functions for HCS
// NOTE: getDB() is already defined in config/db.php
// All other helper functions are here

// ============================================
// AUTHENTICATION & SESSION FUNCTIONS
// ============================================

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current logged in user ID
 * @return int|null
 */
function getCurrentUserId() {
    return isLoggedIn() ? $_SESSION['user_id'] : null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return isLoggedIn() ? $_SESSION['role'] : null;
}

/**
 * Get current user full name
 * @return string|null
 */
function getCurrentUserName() {
    return isLoggedIn() ? $_SESSION['full_name'] : null;
}

/**
 * Check if user has specific role
 * @param string|array $roles Role or array of roles
 * @return bool
 */
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    
    if (is_array($roles)) {
        return in_array($_SESSION['role'], $roles);
    }
    
    return $_SESSION['role'] === $roles;
}

/**
 * Require login - redirect if not logged in
 * @param string $redirectUrl Optional redirect URL after login
 */
function requireLogin($redirectUrl = null) {
    if (!isLoggedIn()) {
        if ($redirectUrl) {
            $_SESSION['redirect_url'] = $redirectUrl;
        } else {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        }
        header("Location: ../auth/login.php");
        exit();
    }
}

/**
 * Require specific role - redirect if not authorized
 * @param string|array $roles Required role(s)
 */
function requireRole($roles) {
    requireLogin();
    
    if (!hasRole($roles)) {
        header("Location: ../dashboard.php");
        exit();
    }
}

/**
 * Redirect user based on their role
 * @param string $role User role
 */
function redirectBasedOnRole($role) {
    switch ($role) {
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

/**
 * Logout user - destroy session
 */
function logout() {
    // Log logout action if audit function exists
    if (isLoggedIn() && function_exists('logAudit')) {
        $conn = getDB(); // getDB from config/db.php
        @logAudit($conn, getCurrentUserId(), 'LOGOUT', 'users', getCurrentUserId());
    }
    
    // Destroy session
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    session_destroy();
    
    // Clear remember me cookie
    setcookie('remember_token', '', time()-3600, '/');
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output CSRF token field for forms
 * @return string
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// ============================================
// STATISTICS FUNCTIONS
// ============================================

/**
 * Get total number of claims
 * @param mysqli $conn Database connection
 * @return int
 */
function getTotalClaims($conn) {
    if (!$conn) return 0;
    $query = "SELECT COUNT(*) as total FROM claims";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

/**
 * Get number of processed claims
 * @param mysqli $conn Database connection
 * @return int
 */
function getProcessedClaims($conn) {
    if (!$conn) return 0;
    $query = "SELECT COUNT(*) as total FROM claims WHERE status IN ('approved', 'paid')";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

/**
 * Get total compensation amount in Billions
 * @param mysqli $conn Database connection
 * @return float
 */
function getTotalCompensation($conn) {
    if (!$conn) return 0;
    $query = "SELECT SUM(total_compensation) as total FROM valuations";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $total = (float)($row['total'] ?? 0);
        return round($total / 1000000000, 1);
    }
    return 0;
}

/**
 * Get number of active users in thousands
 * @param mysqli $conn Database connection
 * @return float
 */
function getActiveUsers($conn) {
    if (!$conn) return 0;
    $query = "SELECT COUNT(*) as total FROM users WHERE status = 'active'";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $total = (int)($row['total'] ?? 0);
        return round($total / 1000, 1);
    }
    return 0;
}

/**
 * Get pending claims count
 * @param mysqli $conn Database connection
 * @return int
 */
function getPendingClaims($conn) {
    if (!$conn) return 0;
    $query = "SELECT COUNT(*) as total FROM claims WHERE status IN ('submitted', 'valuation', 'legal_review')";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    return (int)($row['total'] ?? 0);
}

/**
 * Get claims by status
 * @param mysqli $conn Database connection
 * @return array
 */
function getClaimsByStatus($conn) {
    if (!$conn) return [];
    $query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
    $result = mysqli_query($conn, $query);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[$row['status']] = $row['count'];
    }
    return $data;
}

/**
 * Get monthly claims
 * @param mysqli $conn Database connection
 * @return array
 */
function getMonthlyClaims($conn) {
    if (!$conn) return [];
    $query = "SELECT MONTH(created_at) as month, COUNT(*) as count 
              FROM claims 
              WHERE YEAR(created_at) = YEAR(CURDATE())
              GROUP BY MONTH(created_at)
              ORDER BY month";
    $result = mysqli_query($conn, $query);
    $data = array_fill(1, 12, 0);
    while ($row = mysqli_fetch_assoc($result)) {
        $data[(int)$row['month']] = (int)$row['count'];
    }
    return $data;
}

// ============================================
// CLAIM FUNCTIONS
// ============================================

/**
 * Generate unique claim number
 * @return string
 */
function generateClaimNumber() {
    return 'HCS-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Get claim by ID
 * @param mysqli $conn Database connection
 * @param int $claim_id Claim ID
 * @return array|null
 */
function getClaimById($conn, $claim_id) {
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin
              FROM claims c
              JOIN users u ON c.claimant_id = u.id
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

/**
 * Get claims by claimant
 * @param mysqli $conn Database connection
 * @param int $claimant_id Claimant user ID
 * @param int $limit Optional limit
 * @return array
 */
function getClaimsByClaimant($conn, $claimant_id, $limit = null) {
    $query = "SELECT * FROM claims WHERE claimant_id = ? ORDER BY created_at DESC";
    if ($limit) {
        $query .= " LIMIT " . intval($limit);
    }
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claimant_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $claims = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $claims[] = $row;
    }
    return $claims;
}

/**
 * Update claim status
 * @param mysqli $conn Database connection
 * @param int $claim_id Claim ID
 * @param string $status New status
 * @param int $user_id User performing action
 * @param string $remarks Optional remarks
 * @return bool
 */
function updateClaimStatus($conn, $claim_id, $status, $user_id, $remarks = null) {
    $old_query = "SELECT status FROM claims WHERE id = ?";
    $old_stmt = mysqli_prepare($conn, $old_query);
    mysqli_stmt_bind_param($old_stmt, "i", $claim_id);
    mysqli_stmt_execute($old_stmt);
    $old_result = mysqli_stmt_get_result($old_stmt);
    $old_data = mysqli_fetch_assoc($old_result);
    
    $query = "UPDATE claims SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "si", $status, $claim_id);
    
    if (mysqli_stmt_execute($stmt)) {
        if (function_exists('logAudit')) {
            logAudit($conn, $user_id, 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                    ['status' => $old_data['status']], 
                    ['status' => $status, 'remarks' => $remarks]);
        }
        return true;
    }
    return false;
}

// ============================================
// USER FUNCTIONS
// ============================================

/**
 * Get user by ID
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @return array|null
 */
function getUserById($conn, $user_id) {
    $query = "SELECT id, full_name, email, phone, nin, role, status, created_at, last_login 
              FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// ============================================
// VALIDATION & FORMATTING FUNCTIONS
// ============================================

/**
 * Sanitize input data
 * @param string $data Input data
 * @return string
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Tanzania format)
 * @param string $phone Phone number
 * @return bool
 */
function validatePhone($phone) {
    return preg_match('/^(0[67][0-9]{8})$/', $phone);
}

/**
 * Format currency in TZS
 * @param float $amount Amount to format
 * @return string
 */
function formatCurrency($amount) {
    return number_format($amount, 0, '.', ',') . ' TZS';
}

/**
 * Format date to readable format
 * @param string $date Date string
 * @param string $format Output format
 * @return string
 */
function formatDate($date, $format = 'd M Y, H:i') {
    if (!$date || $date == '0000-00-00 00:00:00') return '-';
    return date($format, strtotime($date));
}

/**
 * Format date in Swahili
 * @param string $date Date string
 * @return string
 */
function formatDateSw($date) {
    if (!$date || $date == '0000-00-00 00:00:00') return '-';
    $months = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Disemba'];
    $timestamp = strtotime($date);
    return date('d', $timestamp) . ' ' . $months[date('n', $timestamp) - 1] . ', ' . date('Y', $timestamp);
}

/**
 * Get status badge class
 * @param string $status Status string
 * @return string
 */
function getStatusBadgeClass($status) {
    $classes = [
        'submitted' => 'bg-blue-100 text-blue-800',
        'valuation' => 'bg-yellow-100 text-yellow-800',
        'legal_review' => 'bg-purple-100 text-purple-800',
        'approved' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100 text-red-800',
        'paid' => 'bg-emerald-100 text-emerald-800',
        'active' => 'bg-green-100 text-green-800',
        'inactive' => 'bg-gray-100 text-gray-800'
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Get status label in Swahili
 * @param string $status Status string
 * @return string
 */
function getStatusLabel($status) {
    $labels = [
        'submitted' => 'Imetumwa',
        'valuation' => 'Tathmini',
        'legal_review' => 'Uhakiki wa Kisheria',
        'approved' => 'Imeidhinishwa',
        'rejected' => 'Imekataliwa',
        'paid' => 'Imelipwa',
        'active' => 'Inatumika',
        'inactive' => 'Haifanyi kazi'
    ];
    return $labels[$status] ?? ucfirst($status);
}

/**
 * Create notification for user
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @return bool
 */
function createNotification($conn, $user_id, $title, $message, $type = 'system') {
    $check = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if (mysqli_num_rows($check) == 0) {
        return true;
    }
    
    $query = "INSERT INTO notifications (user_id, type, title, message, sent_at) VALUES (?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "isss", $user_id, $type, $title, $message);
        return mysqli_stmt_execute($stmt);
    }
    return false;
}
?>