<?php
// admin/users.php - Manage All Users with Add User Modal
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Users';
$page_heading = 'Usimamizi wa Watumiaji';

// Get database connection
$conn = getDB();

// Get filter parameters
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($role_filter !== 'all') {
    $where_clauses[] = "role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if ($status_filter !== 'all') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR nin LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total users count for pagination
$count_query = "SELECT COUNT(*) as total FROM users $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_users = mysqli_fetch_assoc($count_result)['total'];

// Pagination - Changed to 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10; // Changed from 15 to 10
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_users / $per_page);

// Get users data
$query = "SELECT id, full_name, email, phone, nin, role, status, created_at 
          FROM users 
          $where_sql 
          ORDER BY $sort_by $sort_order 
          LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}

// Get role counts for dashboard
$role_counts = [];
$role_query = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$role_result = mysqli_query($conn, $role_query);
while ($row = mysqli_fetch_assoc($role_result)) {
    $role_counts[$row['role']] = $row['count'];
}

// Get status counts
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM users GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

// Handle Add New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $nin = trim($_POST['nin'] ?? '');
    $role = trim($_POST['role'] ?? 'claimant');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = "Jina kamili linahitajika";
    }
    
    if (empty($email)) {
        $errors[] = "Barua pepe inahitajika";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Barua pepe si sahihi";
    }
    
    if (empty($password)) {
        $errors[] = "Nenosiri linahitajika";
    } elseif (strlen($password) < 6) {
        $errors[] = "Nenosiri lazima liwe na angalau herufi 6";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Manenosiri hayalingani";
    }
    
    // Check if email already exists
    $check_email = "SELECT id FROM users WHERE email = ?";
    $check_stmt = mysqli_prepare($conn, $check_email);
    mysqli_stmt_bind_param($check_stmt, "s", $email);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $errors[] = "Barua pepe tayari inatumiwa na mtumiaji mwingine";
    }
    
    // Check if phone already exists
    if (!empty($phone)) {
        $check_phone = "SELECT id FROM users WHERE phone = ?";
        $check_phone_stmt = mysqli_prepare($conn, $check_phone);
        mysqli_stmt_bind_param($check_phone_stmt, "s", $phone);
        mysqli_stmt_execute($check_phone_stmt);
        mysqli_stmt_store_result($check_phone_stmt);
        
        if (mysqli_stmt_num_rows($check_phone_stmt) > 0) {
            $errors[] = "Namba ya simu tayari inatumiwa na mtumiaji mwingine";
        }
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $status = 'active';
        
        $insert_query = "INSERT INTO users (full_name, email, phone, nin, role, password, status, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "sssssss", $full_name, $email, $phone, $nin, $role, $hashed_password, $status);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            $new_user_id = mysqli_insert_id($conn);
            logAudit($conn, $_SESSION['user_id'], 'CREATE_USER', 'users', $new_user_id, null, [
                'full_name' => $full_name,
                'email' => $email,
                'role' => $role
            ]);
            $_SESSION['success_message'] = "Mtumiaji $full_name ameongezwa kikamilifu.";
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kuongeza mtumiaji: " . mysqli_error($conn);
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
    
    header("Location: users.php?role=$role_filter&status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        // Don't allow admin to change own status
        if (in_array($_SESSION['user_id'], $selected_ids) && $action !== 'delete') {
            $_SESSION['error_message'] = "Huwezi kubadilisha hali ya akaunti yako mwenyewe.";
        } else {
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            if ($action === 'delete') {
                // Don't allow deleting own account
                $selected_ids = array_diff($selected_ids, [$_SESSION['user_id']]);
                if (empty($selected_ids)) {
                    $_SESSION['error_message'] = "Huwezi kufuta akaunti yako mwenyewe.";
                } else {
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    $delete_query = "DELETE FROM users WHERE id IN ($placeholders)";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    $delete_types = str_repeat("i", count($selected_ids));
                    mysqli_stmt_bind_param($delete_stmt, $delete_types, ...$selected_ids);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        $affected = mysqli_stmt_affected_rows($delete_stmt);
                        $_SESSION['success_message'] = "Watumiaji $affected wamefutwa kikamilifu.";
                        logAudit($conn, $_SESSION['user_id'], 'BULK_DELETE_USERS', 'users', null, null, ['ids' => $selected_ids]);
                    } else {
                        $_SESSION['error_message'] = "Hitilafu katika kufuta watumiaji.";
                    }
                }
            } else {
                $update_query = "UPDATE users SET status = ? WHERE id IN ($placeholders)";
                $update_params = array_merge([$action], $selected_ids);
                $update_stmt = mysqli_prepare($conn, $update_query);
                $update_types = "s" . str_repeat("i", count($selected_ids));
                mysqli_stmt_bind_param($update_stmt, $update_types, ...$update_params);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $affected = mysqli_stmt_affected_rows($update_stmt);
                    $status_label = $action === 'active' ? 'Wamewezeshwa' : 'Wamezimwa';
                    $_SESSION['success_message'] = "Watumiaji $affected $status_label kikamilifu.";
                    logAudit($conn, $_SESSION['user_id'], 'BULK_UPDATE_USERS', 'users', null, null, [
                        'action' => $action,
                        'count' => $affected,
                        'ids' => $selected_ids
                    ]);
                } else {
                    $_SESSION['error_message'] = "Hitilafu katika kubadilisha hali ya watumiaji.";
                }
            }
        }
    }
    
    header("Location: users.php?role=$role_filter&status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle single user status update
if (isset($_GET['update_status']) && isset($_GET['user_id'])) {
    $new_status = $_GET['update_status'];
    $user_id = intval($_GET['user_id']);
    
    // Don't allow admin to change own status
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "Huwezi kubadilisha hali ya akaunti yako mwenyewe.";
    } else {
        $old_status_query = "SELECT status FROM users WHERE id = ?";
        $old_stmt = mysqli_prepare($conn, $old_status_query);
        mysqli_stmt_bind_param($old_stmt, "i", $user_id);
        mysqli_stmt_execute($old_stmt);
        $old_result = mysqli_stmt_get_result($old_stmt);
        $old_data = mysqli_fetch_assoc($old_result);
        
        $update_stmt = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "si", $new_status, $user_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $_SESSION['success_message'] = "Hali ya mtumiaji imebadilishwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'UPDATE_USER_STATUS', 'users', $user_id, 
                    ['status' => $old_data['status']], 
                    ['status' => $new_status]);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kubadilisha hali ya mtumiaji.";
        }
    }
    
    header("Location: users.php?role=$role_filter&status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle delete user
if (isset($_GET['delete']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    // Don't allow admin to delete own account
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "Huwezi kufuta akaunti yako mwenyewe.";
    } else {
        // Check if user has claims
        $check_claims = mysqli_query($conn, "SELECT id FROM claims WHERE claimant_id = $user_id LIMIT 1");
        if (mysqli_num_rows($check_claims) > 0) {
            $_SESSION['error_message'] = "Huwezi kufuta mtumiaji huyu kwa sababu ana madai yaliyosajiliwa.";
        } else {
            $delete_stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
            mysqli_stmt_bind_param($delete_stmt, "i", $user_id);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                $_SESSION['success_message'] = "Mtumiaji amefutwa kikamilifu.";
                logAudit($conn, $_SESSION['user_id'], 'DELETE_USER', 'users', $user_id);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kufuta mtumiaji.";
            }
        }
    }
    
    header("Location: users.php?role=$role_filter&status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle AJAX get user data for edit
if (isset($_GET['ajax_get_user']) && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $user_id = intval($_GET['user_id']);
    $query = "SELECT id, full_name, email, phone, nin, role, status FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $user]);
    exit();
}

// Handle AJAX update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_user'])) {
    header('Content-Type: application/json');
    
    $user_id = intval($_POST['user_id']);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $nin = trim($_POST['nin'] ?? '');
    $role = trim($_POST['role'] ?? '');
    
    // Don't allow admin to change own role
    if ($user_id == $_SESSION['user_id'] && $role !== 'super_admin') {
        echo json_encode(['success' => false, 'message' => 'Huwezi kubadilisha role yako mwenyewe.']);
        exit();
    }
    
    // Check if email already exists for another user
    $check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
    $check_stmt = mysqli_prepare($conn, $check_email);
    mysqli_stmt_bind_param($check_stmt, "si", $email, $user_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'Barua pepe tayari inatumiwa na mtumiaji mwingine.']);
        exit();
    }
    
    $update_query = "UPDATE users SET full_name = ?, email = ?, phone = ?, nin = ?, role = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "sssssi", $full_name, $email, $phone, $nin, $role, $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id);
        echo json_encode(['success' => true, 'message' => 'Taarifa za mtumiaji zimehaririwa kikamilifu']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Hitilafu katika kuhariri taarifa']);
    }
    exit();
}

