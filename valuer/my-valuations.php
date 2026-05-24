<?php
// valuer/my-valuations.php - View My Valuations
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is valuer or super admin
if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    if ($_SESSION['role'] === 'claimant') {
        header("Location: ../claimant/dashboard.php");
    } elseif ($_SESSION['role'] === 'finance_officer') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit();
}

// Set page variables
$page_title = 'My Valuations';
$page_heading = 'Tathmini Zangu';

// Get database connection
$conn = getDB();

// Check connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['created_at', 'claim_number', 'project_name', 'total_compensation'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'created_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

// Only show valuations by this valuer
if (!$is_super_admin) {
    $where_clauses[] = "v.valuer_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where_clauses[] = "c.status = 'valuation'";
    } elseif ($status_filter === 'completed') {
        $where_clauses[] = "c.status != 'valuation'";
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
if ($count_stmt && !empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_valuations = mysqli_fetch_assoc($count_result)['total'];
} else {
    $total_valuations = 0;
}

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ($total_valuations > 0) ? ceil($total_valuations / $per_page) : 1;

// Get valuations data
$query = "SELECT v.*, 
          c.claim_number, c.project_name, c.status as claim_status, 
          c.property_type, c.property_size, c.district,
          u.full_name as claimant_name, u.email, u.phone
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          $where_sql
          ORDER BY ";

if ($sort_by === 'claim_number') {
    $query .= "c.claim_number $sort_order";
} elseif ($sort_by === 'project_name') {
    $query .= "c.project_name $sort_order";
} elseif ($sort_by === 'total_compensation') {
    $query .= "v.total_compensation $sort_order";
} else {
    $query .= "v.created_at $sort_order";
}

$query .= " LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $valuations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $valuations[] = $row;
    }
} else {
    $valuations = [];
}

// Get summary statistics
$summary = ['total_valuations' => 0, 'total_compensation' => 0, 'avg_compensation' => 0, 'total_property_value' => 0];
if (!$is_super_admin) {
    $summary_query = "SELECT 
        COUNT(v.id) as total_valuations,
        COALESCE(SUM(v.total_compensation), 0) as total_compensation,
        COALESCE(AVG(v.total_compensation), 0) as avg_compensation,
        COALESCE(SUM(v.property_value), 0) as total_property_value
        FROM valuations v
        WHERE v.valuer_id = ?";
    $summary_stmt = mysqli_prepare($conn, $summary_query);
    if ($summary_stmt) {
        mysqli_stmt_bind_param($summary_stmt, "i", $user_id);
        mysqli_stmt_execute($summary_stmt);
        $summary_result = mysqli_stmt_get_result($summary_stmt);
        $summary = mysqli_fetch_assoc($summary_result);
    }
}

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
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
        font-size: 1.5rem;
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
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .valuations-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }
    .valuations-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .valuations-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .valuations-table tr:hover {
        background-color: #f4fcef;
    }
    
    .filter-bar {
        background: white;
        border-radius: 1rem;
        padding: 1rem;
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
    .btn-filter {
        background-color: #006e2c;
        color: white;
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .btn-filter:hover {
        background-color: #005a24;
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
    
    .view-details-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 0.5rem;
        color: #006e2c;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }
    .view-details-btn:hover {
        background-color: #e8f0e4;
    }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex items-center gap-3">
        <a href="dashboard.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Tathmini Zangu</h2>
            <p class="text-secondary text-sm mt-1">Angalia tathmini zote ulizofanya na fidia uliyopendekeza</p>
        </div>
    </div>
    
    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">real_estate_agent</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_valuations'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_compensation'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Fidia</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">calculate</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['avg_compensation'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Wastani wa Fidia</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">real_estate_agent</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_property_value'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Thamani</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Hali</label>
                <select name="status" class="filter-select">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>-- Zote --</option>
                    <option value="valuation" <?php echo $status_filter === 'valuation' ? 'selected' : ''; ?>>Inachakatwa</option>
                    <option value="legal_review" <?php echo $status_filter === 'legal_review' ? 'selected' : ''; ?>>Uhakiki</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Imeidhinishwa</option>
                    <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Imelipwa</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Namba ya dai, mradi, au mwombaji..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div>
                <button type="submit" class="btn-filter w-full">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Valuations Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <table class="valuations-table">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Aina ya Mali</th>
                        <th>Wilaya</th>
                        <th class="text-right">Thamani ya Mali</th>
                        <th class="text-right">Posho ya Usumbufu</th>
                        <th class="text-right">Posho ya Usafiri</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th>Hali</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe</a></th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($valuations)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">real_estate_agent</span>
                            Hakuna tathmini zilizopatikana
                            <?php if (!$is_super_admin): ?>
                            <div class="text-sm mt-2">Bado hujafanya tathmini yoyote</div>
                            <?php endif; ?>
                        </td>
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
                        <td><?php echo htmlspecialchars($valuation['district'] ?? '-'); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['disturbance_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['transport_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive font-bold">TZS <?php echo number_format($valuation['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td>
                            <span class="status-badge <?php echo $valuation['claim_status']; ?>">
                                <?php 
                                $status_labels = [
                                    'valuation' => 'Inachakatwa',
                                    'legal_review' => 'Uhakiki',
                                    'approved' => 'Imeidhinishwa',
                                    'paid' => 'Imelipwa'
                                ];
                                echo $status_labels[$valuation['claim_status']] ?? ucfirst($valuation['claim_status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($valuation['created_at'])); ?></td>
                        <td class="text-center">
                            <a href="view-valuation.php?id=<?php echo $valuation['id']; ?>" class="view-details-btn" title="Angalia Maelezo">
                                <span class="material-symbols-outlined">visibility</span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-outline-variant bg-surface-container-low gap-3">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_valuations); ?> kati ya <?php echo $total_valuations; ?>
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
    
    <!-- Info Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Taarifa kwa Wakaguzi</p>
                <p class="text-sm text-blue-700 mt-1">Tathmini zako zote zinaonekana hapa. Baada ya kuwasilisha tathmini, itaenda kwa idara ya uhakiki kwa kukaguliwa zaidi.</p>
            </div>
        </div>
    </div>
    
</div>

<script>
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

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>