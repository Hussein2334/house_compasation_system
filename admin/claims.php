<?php
// admin/claims.php - Fixed for your actual database structure with valuations
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

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'valuer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Claims';
$page_heading = 'Usimamizi wa Madai';

// Get database connection
$conn = getDB();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total claims count
$count_query = "SELECT COUNT(*) as total FROM claims c JOIN users u ON c.claimant_id = u.id $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_claims = mysqli_fetch_assoc($count_result)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_claims / $per_page);

// Get claims data with valuation information
$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, 
          v.id as valuation_id, v.property_value, v.disturbance_allowance, v.transport_allowance, v.total_compensation, v.valuation_report,
          CONCAT('TZS ', FORMAT(COALESCE(v.property_value, 0), 0)) as property_value_formatted,
          CONCAT('TZS ', FORMAT(COALESCE(v.total_compensation, 0), 0)) as total_compensation_formatted
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
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

$claims = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claims[] = $row;
}

// Get status counts
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

// Project types
$project_types = [
    'SGR Railway Project', 'Road Expansion', 'Dar Port Expansion', 'Mwanza Port', 
    'Kigoma Port', 'Tanga Port', 'Dodoma Ring Road', 'Arusha Tourism', 
    'Zanzibar Development', 'Mbeya Coal Mines', 'Iringa Agriculture', 'Other'
];

// Property types
$property_types = ['residential', 'commercial', 'agricultural', 'industrial', 'other'];

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $update_query = "UPDATE claims SET status = ? WHERE id IN ($placeholders)";
        $update_params = array_merge([$action], $selected_ids);
        $update_stmt = mysqli_prepare($conn, $update_query);
        $update_types = "s" . str_repeat("i", count($selected_ids));
        mysqli_stmt_bind_param($update_stmt, $update_types, ...$update_params);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $affected = mysqli_stmt_affected_rows($update_stmt);
            $_SESSION['success_message'] = "Madai $affected yamebadilishwa hadi " . getStatusLabel($action);
            logAudit($conn, $_SESSION['user_id'], 'BULK_UPDATE_CLAIMS', 'claims', null, null, [
                'action' => $action, 'count' => $affected, 'ids' => $selected_ids
            ]);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kubadilisha madai.";
        }
    }
    header("Location: claims.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle AJAX get claim data
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $claim_id = intval($_GET['claim_id']);
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone,
              v.id as valuation_id, v.property_value, v.disturbance_allowance, v.transport_allowance, v.total_compensation, v.valuation_report
              FROM claims c 
              JOIN users u ON c.claimant_id = u.id 
              LEFT JOIN valuations v ON c.id = v.claim_id
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claim = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $claim]);
    exit();
}