// Handle reset password
if (isset($_GET['reset_password']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    $new_password = 'password123'; // Default password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $update_stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
    mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $_SESSION['success_message'] = "Nenosiri limewekwa upya kuwa: $new_password";
        logAudit($conn, $_SESSION['user_id'], 'RESET_USER_PASSWORD', 'users', $user_id);
    } else {
        $_SESSION['error_message'] = "Hitilafu katika kuweka upya nenosiri.";
    }
    
    header("Location: users.php?role=$role_filter&status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Include header
require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.active { background: #d1fae5; color: #065f46; }
    .status-badge.inactive { background: #fee2e2; color: #991b1b; }
    
    /* Role Badge */
    .role-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    .role-badge.super_admin { background: #e9d5ff; color: #6b21a5; }
    .role-badge.claimant { background: #fed7aa; color: #9a3412; }
    .role-badge.valuer { background: #d1fae5; color: #065f46; }
    .role-badge.legal_officer { background: #cffafe; color: #0891b2; }
    .role-badge.finance_officer { background: #fef3c7; color: #92400e; }
    .role-badge.commissioner { background: #e0e7ff; color: #4338ca; }
    
    /* Table Styles */
    .users-table { width: 100%; border-collapse: collapse; }
    .users-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .users-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; }
    .users-table tr:hover { background-color: #eef6ea; }
    
    /* Action Button */
    .action-btn { background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 0.5rem; color: #6d7b6c; transition: all 0.2s; }
    .action-btn:hover { background-color: #e8f0e4; color: #006e2c; }
    
    /* Filter Tabs */
    .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; transition: all 0.2s ease; }
    .filter-tab.active { background-color: #006e2c; color: white; }
    .filter-tab:not(.active):hover { background-color: #e8f0e4; }
    
    .checkbox-select { width: 1rem; height: 1rem; accent-color: #006e2c; cursor: pointer; }
    .pagination-btn { padding: 0.375rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.75rem; transition: all 0.15s ease; }
    .pagination-btn:hover:not(.active) { background-color: #eef6ea; }
    .pagination-btn.active { background-color: #006e2c; color: white; border-color: #006e2c; }
    
    /* Action Modal */
    .action-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.2s ease; }
    .action-modal-overlay.show { opacity: 1; visibility: visible; }
    .action-modal { background: white; border-radius: 1rem; width: 320px; max-width: 90%; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.2s ease; }
    .action-modal-overlay.show .action-modal { transform: scale(1); }
    .action-modal-header { padding: 1rem; background: #f4fcef; border-bottom: 1px solid #bccab9; font-weight: 600; }
    .action-modal-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid #e8f0e4; width: 100%; background: none; border: none; text-align: left; }
    .action-modal-item:hover { background-color: #eef6ea; }
    .action-modal-item.delete { color: #dc2626; }
    .action-modal-item.delete:hover { background-color: #fee2e2; }
    .action-modal-divider { height: 1px; background: #bccab9; margin: 0.25rem 0; }
    
    /* Edit Modal */
    .edit-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .edit-modal-overlay.show { opacity: 1; visibility: visible; }
    .edit-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 550px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .edit-modal-overlay.show .edit-modal-container { transform: scale(1); }
    .edit-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .edit-modal-body { padding: 1.5rem; }
    .edit-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    
    /* Add User Modal */
    .add-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10002; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .add-modal-overlay.show { opacity: 1; visibility: visible; }
    .add-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 550px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .add-modal-overlay.show .add-modal-container { transform: scale(1); }
    .add-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .add-modal-body { padding: 1.5rem; }
    .add-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.25rem; }
    .form-label.required::after { content: "*"; color: #dc2626; margin-left: 0.25rem; }
    .form-control { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .form-control:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    .form-control[readonly] { background: #f4fcef; }
    .form-hint { font-size: 0.7rem; color: #6d7b6c; margin-top: 0.25rem; }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Usimamizi wa Watumiaji</h2>
            <p class="text-secondary text-sm mt-1">Simamia, kagua na usindikie watumiaji wote wa mfumo</p>
        </div>
        <div class="flex gap-3">
            <button onclick="openAddUserModal()" class="px-4 py-2 bg-primary text-white rounded-lg flex items-center gap-2 hover:bg-primary-container transition shadow-sm">
                <span class="material-symbols-outlined text-sm">person_add</span> Mtumiaji Mpya
            </button>
            <button onclick="exportUsers()" class="px-4 py-2 border border-outline-variant rounded-lg flex items-center gap-2 hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">download</span> Export
            </button>
        </div>
    </div>
    
    <!-- Role Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?role=all&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'all' ? 'active' : 'text-secondary'; ?>">
            Zote (<?php echo array_sum($role_counts); ?>)
        </a>
        <a href="?role=super_admin&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'super_admin' ? 'active' : 'text-secondary'; ?>">
            Super Admin (<?php echo $role_counts['super_admin'] ?? 0; ?>)
        </a>
        <a href="?role=claimant&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'claimant' ? 'active' : 'text-secondary'; ?>">
            Wadai (<?php echo $role_counts['claimant'] ?? 0; ?>)
        </a>
        <a href="?role=valuer&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'valuer' ? 'active' : 'text-secondary'; ?>">
            Wakaguzi (<?php echo $role_counts['valuer'] ?? 0; ?>)
        </a>
        <a href="?role=legal_officer&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'legal_officer' ? 'active' : 'text-secondary'; ?>">
            Wanasheria (<?php echo $role_counts['legal_officer'] ?? 0; ?>)
        </a>
        <a href="?role=finance_officer&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'finance_officer' ? 'active' : 'text-secondary'; ?>">
            Fedha (<?php echo $role_counts['finance_officer'] ?? 0; ?>)
        </a>
        <a href="?role=commissioner&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $role_filter === 'commissioner' ? 'active' : 'text-secondary'; ?>">
            Makamishna (<?php echo $role_counts['commissioner'] ?? 0; ?>)
        </a>
    </div>
    
    <!-- Status Sub-filters -->
    <div class="flex flex-wrap gap-2">
        <a href="?role=<?php echo $role_filter; ?>&status=all&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'all' ? 'active' : 'text-secondary'; ?> text-xs py-1 px-3">
            Hali Zote
        </a>
        <a href="?role=<?php echo $role_filter; ?>&status=active&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'active' ? 'active' : 'text-secondary'; ?> text-xs py-1 px-3">
            Wanafanya Kazi (<?php echo $status_counts['active'] ?? 0; ?>)
        </a>
        <a href="?role=<?php echo $role_filter; ?>&status=inactive&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'inactive' ? 'active' : 'text-secondary'; ?> text-xs py-1 px-3">
            Hawafanyi Kazi (<?php echo $status_counts['inactive'] ?? 0; ?>)
        </a>
    </div>
    
    <!-- Search and Bulk Actions -->
    <div class="flex flex-col md:flex-row gap-4">
        <form method="GET" action="" class="flex-1" id="searchForm">
            <input type="hidden" name="role" value="<?php echo $role_filter; ?>">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-xl">search</span>
                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="Tafuta kwa jina, barua pepe, namba ya simu au NIN..." 
                       class="w-full pl-10 pr-4 py-2.5 border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
            </div>
        </form>
        
        <div class="flex gap-2">
            <select id="bulk_action_select" class="px-3 py-2.5 border border-outline rounded-lg bg-white text-sm">
                <option value="">Bulk Action</option>
                <option value="active">Weka Wanafanya Kazi</option>
                <option value="inactive">Weka Hawafanyi Kazi</option>
                <option value="delete">Futa Watumiaji</option>
            </select>
            <button onclick="applyBulkAction()" class="px-4 py-2.5 bg-primary text-white rounded-lg hover:bg-primary-container transition shadow-sm">
                Tumia
            </button>
        </div>
    </div>
    
    <!-- Users Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <form id="bulk_form" method="POST">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th class="w-10"><input type="checkbox" id="select_all" class="checkbox-select"></th>
                            <th><a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=full_name&order=<?php echo $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">Jina Kamili</a></th>
                            <th>Barua Pepe</th>
                            <th>Namba ya Simu</th>
                            <th>NIN</th>
                            <th><a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=role&order=<?php echo $sort_by == 'role' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">Daraja</a></th>
                            <th>Hali</th>
                            <th><a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=created_at&order=<?php echo $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">Tarehe</a></th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-12 text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2 block">people</span>
                                Hakuna watumiaji wanaolingana na vigezo vyako
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <tr id="row-<?php echo $user['id']; ?>" data-user-id="<?php echo $user['id']; ?>">
                            <td>
                                <input type="checkbox" name="selected_ids[]" value="<?php echo $user['id']; ?>" class="checkbox-select user-checkbox" <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                            </td>
                            <td class="font-semibold"><?php echo htmlspecialchars($user['full_name']); ?> 
                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                <span class="text-xs text-primary ml-1">(Wewe)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($user['nin'] ?? '-'); ?></td>
                            <td><span class="role-badge <?php echo $user['role']; ?>"><?php echo str_replace('_', ' ', ucfirst($user['role'])); ?></span></td>
                            <td><span class="status-badge <?php echo $user['status']; ?>"><?php echo $user['status'] == 'active' ? '✅ Anafanya Kazi' : '❌ Hafanyi Kazi'; ?></span></td>
                            <td class="text-sm text-secondary"><?php echo formatDate($user['created_at'], 'd M Y'); ?></td>
                            <td class="text-center">
                                <div class="action-menu-container">
                                    <button type="button" class="action-btn" onclick="showActionModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')">
                                        <span class="material-symbols-outlined">more_vert</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-outline-variant bg-surface-container-low">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_users); ?> kati ya <?php echo $total_users; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Inayofuata</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Modal -->
<div id="actionModal" class="action-modal-overlay">
    <div class="action-modal">
        <div class="action-modal-header" id="actionModalHeader">Kitendo cha Mtumiaji</div>
        <div class="action-modal-body">
            <button type="button" class="action-modal-item" id="viewUserBtn"><span class="material-symbols-outlined">visibility</span>Angalia Maelezo</button>
            <button type="button" class="action-modal-item" id="editUserBtn"><span class="material-symbols-outlined">edit</span>Hariri Taarifa</button>
            <button type="button" class="action-modal-item" id="resetPasswordBtn"><span class="material-symbols-outlined">lock_reset</span>Weka Upya Nenosiri</button>
            <div class="action-modal-divider"></div>
            <div class="px-3 py-1 text-xs font-semibold text-secondary">Badilisha Hali:</div>
            <button type="button" class="action-modal-item" id="activateUserBtn"><span class="material-symbols-outlined text-green-600">check_circle</span>Weka Anafanya Kazi</button>
            <button type="button" class="action-modal-item" id="deactivateUserBtn"><span class="material-symbols-outlined text-red-600">cancel</span>Weka Hafanyi Kazi</button>
            <div class="action-modal-divider"></div>
            <button type="button" class="action-modal-item delete" id="deleteUserBtn"><span class="material-symbols-outlined">delete</span>Futa Mtumiaji</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="edit-modal-overlay">
    <div class="edit-modal-container">
        <div class="edit-modal-header">
            <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-2xl">edit</span><h3 class="text-lg font-semibold">Hariri Mtumiaji</h3></div>
            <button type="button" id="closeEditModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg"><span class="material-symbols-outlined text-secondary">close</span></button>
        </div>
        <form id="editUserForm" onsubmit="return false;">
            <input type="hidden" id="edit_user_id" name="user_id">
            <div class="edit-modal-body">
                <div class="form-group">
                    <label class="form-label required">Jina Kamili</label>
                    <input type="text" id="edit_full_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label required">Barua Pepe</label>
                    <input type="email" id="edit_email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Namba ya Simu</label>
                    <input type="tel" id="edit_phone" class="form-control" placeholder="0712345678">
                </div>
                <div class="form-group">
                    <label class="form-label">NIN (Namba ya Utambulisho)</label>
                    <input type="text" id="edit_nin" class="form-control" placeholder="Namba ya NIDA">
                </div>
                <div class="form-group">
                    <label class="form-label required">Daraja / Role</label>
                    <select id="edit_role" class="form-control">
                        <option value="claimant">Claimant (Mwombaji)</option>
                        <option value="valuer">Valuer (Mkaguzi)</option>
                        <option value="legal_officer">Legal Officer (Afisa Kisheria)</option>
                        <option value="finance_officer">Finance Officer (Afisa Fedha)</option>
                        <option value="commissioner">Commissioner (Kamishna)</option>
                        <option value="super_admin">Super Admin (Msimamizi Mkuu)</option>
                    </select>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button type="button" id="cancelEditBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" id="saveEditBtn" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Hifadhi</button>
            </div>
        </form>
    </div>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="add-modal-overlay">
    <div class="add-modal-container">
        <div class="add-modal-header">
            <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-2xl">person_add</span><h3 class="text-lg font-semibold">Ongeza Mtumiaji Mpya</h3></div>
            <button type="button" id="closeAddModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg"><span class="material-symbols-outlined text-secondary">close</span></button>
        </div>
        <form id="addUserForm" method="POST" action="">
            <div class="add-modal-body">
                <div class="form-group">
                    <label class="form-label required">Jina Kamili</label>
                    <input type="text" name="full_name" id="add_full_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label required">Barua Pepe</label>
                    <input type="email" name="email" id="add_email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Namba ya Simu</label>
                    <input type="tel" name="phone" id="add_phone" class="form-control" placeholder="0712345678">
                </div>
                <div class="form-group">
                    <label class="form-label">NIN (Namba ya Utambulisho)</label>
                    <input type="text" name="nin" id="add_nin" class="form-control" placeholder="Namba ya NIDA">
                </div>
                <div class="form-group">
                    <label class="form-label required">Daraja / Role</label>
                    <select name="role" id="add_role" class="form-control" required>
                        <option value="claimant">Claimant (Mwombaji)</option>
                        <option value="valuer">Valuer (Mkaguzi)</option>
                        <option value="legal_officer">Legal Officer (Afisa Kisheria)</option>
                        <option value="finance_officer">Finance Officer (Afisa Fedha)</option>
                        <option value="commissioner">Commissioner (Kamishna)</option>
                        <option value="super_admin">Super Admin (Msimamizi Mkuu)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label required">Nenosiri</label>
                    <input type="password" name="password" id="add_password" class="form-control" required>
                    <div class="form-hint">Nenosiri lazima liwe na angalau herufi 6</div>
                </div>
                <div class="form-group">
                    <label class="form-label required">Thibitisha Nenosiri</label>
                    <input type="password" name="confirm_password" id="add_confirm_password" class="form-control" required>
                </div>
            </div>
            <div class="add-modal-footer">
                <button type="button" id="cancelAddBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" name="add_user" value="1" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Ongeza Mtumiaji</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentUserId = null;
    let currentUserName = null;
    
    // ========== ADD USER MODAL FUNCTIONS ==========
    function openAddUserModal() {
        const modal = document.getElementById('addUserModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        // Clear form
        document.getElementById('add_full_name').value = '';
        document.getElementById('add_email').value = '';
        document.getElementById('add_phone').value = '';
        document.getElementById('add_nin').value = '';
        document.getElementById('add_role').value = 'claimant';
        document.getElementById('add_password').value = '';
        document.getElementById('add_confirm_password').value = '';
    }
    
    function closeAddUserModal() {
        const modal = document.getElementById('addUserModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // ========== ACTION MODAL FUNCTIONS ==========
    function showActionModal(userId, userName) {
        currentUserId = userId;
        currentUserName = userName;
        const modal = document.getElementById('actionModal');
        const header = document.getElementById('actionModalHeader');
        header.innerHTML = `Kitendo cha Mtumiaji: ${userName}`;
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function closeActionModal() {
        const modal = document.getElementById('actionModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function viewUser() {
        window.location.href = `view-user.php?id=${currentUserId}`;
    }
    
    // Reset password
    async function resetPassword() {
        closeActionModal();
        
        const result = await Swal.fire({
            title: 'Weka Upya Nenosiri',
            text: `Je, una uhakika unataka kuweka upya nenosiri la ${currentUserName}? Nenosiri jipya litakuwa "password123"`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Weka Upya',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inaweka upya...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            window.location.href = `?reset_password=1&user_id=${currentUserId}&role=${encodeURIComponent('<?php echo $role_filter; ?>')}&status=${encodeURIComponent('<?php echo $status_filter; ?>')}&search=${encodeURIComponent('<?php echo $search_term; ?>')}&page=${<?php echo $page; ?>}`;
        }
    }
    
    // Activate user
    async function activateUser() {
        closeActionModal();
        const result = await Swal.fire({
            title: 'Weka Mtumiaji Anafanya Kazi',
            text: `Je, una uhakika unataka kuwezesha akaunti ya ${currentUserName}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inabadilisha...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            window.location.href = `?update_status=active&user_id=${currentUserId}&role=${encodeURIComponent('<?php echo $role_filter; ?>')}&status=${encodeURIComponent('<?php echo $status_filter; ?>')}&search=${encodeURIComponent('<?php echo $search_term; ?>')}&page=${<?php echo $page; ?>}`;
        }
    }
    
    // Deactivate user
    async function deactivateUser() {
        closeActionModal();
        const result = await Swal.fire({
            title: 'Weka Mtumiaji Hafanyi Kazi',
            text: `Je, una uhakika unataka kuzima akaunti ya ${currentUserName}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inabadilisha...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            window.location.href = `?update_status=inactive&user_id=${currentUserId}&role=${encodeURIComponent('<?php echo $role_filter; ?>')}&status=${encodeURIComponent('<?php echo $status_filter; ?>')}&search=${encodeURIComponent('<?php echo $search_term; ?>')}&page=${<?php echo $page; ?>}`;
        }
    }
    
    // Delete user
    async function deleteUser() {
        closeActionModal();
        const result = await Swal.fire({
            title: 'Futa Mtumiaji?',
            text: `Je, una uhakika unataka kufuta ${currentUserName}? Hatua hii haiwezi kutenduliwa.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inafuta...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            window.location.href = `?delete=1&user_id=${currentUserId}&role=${encodeURIComponent('<?php echo $role_filter; ?>')}&status=${encodeURIComponent('<?php echo $status_filter; ?>')}&search=${encodeURIComponent('<?php echo $search_term; ?>')}&page=${<?php echo $page; ?>}`;
        }
    }
    
    // ========== EDIT MODAL FUNCTIONS ==========
    async function openEditModal() {
        closeActionModal();
        
        const modal = document.getElementById('editModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_user=1&user_id=${currentUserId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const user = data.data;
                document.getElementById('edit_user_id').value = user.id;
                document.getElementById('edit_full_name').value = user.full_name;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_phone').value = user.phone || '';
                document.getElementById('edit_nin').value = user.nin || '';
                document.getElementById('edit_role').value = user.role;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa', confirmButtonColor: '#006e2c' });
                closeEditModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
            closeEditModal();
        }
    }
    
    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    async function submitEditUser() {
        const formData = new URLSearchParams({
            ajax_update_user: 1,
            user_id: document.getElementById('edit_user_id').value,
            full_name: document.getElementById('edit_full_name').value,
            email: document.getElementById('edit_email').value,
            phone: document.getElementById('edit_phone').value,
            nin: document.getElementById('edit_nin').value,
            role: document.getElementById('edit_role').value
        });
        
        Swal.fire({ title: 'Inahifadhi...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData });
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: data.message, confirmButtonColor: '#006e2c', timer: 2000 }).then(() => window.location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' });
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
        }
    }
    
    // ========== BULK ACTIONS ==========
    const selectAll = document.getElementById('select_all');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            userCheckboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = selectAll.checked;
                }
            });
        });
    }
    
    function applyBulkAction() {
        const selected = document.querySelectorAll('.user-checkbox:checked');
        const action = document.getElementById('bulk_action_select').value;
        
        if (selected.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Hakuna Watumiaji', text: 'Chagua angalau mtumiaji mmoja', confirmButtonColor: '#006e2c' });
            return;
        }
        
        if (!action) {
            Swal.fire({ icon: 'warning', title: 'Chagua Kitendo', text: 'Chagua kitendo cha kufanya', confirmButtonColor: '#006e2c' });
            return;
        }
        
        let title = '', text = '', confirmText = '';
        if (action === 'active') {
            title = 'Weka Watumiaji Wanafanya Kazi';
            text = `Je, una uhakika unataka kuwezesha watumiaji ${selected.length}?`;
            confirmText = 'Ndiyo, Wezesha';
        } else if (action === 'inactive') {
            title = 'Weka Watumiaji Hawafanyi Kazi';
            text = `Je, una uhakika unataka kuzima watumiaji ${selected.length}?`;
            confirmText = 'Ndiyo, Zima';
        } else if (action === 'delete') {
            title = 'Futa Watumiaji?';
            text = `Je, una uhakika unataka kufuta watumiaji ${selected.length}? Hatua haiwezi kutenduliwa.`;
            confirmText = 'Ndiyo, Futa';
        }
        
        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: confirmText,
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('bulk_action_value').value = action;
                document.getElementById('bulk_form').submit();
            }
        });
    }
    
    function exportUsers() {
        Swal.fire({ icon: 'info', title: 'Export', text: 'Ripoti ya watumiaji itapakuliwa', confirmButtonColor: '#006e2c' });
    }
    
    // Search with debounce
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
        });
    }
    
    // Validate password match on add user form
    const addUserForm = document.getElementById('addUserForm');
    if (addUserForm) {
        addUserForm.addEventListener('submit', function(e) {
            const password = document.getElementById('add_password').value;
            const confirmPassword = document.getElementById('add_confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Manenosiri hayalingani. Tafadhali hakikisha yanafanana.',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Nenosiri lazima liwe na angalau herufi 6.',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
        });
    }
    
    // ========== EVENT LISTENERS ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Action modal buttons
        document.getElementById('viewUserBtn').addEventListener('click', viewUser);
        document.getElementById('editUserBtn').addEventListener('click', openEditModal);
        document.getElementById('resetPasswordBtn').addEventListener('click', resetPassword);
        document.getElementById('activateUserBtn').addEventListener('click', activateUser);
        document.getElementById('deactivateUserBtn').addEventListener('click', deactivateUser);
        document.getElementById('deleteUserBtn').addEventListener('click', deleteUser);
        
        // Close action modal on overlay click
        document.getElementById('actionModal').addEventListener('click', function(e) {
            if (e.target === this) closeActionModal();
        });
        
        // Edit modal buttons
        document.getElementById('closeEditModalBtn').addEventListener('click', closeEditModal);
        document.getElementById('cancelEditBtn').addEventListener('click', closeEditModal);
        document.getElementById('saveEditBtn').addEventListener('click', submitEditUser);
        
        // Close edit modal on overlay click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        
        // Add user modal buttons
        document.getElementById('closeAddModalBtn').addEventListener('click', closeAddUserModal);
        document.getElementById('cancelAddBtn').addEventListener('click', closeAddUserModal);
        
        // Close add modal on overlay click
        document.getElementById('addUserModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddUserModal();
        });
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>