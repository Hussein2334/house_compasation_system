<?php
// legal/claims.php - Manage Claims for Legal Review (FINAL FIXED VERSION)
session_start();

// Disable error display to browser, log to file instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is legal officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'legal_officer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Legal Claims Management';
$page_heading = 'Usimamizi wa Madai (Kisheria)';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Helper function for JSON responses
function sendJsonResponse($success, $message, $data = null) {
    // Clean output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    $json = json_encode($response);
    if ($json === false) {
        $json = json_encode(['success' => false, 'message' => 'JSON encoding error']);
    }
    echo $json;
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'legal_review';
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
$count_query = "SELECT COUNT(*) as total 
                FROM claims c 
                JOIN users u ON c.claimant_id = u.id 
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_claims = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_claims / $per_page);

// Get claims data with valuation information
$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin,
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          vu.full_name as valuer_name
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN users vu ON v.valuer_id = vu.id
          $where_sql
          ORDER BY $sort_by $sort_order
          LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claims = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $claims[] = $row;
    }
} else {
    $claims = [];
}

// Get status counts
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims WHERE status IN ('legal_review', 'approved', 'rejected') GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}
$status_counts['legal_review'] = $status_counts['legal_review'] ?? 0;
$status_counts['approved'] = $status_counts['approved'] ?? 0;
$status_counts['rejected'] = $status_counts['rejected'] ?? 0;
$status_counts['all'] = array_sum($status_counts);

// ==================== AJAX HANDLERS ====================

// Handle claim approval/rejection via AJAX
if (isset($_GET['ajax_update_status']) && isset($_GET['claim_id']) && isset($_GET['new_status'])) {
    $claim_id = intval($_GET['claim_id']);
    $new_status = $_GET['new_status'];
    $remarks = $_GET['remarks'] ?? '';
    
    // Get old status for audit
    $old_query = "SELECT status FROM claims WHERE id = ?";
    $old_stmt = mysqli_prepare($conn, $old_query);
    mysqli_stmt_bind_param($old_stmt, "i", $claim_id);
    mysqli_stmt_execute($old_stmt);
    $old_result = mysqli_stmt_get_result($old_stmt);
    $old_data = mysqli_fetch_assoc($old_result);
    $old_status = $old_data['status'] ?? '';
    
    if ($new_status === 'approved' || $new_status === 'rejected') {
        $update_query = "UPDATE claims SET status = ?, decision_date = NOW(), updated_at = NOW() WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "si", $new_status, $claim_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Add approval record
            $approval_query = "INSERT INTO approvals (claim_id, approved_by, approval_stage, remarks, status, created_at) 
                               VALUES (?, ?, 'legal_review', ?, ?, NOW())";
            $approval_stmt = mysqli_prepare($conn, $approval_query);
            $approval_status = ($new_status === 'approved') ? 'approved' : 'rejected';
            mysqli_stmt_bind_param($approval_stmt, "iiss", $claim_id, $user_id, $remarks, $approval_status);
            mysqli_stmt_execute($approval_stmt);
            
            // Get claimant info for notification
            $claim_query = "SELECT claimant_id, claim_number FROM claims WHERE id = ?";
            $claim_stmt = mysqli_prepare($conn, $claim_query);
            mysqli_stmt_bind_param($claim_stmt, "i", $claim_id);
            mysqli_stmt_execute($claim_stmt);
            $claim_result = mysqli_stmt_get_result($claim_stmt);
            $claim_data = mysqli_fetch_assoc($claim_result);
            
            if ($claim_data) {
                if ($new_status === 'approved') {
                    $notif_title = "Dai Limeidhinishwa";
                    $notif_message = "Dai lako namba {$claim_data['claim_number']} limeidhinishwa na litaendelea kwenye hatua ya malipo.";
                } else {
                    $notif_title = "Dai Limekataliwa";
                    $notif_message = "Dai lako namba {$claim_data['claim_number']} limekataliwa. Sababu: " . substr($remarks, 0, 200);
                }
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'legal', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $claim_data['claimant_id'], $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            }
            
            logAudit($conn, $user_id, 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                    ['status' => $old_status], ['status' => $new_status]);
            
            sendJsonResponse(true, "Dai limewekwa kama $new_status");
        } else {
            sendJsonResponse(false, 'Hitilafu katika kubadilisha hali ya dai');
        }
    } else {
        sendJsonResponse(false, 'Hali isiyo sahihi');
    }
}

