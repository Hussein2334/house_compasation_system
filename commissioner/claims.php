<?php
// commissioner/claims.php - Commissioner Claims Overview
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is commissioner
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Claims Overview';
$page_heading = 'Muhtasari wa Madai';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

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

// Pagination - 15 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
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
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$claims = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claims[] = $row;
}

// Get status counts for filters
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
$status_counts['all'] = 0;
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
    $status_counts['all'] += $row['count'];
}
$status_counts['submitted'] = $status_counts['submitted'] ?? 0;
$status_counts['valuation'] = $status_counts['valuation'] ?? 0;
$status_counts['legal_review'] = $status_counts['legal_review'] ?? 0;
$status_counts['approved'] = $status_counts['approved'] ?? 0;
$status_counts['rejected'] = $status_counts['rejected'] ?? 0;
$status_counts['paid'] = $status_counts['paid'] ?? 0;

// Get financial totals
$financial_query = "SELECT 
    COALESCE(SUM(v.total_compensation), 0) as total_compensation,
    COALESCE(SUM(CASE WHEN c.status = 'approved' THEN v.total_compensation ELSE 0 END), 0) as approved_amount,
    COALESCE(SUM(CASE WHEN c.status = 'paid' THEN v.total_compensation ELSE 0 END), 0) as paid_amount
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.status IN ('approved', 'paid', 'legal_review')";
$financial_result = mysqli_query($conn, $financial_query);
$financial_totals = mysqli_fetch_assoc($financial_result);