// Handle AJAX update claim with valuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_claim'])) {
    header('Content-Type: application/json');
    
    $claim_id = intval($_POST['claim_id']);
    $project_name = trim($_POST['project_name'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $property_type = trim($_POST['property_type'] ?? '');
    $property_size = trim($_POST['property_size'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Valuation fields
    $property_value = !empty($_POST['property_value']) ? floatval($_POST['property_value']) : 0;
    $disturbance_allowance = !empty($_POST['disturbance_allowance']) ? floatval($_POST['disturbance_allowance']) : 0;
    $transport_allowance = !empty($_POST['transport_allowance']) ? floatval($_POST['transport_allowance']) : 0;
    $valuation_report = trim($_POST['valuation_report'] ?? '');
    $total_compensation = $property_value + $disturbance_allowance + $transport_allowance;
    $valuer_id = $_SESSION['user_id'];
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update claim basic info
        $update_query = "UPDATE claims SET 
                         project_name = ?, 
                         district = ?, 
                         property_type = ?,
                         property_size = ?,
                         description = ?
                         WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "sssssi", 
            $project_name, $district, $property_type, $property_size, $description, $claim_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Hitilafu katika kuhariri taarifa za dai");
        }
        
        // Check if valuation exists
        $check_query = "SELECT id FROM valuations WHERE claim_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            // Update existing valuation
            $update_val_query = "UPDATE valuations SET 
                                 property_value = ?, 
                                 disturbance_allowance = ?, 
                                 transport_allowance = ?, 
                                 total_compensation = ?, 
                                 valuation_report = ?,
                                 valuer_id = ?
                                 WHERE claim_id = ?";
            $update_val_stmt = mysqli_prepare($conn, $update_val_query);
            mysqli_stmt_bind_param($update_val_stmt, "ddddssi", 
                $property_value, $disturbance_allowance, $transport_allowance, 
                $total_compensation, $valuation_report, $valuer_id, $claim_id);
            
            if (!mysqli_stmt_execute($update_val_stmt)) {
                throw new Exception("Hitilafu katika kusasisha tathmini");
            }
        } else {
            // Insert new valuation
            $insert_val_query = "INSERT INTO valuations (claim_id, valuer_id, property_value, 
                                 disturbance_allowance, transport_allowance, total_compensation, valuation_report)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_val_stmt = mysqli_prepare($conn, $insert_val_query);
            mysqli_stmt_bind_param($insert_val_stmt, "iidddds", 
                $claim_id, $valuer_id, $property_value, 
                $disturbance_allowance, $transport_allowance, $total_compensation, $valuation_report);
            
            if (!mysqli_stmt_execute($insert_val_stmt)) {
                throw new Exception("Hitilafu katika kuongeza tathmini");
            }
        }
        
        // Update claim status to legal_review if valuation was added/updated
        if ($property_value > 0 || $disturbance_allowance > 0 || $transport_allowance > 0) {
            $status_update = "UPDATE claims SET status = 'legal_review', updated_at = NOW() WHERE id = ? AND status = 'valuation'";
            $status_stmt = mysqli_prepare($conn, $status_update);
            mysqli_stmt_bind_param($status_stmt, "i", $claim_id);
            mysqli_stmt_execute($status_stmt);
        }
        
        mysqli_commit($conn);
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_CLAIM_WITH_VALUATION', 'claims', $claim_id);
        echo json_encode(['success' => true, 'message' => 'Dai na tathmini zimehifadhiwa kikamilifu']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX status update
if (isset($_GET['ajax_update']) && isset($_GET['claim_id']) && isset($_GET['new_status'])) {
    header('Content-Type: application/json');
    $new_status = $_GET['new_status'];
    $claim_id = intval($_GET['claim_id']);
    
    $old_status_query = "SELECT status FROM claims WHERE id = ?";
    $old_stmt = mysqli_prepare($conn, $old_status_query);
    mysqli_stmt_bind_param($old_stmt, "i", $claim_id);
    mysqli_stmt_execute($old_stmt);
    $old_result = mysqli_stmt_get_result($old_stmt);
    $old_data = mysqli_fetch_assoc($old_result);
    
    $update_stmt = mysqli_prepare($conn, "UPDATE claims SET status = ? WHERE id = ?");
    mysqli_stmt_bind_param($update_stmt, "si", $new_status, $claim_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                ['status' => $old_data['status']], ['status' => $new_status]);
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
    exit();
}

// Handle AJAX delete
if (isset($_GET['ajax_delete']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $claim_id = intval($_GET['claim_id']);
    $check_payment = mysqli_query($conn, "SELECT id FROM payments WHERE claim_id = $claim_id LIMIT 1");
    if (mysqli_num_rows($check_payment) > 0) {
        echo json_encode(['success' => false, 'message' => 'Huwezi kufuta dai lenye malipo']);
    } else {
        $delete_stmt = mysqli_prepare($conn, "DELETE FROM claims WHERE id = ?");
        mysqli_stmt_bind_param($delete_stmt, "i", $claim_id);
        if (mysqli_stmt_execute($delete_stmt)) {
            logAudit($conn, $_SESSION['user_id'], 'DELETE_CLAIM', 'claims', $claim_id);
            echo json_encode(['success' => true, 'message' => 'Dai limefutwa kikamilifu']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hitilafu katika kufuta dai']);
        }
    }
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Status Badge */
    .status-badge { display: inline-flex; align-items: center; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; gap: 0.25rem; }
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    /* Table */
    .claims-table { width: 100%; border-collapse: collapse; }
    .claims-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .claims-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; }
    
    /* Action Button */
    .action-btn { background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 0.5rem; color: #6d7b6c; transition: all 0.2s; }
    .action-btn:hover { background-color: #e8f0e4; color: #006e2c; }
    
    /* Filter Tabs */
    .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; transition: all 0.2s ease; }
    .filter-tab.active { background-color: #006e2c; color: white; }
    .filter-tab:not(.active):hover { background-color: #e8f0e4; }
    
    .checkbox-select { width: 1rem; height: 1rem; accent-color: #006e2c; cursor: pointer; }
    .pagination-btn { padding: 0.375rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.75rem; }
    .pagination-btn.active { background-color: #006e2c; color: white; border-color: #006e2c; }
    
    /* Amount styling */
    .amount-value { font-family: monospace; font-size: 0.8rem; font-weight: 600; }
    .amount-positive { color: #006e2c; }
    .amount-zero { color: #9ca3af; }
    
    /* Action Modal */
    .action-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.2s ease; }
    .action-modal-overlay.show { opacity: 1; visibility: visible; }
    .action-modal { background: white; border-radius: 1rem; width: 340px; max-width: 90%; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.2s ease; }
    .action-modal-overlay.show .action-modal { transform: scale(1); }
    .action-modal-header { padding: 1rem; background: #f4fcef; border-bottom: 1px solid #bccab9; font-weight: 600; }
    .action-modal-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid #e8f0e4; width: 100%; background: none; border: none; text-align: left; }
    .action-modal-item:hover { background-color: #eef6ea; }
    .action-modal-item.delete { color: #dc2626; }
    .action-modal-item.delete:hover { background-color: #fee2e2; }
    .action-modal-divider { height: 1px; background: #bccab9; margin: 0.25rem 0; }
    .status-options-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; padding: 0.75rem; }
    .status-opt { padding: 0.5rem; border-radius: 0.5rem; cursor: pointer; text-align: center; font-size: 0.75rem; transition: all 0.15s; background: none; border: none; }
    .status-opt:hover { background-color: #eef6ea; }
    
    /* Edit Modal */
    .edit-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .edit-modal-overlay.show { opacity: 1; visibility: visible; }
    .edit-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 750px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .edit-modal-overlay.show .edit-modal-container { transform: scale(1); }
    .edit-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .edit-modal-body { padding: 1.5rem; }
    .edit-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.25rem; }
    .form-input, .form-select, .form-textarea { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    .form-input[readonly] { background: #f4fcef; }
    
    /* Valuation Section Styling */
    .valuation-section { background: #f4fcef; border-radius: 0.75rem; padding: 1.25rem; margin-top: 1.5rem; border: 1px solid #d1e0c8; }
    .valuation-section h4 { font-size: 0.85rem; font-weight: 700; margin-bottom: 1rem; color: #006e2c; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid #d1e0c8; padding-bottom: 0.5rem; }
    .valuation-row { display: flex; justify-content: space-between; padding: 0.5rem 0; }
    .valuation-row.total { border-top: 2px solid #bccab9; margin-top: 0.5rem; padding-top: 0.75rem; }
    .valuation-label { font-size: 0.75rem; color: #3d4a3d; font-weight: 500; }
    .valuation-value { font-weight: 700; font-family: monospace; font-size: 0.9rem; }
    .valuation-value.total { color: #006e2c; font-size: 1.1rem; }
    .input-group { margin-bottom: 0.75rem; }
    .input-group label { font-size: 0.7rem; font-weight: 600; color: #3d4a3d; display: block; margin-bottom: 0.25rem; }
    .input-group input, .input-group textarea { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .input-group input:focus, .input-group textarea:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    
    @media (max-width: 640px) {
        .grid-2 { grid-template-columns: 1fr; gap: 0.75rem; }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Usimamizi wa Madai</h2>
            <p class="text-secondary text-sm mt-1">Simamia, kagua na usindikie madai yote ya fidia</p>
        </div>
        <div class="flex gap-3">
            <a href="new-claim.php" class="px-4 py-2 bg-primary text-white rounded-lg flex items-center gap-2 hover:bg-primary-container transition shadow-sm">
                <span class="material-symbols-outlined text-sm">add</span> Dai Jipya
            </a>
            <button onclick="exportClaims()" class="px-4 py-2 border border-outline-variant rounded-lg flex items-center gap-2 hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">download</span> Export
            </button>
        </div>
    </div>
    
    <!-- Status Filters -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : 'text-secondary'; ?>">Zote (<?php echo array_sum($status_counts); ?>)</a>
        <a href="?status=submitted&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'submitted' ? 'active' : 'text-secondary'; ?>">Imewasilishwa (<?php echo $status_counts['submitted'] ?? 0; ?>)</a>
        <a href="?status=valuation&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'valuation' ? 'active' : 'text-secondary'; ?>">Tathmini (<?php echo $status_counts['valuation'] ?? 0; ?>)</a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'legal_review' ? 'active' : 'text-secondary'; ?>">Uhakiki (<?php echo $status_counts['legal_review'] ?? 0; ?>)</a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'approved' ? 'active' : 'text-secondary'; ?>">Imeidhinishwa (<?php echo $status_counts['approved'] ?? 0; ?>)</a>
        <a href="?status=rejected&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'rejected' ? 'active' : 'text-secondary'; ?>">Imekataliwa (<?php echo $status_counts['rejected'] ?? 0; ?>)</a>
        <a href="?status=paid&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'paid' ? 'active' : 'text-secondary'; ?>">Imelipwa (<?php echo $status_counts['paid'] ?? 0; ?>)</a>
    </div>
    
    <!-- Search and Bulk -->
    <div class="flex flex-col md:flex-row gap-4">
        <form method="GET" action="" class="flex-1" id="searchForm">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-xl">search</span>
                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." class="w-full pl-10 pr-4 py-2.5 border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
            </div>
        </form>
        <div class="flex gap-2">
            <select id="bulk_action_select" class="px-3 py-2.5 border border-outline rounded-lg bg-white text-sm">
                <option value="">Bulk Action</option>
                <option value="submitted">Weka Imewasilishwa</option>
                <option value="valuation">Weka Tathmini</option>
                <option value="legal_review">Weka Uhakiki</option>
                <option value="approved">Weka Imeidhinishwa</option>
                <option value="rejected">Weka Imekataliwa</option>
                <option value="paid">Weka Imelipwa</option>
            </select>
            <button onclick="applyBulkAction()" class="px-4 py-2.5 bg-primary text-white rounded-lg hover:bg-primary-container transition">Tumia</button>
        </div>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <form id="bulk_form" method="POST">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="claims-table">
                    <thead>
                        <tr>
                            <th class="w-10"><input type="checkbox" id="select_all" class="checkbox-select"></th>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Mradi</th>
                            <th>Aina ya Mali</th>
                            <th>Thamani ya Mali</th>
                            <th>Fidia</th>
                            <th>Hali</th>
                            <th>Tarehe</th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($claims)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-12 text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2 block">inbox</span>
                                Hakuna madai
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($claims as $claim): ?>
                        <tr id="row-<?php echo $claim['id']; ?>">
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $claim['id']; ?>" class="checkbox-select claim-checkbox"></td>
                            <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                                <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($claim['property_type'] ?? '-'); ?></td>
                            <td class="amount-value <?php echo ($claim['property_value'] ?? 0) > 0 ? 'amount-positive' : 'amount-zero'; ?>">
                                <?php 
                                $property_value = $claim['property_value'] ?? 0;
                                if ($property_value > 0) {
                                    echo 'TZS ' . number_format($property_value, 0, '.', ',');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="amount-value <?php echo ($claim['total_compensation'] ?? 0) > 0 ? 'amount-positive' : 'amount-zero'; ?> font-bold">
                                <?php 
                                $total_comp = $claim['total_compensation'] ?? 0;
                                if ($total_comp > 0) {
                                    echo 'TZS ' . number_format($total_comp, 0, '.', ',');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $claim['status']; ?>">
                                    <span class="material-symbols-outlined">
                                        <?php 
                                        $icons = [
                                            'submitted' => 'pending',
                                            'valuation' => 'real_estate_agent',
                                            'legal_review' => 'gavel',
                                            'approved' => 'verified',
                                            'rejected' => 'cancel',
                                            'paid' => 'payments'
                                        ]; 
                                        echo $icons[$claim['status']] ?? 'info'; 
                                        ?>
                                    </span>
                                    <?php echo getStatusLabel($claim['status']); ?>
                                </span>
                            </td>
                            <td class="text-sm text-secondary"><?php echo formatDate($claim['created_at'], 'd M Y'); ?></td>
                            <td class="text-center">
                                <button type="button" class="action-btn" onclick="showActionModal(<?php echo $claim['id']; ?>, '<?php echo addslashes($claim['claimant_name']); ?>', '<?php echo $claim['claim_number']; ?>')">
                                    <span class="material-symbols-outlined">more_vert</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-outline-variant bg-surface-container-low">
            <div class="text-sm text-secondary">Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?></div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?><a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Awali</a><?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?><a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Inayofuata</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Modal -->
<div id="actionModal" class="action-modal-overlay">
    <div class="action-modal">
        <div class="action-modal-header" id="actionModalHeader">Kitendo cha Dai</div>
        <div class="action-modal-body">
            <button type="button" class="action-modal-item" id="viewClaimBtn"><span class="material-symbols-outlined">visibility</span>Angalia Maelezo</button>
            <button type="button" class="action-modal-item" id="editClaimBtn"><span class="material-symbols-outlined">edit</span>Hariri Dai</button>
            <div class="action-modal-divider"></div>
            <div class="px-3 py-1 text-xs font-semibold text-secondary">Badilisha Hali:</div>
            <div class="status-options-grid">
                <button type="button" class="status-opt text-yellow-700 hover:bg-yellow-50" data-status="submitted">📝 Imewasilishwa</button>
                <button type="button" class="status-opt text-orange-700 hover:bg-orange-50" data-status="valuation">📊 Tathmini</button>
                <button type="button" class="status-opt text-purple-700 hover:bg-purple-50" data-status="legal_review">⚖️ Uhakiki</button>
                <button type="button" class="status-opt text-green-700 hover:bg-green-50" data-status="approved">✅ Idhinisha</button>
                <button type="button" class="status-opt text-red-700 hover:bg-red-50" data-status="rejected">❌ Kataa</button>
                <button type="button" class="status-opt text-emerald-700 hover:bg-emerald-50" data-status="paid">💰 Lipa</button>
            </div>
            <div class="action-modal-divider"></div>
            <button type="button" class="action-modal-item delete" id="deleteClaimBtn"><span class="material-symbols-outlined">delete</span>Futa Dai</button>
        </div>
    </div>
</div>

<!-- Edit Claim Modal - With Valuation Entry Fields -->
<div id="editModal" class="edit-modal-overlay">
    <div class="edit-modal-container">
        <div class="edit-modal-header">
            <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary text-2xl">edit</span><h3 class="text-lg font-semibold">Hariri Dai na Tathmini</h3></div>
            <button type="button" id="closeEditModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg"><span class="material-symbols-outlined text-secondary">close</span></button>
        </div>
        <form id="editClaimForm" onsubmit="return false;">
            <input type="hidden" id="edit_claim_id" name="claim_id">
            <div class="edit-modal-body">
                <!-- Claim Information Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group"><label class="form-label">Namba ya Dai</label><input type="text" id="edit_claim_number" class="form-input" readonly></div>
                    <div class="form-group"><label class="form-label">Mwombaji</label><input type="text" id="edit_claimant_name" class="form-input" readonly></div>
                    <div class="form-group"><label class="form-label">Barua Pepe</label><input type="email" id="edit_email" class="form-input" readonly></div>
                    <div class="form-group"><label class="form-label">Namba ya Simu</label><input type="text" id="edit_phone" class="form-input" readonly></div>
                    <div class="form-group md:col-span-2"><label class="form-label">Jina la Mradi <span class="text-red-500">*</span></label><select id="edit_project_name" class="form-select" required><option value="">-- Chagua Mradi --</option><?php foreach ($project_types as $project): ?><option value="<?php echo htmlspecialchars($project); ?>"><?php echo htmlspecialchars($project); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Wilaya / District</label><input type="text" id="edit_district" class="form-input" placeholder="Mfano: Morogoro"></div>
                    <div class="form-group"><label class="form-label">Aina ya Mali</label><select id="edit_property_type" class="form-select"><?php foreach ($property_types as $type): ?><option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Ukubwa (sqm)</label><input type="text" id="edit_property_size" class="form-input" placeholder="Mfano: 500"></div>
                    <div class="form-group md:col-span-2"><label class="form-label">Maelezo / Description</label><textarea id="edit_description" rows="2" class="form-textarea" placeholder="Maelezo ya dai..."></textarea></div>
                </div>
                
                <!-- Valuation Section - Editable Fields -->
                <div class="valuation-section">
                    <h4>
                        <span class="material-symbols-outlined" style="font-size: 1.2rem;">real_estate_agent</span>
                        Taarifa za Tathmini ya Mali
                    </h4>
                    <div class="grid-2">
                        <div class="input-group">
                            <label>Thamani ya Mali (TZS)</label>
                            <input type="number" id="edit_property_value" name="property_value" class="form-input" step="1000" value="0" oninput="calculateTotal()">
                            <small class="text-secondary text-xs">Thamani ya jumla ya ardhi na majengo</small>
                        </div>
                        <div class="input-group">
                            <label>Posho ya Usumbufu (TZS)</label>
                            <input type="number" id="edit_disturbance_allowance" name="disturbance_allowance" class="form-input" step="1000" value="0" oninput="calculateTotal()">
                            <small class="text-secondary text-xs">Kwa usumbufu wa makazi/biashara</small>
                        </div>
                        <div class="input-group">
                            <label>Posho ya Usafiri (TZS)</label>
                            <input type="number" id="edit_transport_allowance" name="transport_allowance" class="form-input" step="1000" value="0" oninput="calculateTotal()">
                            <small class="text-secondary text-xs">Gharama za kubebea mali</small>
                        </div>
                        <div class="input-group">
                            <label>Jumla ya Fidia (TZS)</label>
                            <input type="number" id="edit_total_compensation" class="form-input" readonly style="background: #eef6ea; font-weight: bold; color: #006e2c;">
                        </div>
                    </div>
                    <div class="input-group" style="margin-top: 0.75rem;">
                        <label>Ripoti / Maelezo ya Tathmini</label>
                        <textarea id="edit_valuation_report" name="valuation_report" rows="3" class="form-textarea" placeholder="Weka maelezo ya mbinu ya tathmini, vigezo vilivyotumika, na taarifa nyingine muhimu..."></textarea>
                    </div>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button type="button" id="cancelEditBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" id="saveEditBtn" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Hifadhi Mabadiliko</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentClaimId = null;
    
    // Calculate total compensation
    function calculateTotal() {
        let propertyValue = parseFloat(document.getElementById('edit_property_value').value) || 0;
        let disturbanceAllowance = parseFloat(document.getElementById('edit_disturbance_allowance').value) || 0;
        let transportAllowance = parseFloat(document.getElementById('edit_transport_allowance').value) || 0;
        let total = propertyValue + disturbanceAllowance + transportAllowance;
        
        document.getElementById('edit_total_compensation').value = total;
    }
    
    // ========== ACTION MODAL FUNCTIONS ==========
    function showActionModal(claimId, claimantName, claimNumber) {
        currentClaimId = claimId;
        const modal = document.getElementById('actionModal');
        const header = document.getElementById('actionModalHeader');
        header.innerHTML = `Kitendo cha Dai: ${claimNumber} - ${claimantName}`;
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function closeActionModal() {
        const modal = document.getElementById('actionModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // ========== EDIT MODAL FUNCTIONS ==========
    async function openEditModal() {
        closeActionModal();
        
        const modal = document.getElementById('editModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${currentClaimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const claim = data.data;
                document.getElementById('edit_claim_id').value = claim.id;
                document.getElementById('edit_claim_number').value = claim.claim_number;
                document.getElementById('edit_claimant_name').value = claim.claimant_name;
                document.getElementById('edit_email').value = claim.email;
                document.getElementById('edit_phone').value = claim.phone || '-';
                document.getElementById('edit_project_name').value = claim.project_name || '';
                document.getElementById('edit_district').value = claim.district || '';
                document.getElementById('edit_property_type').value = claim.property_type || 'residential';
                document.getElementById('edit_property_size').value = claim.property_size || '';
                document.getElementById('edit_description').value = claim.description || '';
                
                // Load valuation data
                document.getElementById('edit_property_value').value = claim.property_value || 0;
                document.getElementById('edit_disturbance_allowance').value = claim.disturbance_allowance || 0;
                document.getElementById('edit_transport_allowance').value = claim.transport_allowance || 0;
                document.getElementById('edit_valuation_report').value = claim.valuation_report || '';
                calculateTotal();
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
    
    async function submitEditClaim() {
        const formData = new URLSearchParams({
            ajax_update_claim: 1,
            claim_id: document.getElementById('edit_claim_id').value,
            project_name: document.getElementById('edit_project_name').value,
            district: document.getElementById('edit_district').value,
            property_type: document.getElementById('edit_property_type').value,
            property_size: document.getElementById('edit_property_size').value,
            description: document.getElementById('edit_description').value,
            property_value: document.getElementById('edit_property_value').value,
            disturbance_allowance: document.getElementById('edit_disturbance_allowance').value,
            transport_allowance: document.getElementById('edit_transport_allowance').value,
            valuation_report: document.getElementById('edit_valuation_report').value
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
    
    // ========== STATUS UPDATE ==========
    async function updateClaimStatus(newStatus) {
        closeActionModal();
        const labels = { 'submitted':'Imewasilishwa','valuation':'Tathmini','legal_review':'Uhakiki','approved':'Imeidhinishwa','rejected':'Imekataliwa','paid':'Imelipwa' };
        const result = await Swal.fire({ title: 'Thibitisha', text: `Badilisha hali kuwa "${labels[newStatus]}"?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#006e2c', cancelButtonColor: '#ba1a1a', confirmButtonText: 'Ndiyo', cancelButtonText: 'Hapana' });
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inabadilisha...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            try {
                const response = await fetch(`?ajax_update=1&claim_id=${currentClaimId}&new_status=${newStatus}`);
                const data = await response.json();
                if (data.success) { Swal.fire({ icon: 'success', title: 'Imefanikiwa!', confirmButtonColor: '#006e2c', timer: 1500 }).then(() => window.location.reload()); }
                else { Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' }); }
            } catch (error) { Swal.close(); Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' }); }
        }
    }
    
    // ========== DELETE ==========
    async function deleteClaim() {
        closeActionModal();
        const result = await Swal.fire({ title: 'Futa Dai?', text: 'Hatua haiwezi kutenduliwa!', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ba1a1a', cancelButtonColor: '#006e2c', confirmButtonText: 'Ndiyo, Futa', cancelButtonText: 'Hapana' });
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inafuta...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            try {
                const response = await fetch(`?ajax_delete=1&claim_id=${currentClaimId}`);
                const data = await response.json();
                if (data.success) { Swal.fire({ icon: 'success', title: 'Imefutwa!', confirmButtonColor: '#006e2c', timer: 1500 }).then(() => window.location.reload()); }
                else { Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' }); }
            } catch (error) { Swal.close(); Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' }); }
        }
    }
    
    function viewClaim() { 
        window.location.href = `view-claim.php?id=${currentClaimId}`; 
    }
    
    // ========== BULK ACTIONS ==========
    const selectAll = document.getElementById('select_all');
    if (selectAll) { 
        selectAll.addEventListener('change', function() { 
            document.querySelectorAll('.claim-checkbox').forEach(cb => cb.checked = selectAll.checked); 
        }); 
    }
    
    function applyBulkAction() {
        const selected = document.querySelectorAll('.claim-checkbox:checked');
        const action = document.getElementById('bulk_action_select').value;
        const labels = { 'submitted':'Imewasilishwa','valuation':'Tathmini','legal_review':'Uhakiki','approved':'Imeidhinishwa','rejected':'Imekataliwa','paid':'Imelipwa' };
        if (selected.length === 0) return Swal.fire({ icon: 'warning', title: 'Hakuna Madai', text: 'Chagua angalau dai moja', confirmButtonColor: '#006e2c' });
        if (!action) return Swal.fire({ icon: 'warning', title: 'Chagua Kitendo', text: 'Chagua kitendo cha kufanya', confirmButtonColor: '#006e2c' });
        Swal.fire({ title: 'Thibitisha', html: `Badilisha madai <strong>${selected.length}</strong> hadi <strong>${labels[action]}</strong>?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#006e2c', cancelButtonColor: '#ba1a1a', confirmButtonText: 'Ndiyo', cancelButtonText: 'Hapana' }).then(res => { if (res.isConfirmed) { document.getElementById('bulk_action_value').value = action; document.getElementById('bulk_form').submit(); } });
    }
    
    function exportClaims() { 
        Swal.fire({ icon: 'info', title: 'Export', text: 'Ripoti itapakuliwa', confirmButtonColor: '#006e2c' }); 
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
    
    // ========== EVENT LISTENERS ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Action Modal Buttons
        document.getElementById('viewClaimBtn').addEventListener('click', viewClaim);
        document.getElementById('editClaimBtn').addEventListener('click', openEditModal);
        document.getElementById('deleteClaimBtn').addEventListener('click', deleteClaim);
        
        // Close Action Modal when clicking overlay
        document.getElementById('actionModal').addEventListener('click', function(e) {
            if (e.target === this) closeActionModal();
        });
        
        // Status option buttons
        document.querySelectorAll('.status-opt').forEach(btn => {
            btn.addEventListener('click', function() {
                const status = this.getAttribute('data-status');
                updateClaimStatus(status);
            });
        });
        
        // Edit Modal Buttons
        document.getElementById('closeEditModalBtn').addEventListener('click', closeEditModal);
        document.getElementById('cancelEditBtn').addEventListener('click', closeEditModal);
        document.getElementById('saveEditBtn').addEventListener('click', submitEditClaim);
        
        // Close Edit Modal when clicking overlay
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
    });
    
    <?php if (!empty($success_message)): ?>Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });<?php endif; ?>
    <?php if (!empty($error_message)): ?>Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>