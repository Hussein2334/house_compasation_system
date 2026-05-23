<?php
// admin/settings.php - System Settings Management
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is super admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Only super admin can access settings
if ($_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'System Settings';
$page_heading = 'Mipangilio ya Mfumo';

// Get database connection
$conn = getDB();

// Create settings table if not exists
$create_table_query = "
CREATE TABLE IF NOT EXISTS system_settings (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text',
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

mysqli_query($conn, $create_table_query);

// Default settings
$default_settings = [
    'site_name' => [
        'value' => 'House Compensation System',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Jina la mfumo'
    ],
    'site_logo' => [
        'value' => '',
        'type' => 'text',
        'category' => 'general',
        'description' => 'URL ya logo ya mfumo'
    ],
    'timezone' => [
        'value' => 'Africa/Dar_es_Salaam',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Majira ya saa'
    ],
    'date_format' => [
        'value' => 'd/m/Y',
        'type' => 'text',
        'category' => 'general',
        'description' => 'Muundo wa tarehe'
    ],
    'items_per_page' => [
        'value' => '15',
        'type' => 'number',
        'category' => 'general',
        'description' => 'Idadi ya rekodi kwa kila ukurasa'
    ],
    
    // Notification Settings
    'email_notifications' => [
        'value' => '1',
        'type' => 'boolean',
        'category' => 'notifications',
        'description' => 'Tuma arifa kwa barua pepe'
    ],
    'admin_email' => [
        'value' => 'admin@hcs.go.tz',
        'type' => 'text',
        'category' => 'notifications',
        'description' => 'Barua pepe ya msimamizi'
    ],
    'smtp_host' => [
        'value' => '',
        'type' => 'text',
        'category' => 'notifications',
        'description' => 'SMTP server host'
    ],
    'smtp_port' => [
        'value' => '587',
        'type' => 'number',
        'category' => 'notifications',
        'description' => 'SMTP port'
    ],
    'smtp_username' => [
        'value' => '',
        'type' => 'text',
        'category' => 'notifications',
        'description' => 'SMTP username'
    ],
    'smtp_password' => [
        'value' => '',
        'type' => 'text',
        'category' => 'notifications',
        'description' => 'SMTP password'
    ],
    
    // Claim Settings
    'claim_deadline_days' => [
        'value' => '30',
        'type' => 'number',
        'category' => 'claims',
        'description' => 'Muda wa kuwasilisha dai (siku)'
    ],
    'valuation_deadline_days' => [
        'value' => '14',
        'type' => 'number',
        'category' => 'claims',
        'description' => 'Muda wa kukamilisha tathmini (siku)'
    ],
    'auto_approve_valuation' => [
        'value' => '0',
        'type' => 'boolean',
        'category' => 'claims',
        'description' => 'Kubali tathmini moja kwa moja'
    ],
    
    // Payment Settings
    'payment_methods' => [
        'value' => '["bank_transfer","mobile_money","cash","cheque"]',
        'type' => 'json',
        'category' => 'payments',
        'description' => 'Njia za malipo zinazokubalika'
    ],
    'min_payment_amount' => [
        'value' => '1000',
        'type' => 'number',
        'category' => 'payments',
        'description' => 'Kiwango cha chini cha malipo (TZS)'
    ],
    'max_payment_amount' => [
        'value' => '1000000000',
        'type' => 'number',
        'category' => 'payments',
        'description' => 'Kiwango cha juu cha malipo (TZS)'
    ],
    
    // Security Settings
    'session_timeout' => [
        'value' => '3600',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Muda wa kukaa kwenye mfumo (sekunde)'
    ],
    'max_login_attempts' => [
        'value' => '5',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Idadi ya majaribio ya kuingia'
    ],
    'password_expiry_days' => [
        'value' => '90',
        'type' => 'number',
        'category' => 'security',
        'description' => 'Nenosiri linabadilishwa baada ya siku'
    ],
    'two_factor_auth' => [
        'value' => '0',
        'type' => 'boolean',
        'category' => 'security',
        'description' => 'Washa uthibitishaji wa hatua mbili'
    ],
    
    // Maintenance Settings
    'maintenance_mode' => [
        'value' => '0',
        'type' => 'boolean',
        'category' => 'maintenance',
        'description' => 'Weka mfumo katika hali ya matengenezo'
    ],
    'maintenance_message' => [
        'value' => 'Mfumo uko kwenye matengenezo. Tafadhali jaribu tena baadaye.',
        'type' => 'text',
        'category' => 'maintenance',
        'description' => 'Ujumbe wa matengenezo'
    ],
    'backup_enabled' => [
        'value' => '1',
        'type' => 'boolean',
        'category' => 'maintenance',
        'description' => 'Washa backup otomatiki'
    ],
    'backup_frequency' => [
        'value' => 'daily',
        'type' => 'text',
        'category' => 'maintenance',
        'description' => 'Mara ngapi backup inafanywa (daily, weekly, monthly)'
    ],
];

// Insert default settings if not exists
foreach ($default_settings as $key => $setting) {
    $check_query = "SELECT id FROM system_settings WHERE setting_key = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "s", $key);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) == 0) {
        $insert_query = "INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "sssss", $key, $setting['value'], $setting['type'], $setting['category'], $setting['description']);
        mysqli_stmt_execute($insert_stmt);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? 'general';
    $settings = $_POST['settings'] ?? [];
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($settings as $key => $value) {
        // Sanitize based on type
        $type_query = "SELECT setting_type FROM system_settings WHERE setting_key = ?";
        $type_stmt = mysqli_prepare($conn, $type_query);
        mysqli_stmt_bind_param($type_stmt, "s", $key);
        mysqli_stmt_execute($type_stmt);
        $type_result = mysqli_stmt_get_result($type_stmt);
        $type_row = mysqli_fetch_assoc($type_result);
        $setting_type = $type_row['setting_type'] ?? 'text';
        
        if ($setting_type === 'boolean') {
            $value = isset($value) && $value == '1' ? '1' : '0';
        } elseif ($setting_type === 'number') {
            $value = floatval($value);
        } elseif ($setting_type === 'json') {
            $value = json_encode($value);
        } else {
            $value = trim($value);
        }
        
        $update_query = "UPDATE system_settings SET setting_value = ? WHERE setting_key = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ss", $value, $key);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
    
    if ($success_count > 0) {
        $_SESSION['success_message'] = "Mipangilio $success_count imehifadhiwa kikamilifu.";
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_SYSTEM_SETTINGS', 'system_settings', null, null, ['category' => $category]);
    }
    if ($error_count > 0) {
        $_SESSION['error_message'] = "Hitilafu katika kuhifadhi mipangilio $error_count.";
    }
    
    header("Location: settings.php?tab=" . urlencode($category));
    exit();
}

// Get current settings
$settings_query = "SELECT setting_key, setting_value, setting_type, category, description FROM system_settings ORDER BY category, setting_key";
$settings_result = mysqli_query($conn, $settings_query);
$all_settings = [];
while ($row = mysqli_fetch_assoc($settings_result)) {
    $value = $row['setting_value'];
    if ($row['setting_type'] === 'boolean') {
        $value = $value == '1';
    } elseif ($row['setting_type'] === 'json') {
        $value = json_decode($value, true);
    }
    $all_settings[$row['category']][$row['setting_key']] = [
        'value' => $value,
        'type' => $row['setting_type'],
        'description' => $row['description']
    ];
}

// Get active tab
$active_tab = $_GET['tab'] ?? 'general';

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Settings Container */
    .settings-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    /* Settings Tabs */
    .settings-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        border-bottom: 1px solid #e8f0e4;
        padding-bottom: 1rem;
        margin-bottom: 1.5rem;
    }
    .settings-tab {
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
    .settings-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .settings-tab:not(.active):hover {
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
    .form-control, .form-select, .form-textarea {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
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
    .btn-reset {
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
    .btn-reset:hover {
        background-color: #eef6ea;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }
    
    @media (max-width: 768px) {
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .settings-tabs {
            flex-direction: column;
        }
        .settings-tab {
            text-align: center;
        }
    }
</style>

<!-- Page Content -->
<div class="settings-container">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Mipangilio ya Mfumo</h2>
            <p class="text-secondary text-sm mt-1">Dhibiti na usimamie mipangilio yote ya mfumo</p>
        </div>
    </div>
    
    <!-- Settings Tabs -->
    <div class="settings-tabs">
        <a href="?tab=general" class="settings-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">settings</span> Jumla
        </a>
        <a href="?tab=notifications" class="settings-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">notifications</span> Arifa
        </a>
        <a href="?tab=claims" class="settings-tab <?php echo $active_tab === 'claims' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">description</span> Madai
        </a>
        <a href="?tab=payments" class="settings-tab <?php echo $active_tab === 'payments' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">payments</span> Malipo
        </a>
        <a href="?tab=security" class="settings-tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">security</span> Usalama
        </a>
        <a href="?tab=maintenance" class="settings-tab <?php echo $active_tab === 'maintenance' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">build</span> Matengenezo
        </a>
    </div>
    
    <!-- Settings Forms -->
    <form method="POST" action="" id="settingsForm">
        <input type="hidden" name="category" value="<?php echo $active_tab; ?>">
        
        <?php if ($active_tab === 'general'): ?>
        <!-- General Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">settings</span>
                    Mipangilio ya Jumla
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="grid-2">
                    <?php if (isset($all_settings['general']['site_name'])): ?>
                    <div class="form-group">
                        <label class="form-label">Jina la Mfumo</label>
                        <input type="text" name="settings[site_name]" class="form-control" value="<?php echo htmlspecialchars($all_settings['general']['site_name']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['general']['site_name']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['general']['site_logo'])): ?>
                    <div class="form-group">
                        <label class="form-label">URL ya Logo</label>
                        <input type="text" name="settings[site_logo]" class="form-control" value="<?php echo htmlspecialchars($all_settings['general']['site_logo']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['general']['site_logo']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['general']['timezone'])): ?>
                    <div class="form-group">
                        <label class="form-label">Majira ya Saa</label>
                        <select name="settings[timezone]" class="form-select">
                            <option value="Africa/Dar_es_Salaam" <?php echo $all_settings['general']['timezone']['value'] == 'Africa/Dar_es_Salaam' ? 'selected' : ''; ?>>Dar es Salaam (EAT)</option>
                            <option value="Africa/Nairobi" <?php echo $all_settings['general']['timezone']['value'] == 'Africa/Nairobi' ? 'selected' : ''; ?>>Nairobi (EAT)</option>
                            <option value="UTC" <?php echo $all_settings['general']['timezone']['value'] == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                        </select>
                        <div class="form-hint"><?php echo $all_settings['general']['timezone']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['general']['date_format'])): ?>
                    <div class="form-group">
                        <label class="form-label">Muundo wa Tarehe</label>
                        <select name="settings[date_format]" class="form-select">
                            <option value="d/m/Y" <?php echo $all_settings['general']['date_format']['value'] == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                            <option value="m/d/Y" <?php echo $all_settings['general']['date_format']['value'] == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                            <option value="Y-m-d" <?php echo $all_settings['general']['date_format']['value'] == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                        </select>
                        <div class="form-hint"><?php echo $all_settings['general']['date_format']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['general']['items_per_page'])): ?>
                    <div class="form-group">
                        <label class="form-label">Idadi ya Rekodi kwa Ukurasa</label>
                        <input type="number" name="settings[items_per_page]" class="form-control" value="<?php echo $all_settings['general']['items_per_page']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['general']['items_per_page']['description']; ?></div>
                    </div>
                    <?php endif; ?>
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
                <div class="grid-2">
                    <?php if (isset($all_settings['notifications']['email_notifications'])): ?>
                    <div class="form-group">
                        <label class="form-label">Arifa kwa Barua Pepe</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[email_notifications]" value="1" <?php echo $all_settings['notifications']['email_notifications']['value'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="form-hint"><?php echo $all_settings['notifications']['email_notifications']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['notifications']['admin_email'])): ?>
                    <div class="form-group">
                        <label class="form-label">Barua Pepe ya Msimamizi</label>
                        <input type="email" name="settings[admin_email]" class="form-control" value="<?php echo htmlspecialchars($all_settings['notifications']['admin_email']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['notifications']['admin_email']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['notifications']['smtp_host'])): ?>
                    <div class="form-group">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="settings[smtp_host]" class="form-control" value="<?php echo htmlspecialchars($all_settings['notifications']['smtp_host']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['notifications']['smtp_host']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['notifications']['smtp_port'])): ?>
                    <div class="form-group">
                        <label class="form-label">SMTP Port</label>
                        <input type="number" name="settings[smtp_port]" class="form-control" value="<?php echo $all_settings['notifications']['smtp_port']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['notifications']['smtp_port']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['notifications']['smtp_username'])): ?>
                    <div class="form-group">
                        <label class="form-label">SMTP Username</label>
                        <input type="text" name="settings[smtp_username]" class="form-control" value="<?php echo htmlspecialchars($all_settings['notifications']['smtp_username']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['notifications']['smtp_username']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['notifications']['smtp_password'])): ?>
                    <div class="form-group">
                        <label class="form-label">SMTP Password</label>
                        <input type="password" name="settings[smtp_password]" class="form-control" value="<?php echo htmlspecialchars($all_settings['notifications']['smtp_password']['value']); ?>">
                        <div class="form-hint"><?php echo $all_settings['notifications']['smtp_password']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($active_tab === 'claims'): ?>
        <!-- Claim Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">description</span>
                    Mipangilio ya Madai
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="grid-2">
                    <?php if (isset($all_settings['claims']['claim_deadline_days'])): ?>
                    <div class="form-group">
                        <label class="form-label">Muda wa Kuwasilisha Dai (Siku)</label>
                        <input type="number" name="settings[claim_deadline_days]" class="form-control" value="<?php echo $all_settings['claims']['claim_deadline_days']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['claims']['claim_deadline_days']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['claims']['valuation_deadline_days'])): ?>
                    <div class="form-group">
                        <label class="form-label">Muda wa Tathmini (Siku)</label>
                        <input type="number" name="settings[valuation_deadline_days]" class="form-control" value="<?php echo $all_settings['claims']['valuation_deadline_days']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['claims']['valuation_deadline_days']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['claims']['auto_approve_valuation'])): ?>
                    <div class="form-group">
                        <label class="form-label">Kubali Tathmini Moja kwa Moja</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[auto_approve_valuation]" value="1" <?php echo $all_settings['claims']['auto_approve_valuation']['value'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="form-hint"><?php echo $all_settings['claims']['auto_approve_valuation']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($active_tab === 'payments'): ?>
        <!-- Payment Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">payments</span>
                    Mipangilio ya Malipo
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="grid-2">
                    <?php if (isset($all_settings['payments']['payment_methods'])): ?>
                    <div class="form-group">
                        <label class="form-label">Njia za Malipo</label>
                        <div class="space-y-2">
                            <?php 
                            $payment_methods = $all_settings['payments']['payment_methods']['value'];
                            if (!is_array($payment_methods)) {
                                $payment_methods = ['bank_transfer', 'mobile_money', 'cash', 'cheque'];
                            }
                            ?>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[payment_methods][]" value="bank_transfer" <?php echo in_array('bank_transfer', (array)$payment_methods) ? 'checked' : ''; ?>>
                                <span>Bank Transfer (Benki)</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[payment_methods][]" value="mobile_money" <?php echo in_array('mobile_money', (array)$payment_methods) ? 'checked' : ''; ?>>
                                <span>Mobile Money (M-Pesa, Tigo Pesa, Airtel Money)</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[payment_methods][]" value="cash" <?php echo in_array('cash', (array)$payment_methods) ? 'checked' : ''; ?>>
                                <span>Cash (Fedha Taslimu)</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[payment_methods][]" value="cheque" <?php echo in_array('cheque', (array)$payment_methods) ? 'checked' : ''; ?>>
                                <span>Cheque (Hundi)</span>
                            </label>
                        </div>
                        <div class="form-hint"><?php echo $all_settings['payments']['payment_methods']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['payments']['min_payment_amount'])): ?>
                    <div class="form-group">
                        <label class="form-label">Kiwango cha Chini cha Malipo (TZS)</label>
                        <input type="number" name="settings[min_payment_amount]" class="form-control" value="<?php echo $all_settings['payments']['min_payment_amount']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['payments']['min_payment_amount']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['payments']['max_payment_amount'])): ?>
                    <div class="form-group">
                        <label class="form-label">Kiwango cha Juu cha Malipo (TZS)</label>
                        <input type="number" name="settings[max_payment_amount]" class="form-control" value="<?php echo $all_settings['payments']['max_payment_amount']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['payments']['max_payment_amount']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($active_tab === 'security'): ?>
        <!-- Security Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">security</span>
                    Mipangilio ya Usalama
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="grid-2">
                    <?php if (isset($all_settings['security']['session_timeout'])): ?>
                    <div class="form-group">
                        <label class="form-label">Muda wa Kukaa kwenye Mfumo (Sekunde)</label>
                        <input type="number" name="settings[session_timeout]" class="form-control" value="<?php echo $all_settings['security']['session_timeout']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['security']['session_timeout']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['security']['max_login_attempts'])): ?>
                    <div class="form-group">
                        <label class="form-label">Idadi ya Majaribio ya Kuingia</label>
                        <input type="number" name="settings[max_login_attempts]" class="form-control" value="<?php echo $all_settings['security']['max_login_attempts']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['security']['max_login_attempts']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['security']['password_expiry_days'])): ?>
                    <div class="form-group">
                        <label class="form-label">Nenosiri Linabadilishwa Baada ya Siku</label>
                        <input type="number" name="settings[password_expiry_days]" class="form-control" value="<?php echo $all_settings['security']['password_expiry_days']['value']; ?>">
                        <div class="form-hint"><?php echo $all_settings['security']['password_expiry_days']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['security']['two_factor_auth'])): ?>
                    <div class="form-group">
                        <label class="form-label">Uthibitishaji wa Hatua Mbili</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[two_factor_auth]" value="1" <?php echo $all_settings['security']['two_factor_auth']['value'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="form-hint"><?php echo $all_settings['security']['two_factor_auth']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($active_tab === 'maintenance'): ?>
        <!-- Maintenance Settings -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">build</span>
                    Mipangilio ya Matengenezo
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="grid-2">
                    <?php if (isset($all_settings['maintenance']['maintenance_mode'])): ?>
                    <div class="form-group">
                        <label class="form-label">Hali ya Matengenezo</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[maintenance_mode]" value="1" <?php echo $all_settings['maintenance']['maintenance_mode']['value'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="form-hint"><?php echo $all_settings['maintenance']['maintenance_mode']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['maintenance']['maintenance_message'])): ?>
                    <div class="form-group">
                        <label class="form-label">Ujumbe wa Matengenezo</label>
                        <textarea name="settings[maintenance_message]" class="form-textarea" rows="3"><?php echo htmlspecialchars($all_settings['maintenance']['maintenance_message']['value']); ?></textarea>
                        <div class="form-hint"><?php echo $all_settings['maintenance']['maintenance_message']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['maintenance']['backup_enabled'])): ?>
                    <div class="form-group">
                        <label class="form-label">Backup Otomatiki</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[backup_enabled]" value="1" <?php echo $all_settings['maintenance']['backup_enabled']['value'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="form-hint"><?php echo $all_settings['maintenance']['backup_enabled']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($all_settings['maintenance']['backup_frequency'])): ?>
                    <div class="form-group">
                        <label class="form-label">Mara ngapi Backup Inafanywa</label>
                        <select name="settings[backup_frequency]" class="form-select">
                            <option value="daily" <?php echo $all_settings['maintenance']['backup_frequency']['value'] == 'daily' ? 'selected' : ''; ?>>Kila Siku</option>
                            <option value="weekly" <?php echo $all_settings['maintenance']['backup_frequency']['value'] == 'weekly' ? 'selected' : ''; ?>>Kila Wiki</option>
                            <option value="monthly" <?php echo $all_settings['maintenance']['backup_frequency']['value'] == 'monthly' ? 'selected' : ''; ?>>Kila Mwezi</option>
                        </select>
                        <div class="form-hint"><?php echo $all_settings['maintenance']['backup_frequency']['description']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Danger Zone -->
        <div class="settings-card" style="border-color: #fee2e2;">
            <div class="settings-card-header" style="background: #fee2e2;">
                <h3 style="color: #991b1b;">
                    <span class="material-symbols-outlined">warning</span>
                    Eneo la Hatari
                </h3>
            </div>
            <div class="settings-card-body">
                <div class="flex flex-col gap-3">
                    <button type="button" class="btn-reset" style="border-color: #dc2626; color: #dc2626;" onclick="confirmResetSettings()">
                        <span class="material-symbols-outlined">restart_alt</span>
                        Rudisha Mipangilio Yote
                    </button>
                    <p class="text-xs text-red-600">Tahadhari: Hii itarudisha mipangilio yote kwenye ile ya awali.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="flex justify-end gap-3 mt-6">
            <button type="button" class="btn-reset" onclick="resetForm()">
                <span class="material-symbols-outlined text-sm">refresh</span>
                Rudia
            </button>
            <button type="submit" class="btn-save">
                <span class="material-symbols-outlined text-sm">save</span>
                Hifadhi Mipangilio
            </button>
        </div>
    </form>
    
</div>

<script>
    // Reset form to original values
    function resetForm() {
        Swal.fire({
            title: 'Rudia Mabadiliko?',
            text: 'Je, una uhakika unataka kurudia mabadiliko yote?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Rudia',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                location.reload();
            }
        });
    }
    
    // Confirm reset all settings to default
    function confirmResetSettings() {
        Swal.fire({
            title: 'Rudisha Mipangilio Yote?',
            html: 'Je, una uhakika unataka kurudisha mipangilio yote kwenye ile ya awali?<br><strong class="text-red-600">Hatua hii haiwezi kutenduliwa!</strong>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Rudisha',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create form to reset settings
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'reset_settings';
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
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