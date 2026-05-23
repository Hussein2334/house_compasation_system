<?php
// admin/help-requests.php - Manage User Help/Support Requests
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and has access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Only super admin and support staff can view help requests
if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'commissioner') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Help Requests';
$page_heading = 'Maombi ya Msaada';

// Get database connection
$conn = getDB();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['created_at', 'full_name', 'subject', 'status', 'priority'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'created_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "h.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($category_filter !== 'all') {
    $where_clauses[] = "h.category = ?";
    $params[] = $category_filter;
    $types .= "s";
}

if ($priority_filter !== 'all') {
    $where_clauses[] = "h.priority = ?";
    $params[] = $priority_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(h.full_name LIKE ? OR h.email LIKE ? OR h.subject LIKE ? OR h.message LIKE ? OR h.phone LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total requests count
$count_query = "SELECT COUNT(*) as total FROM help_requests h $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_requests = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_requests / $per_page);

// Get help requests data
$query = "SELECT h.*, 
          a.full_name as assigned_to_name,
          r.full_name as responded_by_name
          FROM help_requests h
          LEFT JOIN users a ON h.assigned_to = a.id
          LEFT JOIN users r ON h.responded_by = r.id
          $where_sql
          ORDER BY ";
          
if ($sort_by === 'full_name') {
    $query .= "h.full_name $sort_order";
} elseif ($sort_by === 'subject') {
    $query .= "h.subject $sort_order";
} elseif ($sort_by === 'status') {
    $query .= "h.status $sort_order";
} elseif ($sort_by === 'priority') {
    $query .= "FIELD(h.priority, 'urgent', 'high', 'medium', 'low') $sort_order";
} else {
    $query .= "h.created_at $sort_order";
}

$query .= " LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$requests = [];
while ($row = mysqli_fetch_assoc($result)) {
    $requests[] = $row;
}

// Get status counts
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM help_requests GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

// Get category counts
$category_counts = [];
$category_query = "SELECT category, COUNT(*) as count FROM help_requests GROUP BY category";
$category_result = mysqli_query($conn, $category_query);
while ($row = mysqli_fetch_assoc($category_result)) {
    $category_counts[$row['category']] = $row['count'];
}

// Get priority counts
$priority_counts = [];
$priority_query = "SELECT priority, COUNT(*) as count FROM help_requests GROUP BY priority";
$priority_result = mysqli_query($conn, $priority_query);
while ($row = mysqli_fetch_assoc($priority_result)) {
    $priority_counts[$row['priority']] = $row['count'];
}

// Get staff users for assignment
$staff_query = "SELECT id, full_name, email, role FROM users WHERE role IN ('super_admin', 'commissioner') AND status = 'active' ORDER BY full_name";
$staff_result = mysqli_query($conn, $staff_query);
$staff_users = [];
while ($row = mysqli_fetch_assoc($staff_result)) {
    $staff_users[] = $row;
}

// Handle update request (assign, change status, add response)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {
    $request_id = intval($_POST['request_id']);
    $status = $_POST['status'] ?? null;
    $priority = $_POST['priority'] ?? null;
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $response = trim($_POST['response'] ?? '');
    
    $update_fields = [];
    $update_params = [];
    $update_types = "";
    
    if ($status) {
        $update_fields[] = "status = ?";
        $update_params[] = $status;
        $update_types .= "s";
    }
    
    if ($priority) {
        $update_fields[] = "priority = ?";
        $update_params[] = $priority;
        $update_types .= "s";
    }
    
    if ($assigned_to !== null) {
        $update_fields[] = "assigned_to = ?";
        $update_params[] = $assigned_to;
        $update_types .= "i";
    }
    
    if (!empty($response)) {
        $update_fields[] = "response = ?";
        $update_fields[] = "responded_by = ?";
        $update_fields[] = "responded_at = NOW()";
        $update_params[] = $response;
        $update_params[] = $_SESSION['user_id'];
        $update_types .= "si";
    }
    
    if (!empty($update_fields)) {
        $update_query = "UPDATE help_requests SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $update_params[] = $request_id;
        $update_types .= "i";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, $update_types, ...$update_params);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $_SESSION['success_message'] = "Ombi la msaada limebadilishwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'UPDATE_HELP_REQUEST', 'help_requests', $request_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kubadilisha ombi: " . mysqli_error($conn);
        }
    }
    
    header("Location: help-requests.php?status=$status_filter&category=$category_filter&priority=$priority_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        if ($action === 'delete') {
            $delete_query = "DELETE FROM help_requests WHERE id IN ($placeholders)";
            $delete_stmt = mysqli_prepare($conn, $delete_query);
            $delete_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($delete_stmt, $delete_types, ...$selected_ids);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                $affected = mysqli_stmt_affected_rows($delete_stmt);
                $_SESSION['success_message'] = "Maombi $affected yamefutwa kikamilifu.";
                logAudit($conn, $_SESSION['user_id'], 'BULK_DELETE_HELP_REQUESTS', 'help_requests', null, null, ['ids' => $selected_ids]);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kufuta maombi.";
            }
        } elseif ($action === 'mark_resolved') {
            $update_query = "UPDATE help_requests SET status = 'resolved' WHERE id IN ($placeholders)";
            $update_stmt = mysqli_prepare($conn, $update_query);
            $update_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($update_stmt, $update_types, ...$selected_ids);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $affected = mysqli_stmt_affected_rows($update_stmt);
                $_SESSION['success_message'] = "Maombi $affected yamewekwa kama yametatuliwa.";
                logAudit($conn, $_SESSION['user_id'], 'BULK_RESOLVE_HELP_REQUESTS', 'help_requests', null, null, ['ids' => $selected_ids]);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kubadilisha maombi.";
            }
        } elseif ($action === 'mark_in_progress') {
            $update_query = "UPDATE help_requests SET status = 'in_progress' WHERE id IN ($placeholders)";
            $update_stmt = mysqli_prepare($conn, $update_query);
            $update_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($update_stmt, $update_types, ...$selected_ids);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $affected = mysqli_stmt_affected_rows($update_stmt);
                $_SESSION['success_message'] = "Maombi $affected yamewekwa kama yanachakatwa.";
                logAudit($conn, $_SESSION['user_id'], 'BULK_INPROGRESS_HELP_REQUESTS', 'help_requests', null, null, ['ids' => $selected_ids]);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kubadilisha maombi.";
            }
        }
    }
    
    header("Location: help-requests.php?status=$status_filter&category=$category_filter&priority=$priority_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle AJAX get request details
if (isset($_GET['ajax_get_request']) && isset($_GET['request_id'])) {
    header('Content-Type: application/json');
    $request_id = intval($_GET['request_id']);
    $query = "SELECT h.*, 
              a.full_name as assigned_to_name,
              r.full_name as responded_by_name
              FROM help_requests h
              LEFT JOIN users a ON h.assigned_to = a.id
              LEFT JOIN users r ON h.responded_by = r.id
              WHERE h.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $request]);
    exit();
}

