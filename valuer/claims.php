<?php
// valuer/claims.php - View Claims that Need Valuation (Separate from valuations.php)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// ========== AJAX HANDLER FOR GETTING CLAIM DETAILS ==========
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $conn = getDB();
    $claim_id = intval($_GET['claim_id']);
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin
              FROM claims c 
              JOIN users u ON c.claimant_id = u.id 
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claim = mysqli_fetch_assoc($result);
    
    if ($claim) {
        echo json_encode(['success' => true, 'data' => $claim]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Claim not found']);
    }
    exit();
}

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
$page_title = 'Claims for Valuation';
$page_heading = 'Madai Yanayohitaji Tathmini';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query - Only claims with status 'valuation' that have NO valuation yet
$where_clauses = ["c.status = 'valuation'"];
$params = [];
$types = "";

// Exclude claims that already have valuations
$where_clauses[] = "NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = c.id)";

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

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
$total_pages = $total_claims > 0 ? ceil($total_claims / $per_page) : 1;

// Get claims data
$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin
          FROM claims c
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

$claims = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claims[] = $row;
}

// Get statistics
$total_pending = $total_claims;
$my_completed_count = 0;
if (!$is_super_admin) {
    $completed_query = "SELECT COUNT(*) as count FROM valuations WHERE valuer_id = ?";
    $completed_stmt = mysqli_prepare($conn, $completed_query);
    mysqli_stmt_bind_param($completed_stmt, "i", $user_id);
    mysqli_stmt_execute($completed_stmt);
    $completed_result = mysqli_stmt_get_result($completed_stmt);
    $my_completed_count = mysqli_fetch_assoc($completed_result)['count'];
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
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
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
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
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    
    /* Table Styles */
    .claims-table {
        width: 100%;
        border-collapse: collapse;
    }
    .claims-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .claims-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .claims-table tr:hover {
        background-color: #eef6ea;
    }
    
    /* Action Button */
    .action-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 0.5rem;
        color: #6d7b6c;
        transition: all 0.2s;
    }
    .action-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 1rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1.5rem;
    }
    .filter-input {
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
        width: 100%;
    }
    .filter-input:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .btn-search {
        background-color: #006e2c;
        color: white;
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .btn-search:hover {
        background-color: #005a24;
    }
    .btn-refresh {
        background-color: white;
        color: #3d4a3d;
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-refresh:hover {
        background-color: #eef6ea;
    }
    
    .pagination-btn {
        padding: 0.375rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        transition: all 0.15s ease;
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
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
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
    
    .total-box {
        background: #f4fcef;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-top: 1rem;
        text-align: center;
    }
    .total-box .amount {
        font-size: 1.5rem;
        font-weight: 700;
        color: #006e2c;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .filter-actions {
            flex-direction: column;
        }
        .btn-search, .btn-refresh {
            width: 100%;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-center gap-2 text-green-800">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-center gap-2 text-red-800">
            <span class="material-symbols-outlined">error</span>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Madai Yanayohitaji Tathmini</h2>
            <p class="text-secondary text-sm mt-1">Kagua na ufanye tathmini kwa madai ambayo hayajatathminiwa</p>
        </div>
        <div>
            <a href="valuations.php" class="inline-flex items-center gap-2 px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">real_estate_agent</span>
                Tathmini Zangu
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #fed7aa; color: #9a3412;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format($total_pending); ?></div>
            <div class="stat-label">Madai Yanayosubiri Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">check_circle</span>
            </div>
            <div class="stat-value"><?php echo number_format($my_completed_count); ?></div>
            <div class="stat-label">Tathmini Zangu Zilizokamilika</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">real_estate_agent</span>
            </div>
            <div class="stat-value"><?php echo number_format($total_claims); ?></div>
            <div class="stat-label">Zinazohitaji Ukaguzi Sasa</div>
        </div>
    </div>
    
    <!-- Search Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-3">
            <div class="flex-1">
                <input type="text" name="search" class="filter-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex gap-2 filter-actions">
                <button type="submit" class="btn-search">
                    <span class="material-symbols-outlined text-sm">search</span> Tafuta
                </button>
                <a href="claims.php" class="btn-refresh inline-flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-sm">refresh</span> Onyesha Zote
                </a>
            </div>
        </form>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="claims-table">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mwombaji</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'project_name', 'order' => $sort_by == 'project_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mradi</a></th>
                        <th>Aina ya Mali</th>
                        <th>Wilaya</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe</a></th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">check_circle</span>
                            Hakuna madai yanayohitaji tathmini kwa sasa
                            <div class="mt-2 text-sm">Madai yote yaliyopo yameshatathminiwa</div>
                            <div class="mt-1 text-xs text-blue-600">Ili kuongeza dai linalohitaji tathmini, badilisha status ya dai kuwa 'valuation'</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($claims as $claim): ?>
                    <tr id="row-<?php echo $claim['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['phone'] ?? '-'); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></td>
                        <td><?php echo htmlspecialchars($claim['district'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td class="text-center">
                            <button type="button" class="action-btn" onclick="startValuation(<?php echo $claim['id']; ?>)" title="Fanya Tathmini">
                                <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                            </button>
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
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">Inayofuata</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Maelekezo ya Tathmini</p>
                <ul class="text-sm text-blue-700 mt-1 space-y-1 list-disc list-inside">
                    <li>Kagua taarifa zote za mwombaji na nyaraka za mali</li>
                    <li>Thamini mali kwa kutumia thamani ya soko na kanuni za serikali</li>
                    <li>Jaza thamani ya mali, posho ya usumbufu, na posho ya usafiri</li>
                    <li>Toa maelezo ya kina katika ripoti ya tathmini</li>
                    <li>Baada ya kuwasilisha, tathmini itaenda kwa idara ya uhakiki</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Valuation Modal -->
<div id="valuationModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">real_estate_agent</span>
                <h3 class="text-lg font-semibold">Fanya Tathmini ya Mali</h3>
            </div>
            <button onclick="closeValuationModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="valuationForm" method="POST" action="process-valuation.php">
            <input type="hidden" id="valuation_claim_id" name="claim_id">
            <div class="modal-body">
                <!-- Claim Information Summary -->
                <div class="bg-surface-container-low p-4 rounded-lg mb-4">
                    <h4 class="font-semibold text-sm mb-3">Taarifa za Dai</h4>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-secondary">Namba ya Dai:</span><br><span id="view_claim_number" class="font-mono font-semibold">-</span></div>
                        <div><span class="text-secondary">Mwombaji:</span><br><span id="view_claimant_name" class="font-semibold">-</span></div>
                        <div><span class="text-secondary">Barua Pepe:</span><br><span id="view_email">-</span></div>
                        <div><span class="text-secondary">Simu:</span><br><span id="view_phone">-</span></div>
                        <div><span class="text-secondary">Mradi:</span><br><span id="view_project_name">-</span></div>
                        <div><span class="text-secondary">Wilaya:</span><br><span id="view_district">-</span></div>
                        <div><span class="text-secondary">Aina ya Mali:</span><br><span id="view_property_type">-</span></div>
                        <div><span class="text-secondary">Ukubwa:</span><br><span id="view_property_size">-</span></div>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Thamani ya Mali (TZS)</label>
                        <input type="number" name="property_value" id="property_value" class="form-control" step="1000" value="0" oninput="calculateTotal()" required>
                        <div class="form-hint">Thamani ya jumla ya ardhi na majengo</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usumbufu (TZS)</label>
                        <input type="number" name="disturbance_allowance" id="disturbance_allowance" class="form-control" step="1000" value="0" oninput="calculateTotal()">
                        <div class="form-hint">Kwa usumbufu wa makazi/biashara</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usafiri (TZS)</label>
                        <input type="number" name="transport_allowance" id="transport_allowance" class="form-control" step="1000" value="0" oninput="calculateTotal()">
                        <div class="form-hint">Gharama za kubebea mali</div>
                    </div>
                </div>
                
                <div class="total-box">
                    <div class="label">Jumla ya Fidia Inayopendekezwa</div>
                    <div class="amount" id="total_compensation_display">TZS 0</div>
                    <input type="hidden" name="total_compensation" id="total_compensation" value="0">
                </div>
                
                <div class="form-group mt-4">
                    <label class="form-label">Ripoti ya Tathmini</label>
                    <textarea name="valuation_report" id="valuation_report" rows="4" class="form-control form-textarea" placeholder="Eleza mbinu ya tathmini, vigezo vilivyotumika, na taarifa nyingine muhimu..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeValuationModal()" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Wasilisha Tathmini</button>
            </div>
        </form>
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
    
    // Start valuation for a claim
    async function startValuation(claimId) {
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
                document.getElementById('view_email').innerHTML = claim.email;
                document.getElementById('view_phone').innerHTML = claim.phone || '-';
                document.getElementById('view_project_name').innerHTML = claim.project_name || '-';
                document.getElementById('view_district').innerHTML = claim.district || '-';
                document.getElementById('view_property_type').innerHTML = claim.property_type ? claim.property_type.replace('_', ' ') : '-';
                document.getElementById('view_property_size').innerHTML = claim.property_size ? claim.property_size + ' sqm' : '-';
                
                // Reset valuation fields
                document.getElementById('property_value').value = 0;
                document.getElementById('disturbance_allowance').value = 0;
                document.getElementById('transport_allowance').value = 0;
                document.getElementById('valuation_report').value = '';
                calculateTotal();
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata taarifa za dai', confirmButtonColor: '#006e2c' });
                closeValuationModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao. Tafadhali jaribu tena.', confirmButtonColor: '#006e2c' });
            closeValuationModal();
        }
    }
    
    function closeValuationModal() {
        const modal = document.getElementById('valuationModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Form validation before submit
    const valuationForm = document.getElementById('valuationForm');
    if (valuationForm) {
        valuationForm.addEventListener('submit', function(e) {
            const propertyValue = document.getElementById('property_value').value;
            
            if (!propertyValue || parseFloat(propertyValue) <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu ya Uthibitishaji',
                    text: 'Tafadhali ingiza thamani ya mali',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            // Show confirmation
            e.preventDefault();
            Swal.fire({
                title: 'Thibitisha Tathmini',
                html: `Je, una uhakika unataka kuwasilisha tathmini hii?<br><br>
                       <strong>Thamani ya Mali:</strong> TZS ${parseFloat(document.getElementById('property_value').value || 0).toLocaleString()}<br>
                       <strong>Jumla ya Fidia:</strong> TZS ${parseFloat(document.getElementById('total_compensation').value || 0).toLocaleString()}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Wasilisha',
                cancelButtonText: 'Hapana'
            }).then((result) => {
                if (result.isConfirmed) {
                    valuationForm.submit();
                }
            });
            
            return false;
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
    
    // Close modal when clicking outside
    document.getElementById('valuationModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeValuationModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>