require_once __DIR__ . '/includes/commissioner-header.php';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        transition: all 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e2a1e;
    }
    .stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #6d7b6c;
        font-weight: 600;
        margin-top: 0.25rem;
    }
    .stat-total {
        font-size: 0.7rem;
        color: #006e2c;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-submitted { background: #e9d5ff; color: #6b21a5; }
    .status-valuation { background: #fed7aa; color: #9a3412; }
    .status-legal_review { background: #cffafe; color: #0891b2; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .status-paid { background: #d1fae5; color: #006e2c; }
    
    /* Filter Tabs */
    .filter-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        border-bottom: 1px solid #e8f0e4;
        padding-bottom: 0.5rem;
    }
    .filter-tab {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .filter-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .filter-tab:not(.active) {
        background-color: white;
        color: #3d4a3d;
        border: 1px solid #bccab9;
    }
    .filter-tab:not(.active):hover {
        background-color: #eef6ea;
    }
    
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
        background-color: #f4fcef;
    }
    
    /* Sortable Links */
    .sort-link {
        text-decoration: none;
        color: #3d4a3d;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    .sort-link:hover {
        color: #006e2c;
    }
    
    /* Search Input */
    .search-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        width: 100%;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        gap: 0.25rem;
        justify-content: center;
        margin-top: 1rem;
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
    
    /* Buttons */
    .btn-primary {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
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
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        text-decoration: none;
    }
    .btn-outline:hover {
        background-color: #eef6ea;
    }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    /* Modal */
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
        border-radius: 1rem;
        width: 90%;
        max-width: 800px;
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
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .filter-tabs {
            flex-direction: column;
        }
        .filter-tab {
            justify-content: center;
        }
        .claims-table {
            min-width: 700px;
        }
        .table-container {
            overflow-x: auto;
        }
        .filter-actions {
            flex-direction: column;
        }
        .filter-actions .btn-primary,
        .filter-actions .btn-outline {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Muhtasari wa Madai</h2>
            <p class="text-secondary text-xs">Angalia madai yote, tathmini na malipo</p>
        </div>
        <div class="flex gap-2">
            <a href="reports.php?type=claims" class="btn-outline">
                <span class="material-symbols-outlined text-sm">analytics</span>
                Ripoti za Kina
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['all']); ?></div>
            <div class="stat-label">Jumla ya Madai</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['submitted']); ?></div>
            <div class="stat-label">Yaliyowasilishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['valuation']); ?></div>
            <div class="stat-label">Katika Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['legal_review']); ?></div>
            <div class="stat-label">Uhakiki wa Kisheria</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['approved']); ?></div>
            <div class="stat-label">Yaliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['rejected']); ?></div>
            <div class="stat-label">Yaliyokataliwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($status_counts['paid']); ?></div>
            <div class="stat-label">Yaliyolipwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_totals['total_compensation'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Fidia</div>
            <div class="stat-total">Idhinishwa: <?php echo formatCurrency($financial_totals['approved_amount'] ?? 0); ?></div>
        </div>
    </div>
    
    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            Zote (<?php echo number_format($status_counts['all']); ?>)
        </a>
        <a href="?status=submitted&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'submitted' ? 'active' : ''; ?>">
            Yaliyowasilishwa (<?php echo number_format($status_counts['submitted']); ?>)
        </a>
        <a href="?status=valuation&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'valuation' ? 'active' : ''; ?>">
            Tathmini (<?php echo number_format($status_counts['valuation']); ?>)
        </a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'legal_review' ? 'active' : ''; ?>">
            Uhakiki (<?php echo number_format($status_counts['legal_review']); ?>)
        </a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'approved' ? 'active' : ''; ?>">
            Yaliyoidhinishwa (<?php echo number_format($status_counts['approved']); ?>)
        </a>
        <a href="?status=rejected&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">
            Yaliyokataliwa (<?php echo number_format($status_counts['rejected']); ?>)
        </a>
        <a href="?status=paid&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'paid' ? 'active' : ''; ?>">
            Yaliyolipwa (<?php echo number_format($status_counts['paid']); ?>)
        </a>
    </div>
    
    <!-- Search Bar -->
    <div class="bg-white border rounded-lg p-3">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-2">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="flex-1">
                <input type="text" name="search" class="search-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex gap-2 filter-actions">
                <button type="submit" class="btn-primary">Tafuta</button>
                <a href="claims.php" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="claims-table">
                <thead>
                    <tr>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Namba ya Dai
                                <?php if ($sort_by == 'claim_number'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Mwombaji
                                <?php if ($sort_by == 'full_name'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'project_name', 'order' => $sort_by == 'project_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Mradi
                                <?php if ($sort_by == 'project_name'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Aina ya Mali</th>
                        <th class="text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'total_compensation', 'order' => $sort_by == 'total_compensation' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Fidia
                                <?php if ($sort_by == 'total_compensation'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Mkaguzi</th>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Tarehe
                                <?php if ($sort_by == 'created_at'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Hali</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">gavel</span>
                            Hakuna madai yanayoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($claims as $claim): ?>
                    <tr id="row-<?php echo $claim['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></td>
                        <td class="text-right">
                            <?php if ($claim['total_compensation'] > 0): ?>
                                <span class="amount-positive">TZS <?php echo number_format($claim['total_compensation'], 0, '.', ','); ?></span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $claim['status']; ?>">
                                <?php 
                                $status_labels = [
                                    'submitted' => 'Yaliyowasilishwa',
                                    'valuation' => 'Tathmini',
                                    'legal_review' => 'Uhakiki',
                                    'approved' => 'Imeidhinishwa',
                                    'rejected' => 'Imekataliwa',
                                    'paid' => 'Imelipwa'
                                ];
                                echo $status_labels[$claim['status']] ?? ucfirst($claim['status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="action-btn" onclick="viewClaimDetails(<?php echo $claim['id']; ?>)" title="Angalia Maelezo">
                                <span class="material-symbols-outlined text-primary">visibility</span>
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
        <div class="flex flex-col sm:flex-row items-center justify-between px-4 py-3 border-t gap-2">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">«</a>
                <?php endif; ?>
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">»</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <div>
                <p class="font-semibold text-sm">Maelekezo</p>
                <p class="text-xs mt-1">Unaweza kuona muhtasari wa madai yote, kuchuja kwa hali, na kutafuta. Bonyeza kwenye dai kuona maelezo kamili.</p>
            </div>
        </div>
    </div>
</div>

<!-- Claim Details Modal -->
<div id="claimModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="text-lg font-semibold">Maelezo ya Dai</h3>
            <button onclick="closeModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="claimModalBody">
            <div class="text-center py-4">Inapakia...</div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal()" class="btn-outline">Funga</button>
        </div>
    </div>
</div>

<script>
    let currentClaimId = null;
    
    // View claim details
    async function viewClaimDetails(claimId) {
        currentClaimId = claimId;
        const modal = document.getElementById('claimModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`get-claim-details.php?id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success && data.data) {
                const claim = data.data;
                let html = `
                    <div class="space-y-4">
                        <!-- Claim Information -->
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Taarifa za Dai</h4>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div><span class="text-secondary">Namba ya Dai:</span><br><span class="font-mono font-semibold">${escapeHtml(claim.claim_number)}</span></div>
                                <div><span class="text-secondary">Mwombaji:</span><br><span class="font-semibold">${escapeHtml(claim.claimant_name)}</span></div>
                                <div><span class="text-secondary">Barua Pepe:</span><br>${escapeHtml(claim.email)}</div>
                                <div><span class="text-secondary">Simu:</span><br>${escapeHtml(claim.phone || '-')}</div>
                                <div><span class="text-secondary">Mradi:</span><br>${escapeHtml(claim.project_name || '-')}</div>
                                <div><span class="text-secondary">Wilaya:</span><br>${escapeHtml(claim.district || '-')}</div>
                                <div><span class="text-secondary">Kata:</span><br>${escapeHtml(claim.ward || '-')}</div>
                                <div><span class="text-secondary">Kijiji:</span><br>${escapeHtml(claim.village || '-')}</div>
                                <div><span class="text-secondary">Aina ya Mali:</span><br>${claim.property_type ? claim.property_type.replace('_', ' ') : '-'}</div>
                                <div><span class="text-secondary">Ukubwa:</span><br>${claim.property_size ? claim.property_size + ' sqm' : '-'}</div>
                                <div><span class="text-secondary">Tarehe ya Kuwasilisha:</span><br>${new Date(claim.created_at).toLocaleDateString()}</div>
                                <div><span class="text-secondary">Hali:</span><br><span class="status-badge status-${claim.status}">${getStatusLabel(claim.status)}</span></div>
                            </div>
                        </div>
                        
                        <!-- Valuation Details -->
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Taarifa za Tathmini</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between"><span class="text-secondary">Thamani ya Mali:</span><span>TZS ${(claim.property_value || 0).toLocaleString()}</span></div>
                                <div class="flex justify-between"><span class="text-secondary">Posho ya Usumbufu:</span><span>TZS ${(claim.disturbance_allowance || 0).toLocaleString()}</span></div>
                                <div class="flex justify-between"><span class="text-secondary">Posho ya Usafiri:</span><span>TZS ${(claim.transport_allowance || 0).toLocaleString()}</span></div>
                                <div class="flex justify-between pt-2 border-t"><span class="font-semibold">Jumla ya Fidia:</span><span class="font-bold text-primary">TZS ${(claim.total_compensation || 0).toLocaleString()}</span></div>
                                <div class="flex justify-between"><span class="text-secondary">Mkaguzi:</span><span>${escapeHtml(claim.valuer_name || '-')}</span></div>
                                ${claim.decision_date ? `<div class="flex justify-between"><span class="text-secondary">Tarehe ya Uamuzi:</span><span>${new Date(claim.decision_date).toLocaleDateString()}</span></div>` : ''}
                            </div>
                        </div>
                        
                        <!-- Valuation Report -->
                        ${claim.valuation_report ? `
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Ripoti ya Tathmini</h4>
                            <p class="text-sm whitespace-pre-wrap">${escapeHtml(claim.valuation_report)}</p>
                        </div>
                        ` : ''}
                        
                        <!-- Claim Description -->
                        ${claim.description ? `
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Maelezo ya Mwombaji</h4>
                            <p class="text-sm whitespace-pre-wrap">${escapeHtml(claim.description)}</p>
                        </div>
                        ` : ''}
                    </div>
                `;
                document.getElementById('claimModalBody').innerHTML = html;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata taarifa za dai' });
                closeModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao: ' + error.message });
            closeModal();
        }
    }
    
    function getStatusLabel(status) {
        const labels = {
            'submitted': 'Yaliyowasilishwa',
            'valuation': 'Katika Tathmini',
            'legal_review': 'Uhakiki wa Kisheria',
            'approved': 'Imeidhinishwa',
            'rejected': 'Imekataliwa',
            'paid': 'Imelipwa'
        };
        return labels[status] || status;
    }
    
    function closeModal() {
        const modal = document.getElementById('claimModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Close modal when clicking outside
    document.getElementById('claimModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
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
</script>

<?php require_once __DIR__ . '/includes/commissioner-footer.php'; ?>