// Handle export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="help_requests_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Subject', 'Category', 'Priority', 'Status', 'Created Date']);
    
    $export_query = "SELECT id, full_name, email, phone, subject, category, priority, status, created_at 
                     FROM help_requests 
                     ORDER BY created_at DESC";
    $export_result = mysqli_query($conn, $export_query);
    
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, [
            $row['id'],
            $row['full_name'],
            $row['email'],
            $row['phone'],
            $row['subject'],
            $row['category'],
            $row['priority'],
            $row['status'],
            $row['created_at']
        ]);
    }
    fclose($output);
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        transition: all 0.2s;
    }
    .stat-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transform: translateY(-2px);
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 0.75rem;
    }
    .stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e2a1e;
    }
    .stat-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #6d7b6c;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    
    /* Priority Badges */
    .priority-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .priority-badge.urgent { background: #fee2e2; color: #991b1b; }
    .priority-badge.high { background: #fed7aa; color: #9a3412; }
    .priority-badge.medium { background: #fef3c7; color: #92400e; }
    .priority-badge.low { background: #d1fae5; color: #065f46; }
    
    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.pending { background: #fef3c7; color: #92400e; }
    .status-badge.in_progress { background: #cffafe; color: #0891b2; }
    .status-badge.resolved { background: #d1fae5; color: #065f46; }
    .status-badge.closed { background: #e8f0e4; color: #3d4a3d; }
    
    /* Category Badges */
    .category-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .category-badge.registration { background: #e0e7ff; color: #4338ca; }
    .category-badge.claim { background: #d1fae5; color: #065f46; }
    .category-badge.valuation { background: #fed7aa; color: #9a3412; }
    .category-badge.payment { background: #fef3c7; color: #92400e; }
    .category-badge.technical { background: #cffafe; color: #0891b2; }
    .category-badge.other { background: #e8f0e4; color: #3d4a3d; }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .help-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }
    .help-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .help-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .help-table tr:hover {
        background-color: #f4fcef;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1.5rem;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-select, .filter-input {
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
        width: 100%;
    }
    .btn-filter, .btn-export {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    .btn-filter {
        background-color: #006e2c;
        color: white;
        border: none;
    }
    .btn-filter:hover {
        background-color: #005a24;
    }
    .btn-export {
        background-color: white;
        color: #3d4a3d;
        border: 1px solid #bccab9;
    }
    .btn-export:hover {
        background-color: #eef6ea;
        border-color: #006e2c;
    }
    .button-group {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        backdrop-filter: blur(4px);
    }
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    .modal-container {
        background: white;
        border-radius: 1.5rem;
        width: 95%;
        max-width: 750px;
        max-height: 90vh;
        overflow-y: auto;
        transform: scale(0.95);
        transition: transform 0.3s ease;
    }
    .modal-overlay.show .modal-container {
        transform: scale(1);
    }
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f4fcef;
        position: sticky;
        top: 0;
    }
    .modal-body {
        padding: 1.5rem;
    }
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e8f0e4;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        background: white;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    .form-label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.25rem;
    }
    .form-control, .form-select, .form-textarea {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
    }
    .form-control:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    
    .info-row {
        display: flex;
        padding: 0.75rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-label {
        width: 30%;
        font-weight: 600;
        color: #3d4a3d;
    }
    .info-value {
        width: 70%;
        color: #1e2a1e;
    }
    
    .pagination {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
        align-items: center;
    }
    .pagination-btn {
        padding: 0.5rem 0.875rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        text-decoration: none;
        color: #3d4a3d;
        background: white;
    }
    .pagination-btn.active {
        background-color: #006e2c;
        color: white;
        border-color: #006e2c;
    }
    .pagination-btn:hover:not(.active) {
        background-color: #eef6ea;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
        .stat-value {
            font-size: 1.25rem;
        }
    }
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Maombi ya Msaada</h2>
            <p class="text-secondary text-sm mt-1">Simamia na kushughulikia maombi ya msaada kutoka kwa wadai na watumiaji</p>
        </div>
        <div class="button-group">
            <button onclick="exportRequests()" class="btn-export">
                <span class="material-symbols-outlined text-sm">download</span> Export CSV
            </button>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">support_agent</span>
            </div>
            <div class="stat-value"><?php echo array_sum($status_counts); ?></div>
            <div class="stat-label">Jumla ya Maombi</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo $status_counts['pending'] ?? 0; ?></div>
            <div class="stat-label">Yanayosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #cffafe; color: #0891b2;">
                <span class="material-symbols-outlined">progress_activity</span>
            </div>
            <div class="stat-value"><?php echo $status_counts['in_progress'] ?? 0; ?></div>
            <div class="stat-label">Yanachakatwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">check_circle</span>
            </div>
            <div class="stat-value"><?php echo ($status_counts['resolved'] ?? 0) + ($status_counts['closed'] ?? 0); ?></div>
            <div class="stat-label">Yametatuliwa</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Hali</label>
                <select name="status" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Yanayosubiri</option>
                    <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>Yanachakatwa</option>
                    <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Yametatuliwa</option>
                    <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Yamefungwa</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Aina</label>
                <select name="category" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <option value="registration" <?php echo $category_filter == 'registration' ? 'selected' : ''; ?>>Usajili</option>
                    <option value="claim" <?php echo $category_filter == 'claim' ? 'selected' : ''; ?>>Madai</option>
                    <option value="valuation" <?php echo $category_filter == 'valuation' ? 'selected' : ''; ?>>Tathmini</option>
                    <option value="payment" <?php echo $category_filter == 'payment' ? 'selected' : ''; ?>>Malipo</option>
                    <option value="technical" <?php echo $category_filter == 'technical' ? 'selected' : ''; ?>>Kiteknolojia</option>
                    <option value="other" <?php echo $category_filter == 'other' ? 'selected' : ''; ?>>Nyingine</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Kipaumbele</label>
                <select name="priority" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <option value="urgent" <?php echo $priority_filter == 'urgent' ? 'selected' : ''; ?>>Dharura</option>
                    <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>Juu</option>
                    <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Kati</option>
                    <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Chini</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Jina, barua pepe, au mada..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="button-group">
                <button type="submit" class="btn-filter">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
                <a href="help-requests.php" class="btn-export">
                    <span class="material-symbols-outlined text-sm">refresh</span> Reset
                </a>
            </div>
        </form>
    </div>
    
    <!-- Help Requests Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <form id="bulk_form" method="POST">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="help-table">
                    <thead>
                        <tr>
                            <th class="w-10"><input type="checkbox" id="select_all" class="checkbox-select"></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mwombaji</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'subject', 'order' => $sort_by == 'subject' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mada</a></th>
                            <th>Aina</th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'priority', 'order' => $sort_by == 'priority' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Kipaumbele</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'status', 'order' => $sort_by == 'status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Hali</a></th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-12 text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2 block">support_agent</span>
                                Hakuna maombi ya msaada yanayoendana na vigezo vyako
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                        <tr id="row-<?php echo $request['id']; ?>">
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $request['id']; ?>" class="checkbox-select request-checkbox"></td>
                            <td class="whitespace-nowrap text-sm"><?php echo date('d/m/Y H:i', strtotime($request['created_at'])); ?></td>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                <div class="text-xs text-secondary"><?php echo htmlspecialchars($request['email']); ?></div>
                                <div class="text-xs text-secondary"><?php echo htmlspecialchars($request['phone'] ?? '-'); ?></div>
                            </td>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($request['subject']); ?></div>
                                <div class="text-xs text-secondary line-clamp-2"><?php echo htmlspecialchars(substr($request['message'], 0, 60)); ?>...</div>
                            </td>
                            <td><span class="category-badge <?php echo $request['category']; ?>"><?php echo ucfirst(str_replace('_', ' ', $request['category'])); ?></span></td>
                            <td><span class="priority-badge <?php echo $request['priority']; ?>"><?php echo ucfirst($request['priority']); ?></span></td>
                            <td><span class="status-badge <?php echo $request['status']; ?>"><?php echo str_replace('_', ' ', ucfirst($request['status'])); ?></span></td>
                            <td class="text-center">
                                <button type="button" class="action-btn" onclick="viewRequest(<?php echo $request['id']; ?>)" title="Angalia na Shirikisha">
                                    <span class="material-symbols-outlined">visibility</span>
                                </button>
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
        <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-outline-variant bg-surface-container-low gap-3">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_requests); ?> kati ya <?php echo $total_requests; ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">« Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">Inayofuata »</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- View/Edit Request Modal -->
<div id="requestModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">support_agent</span>
                <h3 class="text-lg font-semibold">Maelezo ya Ombi la Msaada</h3>
            </div>
            <button type="button" id="closeModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <form id="updateRequestForm" method="POST" action="">
            <input type="hidden" name="update_request" value="1">
            <input type="hidden" id="request_id" name="request_id">
            <div class="modal-body">
                <!-- Request Details -->
                <div class="bg-surface-container-low p-3 rounded-lg mb-4">
                    <div class="info-row">
                        <div class="info-label">Mwombaji:</div>
                        <div class="info-value" id="view_full_name">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Barua Pepe:</div>
                        <div class="info-value" id="view_email">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Namba ya Simu:</div>
                        <div class="info-value" id="view_phone">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Mada:</div>
                        <div class="info-value font-semibold" id="view_subject">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Ujumbe:</div>
                        <div class="info-value" id="view_message">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tarehe ya Kutuma:</div>
                        <div class="info-value" id="view_created_at">-</div>
                    </div>
                </div>
                
                <!-- Admin Response Section -->
                <div class="border-t pt-4 mt-2">
                    <h4 class="font-semibold mb-3">Majibu na Hatua</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Hali</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="pending">Yanayosubiri</option>
                                <option value="in_progress">Yanachakatwa</option>
                                <option value="resolved">Yametatuliwa</option>
                                <option value="closed">Yamefungwa</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kipaumbele</label>
                            <select name="priority" id="edit_priority" class="form-select">
                                <option value="low">Chini</option>
                                <option value="medium">Kati</option>
                                <option value="high">Juu</option>
                                <option value="urgent">Dharura</option>
                            </select>
                        </div>
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Mkabidhi (Assign to)</label>
                            <select name="assigned_to" id="edit_assigned_to" class="form-select">
                                <option value="">-- Hakuna --</option>
                                <?php foreach ($staff_users as $staff): ?>
                                    <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['full_name']); ?> (<?php echo str_replace('_', ' ', $staff['role']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Jibu / Response</label>
                            <textarea name="response" id="edit_response" rows="4" class="form-textarea" placeholder="Weka jibu lako hapa..."></textarea>
                        </div>
                    </div>
                    
                    <?php if (!empty($request['response'])): ?>
                    <div class="bg-green-50 p-3 rounded-lg mt-3">
                        <div class="text-xs text-green-800 mb-1">Jibu lililotumwa:</div>
                        <div class="text-sm" id="view_response">-</div>
                        <div class="text-xs text-green-600 mt-1" id="view_responded_at">-</div>
                        <div class="text-xs text-green-600" id="view_responded_by">-</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="cancelModalBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Hifadhi Mabadiliko</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Select all checkbox
    const selectAll = document.getElementById('select_all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.request-checkbox').forEach(cb => cb.checked = selectAll.checked);
        });
    }
    
    // View request details
    async function viewRequest(requestId) {
        const modal = document.getElementById('requestModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_request=1&request_id=${requestId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const request = data.data;
                document.getElementById('request_id').value = request.id;
                document.getElementById('view_full_name').innerHTML = request.full_name;
                document.getElementById('view_email').innerHTML = request.email;
                document.getElementById('view_phone').innerHTML = request.phone || '-';
                document.getElementById('view_subject').innerHTML = request.subject;
                document.getElementById('view_message').innerHTML = request.message;
                document.getElementById('view_created_at').innerHTML = new Date(request.created_at).toLocaleString('sw-TZ');
                
                document.getElementById('edit_status').value = request.status;
                document.getElementById('edit_priority').value = request.priority;
                document.getElementById('edit_assigned_to').value = request.assigned_to || '';
                document.getElementById('edit_response').value = request.response || '';
                
                if (request.response) {
                    document.getElementById('view_response').innerHTML = request.response;
                    document.getElementById('view_responded_at').innerHTML = request.responded_at ? new Date(request.responded_at).toLocaleString('sw-TZ') : '';
                    document.getElementById('view_responded_by').innerHTML = request.responded_by_name ? `Imesajiliwa na: ${request.responded_by_name}` : '';
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa' });
                closeModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao' });
            closeModal();
        }
    }
    
    function closeModal() {
        const modal = document.getElementById('requestModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function exportRequests() {
        Swal.fire({
            title: 'Export Maombi',
            text: 'Je, unataka kupakua ripoti ya maombi ya msaada?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?export=1';
            }
        });
    }
    
    // Search with debounce
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = searchInput.closest('form');
                if (form) form.submit();
            }, 500);
        });
    }
    
    // Event listeners
    document.getElementById('closeModalBtn')?.addEventListener('click', closeModal);
    document.getElementById('cancelModalBtn')?.addEventListener('click', closeModal);
    document.getElementById('requestModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>