// Handle edit claim via AJAX (update) - SIMPLIFIED VERSION
if (isset($_GET['ajax_edit_claim']) && isset($_GET['claim_id'])) {
    // Make sure we handle only POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Method not allowed');
    }
    
    $claim_id = intval($_GET['claim_id']);
    
    // Get input data
    $raw_input = file_get_contents('php://input');
    if (empty($raw_input)) {
        sendJsonResponse(false, 'Hakuna data iliyopokelewa');
    }
    
    $input = json_decode($raw_input, true);
    if ($input === null) {
        sendJsonResponse(false, 'Invalid JSON data');
    }
    
    // Extract data
    $project_name = trim(mysqli_real_escape_string($conn, $input['project_name'] ?? ''));
    $district = trim(mysqli_real_escape_string($conn, $input['district'] ?? ''));
    $property_type = trim(mysqli_real_escape_string($conn, $input['property_type'] ?? ''));
    $property_size = floatval($input['property_size'] ?? 0);
    $description = mysqli_real_escape_string($conn, $input['description'] ?? '');
    
    $property_value = floatval($input['property_value'] ?? 0);
    $disturbance_allowance = floatval($input['disturbance_allowance'] ?? 0);
    $transport_allowance = floatval($input['transport_allowance'] ?? 0);
    $total_compensation = floatval($input['total_compensation'] ?? 0);
    $valuation_report = mysqli_real_escape_string($conn, $input['valuation_report'] ?? '');
    
    // Validation
    if (empty($project_name)) {
        sendJsonResponse(false, 'Tafadhali jaza jina la mradi');
    }
    if (empty($district)) {
        sendJsonResponse(false, 'Tafadhali jaza jina la wilaya');
    }
    if (empty($property_type)) {
        sendJsonResponse(false, 'Tafadhali chagua aina ya mali');
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // Update claims table
    $update_claim_query = "UPDATE claims SET 
                           project_name = ?, 
                           district = ?, 
                           property_type = ?, 
                           property_size = ?, 
                           description = ?,
                           updated_at = NOW() 
                           WHERE id = ?";
    $update_claim_stmt = mysqli_prepare($conn, $update_claim_query);
    mysqli_stmt_bind_param($update_claim_stmt, "sssdsi", $project_name, $district, $property_type, $property_size, $description, $claim_id);
    
    if (!mysqli_stmt_execute($update_claim_stmt)) {
        mysqli_rollback($conn);
        sendJsonResponse(false, 'Hitilafu katika kuhariri taarifa za dai');
    }
    
    // Check if valuation exists
    $check_val_query = "SELECT id FROM valuations WHERE claim_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_val_query);
    mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $valuation_exists = mysqli_fetch_assoc($check_result);
    
    if ($valuation_exists) {
        // Update existing valuation
        $update_val_query = "UPDATE valuations SET 
                             property_value = ?, 
                             disturbance_allowance = ?, 
                             transport_allowance = ?, 
                             total_compensation = ?,
                             valuation_report = ?
                             WHERE claim_id = ?";
        $update_val_stmt = mysqli_prepare($conn, $update_val_query);
        mysqli_stmt_bind_param($update_val_stmt, "dddddsi", $property_value, $disturbance_allowance, $transport_allowance, $total_compensation, $valuation_report, $claim_id);
        
        if (!mysqli_stmt_execute($update_val_stmt)) {
            mysqli_rollback($conn);
            sendJsonResponse(false, 'Hitilafu katika kuhariri tathmini');
        }
    } else {
        // Insert new valuation
        $insert_val_query = "INSERT INTO valuations (claim_id, valuer_id, property_value, disturbance_allowance, transport_allowance, total_compensation, valuation_report, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_val_stmt = mysqli_prepare($conn, $insert_val_query);
        mysqli_stmt_bind_param($insert_val_stmt, "iidddds", $claim_id, $user_id, $property_value, $disturbance_allowance, $transport_allowance, $total_compensation, $valuation_report);
        
        if (!mysqli_stmt_execute($insert_val_stmt)) {
            mysqli_rollback($conn);
            sendJsonResponse(false, 'Hitilafu katika kuongeza tathmini');
        }
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Log audit
    logAudit($conn, $user_id, 'EDIT_CLAIM', 'claims', $claim_id, [], $input);
    
    sendJsonResponse(true, 'Taarifa za dai zimehaririwa kikamilifu');
}

// Handle AJAX get claim details for modal
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    $claim_id = intval($_GET['claim_id']);
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin,
              v.id as valuation_id, v.property_value, v.disturbance_allowance, 
              v.transport_allowance, v.total_compensation, v.valuation_report,
              vu.full_name as valuer_name
              FROM claims c 
              JOIN users u ON c.claimant_id = u.id 
              LEFT JOIN valuations v ON c.id = v.claim_id
              LEFT JOIN users vu ON v.valuer_id = vu.id
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claim = mysqli_fetch_assoc($result);
    
    if ($claim) {
        sendJsonResponse(true, 'Success', $claim);
    } else {
        sendJsonResponse(false, 'Dai halijapatikana');
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/legal-header.php';
?>

<style>
    /* CSS Styles - same as before but minimized */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: white; border-radius: 0.75rem; padding: 1rem; border: 1px solid #e8f0e4; transition: all 0.2s; text-align: center; text-decoration: none; display: block; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .stat-number { font-size: 1.5rem; font-weight: 700; color: #1e2a1e; }
    .stat-label { font-size: 0.65rem; text-transform: uppercase; color: #6d7b6c; font-weight: 600; margin-top: 0.25rem; }
    .stat-card.pending .stat-number { color: #6b21a5; }
    .stat-card.approved .stat-number { color: #065f46; }
    .stat-card.rejected .stat-number { color: #991b1b; }
    .status-badge { display: inline-flex; align-items: center; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 600; gap: 0.25rem; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .claims-table { width: 100%; border-collapse: collapse; }
    .claims-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .claims-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; font-size: 0.875rem; }
    .claims-table tr:hover { background-color: #f4fcef; }
    .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; transition: all 0.2s ease; text-decoration: none; display: inline-block; }
    .filter-tab.active { background-color: #006e2c; color: white; }
    .filter-tab:not(.active):hover { background-color: #e8f0e4; }
    .btn-primary { background-color: #006e2c; color: white; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; border: none; cursor: pointer; transition: background-color 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; }
    .btn-primary:hover { background-color: #005a24; }
    .btn-outline { background-color: white; color: #3d4a3d; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; border: 1px solid #bccab9; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; text-decoration: none; }
    .btn-outline:hover { background-color: #eef6ea; }
    .pagination-btn { padding: 0.375rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.75rem; transition: all 0.15s ease; text-decoration: none; color: #3d4a3d; background: white; }
    .pagination-btn.active { background-color: #006e2c; color: white; border-color: #006e2c; }
    .pagination-btn:hover:not(.active) { background-color: #eef6ea; }
    .action-btn { background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 0.5rem; color: #6d7b6c; transition: all 0.2s; }
    .action-btn:hover { background-color: #e8f0e4; color: #006e2c; }
    .search-input { padding: 0.5rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; width: 100%; }
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .modal-overlay.show { opacity: 1; visibility: visible; }
    .modal-container { background: white; border-radius: 1rem; width: 90%; max-width: 750px; max-height: 90vh; overflow-y: auto; }
    .modal-container.large { max-width: 900px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.25rem; }
    .form-input, .form-select, .form-textarea { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; transition: all 0.2s; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 2px rgba(0,110,44,0.1); }
    .form-textarea { resize: vertical; min-height: 80px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        .claims-table { min-width: 700px; }
        .table-container { overflow-x: auto; }
        .filter-actions { flex-direction: column; }
        .filter-actions .btn-primary, .filter-actions .btn-outline { width: 100%; justify-content: center; }
        .form-row, .form-row-3 { grid-template-columns: 1fr; gap: 0.75rem; }
    }
</style>

<!-- Page Content -->
<div class="space-y-4">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Usimamizi wa Madai (Kisheria)</h2>
            <p class="text-secondary text-xs">Kagua, idhinisha au kataa madai kulingana na tathmini</p>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="stat-card"><div class="stat-number"><?php echo $status_counts['all']; ?></div><div class="stat-label">Zote</div></a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" class="stat-card pending"><div class="stat-number"><?php echo $status_counts['legal_review']; ?></div><div class="stat-label">Yanayosubiri</div></a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" class="stat-card approved"><div class="stat-number"><?php echo $status_counts['approved']; ?></div><div class="stat-label">Yaliyoidhinishwa</div></a>
        <a href="?status=rejected&search=<?php echo urlencode($search_term); ?>" class="stat-card rejected"><div class="stat-number"><?php echo $status_counts['rejected']; ?></div><div class="stat-label">Yaliyokataliwa</div></a>
    </div>
    
    <!-- Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b pb-2">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">Zote</a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'legal_review' ? 'active' : ''; ?>">Yanayosubiri Uhakiki</a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'approved' ? 'active' : ''; ?>">Yaliyoidhinishwa</a>
        <a href="?status=rejected&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">Yaliyokataliwa</a>
    </div>
    
    <!-- Search Bar -->
    <div class="bg-white border rounded-lg p-3">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-2">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="flex-1"><input type="text" name="search" class="search-input" placeholder="Tafuta..." value="<?php echo htmlspecialchars($search_term); ?>"></div>
            <div class="flex gap-2 filter-actions"><button type="submit" class="btn-primary">Tafuta</button><a href="claims.php" class="btn-outline">Reset</a></div>
        </form>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="claims-table">
                <thead><tr><th>Namba ya Dai</th><th>Mwombaji</th><th>Mradi</th><th>Aina ya Mali</th><th class="text-right">Fidia</th><th>Mkaguzi</th><th>Tarehe</th><th>Hali</th><th class="text-center">Hatua</th></tr></thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr><td colspan="9" class="text-center py-12 text-secondary">Hakuna madai</td></tr>
                    <?php else: foreach ($claims as $claim): ?>
                    <tr id="row-<?php echo $claim['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td><div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div><div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div></td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></td>
                        <td class="text-right font-semibold text-primary">TZS <?php echo number_format($claim['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-sm"><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td><span class="status-badge <?php echo $claim['status']; ?>"><?php $labels = ['legal_review'=>'Uhakiki','approved'=>'Imeidhinishwa','rejected'=>'Imekataliwa']; echo $labels[$claim['status']] ?? ucfirst($claim['status']); ?></span></td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <?php if ($claim['status'] === 'legal_review'): ?>
                                <button type="button" class="action-btn review-btn" data-id="<?php echo $claim['id']; ?>" title="Kagua"><span class="material-symbols-outlined text-primary">gavel</span></button>
                                <?php endif; ?>
                                <button type="button" class="action-btn edit-btn" data-id="<?php echo $claim['id']; ?>" title="Hariri"><span class="material-symbols-outlined" style="color:#d97706;">edit</span></button>
                                <?php if ($claim['status'] !== 'legal_review'): ?>
                                <button type="button" class="action-btn view-btn" data-id="<?php echo $claim['id']; ?>" title="Angalia"><span class="material-symbols-outlined">visibility</span></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-4 py-3 border-t gap-2">
            <div class="text-sm text-secondary">Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?></div>
            <div class="flex gap-1">
                <?php if ($page > 1): ?><a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">«</a><?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">»</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2"><span class="material-symbols-outlined text-sm">info</span><div><p class="font-semibold text-sm">Maelekezo ya Uhakiki</p><p class="text-xs mt-1">Kagua tathmini kwa makini. Hakikisha thamani ya mali, posho za usumbufu na usafiri zinaendana na kanuni za serikali.</p></div></div>
    </div>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="modal-overlay"><div class="modal-container"><div class="modal-header"><h3 class="text-lg font-semibold">Kagua na Uamue</h3><button class="close-review-modal p-1 hover:bg-surface-container-low rounded-lg"><span class="material-symbols-outlined">close</span></button></div><div class="modal-body" id="reviewModalBody"><div class="text-center py-4">Inapakia...</div></div><div class="modal-footer"><button class="close-review-modal btn-outline">Funga</button></div></div></div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay"><div class="modal-container large"><div class="modal-header"><h3 class="text-lg font-semibold">Hariri Taarifa za Dai na Tathmini</h3><button class="close-edit-modal p-1 hover:bg-surface-container-low rounded-lg"><span class="material-symbols-outlined">close</span></button></div><div class="modal-body" id="editModalBody"><div class="text-center py-4">Inapakia...</div></div><div class="modal-footer"><button class="close-edit-modal btn-outline">Ghairi</button><button id="saveEditBtn" class="btn-primary">Hifadhi Mabadiliko</button></div></div></div>

<script>
// Simple, clean JavaScript - no conflicts
document.addEventListener('DOMContentLoaded', function() {
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function closeReviewModal() {
        document.getElementById('reviewModal').classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Review Modal
    window.openReviewModal = async function(claimId) {
        const modal = document.getElementById('reviewModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success && data.data) {
                const c = data.data;
                document.getElementById('reviewModalBody').innerHTML = `
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-2">Taarifa za Dai</h4><div class="grid grid-cols-2 gap-2 text-sm">
                            <div><span class="text-secondary">Namba:</span><br><span class="font-mono font-semibold">${escapeHtml(c.claim_number)}</span></div>
                            <div><span class="text-secondary">Mwombaji:</span><br><span class="font-semibold">${escapeHtml(c.claimant_name)}</span></div>
                            <div><span class="text-secondary">Barua Pepe:</span><br>${escapeHtml(c.email)}</div>
                            <div><span class="text-secondary">Simu:</span><br>${escapeHtml(c.phone || '-')}</div>
                            <div><span class="text-secondary">Mradi:</span><br>${escapeHtml(c.project_name || '-')}</div>
                            <div><span class="text-secondary">Wilaya:</span><br>${escapeHtml(c.district || '-')}</div>
                            <div><span class="text-secondary">Aina ya Mali:</span><br>${c.property_type ? c.property_type.replace('_', ' ') : '-'}</div>
                            <div><span class="text-secondary">Ukubwa:</span><br>${c.property_size ? c.property_size + ' sqm' : '-'}</div>
                        </div></div>
                        <div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-2">Taarifa za Tathmini</h4><div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-secondary">Thamani ya Mali:</span><span>TZS ${(c.property_value || 0).toLocaleString()}</span></div>
                            <div class="flex justify-between"><span class="text-secondary">Posho ya Usumbufu:</span><span>TZS ${(c.disturbance_allowance || 0).toLocaleString()}</span></div>
                            <div class="flex justify-between"><span class="text-secondary">Posho ya Usafiri:</span><span>TZS ${(c.transport_allowance || 0).toLocaleString()}</span></div>
                            <div class="flex justify-between pt-2 border-t"><span class="font-semibold">Jumla:</span><span class="font-bold text-primary">TZS ${(c.total_compensation || 0).toLocaleString()}</span></div>
                            <div class="flex justify-between"><span class="text-secondary">Mkaguzi:</span><span>${escapeHtml(c.valuer_name || '-')}</span></div>
                        </div></div>
                        ${c.valuation_report ? `<div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-2">Ripoti ya Tathmini</h4><p class="text-sm">${escapeHtml(c.valuation_report)}</p></div>` : ''}
                        ${c.description ? `<div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-2">Maelezo</h4><p class="text-sm">${escapeHtml(c.description)}</p></div>` : ''}
                        <div class="bg-yellow-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-2">Uamuzi Wako</h4><div class="space-y-3">
                            <div class="flex gap-3"><button onclick="makeDecision('approved', ${c.id})" class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700">✅ Idhinisha</button><button onclick="makeDecision('rejected', ${c.id})" class="flex-1 bg-red-600 text-white py-2 rounded-lg hover:bg-red-700">❌ Kataa</button></div>
                            <div><label class="block text-xs font-semibold text-secondary uppercase mb-1">Sababu (kwa kukataa)</label><textarea id="decision_remarks" rows="3" class="w-full px-3 py-2 border rounded-lg" placeholder="Taja sababu za kukataa..."></textarea></div>
                        </div></div>
                    </div>`;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata taarifa' });
                closeReviewModal();
            }
        } catch(e) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo: ' + e.message });
            closeReviewModal();
        }
    };
    
    // Edit Modal
    window.openEditModal = async function(claimId) {
        const modal = document.getElementById('editModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success && data.data) {
                const c = data.data;
                document.getElementById('editModalBody').innerHTML = `
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-3">Taarifa za Dai</h4>
                            <div class="space-y-3">
                                <div class="form-row"><div class="form-group"><label class="form-label">Namba ya Dai</label><input type="text" class="form-input bg-gray-100" value="${escapeHtml(c.claim_number)}" disabled></div>
                                <div class="form-group"><label class="form-label">Mwombaji</label><input type="text" class="form-input bg-gray-100" value="${escapeHtml(c.claimant_name)}" disabled></div></div>
                                <div class="form-row"><div class="form-group"><label class="form-label">Jina la Mradi *</label><input type="text" id="edit_project_name" class="form-input" value="${escapeHtml(c.project_name || '')}"></div>
                                <div class="form-group"><label class="form-label">Wilaya *</label><input type="text" id="edit_district" class="form-input" value="${escapeHtml(c.district || '')}"></div></div>
                                <div class="form-row"><div class="form-group"><label class="form-label">Aina ya Mali *</label><select id="edit_property_type" class="form-select"><option value="">-- Chagua --</option><option value="land" ${c.property_type === 'land' ? 'selected' : ''}>Shamba/Ardhi</option><option value="building" ${c.property_type === 'building' ? 'selected' : ''}>Jengo</option><option value="crop" ${c.property_type === 'crop' ? 'selected' : ''}>Mazao</option><option value="business" ${c.property_type === 'business' ? 'selected' : ''}>Biashara</option><option value="other" ${c.property_type === 'other' ? 'selected' : ''}>Nyingine</option></select></div>
                                <div class="form-group"><label class="form-label">Ukubwa (sqm)</label><input type="number" id="edit_property_size" class="form-input" step="0.01" value="${c.property_size || ''}"></div></div>
                                <div class="form-group"><label class="form-label">Maelezo ya Dai</label><textarea id="edit_description" class="form-textarea" rows="3">${escapeHtml(c.description || '')}</textarea></div>
                            </div>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg"><h4 class="font-semibold text-sm mb-3">Taarifa za Tathmini</h4>
                            <div class="space-y-3">
                                <div class="form-row-3"><div class="form-group"><label class="form-label">Thamani ya Mali (TZS)</label><input type="number" id="edit_property_value" class="form-input" step="1000" value="${c.property_value || 0}" oninput="updateTotal()"></div>
                                <div class="form-group"><label class="form-label">Posho ya Usumbufu (TZS)</label><input type="number" id="edit_disturbance_allowance" class="form-input" step="1000" value="${c.disturbance_allowance || 0}" oninput="updateTotal()"></div>
                                <div class="form-group"><label class="form-label">Posho ya Usafiri (TZS)</label><input type="number" id="edit_transport_allowance" class="form-input" step="1000" value="${c.transport_allowance || 0}" oninput="updateTotal()"></div></div>
                                <div class="form-group"><label class="form-label font-bold">Jumla ya Fidia (TZS)</label><input type="number" id="edit_total_compensation" class="form-input bg-green-50 font-semibold" step="1000" value="${c.total_compensation || 0}" readonly></div>
                                <div class="form-group"><label class="form-label">Ripoti ya Tathmini</label><textarea id="edit_valuation_report" class="form-textarea" rows="4">${escapeHtml(c.valuation_report || '')}</textarea></div>
                            </div>
                        </div>
                        <input type="hidden" id="edit_claim_id" value="${c.id}">
                    </div>`;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata taarifa' });
                closeEditModal();
            }
        } catch(e) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo: ' + e.message });
            closeEditModal();
        }
    };
    
    // Update total
    window.updateTotal = function() {
        const pv = parseFloat(document.getElementById('edit_property_value')?.value) || 0;
        const da = parseFloat(document.getElementById('edit_disturbance_allowance')?.value) || 0;
        const ta = parseFloat(document.getElementById('edit_transport_allowance')?.value) || 0;
        const total = document.getElementById('edit_total_compensation');
        if (total) total.value = (pv + da + ta).toFixed(2);
    };
    
    // Submit edit
    window.submitEdit = async function() {
        const claimId = document.getElementById('edit_claim_id')?.value;
        if (!claimId) { Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Claim ID haikupatikana' }); return; }
        
        const data = {
            project_name: document.getElementById('edit_project_name')?.value || '',
            district: document.getElementById('edit_district')?.value || '',
            property_type: document.getElementById('edit_property_type')?.value || '',
            property_size: parseFloat(document.getElementById('edit_property_size')?.value) || 0,
            description: document.getElementById('edit_description')?.value || '',
            property_value: parseFloat(document.getElementById('edit_property_value')?.value) || 0,
            disturbance_allowance: parseFloat(document.getElementById('edit_disturbance_allowance')?.value) || 0,
            transport_allowance: parseFloat(document.getElementById('edit_transport_allowance')?.value) || 0,
            total_compensation: parseFloat(document.getElementById('edit_total_compensation')?.value) || 0,
            valuation_report: document.getElementById('edit_valuation_report')?.value || ''
        };
        
        if (!data.project_name.trim()) { Swal.fire({ icon: 'warning', title: 'Taarifa Zimekosekana', text: 'Jaza jina la mradi' }); return; }
        if (!data.district.trim()) { Swal.fire({ icon: 'warning', title: 'Taarifa Zimekosekana', text: 'Jaza jina la wilaya' }); return; }
        if (!data.property_type) { Swal.fire({ icon: 'warning', title: 'Taarifa Zimekosekana', text: 'Chagua aina ya mali' }); return; }
        
        Swal.fire({ title: 'Inachakata...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_edit_claim=1&claim_id=${claimId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: result.message, timer: 1500 });
                setTimeout(() => location.reload(), 1500);
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: result.message });
            }
        } catch(e) {
            Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo: ' + e.message });
        }
    };
    
    // Make decision
    window.makeDecision = async function(status, claimId) {
        const remarks = document.getElementById('decision_remarks')?.value || '';
        if (status === 'rejected' && !remarks.trim()) {
            Swal.fire({ icon: 'warning', title: 'Sababu Inahitajika', text: 'Jaza sababu ya kukataa' });
            return;
        }
        
        const result = await Swal.fire({ title: status === 'approved' ? 'Thibitisha Uidhinishaji' : 'Thibitisha Kukataa', text: status === 'approved' ? 'Una uhakika unataka kuidhinisha dai hili?' : 'Una uhakika unataka kukataa dai hili?', icon: 'question', showCancelButton: true, confirmButtonColor: status === 'approved' ? '#006e2c' : '#ba1a1a', confirmButtonText: 'Ndiyo', cancelButtonText: 'Hapana' });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inachakata...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            try {
                const response = await fetch(`?ajax_update_status=1&claim_id=${claimId}&new_status=${status}&remarks=${encodeURIComponent(remarks)}`);
                const data = await response.json();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: data.message, timer: 1500 });
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message });
                }
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo: ' + e.message });
            }
        }
    };
    
    function viewClaim(claimId) { window.location.href = `view-claim.php?id=${claimId}`; }
    
    // Attach event listeners
    document.querySelectorAll('.review-btn').forEach(btn => btn.addEventListener('click', () => openReviewModal(parseInt(btn.dataset.id))));
    document.querySelectorAll('.edit-btn').forEach(btn => btn.addEventListener('click', () => openEditModal(parseInt(btn.dataset.id))));
    document.querySelectorAll('.view-btn').forEach(btn => btn.addEventListener('click', () => viewClaim(parseInt(btn.dataset.id))));
    document.querySelectorAll('.close-review-modal').forEach(btn => btn.addEventListener('click', closeReviewModal));
    document.querySelectorAll('.close-edit-modal').forEach(btn => btn.addEventListener('click', closeEditModal));
    document.getElementById('saveEditBtn')?.addEventListener('click', submitEdit);
    document.getElementById('reviewModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeReviewModal(); });
    document.getElementById('editModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeEditModal(); });
    
    // Search debounce
    let timeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) searchInput.addEventListener('keyup', () => { clearTimeout(timeout); timeout = setTimeout(() => searchInput.closest('form')?.submit(), 500); });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/legal-footer.php'; ?>