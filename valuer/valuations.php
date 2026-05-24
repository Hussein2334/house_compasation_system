<?php
// valuer/valuations.php - Manage Property Valuations for Valuer (With Edit Functionality)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'My Valuations';
$page_heading = 'Tathmini Zangu';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query for valuations
$where_clauses = [];
$params = [];
$types = "";

if (!$is_super_admin) {
    $where_clauses[] = "v.valuer_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

if ($status_filter !== 'all') {
    if ($status_filter === 'completed') {
        $where_clauses[] = "c.status = 'legal_review'";
    } elseif ($status_filter === 'pending') {
        $where_clauses[] = "c.status = 'valuation'";
        $where_clauses[] = "v.id IS NULL";
    } else {
        $where_clauses[] = "c.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }
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

// Get total valuations count
$count_query = "SELECT COUNT(*) as total 
                FROM valuations v
                JOIN claims c ON v.claim_id = c.id
                JOIN users u ON c.claimant_id = u.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_valuations = mysqli_fetch_assoc($count_result)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_valuations / $per_page);

// Get valuations data
$query = "SELECT v.*, 
          c.claim_number, c.project_name, c.district, c.property_type, c.property_size, c.status as claim_status, c.created_at as claim_date,
          u.full_name as claimant_name, u.email, u.phone, u.nin
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
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

// Get pending valuations
$pending_query = "SELECT c.id, c.claim_number, c.project_name, c.district, 
                  c.property_type, c.property_size,
                  u.full_name as claimant_name, u.email, u.phone,
                  c.created_at
                  FROM claims c
                  JOIN users u ON c.claimant_id = u.id
                  WHERE c.status = 'valuation'
                  AND NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = c.id)
                  ORDER BY c.created_at ASC";
$pending_result = mysqli_query($conn, $pending_query);
$pending_valuations = [];
while ($row = mysqli_fetch_assoc($pending_result)) {
    $pending_valuations[] = $row;
}

// Get status counts
$status_counts = [];
if (!$is_super_admin) {
    $count_completed = "SELECT COUNT(*) as count FROM valuations v WHERE v.valuer_id = ?";
    $count_stmt = mysqli_prepare($conn, $count_completed);
    mysqli_stmt_bind_param($count_stmt, "i", $user_id);
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $status_counts['completed'] = mysqli_fetch_assoc($count_result)['count'];
    $status_counts['pending'] = count($pending_valuations);
    $status_counts['all'] = $status_counts['completed'] + $status_counts['pending'];
} else {
    $status_query = "SELECT COUNT(*) as count FROM valuations";
    $status_result = mysqli_query($conn, $status_query);
    $status_counts['all'] = mysqli_fetch_assoc($status_result)['count'];
    $status_counts['completed'] = $status_counts['all'];
    $pending_query_all = "SELECT COUNT(*) as count FROM claims WHERE status = 'valuation' AND NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = claims.id)";
    $pending_result_all = mysqli_query($conn, $pending_query_all);
    $status_counts['pending'] = mysqli_fetch_assoc($pending_result_all)['count'];
}

// Handle AJAX get claim data
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

// Handle update valuation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_valuation'])) {
    $valuation_id = intval($_POST['valuation_id']);
    $claim_id = intval($_POST['claim_id']);
    $property_value = !empty($_POST['property_value']) ? floatval($_POST['property_value']) : 0;
    $disturbance_allowance = !empty($_POST['disturbance_allowance']) ? floatval($_POST['disturbance_allowance']) : 0;
    $transport_allowance = !empty($_POST['transport_allowance']) ? floatval($_POST['transport_allowance']) : 0;
    $total_compensation = $property_value + $disturbance_allowance + $transport_allowance;
    $valuation_report = trim($_POST['valuation_report'] ?? '');
    
    $check_query = "SELECT id FROM valuations WHERE id = ? AND valuer_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $valuation_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0 || $is_super_admin) {
        $update_query = "UPDATE valuations SET 
                         property_value = ?, 
                         disturbance_allowance = ?, 
                         transport_allowance = ?, 
                         total_compensation = ?, 
                         valuation_report = ?
                         WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ddddss", 
            $property_value, $disturbance_allowance, $transport_allowance, 
            $total_compensation, $valuation_report, $valuation_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $_SESSION['success_message'] = "Tathmini imesasishwa kikamilifu.";
            logAudit($conn, $user_id, 'UPDATE_VALUATION', 'valuations', $valuation_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kusasisha tathmini: " . mysqli_error($conn);
        }
    } else {
        $_SESSION['error_message'] = "Huna ruhusa ya kuhariri tathmini hii.";
    }
    
    header("Location: valuations.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
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
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #006e2c;
    }
    .stat-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    
    .filter-tab {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
    }
    .filter-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .filter-tab:not(.active) {
        color: #6d7b6c;
    }
    .filter-tab:not(.active):hover {
        background-color: #e8f0e4;
    }
    
    .valuations-table {
        width: 100%;
        border-collapse: collapse;
    }
    .valuations-table th {
        padding: 0.75rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .valuations-table td {
        padding: 0.75rem;
        border-bottom: 1px solid #e8f0e4;
        font-size: 0.8rem;
    }
    .valuations-table tr:hover {
        background-color: #f4fcef;
    }
    
    .action-btn, .edit-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.4rem;
        border-radius: 0.5rem;
        color: #6d7b6c;
        transition: all 0.2s;
    }
    .action-btn:hover, .edit-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    .pagination-btn {
        padding: 0.35rem 0.7rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.75rem;
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
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    .btn-primary {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-primary:hover {
        background-color: #005a24;
    }
    .btn-outline {
        background-color: white;
        color: #3d4a3d;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-outline:hover {
        background-color: #eef6ea;
    }
    
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
    }
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    .modal-container {
        background: white;
        border-radius: 1rem;
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
    }
    .modal-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f4fcef;
        position: sticky;
        top: 0;
    }
    .modal-body {
        padding: 1.25rem;
    }
    .modal-footer {
        padding: 1rem 1.25rem;
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
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
    }
    .form-control:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    
    .total-box {
        background: #f4fcef;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        padding: 0.75rem;
        margin-top: 1rem;
        text-align: center;
    }
    .total-box .amount {
        font-size: 1.25rem;
        font-weight: 700;
        color: #006e2c;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .info-row {
        display: flex;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-label {
        width: 35%;
        font-weight: 600;
        color: #3d4a3d;
    }
    .info-value {
        width: 65%;
    }
    
    .search-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        width: 100%;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
        }
        .info-row {
            flex-direction: column;
        }
        .info-label {
            width: 100%;
            margin-bottom: 0.25rem;
        }
        .info-value {
            width: 100%;
        }
        .valuations-table {
            min-width: 650px;
        }
        .table-container {
            overflow-x: auto;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-green-800">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-red-800">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined">error</span>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Tathmini Zangu</h2>
            <p class="text-secondary text-sm">Kagua, hariri na usimamie tathmini zako zote</p>
        </div>
        <div>
            <a href="claims.php" class="btn-primary">
                <span class="material-symbols-outlined text-sm">add</span> Tathmini Mpya
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['all'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['pending'] ?? 0); ?></div>
            <div class="stat-label">Zinazosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['completed'] ?? 0); ?></div>
            <div class="stat-label">Zilizokamilika</div>
        </div>
    </div>
    
    <!-- Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b pb-2">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">Zote (<?php echo $status_counts['all'] ?? 0; ?>)</a>
        <a href="?status=pending&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">Zinazosubiri (<?php echo $status_counts['pending'] ?? 0; ?>)</a>
        <a href="?status=completed&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Zilizokamilika (<?php echo $status_counts['completed'] ?? 0; ?>)</a>
    </div>
    
    <!-- Search Bar -->
    <div class="bg-white border rounded-lg p-3">
        <form method="GET" action="" class="flex gap-2">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <input type="text" name="search" class="search-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji au mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
            <button type="submit" class="btn-primary">Tafuta</button>
            <a href="valuations.php" class="btn-outline">Reset</a>
        </form>
    </div>
    
    <!-- Pending Valuations Section -->
    <?php if ($status_filter === 'all' || $status_filter === 'pending'): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-surface-container-low border-b">
            <h3 class="font-semibold">📋 Madai Yanayosubiri Tathmini</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="valuations-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Aina ya Mali</th>
                        <th>Wilaya</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_valuations)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-6 text-secondary">Hakuna madai yanayosubiri tathmini</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($pending_valuations as $pending): ?>
                    <tr>
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($pending['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($pending['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($pending['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($pending['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $pending['property_type'] ?? '-')); ?></td>
                        <td><?php echo htmlspecialchars($pending['district'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($pending['created_at'])); ?></td>
                        <td class="text-center">
                            <a href="claims.php" class="action-btn" title="Fanya Tathmini">
                                <span class="material-symbols-outlined text-primary">edit_note</span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Completed Valuations Table -->
    <?php if ($status_filter === 'all' || $status_filter === 'completed'): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-surface-container-low border-b">
            <h3 class="font-semibold">✅ Tathmini Zangu Zilizokamilika</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="valuations-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Aina ya Mali</th>
                        <th class="text-right">Thamani ya Mali</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($valuations)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-6 text-secondary">Hakuna tathmini zilizokamilika</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($valuations as $valuation): ?>
                    <tr>
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($valuation['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($valuation['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($valuation['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($valuation['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $valuation['property_type'] ?? '-')); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right font-semibold text-primary">TZS <?php echo number_format($valuation['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($valuation['created_at'])); ?></td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <button onclick="viewValuation(<?php echo $valuation['claim_id']; ?>)" class="action-btn" title="Angalia">
                                    <span class="material-symbols-outlined">visibility</span>
                                </button>
                                <button onclick="editValuation(<?php echo $valuation['id']; ?>, <?php echo $valuation['claim_id']; ?>)" class="edit-btn" title="Hariri">
                                    <span class="material-symbols-outlined">edit</span>
                                </button>
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
        <div class="flex flex-col sm:flex-row items-center justify-between px-3 py-2 border-t gap-2">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_valuations); ?> kati ya <?php echo $total_valuations; ?>
            </div>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">«</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">»</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Info Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <div>
                <p class="font-semibold">Maelekezo</p>
                <p class="text-sm">Unaweza kuangalia na kuhariri tathmini zako zilizokamilika. Tathmini zinazohitaji kukaguliwa zitaenda kwa idara ya uhakiki.</p>
            </div>
        </div>
    </div>
</div>

<!-- Edit Valuation Modal -->
<div id="editValuationModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="font-semibold">Hariri Tathmini</h3>
            <button onclick="closeEditModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="update_valuation" value="1">
            <input type="hidden" id="edit_valuation_id" name="valuation_id">
            <input type="hidden" id="edit_claim_id" name="claim_id">
            <div class="modal-body">
                <!-- Claim Summary -->
                <div class="bg-surface-container-low p-3 rounded-lg mb-3">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div><span class="text-secondary">Namba ya Dai:</span><br><span id="edit_claim_number" class="font-semibold">-</span></div>
                        <div><span class="text-secondary">Mwombaji:</span><br><span id="edit_claimant_name" class="font-semibold">-</span></div>
                        <div><span class="text-secondary">Mradi:</span><br><span id="edit_project_name">-</span></div>
                        <div><span class="text-secondary">Aina ya Mali:</span><br><span id="edit_property_type">-</span></div>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Thamani ya Mali (TZS)</label>
                        <input type="number" id="edit_property_value" name="property_value" class="form-control" step="1000" value="0" oninput="calculateEditTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usumbufu (TZS)</label>
                        <input type="number" id="edit_disturbance_allowance" name="disturbance_allowance" class="form-control" step="1000" value="0" oninput="calculateEditTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usafiri (TZS)</label>
                        <input type="number" id="edit_transport_allowance" name="transport_allowance" class="form-control" step="1000" value="0" oninput="calculateEditTotal()">
                    </div>
                </div>
                
                <div class="total-box">
                    <div class="label">Jumla ya Fidia</div>
                    <div class="amount" id="edit_total_display">TZS 0</div>
                    <input type="hidden" name="total_compensation" id="edit_total_value" value="0">
                </div>
                
                <div class="form-group mt-3">
                    <label class="form-label">Ripoti ya Tathmini</label>
                    <textarea id="edit_valuation_report" name="valuation_report" rows="3" class="form-textarea" placeholder="Maelezo ya tathmini..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEditModal()" class="btn-outline">Ghairi</button>
                <button type="submit" class="btn-primary">Hifadhi</button>
            </div>
        </form>
    </div>
</div>

<!-- View Valuation Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="font-semibold">Maelezo ya Tathmini</h3>
            <button onclick="closeViewModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="text-center py-4">Inapakia...</div>
        </div>
    </div>
</div>

<script>
    let currentClaimId = null;
    let currentValuationId = null;
    
    function calculateEditTotal() {
        let property = parseFloat(document.getElementById('edit_property_value').value) || 0;
        let disturbance = parseFloat(document.getElementById('edit_disturbance_allowance').value) || 0;
        let transport = parseFloat(document.getElementById('edit_transport_allowance').value) || 0;
        let total = property + disturbance + transport;
        document.getElementById('edit_total_value').value = total;
        document.getElementById('edit_total_display').innerHTML = 'TZS ' + total.toLocaleString();
    }
    
    async function editValuation(valuationId, claimId) {
        currentValuationId = valuationId;
        currentClaimId = claimId;
        const modal = document.getElementById('editValuationModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const claim = data.data;
                document.getElementById('edit_valuation_id').value = valuationId;
                document.getElementById('edit_claim_id').value = claim.id;
                document.getElementById('edit_claim_number').innerHTML = claim.claim_number;
                document.getElementById('edit_claimant_name').innerHTML = claim.claimant_name;
                document.getElementById('edit_project_name').innerHTML = claim.project_name || '-';
                document.getElementById('edit_property_type').innerHTML = claim.property_type || '-';
                document.getElementById('edit_property_value').value = claim.property_value || 0;
                document.getElementById('edit_disturbance_allowance').value = claim.disturbance_allowance || 0;
                document.getElementById('edit_transport_allowance').value = claim.transport_allowance || 0;
                document.getElementById('edit_valuation_report').value = claim.valuation_report || '';
                calculateEditTotal();
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa' });
                closeEditModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao' });
            closeEditModal();
        }
    }
    
    function closeEditModal() {
        const modal = document.getElementById('editValuationModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
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
                let html = `
                    <div class="space-y-2">
                        <div class="info-row"><div class="info-label">Namba ya Dai:</div><div class="info-value font-mono">${claim.claim_number}</div></div>
                        <div class="info-row"><div class="info-label">Mwombaji:</div><div class="info-value">${claim.claimant_name}</div></div>
                        <div class="info-row"><div class="info-label">Mradi:</div><div class="info-value">${claim.project_name || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Aina ya Mali:</div><div class="info-value">${claim.property_type || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Wilaya:</div><div class="info-value">${claim.district || '-'}</div></div>
                        <hr>
                        <div class="info-row"><div class="info-label">Thamani ya Mali:</div><div class="info-value">TZS ${(claim.property_value || 0).toLocaleString()}</div></div>
                        <div class="info-row"><div class="info-label">Posho ya Usumbufu:</div><div class="info-value">TZS ${(claim.disturbance_allowance || 0).toLocaleString()}</div></div>
                        <div class="info-row"><div class="info-label">Posho ya Usafiri:</div><div class="info-value">TZS ${(claim.transport_allowance || 0).toLocaleString()}</div></div>
                        <div class="info-row"><div class="info-label">Jumla ya Fidia:</div><div class="info-value amount-positive font-bold">TZS ${(claim.total_compensation || 0).toLocaleString()}</div></div>
                        <div class="info-row"><div class="info-label">Ripoti:</div><div class="info-value">${claim.valuation_report || '-'}</div></div>
                    </div>
                `;
                document.getElementById('viewModalBody').innerHTML = html;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa' });
                closeViewModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao' });
            closeViewModal();
        }
    }
    
    function closeViewModal() {
        const modal = document.getElementById('viewModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Search debounce
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
    
    // Close modals on outside click
    document.getElementById('editValuationModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    document.getElementById('viewModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>