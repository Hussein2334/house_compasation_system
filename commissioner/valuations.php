<?php
// commissioner/valuations.php - Commissioner Valuations Overview
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
$page_title = 'Valuations Overview';
$page_heading = 'Muhtasari wa Tathmini';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$search_term = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(v.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.project_name LIKE ? OR vu.full_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total valuations count
$count_query = "SELECT COUNT(*) as total 
                FROM valuations v
                JOIN claims c ON v.claim_id = c.id
                JOIN users u ON c.claimant_id = u.id
                LEFT JOIN users vu ON v.valuer_id = vu.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_valuations = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 15 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_valuations / $per_page);

// Get valuations data
$query = "SELECT v.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          u.full_name as claimant_name, u.email, u.phone,
          vu.full_name as valuer_name
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
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

// Get summary statistics
$summary_query = "SELECT 
    COUNT(*) as total_valuations,
    COALESCE(SUM(property_value), 0) as total_property_value,
    COALESCE(SUM(disturbance_allowance), 0) as total_disturbance,
    COALESCE(SUM(transport_allowance), 0) as total_transport,
    COALESCE(SUM(total_compensation), 0) as total_compensation,
    COALESCE(AVG(total_compensation), 0) as avg_compensation,
    COUNT(DISTINCT valuer_id) as total_valuers,
    COUNT(DISTINCT claim_id) as total_claims_valued
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

// Get valuations by month
$monthly_query = "SELECT 
    DATE_FORMAT(v.created_at, '%Y-%m') as month,
    DATE_FORMAT(v.created_at, '%M %Y') as month_name,
    COUNT(*) as total_valuations,
    COALESCE(SUM(v.total_compensation), 0) as total_amount
    FROM valuations v
    WHERE v.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(v.created_at), MONTH(v.created_at)
    ORDER BY v.created_at ASC";
$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_valuations = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_valuations[] = $row;
}

// Get top valuers
$top_valuers_query = "SELECT 
    vu.full_name as valuer_name,
    COUNT(*) as total_valuations,
    COALESCE(SUM(v.total_compensation), 0) as total_amount
    FROM valuations v
    JOIN users vu ON v.valuer_id = vu.id
    GROUP BY v.valuer_id
    ORDER BY total_valuations DESC
    LIMIT 5";
$top_valuers_result = mysqli_query($conn, $top_valuers_query);
$top_valuers = [];
while ($row = mysqli_fetch_assoc($top_valuers_result)) {
    $top_valuers[] = $row;
}

// Get valuation by property type
$property_type_query = "SELECT 
    c.property_type,
    COUNT(*) as total_valuations,
    COALESCE(SUM(v.total_compensation), 0) as total_amount
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    WHERE c.property_type IS NOT NULL
    GROUP BY c.property_type
    ORDER BY total_valuations DESC";
