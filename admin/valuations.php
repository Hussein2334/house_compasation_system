<?php
// admin/valuations.php - Manage Property Valuations (Updated for your DB structure)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin or valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'valuer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Valuations';
$page_heading = 'Usimamizi wa Tathmini';

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

// Get total valuations count for pagination
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
$total_valuations = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_valuations / $per_page);

// Get valuations data - FIXED to match your actual table structure
$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin,
          v.id as valuation_id, v.valuer_id, v.property_value, 
          v.disturbance_allowance, v.transport_allowance, v.total_compensation, 
          v.valuation_report, v.created_at as valuation_date,
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
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$valuations = [];
while ($row = mysqli_fetch_assoc($result)) {
    $valuations[] = $row;
}

// Get status counts
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

// Get valuators list for assignment
$valuators_query = "SELECT id, full_name, email FROM users WHERE role = 'valuer' AND status = 'active' ORDER BY full_name";
$valuators_result = mysqli_query($conn, $valuators_query);
$valuators = [];
while ($row = mysqli_fetch_assoc($valuators_result)) {
    $valuators[] = $row;
}

// Handle submit valuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_valuation'])) {
    $claim_id = intval($_POST['claim_id']);
    $property_value = !empty($_POST['property_value']) ? floatval($_POST['property_value']) : 0;
    $disturbance_allowance = !empty($_POST['disturbance_allowance']) ? floatval($_POST['disturbance_allowance']) : 0;
    $transport_allowance = !empty($_POST['transport_allowance']) ? floatval($_POST['transport_allowance']) : 0;
    $total_compensation = $property_value + $disturbance_allowance + $transport_allowance;
    $valuation_report = trim($_POST['valuation_report'] ?? '');
    $valuer_id = $_SESSION['user_id'];
    
    // Check if valuation already exists
    $check_query = "SELECT id FROM valuations WHERE claim_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        // Update existing valuation
        $update_query = "UPDATE valuations SET 
                         property_value = ?, disturbance_allowance = ?, transport_allowance = ?, 
                         total_compensation = ?, valuation_report = ?, valuer_id = ?
                         WHERE claim_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ddddssi", 
            $property_value, $disturbance_allowance, $transport_allowance, 
            $total_compensation, $valuation_report, $valuer_id, $claim_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Update claim status to legal_review
            mysqli_query($conn, "UPDATE claims SET status = 'legal_review', updated_at = NOW() WHERE id = $claim_id");
            $_SESSION['success_message'] = "Tathmini imesasishwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'UPDATE_VALUATION', 'valuations', $claim_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kusasisha tathmini: " . mysqli_error($conn);
        }
    } else {
        // Insert new valuation
        $insert_query = "INSERT INTO valuations (claim_id, valuer_id, property_value, 
                         disturbance_allowance, transport_allowance, total_compensation, valuation_report)
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "iidddds", 
            $claim_id, $valuer_id, $property_value, 
            $disturbance_allowance, $transport_allowance, $total_compensation, $valuation_report);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            // Update claim status to legal_review
            mysqli_query($conn, "UPDATE claims SET status = 'legal_review', updated_at = NOW() WHERE id = $claim_id");
            $_SESSION['success_message'] = "Tathmini imewasilishwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'CREATE_VALUATION', 'valuations', $claim_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kuwasilisha tathmini: " . mysqli_error($conn);
        }
    }
    
    header("Location: valuations.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle update claim status
if (isset($_GET['update_status']) && isset($_GET['claim_id']) && isset($_GET['new_status'])) {
    $claim_id = intval($_GET['claim_id']);
    $new_status = $_GET['new_status'];
    
    $old_status_query = "SELECT status FROM claims WHERE id = ?";
    $old_stmt = mysqli_prepare($conn, $old_status_query);
    mysqli_stmt_bind_param($old_stmt, "i", $claim_id);
    mysqli_stmt_execute($old_stmt);
    $old_result = mysqli_stmt_get_result($old_stmt);
    $old_data = mysqli_fetch_assoc($old_result);
    
    $update_stmt = mysqli_prepare($conn, "UPDATE claims SET status = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($update_stmt, "si", $new_status, $claim_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $_SESSION['success_message'] = "Hali ya dai imebadilishwa kikamilifu.";
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                ['status' => $old_data['status']], ['status' => $new_status]);
    } else {
        $_SESSION['error_message'] = "Hitilafu katika kubadilisha hali ya dai.";
    }
    
    header("Location: valuations.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle AJAX get claim data for valuation
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $claim_id = intval($_GET['claim_id']);
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin,
              v.id as valuation_id, v.property_value, v.disturbance_allowance, v.transport_allowance, 
              v.total_compensation, v.valuation_report
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

// Handle export valuations
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="valuations_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Claim Number', 'Claimant Name', 'Project Name', 'District',
        'Property Type', 'Property Size (sqm)', 'Property Value (TZS)',
        'Disturbance Allowance (TZS)', 'Transport Allowance (TZS)', 
        'Total Compensation (TZS)', 'Valuation Date', 'Status'
    ]);
    
    $export_query = "SELECT c.claim_number, u.full_name, c.project_name, c.district,
                     c.property_type, c.property_size, 
                     COALESCE(v.property_value, 0) as property_value,
                     COALESCE(v.disturbance_allowance, 0) as disturbance_allowance,
                     COALESCE(v.transport_allowance, 0) as transport_allowance,
                     COALESCE(v.total_compensation, 0) as total_compensation,
                     v.created_at as valuation_date, c.status
                     FROM claims c
                     JOIN users u ON c.claimant_id = u.id
                     LEFT JOIN valuations v ON c.id = v.claim_id
                     WHERE c.status IN ('valuation', 'legal_review', 'approved', 'paid')";
    
    $export_result = mysqli_query($conn, $export_query);
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, $row);
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
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    /* Table Styles */
    .valuations-table { width: 100%; border-collapse: collapse; }
    .valuations-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .valuations-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; }
    .valuations-table tr:hover { background-color: #eef6ea; }
    
    /* Action Button */
    .action-btn { background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 0.5rem; color: #6d7b6c; transition: all 0.2s; }
    .action-btn:hover { background-color: #e8f0e4; color: #006e2c; }
    
    /* Filter Tabs */
    .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; transition: all 0.2s ease; }
    .filter-tab.active { background-color: #006e2c; color: white; }
    .filter-tab:not(.active):hover { background-color: #e8f0e4; }
    
    .pagination-btn { padding: 0.375rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.75rem; transition: all 0.15s ease; }
    .pagination-btn:hover:not(.active) { background-color: #eef6ea; }
    .pagination-btn.active { background-color: #006e2c; color: white; border-color: #006e2c; }
    
    /* Valuation Modal */
    .valuation-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .valuation-modal-overlay.show { opacity: 1; visibility: visible; }
    .valuation-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 750px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .valuation-modal-overlay.show .valuation-modal-container { transform: scale(1); }
    .valuation-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .valuation-modal-body { padding: 1.5rem; }
    .valuation-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    
    /* View Modal */
    .view-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .view-modal-overlay.show { opacity: 1; visibility: visible; }
    .view-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 600px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .view-modal-overlay.show .view-modal-container { transform: scale(1); }
    .view-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .view-modal-body { padding: 1.5rem; }
    
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.25rem; }
    .form-label.required::after { content: "*"; color: #dc2626; margin-left: 0.25rem; }
    .form-control { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .form-control:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    .form-control[readonly] { background: #f4fcef; }
    .form-textarea { min-height: 100px; resize: vertical; }
    
    .info-row { display: flex; padding: 0.5rem 0; border-bottom: 1px solid #e8f0e4; }
    .info-label { width: 35%; font-weight: 600; color: #3d4a3d; }
    .info-value { width: 65%; color: #1e2a1e; }
    
    .total-box { background: #f4fcef; border: 1px solid #bccab9; border-radius: 0.5rem; padding: 1rem; margin-top: 1rem; text-align: center; }
    .total-box .amount { font-size: 1.5rem; font-weight: 700; color: #006e2c; }
    .total-box .label { font-size: 0.7rem; text-transform: uppercase; color: #3d4a3d; }
    
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    
    @media (max-width: 640px) {
        .grid-2 { grid-template-columns: 1fr; gap: 0.75rem; }
        .info-row { flex-direction: column; }
        .info-label { width: 100%; margin-bottom: 0.25rem; }
        .info-value { width: 100%; }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Usimamizi wa Tathmini</h2>
            <p class="text-secondary text-sm mt-1">Kagua, tathmini na usindikie thamani ya mali kwa ajili ya fidia</p>
        </div>
        <div class="flex gap-3">
            <button onclick="exportValuations()" class="px-4 py-2 border border-outline-variant rounded-lg flex items-center gap-2 hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">download</span> Export CSV
            </button>
        </div>
    </div>
    
    <!-- Status Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'all' ? 'active' : 'text-secondary'; ?>">
            Zote (<?php echo array_sum($status_counts); ?>)
        </a>
        <a href="?status=valuation&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'valuation' ? 'active' : 'text-secondary'; ?>">
            Inahitaji Tathmini (<?php echo $status_counts['valuation'] ?? 0; ?>)
        </a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'legal_review' ? 'active' : 'text-secondary'; ?>">
            Tathmini Imekamilika (<?php echo $status_counts['legal_review'] ?? 0; ?>)
        </a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" 
           class="filter-tab <?php echo $status_filter === 'approved' ? 'active' : 'text-secondary'; ?>">
            Imeidhinishwa (<?php echo $status_counts['approved'] ?? 0; ?>)
        </a>
    </div>
    
    <!-- Search -->
    <div class="flex flex-col md:flex-row gap-4">
        <form method="GET" action="" class="flex-1" id="searchForm">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-xl">search</span>
                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." 
                       class="w-full pl-10 pr-4 py-2.5 border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
            </div>
        </form>
    </div>
    
    <!-- Valuations Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="valuations-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Aina ya Mali</th>
                        <th>Thamani ya Mali (TZS)</th>
                        <th>Fidia (TZS)</th>
                        <th>Hali</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($valuations)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">real_estate_agent</span>
                            Hakuna tathmini zinazoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($valuations as $valuation): ?>
                    <tr id="row-<?php echo $valuation['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($valuation['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($valuation['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($valuation['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($valuation['project_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($valuation['property_type'] ?? '-'); ?></td>
                        <td class="text-right">
                            <?php echo number_format($valuation['property_value'] ?? 0, 0, '.', ','); ?>
                        </td>
                        <td class="text-right font-semibold text-primary">
                            <?php echo number_format($valuation['total_compensation'] ?? 0, 0, '.', ','); ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $valuation['status']; ?>">
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
                                    echo $icons[$valuation['status']] ?? 'info';
                                    ?>
                                </span>
                                <?php echo getStatusLabel($valuation['status']); ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary"><?php echo formatDate($valuation['created_at'], 'd M Y'); ?></td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" class="action-btn" onclick="viewValuation(<?php echo $valuation['id']; ?>)" title="Angalia Maelezo">
                                    <span class="material-symbols-outlined">visibility</span>
                                </button>
                                <?php if ($valuation['status'] === 'valuation' || ($valuation['status'] === 'legal_review' && $_SESSION['role'] === 'super_admin')): ?>
                                <button type="button" class="action-btn" onclick="openValuationModal(<?php echo $valuation['id']; ?>)" title="Tathmini / Hariri">
                                    <span class="material-symbols-outlined">edit_note</span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-outline-variant bg-surface-container-low">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_valuations); ?> kati ya <?php echo $total_valuations; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Inayofuata</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Valuation Modal (Add/Edit) -->
<div id="valuationModal" class="valuation-modal-overlay">
    <div class="valuation-modal-container">
        <div class="valuation-modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">real_estate_agent</span>
                <h3 class="text-lg font-semibold">Tathmini ya Mali</h3>
            </div>
            <button type="button" id="closeValuationModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <form id="valuationForm" method="POST" action="">
            <input type="hidden" name="submit_valuation" value="1">
            <input type="hidden" id="valuation_claim_id" name="claim_id">
            <div class="valuation-modal-body">
                <!-- Claim Information Summary -->
                <div class="bg-surface-container-low p-3 rounded-lg mb-4">
                    <div class="grid-2">
                        <div><span class="text-xs text-secondary">Namba ya Dai:</span><br><span id="view_claim_number" class="font-semibold">-</span></div>
                        <div><span class="text-xs text-secondary">Mwombaji:</span><br><span id="view_claimant_name" class="font-semibold">-</span></div>
                        <div><span class="text-xs text-secondary">Mradi:</span><br><span id="view_project_name">-</span></div>
                        <div><span class="text-xs text-secondary">Aina ya Mali:</span><br><span id="view_property_type">-</span></div>
                        <div><span class="text-xs text-secondary">Ukubwa:</span><br><span id="view_property_size">-</span></div>
                        <div><span class="text-xs text-secondary">Wilaya:</span><br><span id="view_district">-</span></div>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Thamani ya Mali (TZS)</label>
                        <input type="number" name="property_value" id="property_value" class="form-control" step="1000" value="0" onchange="calculateTotal()">
                        <div class="form-hint">Thamani ya jumla ya mali (ardhi, majengo, na mali nyingine)</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usumbufu (TZS)</label>
                        <input type="number" name="disturbance_allowance" id="disturbance_allowance" class="form-control" step="1000" value="0" onchange="calculateTotal()">
                        <div class="form-hint">Posho kwa usumbufu wa makazi au biashara</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usafiri (TZS)</label>
                        <input type="number" name="transport_allowance" id="transport_allowance" class="form-control" step="1000" value="0" onchange="calculateTotal()">
                        <div class="form-hint">Posho ya gharama za usafiri wa kubebea mali</div>
                    </div>
                </div>
                
                <div class="total-box">
                    <div class="label">Jumla ya Fidia Inayopendekezwa</div>
                    <div class="amount" id="total_compensation_display">TZS 0</div>
                    <input type="hidden" name="total_compensation" id="total_compensation" value="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Ripoti ya Tathmini / Maelezo</label>
                    <textarea name="valuation_report" id="valuation_report" rows="4" class="form-control form-textarea" placeholder="Weka maelezo ya tathmini, mbinu iliyotumika, na taarifa nyingine muhimu..."></textarea>
                </div>
            </div>
            <div class="valuation-modal-footer">
                <button type="button" id="cancelValuationBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Wasilisha Tathmini</button>
            </div>
        </form>
    </div>
</div>

<!-- View Valuation Modal -->
<div id="viewModal" class="view-modal-overlay">
    <div class="view-modal-container">
        <div class="view-modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">description</span>
                <h3 class="text-lg font-semibold">Maelezo ya Tathmini</h3>
            </div>
            <button type="button" id="closeViewModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <div class="view-modal-body" id="viewModalBody">
            <!-- Dynamic content will be loaded here -->
        </div>
    </div>
</div>

<script>
    let currentClaimId = null;
    
    // Calculate total compensation
    function calculateTotal() {
        let propertyValue = parseFloat(document.getElementById('property_value').value) || 0;
        let disturbanceAllowance = parseFloat(document.getElementById('disturbance_allowance').value) || 0;
        let transportAllowance = parseFloat(document.getElementById('transport_allowance').value) || 0;
        let total = propertyValue + disturbanceAllowance + transportAllowance;
        
        document.getElementById('total_compensation').value = total;
        document.getElementById('total_compensation_display').innerHTML = 'TZS ' + total.toLocaleString('en-US');
    }
    
    // Open valuation modal
    async function openValuationModal(claimId) {
        currentClaimId = claimId;
        const modal = document.getElementById('valuationModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const claim = data.data;
                document.getElementById('valuation_claim_id').value = claim.id;
                document.getElementById('view_claim_number').innerHTML = claim.claim_number;
                document.getElementById('view_claimant_name').innerHTML = claim.claimant_name;
                document.getElementById('view_project_name').innerHTML = claim.project_name || '-';
                document.getElementById('view_property_type').innerHTML = claim.property_type || '-';
                document.getElementById('view_property_size').innerHTML = claim.property_size ? claim.property_size + ' sqm' : '-';
                document.getElementById('view_district').innerHTML = claim.district || '-';
                
                // Load existing valuation data if available
                document.getElementById('property_value').value = claim.property_value || 0;
                document.getElementById('disturbance_allowance').value = claim.disturbance_allowance || 0;
                document.getElementById('transport_allowance').value = claim.transport_allowance || 0;
                document.getElementById('valuation_report').value = claim.valuation_report || '';
                
                calculateTotal();
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa', confirmButtonColor: '#006e2c' });
                closeValuationModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
            closeValuationModal();
        }
    }
    
    function closeValuationModal() {
        const modal = document.getElementById('valuationModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // View valuation details
    async function viewValuation(claimId) {
        const modal = document.getElementById('viewModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const claim = data.data;
                const totalValue = (claim.property_value || 0) + (claim.disturbance_allowance || 0) + (claim.transport_allowance || 0);
                
                let html = `
                    <div class="space-y-4">
                        <div class="info-row">
                            <div class="info-label">Namba ya Dai:</div>
                            <div class="info-value font-mono">${claim.claim_number}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Mwombaji:</div>
                            <div class="info-value">${claim.claimant_name}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Barua Pepe:</div>
                            <div class="info-value">${claim.email}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">NIN:</div>
                            <div class="info-value">${claim.nin || '-'}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Mradi:</div>
                            <div class="info-value">${claim.project_name || '-'}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Wilaya:</div>
                            <div class="info-value">${claim.district || '-'}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Aina ya Mali:</div>
                            <div class="info-value">${claim.property_type || '-'}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Ukubwa:</div>
                            <div class="info-value">${claim.property_size ? claim.property_size + ' sqm' : '-'}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Maelezo ya Dai:</div>
                            <div class="info-value">${claim.description || '-'}</div>
                        </div>
                        <hr>
                        <div class="info-row">
                            <div class="info-label">Thamani ya Mali:</div>
                            <div class="info-value">TZS ${(claim.property_value || 0).toLocaleString()}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Posho ya Usumbufu:</div>
                            <div class="info-value">TZS ${(claim.disturbance_allowance || 0).toLocaleString()}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Posho ya Usafiri:</div>
                            <div class="info-value">TZS ${(claim.transport_allowance || 0).toLocaleString()}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Jumla ya Fidia:</div>
                            <div class="info-value font-bold text-primary">TZS ${totalValue.toLocaleString()}</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Ripoti ya Tathmini:</div>
                            <div class="info-value">${claim.valuation_report || '-'}</div>
                        </div>
                        <hr>
                        <div class="info-row">
                            <div class="info-label">Hali ya Dai:</div>
                            <div class="info-value"><span class="status-badge ${claim.status}">${getStatusLabel(claim.status)}</span></div>
                        </div>
                    </div>
                `;
                
                document.getElementById('viewModalBody').innerHTML = html;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa', confirmButtonColor: '#006e2c' });
                closeViewModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
            closeViewModal();
        }
    }
    
    function closeViewModal() {
        const modal = document.getElementById('viewModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function getStatusLabel(status) {
        const labels = {
            'submitted': 'Imewasilishwa',
            'valuation': 'Tathmini',
            'legal_review': 'Uhakiki',
            'approved': 'Imeidhinishwa',
            'rejected': 'Imekataliwa',
            'paid': 'Imelipwa'
        };
        return labels[status] || status;
    }
    
    function exportValuations() {
        Swal.fire({
            title: 'Export Tathmini',
            text: 'Je, unataka kupakua ripoti ya tathmini?',
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
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
        });
    }
    
    // ========== EVENT LISTENERS ==========
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('closeValuationModalBtn').addEventListener('click', closeValuationModal);
        document.getElementById('cancelValuationBtn').addEventListener('click', closeValuationModal);
        document.getElementById('closeViewModalBtn').addEventListener('click', closeViewModal);
        
        document.getElementById('valuationModal').addEventListener('click', function(e) {
            if (e.target === this) closeValuationModal();
        });
        
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
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