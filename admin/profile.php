<?php
// admin/profile.php - User Profile Management
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Set page variables
$page_title = 'My Profile';
$page_heading = 'Akaunti Yangu';

// Get database connection
$conn = getDB();

// Create user_settings table if not exists
$create_table_query = "
CREATE TABLE IF NOT EXISTS user_settings (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_setting (user_id, setting_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $create_table_query);

// Get user data
$user_id = $_SESSION['user_id'];
$query = "SELECT id, full_name, email, phone, nin, role, status, created_at FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    header("Location: ../auth/logout.php");
    exit();
}

// Get user statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM claims WHERE claimant_id = ?) as total_claims,
    (SELECT COUNT(*) FROM valuations WHERE valuer_id = ?) as total_valuations,
    (SELECT COUNT(*) FROM audit_logs WHERE user_id = ?) as total_activities";
$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "iii", $user_id, $user_id, $user_id);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $nin = trim($_POST['nin'] ?? '');
    
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = "Jina kamili linahitajika";
    }
    
    if (empty($errors)) {
        $update_query = "UPDATE users SET full_name = ?, phone = ?, nin = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "sssi", $full_name, $phone, $nin, $user_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $_SESSION['full_name'] = $full_name;
            $_SESSION['success_message'] = "Taarifa za akaunti zimebadilishwa kikamilifu.";
            logAudit($conn, $user_id, 'UPDATE_PROFILE', 'users', $user_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kubadilisha taarifa.";
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
    
    header("Location: profile.php");
    exit();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if (empty($current_password)) {
        $errors[] = "Tafadhali ingiza nenosiri la sasa";
    }
    
    if (strlen($new_password) < 6) {
        $errors[] = "Nenosiri jipya lazima liwe na angalau herufi 6";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "Manenosiri mapya hayalingani";
    }
    
    if (empty($errors)) {
        // Verify current password
        $pass_query = "SELECT password FROM users WHERE id = ?";
        $pass_stmt = mysqli_prepare($conn, $pass_query);
        mysqli_stmt_bind_param($pass_stmt, "i", $user_id);
        mysqli_stmt_execute($pass_stmt);
        $pass_result = mysqli_stmt_get_result($pass_stmt);
        $user_data = mysqli_fetch_assoc($pass_result);
        
        if (password_verify($current_password, $user_data['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $_SESSION['success_message'] = "Nenosiri limebadilishwa kikamilifu.";
                logAudit($conn, $user_id, 'CHANGE_PASSWORD', 'users', $user_id);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kubadilisha nenosiri.";
            }
        } else {
            $_SESSION['error_message'] = "Nenosiri la sasa si sahihi.";
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
    
    header("Location: profile.php?tab=security");
    exit();
}

// Handle notification settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    
    // Insert or update settings
    $settings = [
        'email_notifications' => $email_notifications,
        'sms_notifications' => $sms_notifications
    ];
    
    foreach ($settings as $key => $value) {
        // Check if setting exists
        $check_query = "SELECT id FROM user_settings WHERE user_id = ? AND setting_key = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "is", $user_id, $key);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_fetch_assoc($check_result)) {
            // Update existing
            $update_query = "UPDATE user_settings SET setting_value = ? WHERE user_id = ? AND setting_key = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "sis", $value, $user_id, $key);
            mysqli_stmt_execute($update_stmt);
        } else {
            // Insert new
            $insert_query = "INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "iss", $user_id, $key, $value);
            mysqli_stmt_execute($insert_stmt);
        }
    }
    
    $_SESSION['success_message'] = "Mipangilio ya arifa imehifadhiwa.";
    logAudit($conn, $user_id, 'UPDATE_NOTIFICATION_SETTINGS', 'user_settings', $user_id);
    
    header("Location: profile.php?tab=notifications");
    exit();
}

// Get user notification settings
$email_notifications = 1;
$sms_notifications = 0;

// Check if table exists and get settings
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'user_settings'");
if (mysqli_num_rows($table_check) > 0) {
    $settings_query = "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?";
    $settings_stmt = mysqli_prepare($conn, $settings_query);
    mysqli_stmt_bind_param($settings_stmt, "i", $user_id);
    mysqli_stmt_execute($settings_stmt);
    $settings_result = mysqli_stmt_get_result($settings_stmt);
    while ($row = mysqli_fetch_assoc($settings_result)) {
        if ($row['setting_key'] == 'email_notifications') {
            $email_notifications = $row['setting_value'];
        } elseif ($row['setting_key'] == 'sms_notifications') {
            $sms_notifications = $row['setting_value'];
        }
    }
}

// Get recent activities
$activities_query = "SELECT action_performed, ip_address, created_at 
                     FROM audit_logs 
                     WHERE user_id = ? 
                     ORDER BY created_at DESC 
                     LIMIT 10";
$activities_stmt = mysqli_prepare($conn, $activities_query);
mysqli_stmt_bind_param($activities_stmt, "i", $user_id);
mysqli_stmt_execute($activities_stmt);
$activities_result = mysqli_stmt_get_result($activities_stmt);
$recent_activities = [];
while ($row = mysqli_fetch_assoc($activities_result)) {
    $recent_activities[] = $row;
}

$active_tab = $_GET['tab'] ?? 'profile';
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Profile Container */
    .profile-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    /* Profile Sidebar */
    .profile-sidebar {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
    }
    .profile-avatar {
        background: linear-gradient(135deg, #006e2c, #1eb050);
        padding: 2rem;
        text-align: center;
    }
    .avatar-circle {
        width: 100px;
        height: 100px;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        font-size: 2.5rem;
        font-weight: bold;
        color: #006e2c;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .profile-name {
        font-size: 1.25rem;
        font-weight: 700;
        color: white;
        margin-top: 1rem;
    }
    .profile-role {
        font-size: 0.8rem;
        color: rgba(255,255,255,0.9);
        margin-top: 0.25rem;
    }
    .profile-info {
        padding: 1.5rem;
    }
    .info-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-item:last-child {
        border-bottom: none;
    }
    .info-icon {
        width: 36px;
        height: 36px;
        background: #eef6ea;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #006e2c;
    }
    .info-label {
        font-size: 0.7rem;
        color: #6d7b6c;
        text-transform: uppercase;
    }
    .info-value {
        font-weight: 600;
        color: #1e2a1e;
    }
    
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        text-align: center;
    }
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #006e2c;
    }
    .stat-label {
        font-size: 0.7rem;
        color: #6d7b6c;
        text-transform: uppercase;
        margin-top: 0.25rem;
    }
    
    /* Settings Tabs */
    .profile-tabs {
        display: flex;
        gap: 0.5rem;
        border-bottom: 1px solid #e8f0e4;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        flex-wrap: wrap;
    }
    .profile-tab {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s ease;
        background: none;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    .profile-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .profile-tab:not(.active):hover {
        background-color: #e8f0e4;
        color: #1e2a1e;
    }
    
    /* Settings Cards */
    .settings-card {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .settings-card-header {
        padding: 1.25rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .settings-card-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .settings-card-body {
        padding: 1.5rem;
    }
    
    /* Form Groups */
    .form-group {
        margin-bottom: 1.25rem;
    }
    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.5rem;
    }
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
    }
    .form-control, .form-select {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .form-control[readonly] {
        background: #f4fcef;
        cursor: not-allowed;
    }
    .form-hint {
        font-size: 0.7rem;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #bccab9;
        transition: 0.3s;
        border-radius: 24px;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
    }
    input:checked + .toggle-slider {
        background-color: #006e2c;
    }
    input:checked + .toggle-slider:before {
        transform: translateX(26px);
    }
    
    /* Buttons */
    .btn-save {
        background-color: #006e2c;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-save:hover {
        background-color: #005a24;
    }
    .btn-cancel {
        background-color: white;
        color: #3d4a3d;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-cancel:hover {
        background-color: #eef6ea;
    }
    
    /* Activity List */
    .activity-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .activity-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.875rem;
        border-bottom: 1px solid #e8f0e4;
        transition: background 0.2s;
    }
    .activity-item:hover {
        background-color: #f4fcef;
    }
    .activity-icon {
        width: 40px;
        height: 40px;
        background: #eef6ea;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #006e2c;
    }
    .activity-details {
        flex: 1;
    }
    .activity-action {
        font-weight: 600;
        font-size: 0.875rem;
    }
    .activity-time {
        font-size: 0.7rem;
        color: #6d7b6c;
    }
    .activity-ip {
        font-size: 0.7rem;
        font-family: monospace;
        color: #6d7b6c;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .profile-tabs {
            flex-direction: column;
        }
        .profile-tab {
            text-align: center;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="profile-container">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Akaunti Yangu</h2>
            <p class="text-secondary text-sm mt-1">Simamia taarifa zako za akaunti na mipangilio</p>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Left Column - Profile Info -->
        <div class="md:col-span-1">
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <div class="avatar-circle">
                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></div>
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <div class="info-icon">
                            <span class="material-symbols-outlined">email</span>
                        </div>
                        <div>
                            <div class="info-label">Barua Pepe</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <span class="material-symbols-outlined">phone</span>
                        </div>
                        <div>
                            <div class="info-label">Namba ya Simu</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <span class="material-symbols-outlined">badge</span>
                        </div>
                        <div>
                            <div class="info-label">NIN</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['nin'] ?? '-'); ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <span class="material-symbols-outlined">calendar_today</span>
                        </div>
                        <div>
                            <div class="info-label">Akaunti Imefunguliwa</div>
                            <div class="info-value"><?php echo date('d F Y', strtotime($user['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid mt-4">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_claims'] ?? 0); ?></div>
                    <div class="stat-label">Madai Yaliyowasilishwa</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_valuations'] ?? 0); ?></div>
                    <div class="stat-label">Tathmini Zilizofanywa</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_activities'] ?? 0); ?></div>
                    <div class="stat-label">Shughuli za Mfumo</div>
                </div>
            </div>
        </div>
        
        <!-- Right Column - Profile Settings -->
        <div class="md:col-span-2">
            
            <!-- Tabs -->
            <div class="profile-tabs">
                <a href="?tab=profile" class="profile-tab <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                    <span class="material-symbols-outlined" style="font-size: 1rem;">account_circle</span> Taarifa za Akaunti
                </a>
                <a href="?tab=security" class="profile-tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
                    <span class="material-symbols-outlined" style="font-size: 1rem;">lock</span> Usalama
                </a>
                <a href="?tab=notifications" class="profile-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>">
                    <span class="material-symbols-outlined" style="font-size: 1rem;">notifications</span> Arifa
                </a>
                <a href="?tab=activities" class="profile-tab <?php echo $active_tab === 'activities' ? 'active' : ''; ?>">
                    <span class="material-symbols-outlined" style="font-size: 1rem;">history</span> Shughuli Zangu
                </a>
            </div>
            
            <?php if ($active_tab === 'profile'): ?>
            <!-- Profile Information Form -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>
                        <span class="material-symbols-outlined text-primary">edit</span>
                        Hariri Taarifa za Akaunti
                    </h3>
                </div>
                <div class="settings-card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label required">Jina Kamili</label>
                                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Barua Pepe</label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly disabled>
                                <div class="form-hint">Barua pepe haiwezi kubadilishwa</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Namba ya Simu</label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="0712345678">
                            </div>
                            <div class="form-group">
                                <label class="form-label">NIN (Namba ya Utambulisho)</label>
                                <input type="text" name="nin" class="form-control" value="<?php echo htmlspecialchars($user['nin'] ?? ''); ?>" placeholder="Namba ya NIDA">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 mt-4">
                            <button type="submit" class="btn-save">
                                <span class="material-symbols-outlined text-sm">save</span>
                                Hifadhi Mabadiliko
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'security'): ?>
            <!-- Change Password Form -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>
                        <span class="material-symbols-outlined text-primary">lock</span>
                        Badilisha Nenosiri
                    </h3>
                </div>
                <div class="settings-card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="change_password" value="1">
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label required">Nenosiri la Sasa</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div></div>
                            <div class="form-group">
                                <label class="form-label required">Nenosiri Jipya</label>
                                <input type="password" name="new_password" class="form-control" required>
                                <div class="form-hint">Lazima iwe na angalau herufi 6</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Thibitisha Nenosiri Jipya</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 mt-4">
                            <button type="submit" class="btn-save">
                                <span class="material-symbols-outlined text-sm">lock_reset</span>
                                Badilisha Nenosiri
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Session Management -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>
                        <span class="material-symbols-outlined text-primary">devices</span>
                        Vifaa na Vipindi
                    </h3>
                </div>
                <div class="settings-card-body">
                    <div class="flex justify-between items-center flex-wrap gap-3">
                        <div>
                            <p class="text-sm">Hivi sasa umeingia kwenye kifaa hiki</p>
                            <p class="text-xs text-secondary mt-1">IP: <?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?></p>
                        </div>
                        <button type="button" class="btn-cancel" onclick="logoutAllDevices()">
                            <span class="material-symbols-outlined text-sm">logout</span>
                            Funga Vipindi Vyote
                        </button>
                    </div>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'notifications'): ?>
            <!-- Notification Settings -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>
                        <span class="material-symbols-outlined text-primary">notifications</span>
                        Mipangilio ya Arifa
                    </h3>
                </div>
                <div class="settings-card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="update_notifications" value="1">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-2 border-b border-outline-variant">
                                <div>
                                    <div class="font-medium">Arifa kwa Barua Pepe</div>
                                    <div class="text-xs text-secondary">Pokea arifa kuhusu mabadiliko ya madai na malipo</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="email_notifications" value="1" <?php echo $email_notifications ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-outline-variant">
                                <div>
                                    <div class="font-medium">Arifa kwa SMS</div>
                                    <div class="text-xs text-secondary">Pokea taarifa kwa ujumbe mfupi wa simu</div>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="sms_notifications" value="1" <?php echo $sms_notifications ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="submit" class="btn-save">
                                <span class="material-symbols-outlined text-sm">save</span>
                                Hifadhi Mipangilio
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'activities'): ?>
            <!-- Recent Activities -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>
                        <span class="material-symbols-outlined text-primary">history</span>
                        Shughuli Zangu za Hivi Karibuni
                    </h3>
                </div>
                <div class="settings-card-body p-0">
                    <div class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-8 text-secondary">
                            <span class="material-symbols-outlined text-4xl mb-2 block">history</span>
                            Hakuna shughuli za hivi karibuni
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <span class="material-symbols-outlined">
                                    <?php 
                                    $icons = [
                                        'LOGIN' => 'login',
                                        'LOGOUT' => 'logout',
                                        'CREATE_CLAIM' => 'add_circle',
                                        'UPDATE_CLAIM' => 'edit',
                                        'DELETE_CLAIM' => 'delete',
                                        'CREATE_USER' => 'person_add',
                                        'UPDATE_USER' => 'edit',
                                        'DELETE_USER' => 'delete',
                                    ];
                                    $action = explode(' ON ', $activity['action_performed'])[0];
                                    $action = explode(' - ', $action)[0];
                                    echo $icons[$action] ?? 'info';
                                    ?>
                                </span>
                            </div>
                            <div class="activity-details">
                                <div class="activity-action"><?php echo htmlspecialchars($activity['action_performed']); ?></div>
                                <div class="activity-time"><?php echo date('d M Y H:i:s', strtotime($activity['created_at'])); ?></div>
                                <div class="activity-ip">IP: <?php echo htmlspecialchars($activity['ip_address']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Logout all devices
    function logoutAllDevices() {
        Swal.fire({
            title: 'Funga Vipindi Vyote?',
            text: 'Je, una uhakika unataka kufunga vipindi vyako kwenye vifaa vyote? Utahitaji kuingia tena.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Funga',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Taarifa',
                    text: 'Kipengele hiki kitaongezwa katika toleo lijalo.',
                    icon: 'info',
                    confirmButtonColor: '#006e2c'
                });
            }
        });
    }
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>