$property_type_result = mysqli_query($conn, $property_type_query);
$property_type_valuations = [];
while ($row = mysqli_fetch_assoc($property_type_result)) {
    $property_type_valuations[] = $row;
}

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
    
    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    
    /* Table Styles */
    .valuations-table {
        width: 100%;
        border-collapse: collapse;
    }
    .valuations-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .valuations-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .valuations-table tr:hover {
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
    
    /* Form Inputs */
    .search-input, .form-input, .form-select {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        width: 100%;
    }
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 2px rgba(0,110,44,0.1);
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
    
    /* Chart Bars */
    .chart-bar {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }
    .chart-label {
        width: 120px;
        font-size: 0.75rem;
        color: #1e2a1e;
    }
    .chart-bar-fill {
        flex: 1;
        height: 28px;
        background: linear-gradient(90deg, #006e2c, #1eb050);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 8px;
        color: white;
        font-size: 0.7rem;
        font-weight: 600;
    }
    .chart-value {
        width: 100px;
        text-align: right;
        font-size: 0.75rem;
        font-weight: 600;
        color: #1e2a1e;
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
        .valuations-table {
            min-width: 700px;
        }
        .table-container {
            overflow-x: auto;
        }
        .filter-row {
            flex-direction: column;
        }
        .date-range {
            flex-direction: column;
        }
        .chart-label {
            width: 80px;
            font-size: 0.7rem;
        }
        .chart-value {
            width: 70px;
            font-size: 0.7rem;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Muhtasari wa Tathmini</h2>
            <p class="text-secondary text-xs">Angalia tathmini zote za mali, fidia na takwimu za ukaguzi</p>
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
            <div class="stat-number"><?php echo number_format($summary['total_valuations'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Tathmini</div>
            <div class="stat-total">Madai: <?php echo number_format($summary['total_claims_valued'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($summary['total_property_value'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Thamani ya Mali</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($summary['total_compensation'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Fidia</div>
            <div class="stat-total">Wastani: <?php echo formatCurrency($summary['avg_compensation'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($summary['total_valuers'] ?? 0); ?></div>
            <div class="stat-label">Wakaguzi</div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" action="" class="space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 filter-row">
                <div class="md:col-span-2">
                    <input type="text" name="search" class="search-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, mradi, mkaguzi..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary flex-1">Tafuta</button>
                    <a href="valuations.php" class="btn-outline">Reset</a>
                </div>
            </div>
            
            <!-- Date Range -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 date-range">
                <div>
                    <label class="text-xs text-secondary">Kuanzia Tarehe</label>
                    <input type="date" name="date_from" class="form-input" value="<?php echo $date_from; ?>">
                </div>
                <div>
                    <label class="text-xs text-secondary">Mpaka Tarehe</label>
                    <input type="date" name="date_to" class="form-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">Chuja kwa Tarehe</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Top Valuers -->
    <?php if (!empty($top_valuers)): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b">
            <h3 class="font-semibold text-sm">Wakaguzi Bora 5</h3>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <?php 
                $max_valuations = !empty($top_valuers) ? max(array_column($top_valuers, 'total_valuations')) : 1;
                foreach ($top_valuers as $valuer):
                    $percentage = ($valuer['total_valuations'] / $max_valuations) * 100;
                ?>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="font-semibold text-primary text-sm truncate" title="<?php echo htmlspecialchars($valuer['valuer_name']); ?>">
                        <?php echo htmlspecialchars(substr($valuer['valuer_name'], 0, 20)); ?>
                    </div>
                    <div class="text-2xl font-bold mt-1"><?php echo number_format($valuer['total_valuations']); ?></div>
                    <div class="text-xs text-secondary">Tathmini</div>
                    <div class="text-xs amount-positive mt-1"><?php echo formatCurrency($valuer['total_amount']); ?></div>
                    <div class="w-full bg-gray-200 rounded-full h-1 mt-2">
                        <div class="bg-primary h-1 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Valuations by Property Type -->
    <?php if (!empty($property_type_valuations)): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b">
            <h3 class="font-semibold text-sm">Tathmini kwa Aina ya Mali</h3>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <?php 
                $type_labels = [
                    'land' => 'Shamba/Ardhi',
                    'building' => 'Jengo',
                    'crop' => 'Mazao',
                    'business' => 'Biashara',
                    'other' => 'Nyingine'
                ];
                foreach ($property_type_valuations as $type):
                    $type_name = $type_labels[$type['property_type']] ?? ucfirst($type['property_type']);
                ?>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-lg font-bold text-primary"><?php echo number_format($type['total_valuations']); ?></div>
                    <div class="text-xs text-secondary">Tathmini</div>
                    <div class="text-xs amount-positive mt-1"><?php echo formatCurrency($type['total_amount']); ?></div>
                    <div class="text-xs text-secondary mt-1"><?php echo $type_name; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Monthly Valuations Trend -->
    <?php if (!empty($monthly_valuations)): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b">
            <h3 class="font-semibold text-sm">Mwenendo wa Tathmini kwa Mwezi</h3>
        </div>
        <div class="p-4">
            <?php 
            $max_amount = !empty($monthly_valuations) ? max(array_column($monthly_valuations, 'total_amount')) : 1;
            foreach ($monthly_valuations as $valuation): 
                $percentage = ($valuation['total_amount'] / $max_amount) * 100;
            ?>
            <div class="chart-bar">
                <div class="chart-label"><?php echo $valuation['month_name']; ?></div>
                <div class="flex-1">
                    <div class="h-7 rounded-lg" style="width: <?php echo $percentage; ?>%; background: #006e2c; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px;">
                        <span class="text-white text-xs font-semibold"><?php echo number_format($valuation['total_valuations']); ?> tathmini</span>
                    </div>
                </div>
                <div class="chart-value"><?php echo formatCurrency($valuation['total_amount']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Valuations Table -->
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="valuations-table">
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
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claimant_name', 'order' => $sort_by == 'claimant_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Mwombaji
                                <?php if ($sort_by == 'claimant_name'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Mradi</th>
                        <th class="text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'property_value', 'order' => $sort_by == 'property_value' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Thamani ya Mali
                                <?php if ($sort_by == 'property_value'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'total_compensation', 'order' => $sort_by == 'total_compensation' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Jumla ya Fidia
                                <?php if ($sort_by == 'total_compensation'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Mkaguzi</th>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="sort-link">
                                Tarehe ya Tathmini
                                <?php if ($sort_by == 'created_at'): ?>
                                    <span class="material-symbols-outlined text-sm"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Hali ya Dai</th>
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
                        <td class="text-right amount-positive"><?php echo formatCurrency($valuation['property_value']); ?></td>
                        <td class="text-right amount-positive"><?php echo formatCurrency($valuation['total_compensation']); ?></td>
                        <td><?php echo htmlspecialchars($valuation['valuer_name'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($valuation['created_at'])); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $valuation['claim_status']; ?>">
                                <?php 
                                $status_labels = [
                                    'submitted' => 'Yaliyowasilishwa',
                                    'valuation' => 'Tathmini',
                                    'legal_review' => 'Uhakiki',
                                    'approved' => 'Imeidhinishwa',
                                    'rejected' => 'Imekataliwa',
                                    'paid' => 'Imelipwa'
                                ];
                                echo $status_labels[$valuation['claim_status']] ?? ucfirst($valuation['claim_status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="action-btn" onclick="viewValuationDetails(<?php echo $valuation['id']; ?>)" title="Angalia Maelezo">
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
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_valuations); ?> kati ya <?php echo $total_valuations; ?>
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
                <p class="text-xs mt-1">Unaweza kuona muhtasari wa tathmini zote za mali, kuchuja kwa tarehe, na kutafuta kwa namba ya dai, jina la mwombaji, au mkaguzi. Bonyeza kwenye tathmini kuona maelezo kamili.</p>
            </div>
        </div>
    </div>
</div>

<!-- Valuation Details Modal -->
<div id="valuationModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="text-lg font-semibold">Maelezo ya Tathmini</h3>
            <button onclick="closeModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="valuationModalBody">
            <div class="text-center py-4">Inapakia...</div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal()" class="btn-outline">Funga</button>
        </div>
    </div>
</div>

<script>
    let currentValuationId = null;
    
    // View valuation details
    async function viewValuationDetails(valuationId) {
        if (!valuationId || valuationId <= 0) {
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Valuation ID si sahihi' });
            return;
        }
        
        currentValuationId = valuationId;
        const modal = document.getElementById('valuationModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ 
            title: 'Inapakia...', 
            allowOutsideClick: false, 
            didOpen: () => Swal.showLoading()
        });
        
        try {
            const response = await fetch(`get-valuation-details.php?id=${valuationId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success && data.data) {
                const valuation = data.data;
                document.getElementById('valuationModalBody').innerHTML = `
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Taarifa za Dai</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Namba ya Dai:</span><span class="font-mono">${escapeHtml(valuation.claim_number)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Mwombaji:</span><span>${escapeHtml(valuation.claimant_name)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Barua Pepe:</span><span>${escapeHtml(valuation.email)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Simu:</span><span>${escapeHtml(valuation.phone || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Mradi:</span><span>${escapeHtml(valuation.project_name || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Wilaya:</span><span>${escapeHtml(valuation.district || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Aina ya Mali:</span><span>${getPropertyTypeLabel(valuation.property_type)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Ukubwa:</span><span>${valuation.property_size ? valuation.property_size + ' sqm' : '-'}</span></div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Maelezo ya Tathmini</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Thamani ya Mali:</span><span class="amount-positive">${formatCurrencyNumber(valuation.property_value)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Posho ya Usumbufu:</span><span class="amount-positive">${formatCurrencyNumber(valuation.disturbance_allowance)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Posho ya Usafiri:</span><span class="amount-positive">${formatCurrencyNumber(valuation.transport_allowance)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium font-bold">Jumla ya Fidia:</span><span class="amount-positive font-bold">${formatCurrencyNumber(valuation.total_compensation)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Mkaguzi:</span><span>${escapeHtml(valuation.valuer_name || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Tarehe ya Tathmini:</span><span>${new Date(valuation.created_at).toLocaleString()}</span></div>
                            </div>
                        </div>
                        
                        ${valuation.valuation_report ? `
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Ripoti ya Tathmini</h4>
                            <p class="text-sm whitespace-pre-wrap">${escapeHtml(valuation.valuation_report)}</p>
                        </div>
                        ` : ''}
                        
                        ${valuation.description ? `
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Maelezo ya Mwombaji</h4>
                            <p class="text-sm whitespace-pre-wrap">${escapeHtml(valuation.description)}</p>
                        </div>
                        ` : ''}
                        
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Hali ya Dai</h4>
                            <div class="flex justify-between py-1 border-b"><span class="font-medium">Hali:</span><span><span class="status-badge status-${valuation.claim_status}">${getStatusLabel(valuation.claim_status)}</span></span></div>
                            ${valuation.decision_date ? `<div class="flex justify-between py-1 border-b"><span class="font-medium">Tarehe ya Uamuzi:</span><span>${new Date(valuation.decision_date).toLocaleDateString()}</span></div>` : ''}
                        </div>
                    </div>
                `;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata maelezo ya tathmini' });
                closeModal();
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.close();
            Swal.fire({ 
                icon: 'error', 
                title: 'Hitilafu', 
                text: 'Tatizo la mtandao: ' + error.message
            });
            closeModal();
        }
    }
    
    function getPropertyTypeLabel(type) {
        const labels = {
            'land': 'Shamba/Ardhi',
            'building': 'Jengo',
            'crop': 'Mazao',
            'business': 'Biashara',
            'other': 'Nyingine'
        };
        return labels[type] || type || '-';
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
    
    function formatCurrencyNumber(amount) {
        if (!amount) return 'TZS 0';
        return 'TZS ' + new Intl.NumberFormat().format(amount);
    }
    
    function closeModal() {
        const modal = document.getElementById('valuationModal');
        if (modal) {
            modal.classList.remove('show');
        }
        document.body.style.overflow = '';
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Close modal when clicking outside
    document.getElementById('valuationModal')?.addEventListener('click', function(e) {
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