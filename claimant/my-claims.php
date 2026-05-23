<?php
// claimant/my-claims.php - View My Claims
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'My Claims';
$page_heading = 'Madai Yangu';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['created_at', 'claim_number', 'project_name', 'claim_amount', 'status'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'created_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = ["claimant_id = ?"];
$params = [$user_id];
$types = "i";

if ($status_filter !== 'all') {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(claim_number LIKE ? OR project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Get total claims count
$count_query = "SELECT COUNT(*) as total FROM claims $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_claims = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_claims / $per_page);

// Get claims data with valuation information
$query = "SELECT c.*, 
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          p.id as payment_id, p.amount as paid_amount, p.payment_status, p.paid_at
          FROM claims c
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN payments p ON c.id = p.claim_id
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

// Get status counts for dashboard
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims WHERE claimant_id = ? GROUP BY status";
$status_stmt = mysqli_prepare($conn, $status_query);
mysqli_stmt_bind_param($status_stmt, "i", $user_id);
mysqli_stmt_execute($status_stmt);
$status_result = mysqli_stmt_get_result($status_stmt);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

require_once __DIR__ . '/includes/claimant-header.php';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        text-align: center;
        transition: all 0.2s;
        cursor: pointer;
        text-decoration: none;
        display: block;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .stat-card.active {
        border: 2px solid #006e2c;
        background: #f4fcef;
    }
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #006e2c;
    }
    .stat-label {
        font-size: 0.7rem;
        color: #6d7b6c;
        text-transform: uppercase;
        font-weight: 600;
        margin-top: 0.25rem;
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
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    /* Payment Badge */
    .payment-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .payment-badge.completed { background: #d1fae5; color: #065f46; }
    .payment-badge.pending { background: #fef3c7; color: #92400e; }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .claims-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }
    .claims-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .claims-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .claims-table tr:hover {
        background-color: #f4fcef;
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
    }
    .view-details-btn:hover {
        background-color: #e8f0e4;
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
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
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Madai Yangu</h2>
            <p class="text-secondary text-sm mt-1">Fuatilia na uangalie hali ya madai yako yote</p>
        </div>
    </div>
    
    <!-- Success Message from Session -->
    <?php if (isset($_SESSION['success_message']) && !empty($_SESSION['success_message'])): ?>
    <div class="alert-success bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-center gap-2 text-green-800">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo $_SESSION['success_message']; ?></span>
        </div>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <!-- Status Filter Cards -->
    <div class="stats-grid">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo array_sum($status_counts); ?></div>
            <div class="stat-label">Zote</div>
        </a>
        <a href="?status=submitted&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'submitted' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo $status_counts['submitted'] ?? 0; ?></div>
            <div class="stat-label">Imewasilishwa</div>
        </a>
        <a href="?status=valuation&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'valuation' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo $status_counts['valuation'] ?? 0; ?></div>
            <div class="stat-label">Tathmini</div>
        </a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'legal_review' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo $status_counts['legal_review'] ?? 0; ?></div>
            <div class="stat-label">Uhakiki</div>
        </a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'approved' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo $status_counts['approved'] ?? 0; ?></div>
            <div class="stat-label">Imeidhinishwa</div>
        </a>
        <a href="?status=paid&search=<?php echo urlencode($search_term); ?>" class="stat-card <?php echo $status_filter === 'paid' ? 'active' : ''; ?>">
            <div class="stat-number"><?php echo $status_counts['paid'] ?? 0; ?></div>
            <div class="stat-label">Imelipwa</div>
        </a>
    </div>
    
    <!-- Search and Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="flex-1">
                <input type="text" name="search" class="filter-input" placeholder="Tafuta kwa namba ya dai au jina la mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div>
                <button type="submit" class="btn-filter w-full md:w-auto">
                    <span class="material-symbols-outlined text-sm">search</span> Tafuta
                </button>
            </div>
        </form>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <table class="claims-table">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'project_name', 'order' => $sort_by == 'project_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mradi</a></th>
                        <th>Aina ya Mali</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_amount', 'order' => $sort_by == 'claim_amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Kiasi Kinachodaiwa</a></th>
                        <th>Fidia Iliyoidhinishwa</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'status', 'order' => $sort_by == 'status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Hali</a></th>
                        <th>Malipo</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe ya Kuwasilisha</a></th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">inbox</span>
                            Hakuna madai yaliyowasilishwa
                            <div class="mt-2">
                                <a href="submit-claim.php" class="text-primary hover:underline">Wasilisha Dai Jipya</a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($claims as $claim): ?>
                    <tr id="row-<?php echo $claim['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></td>
                        <td class="font-semibold"><?php echo $claim['claim_amount'] ? 'TZS ' . number_format($claim['claim_amount'], 0, '.', ',') : '-'; ?></td>
                        <td class="font-semibold text-primary">
                            <?php 
                            $approved_amount = $claim['total_compensation'] ?? null;
                            if ($approved_amount && $approved_amount > 0) {
                                echo 'TZS ' . number_format($approved_amount, 0, '.', ',');
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
                        <td>
                            <?php if ($claim['payment_status'] == 'completed'): ?>
                            <span class="payment-badge completed">
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                Imelipwa
                            </span>
                            <?php elseif ($claim['payment_status'] == 'pending'): ?>
                            <span class="payment-badge pending">Inasubiri</span>
                            <?php else: ?>
                            <span class="text-secondary text-xs">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td class="text-center">
                            <a href="view-claim.php?id=<?php echo $claim['id']; ?>" class="view-details-btn" title="Angalia Maelezo">
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
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?>
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
                <p class="text-sm font-semibold text-blue-800">Taarifa</p>
                <p class="text-sm text-blue-700 mt-1">Ukishawasilisha dai lako, utapata taarifa kupitia arifa na barua pepe kuhusu maendeleo ya dai lako. Unaweza kuwasilisha nyaraka za kuthibitisha baada ya kuwasilisha dai.</p>
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
</script>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>