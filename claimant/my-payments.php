<?php
// claimant/my-payments.php - View My Payments
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
    if ($_SESSION['role'] === 'super_admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit();
}

// Set page variables
$page_title = 'My Payments';
$page_heading = 'Malipo Yangu';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'paid_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['paid_at', 'amount', 'payment_status', 'claim_number'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'paid_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = ["c.claimant_id = ?"];
$params = [$user_id];
$types = "i";

if ($status_filter !== 'all') {
    $where_clauses[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR c.project_name LIKE ? OR p.transaction_reference LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Get total payments count
$count_query = "SELECT COUNT(*) as total 
                FROM payments p
                JOIN claims c ON p.claim_id = c.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_payments = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_payments / $per_page);

// Get payments data
$query = "SELECT p.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          v.total_compensation as approved_amount
          FROM payments p
          JOIN claims c ON p.claim_id = c.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          $where_sql
          ORDER BY ";

if ($sort_by === 'claim_number') {
    $query .= "c.claim_number $sort_order";
} elseif ($sort_by === 'amount') {
    $query .= "p.amount $sort_order";
} elseif ($sort_by === 'payment_status') {
    $query .= "p.payment_status $sort_order";
} else {
    $query .= "p.paid_at $sort_order";
}

$query .= " LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$payments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $payments[] = $row;
}

// Get payment status counts
$status_counts = [];
$status_query = "SELECT p.payment_status, COUNT(*) as count 
                 FROM payments p
                 JOIN claims c ON p.claim_id = c.id
                 WHERE c.claimant_id = ?
                 GROUP BY p.payment_status";
$status_stmt = mysqli_prepare($conn, $status_query);
mysqli_stmt_bind_param($status_stmt, "i", $user_id);
mysqli_stmt_execute($status_stmt);
$status_result = mysqli_stmt_get_result($status_stmt);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['payment_status']] = $row['count'];
}

// Get summary statistics
$summary_query = "SELECT 
    COUNT(p.id) as total_payments,
    COALESCE(SUM(p.amount), 0) as total_amount,
    COALESCE(AVG(p.amount), 0) as avg_amount,
    COALESCE(SUM(v.total_compensation), 0) as total_approved
    FROM payments p
    JOIN claims c ON p.claim_id = c.id
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.claimant_id = ? AND p.payment_status = 'completed'";
$summary_stmt = mysqli_prepare($conn, $summary_query);
mysqli_stmt_bind_param($summary_stmt, "i", $user_id);
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

if (!$summary) {
    $summary = ['total_payments' => 0, 'total_amount' => 0, 'avg_amount' => 0, 'total_approved' => 0];
}

require_once __DIR__ . '/includes/claimant-header.php';
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
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.processed { background: #fef3c7; color: #92400e; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .payments-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
    }
    .payments-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .payments-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .payments-table tr:hover {
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
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
    .filter-select:focus, .filter-input:focus {
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
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .filter-grid {
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
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Malipo Yangu</h2>
            <p class="text-secondary text-sm mt-1">Fuatilia malipo yako ya fidia</p>
        </div>
    </div>
    
    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">receipt</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_payments'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Kiasi Kilicholipwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">calculate</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['avg_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Wastani wa Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #a7f3d0; color: #064e3b;">
                <span class="material-symbols-outlined">verified</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_approved'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Fidia Iliyoidhinishwa</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Hali ya Malipo</label>
                <select name="status" class="filter-select">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>-- Zote --</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Yanatarajiwa</option>
                    <option value="processed" <?php echo $status_filter === 'processed' ? 'selected' : ''; ?>>Yanachakatwa</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Yamekamilika</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Namba ya dai, mradi, au marejeleo..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div>
                <button type="submit" class="btn-filter w-full">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Payments Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Namba ya Dai</a></th>
                        <th>Mradi</th>
                        <th class="text-right"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'amount', 'order' => $sort_by == 'amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Kiasi (TZS)</a></th>
                        <th>Fidia Iliyoidhinishwa</th>
                        <th>Njia ya Malipo</th>
                        <th>Namba ya Marejeleo</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'payment_status', 'order' => $sort_by == 'payment_status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Hali</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'paid_at', 'order' => $sort_by == 'paid_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Tarehe ya Malipo</a></th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">payments</span>
                            Hakuna malipo yaliyopatikana
                            <div class="text-sm mt-2">Malipo yataonekana hapo baada ya kukamilika</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                    <tr id="row-<?php echo $payment['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                        <td class="font-medium"><?php echo htmlspecialchars($payment['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive font-bold">TZS <?php echo number_format($payment['amount'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($payment['approved_amount'] ?? 0, 0, '.', ','); ?></td>
                        <td>
                            <?php 
                            $method_labels = [
                                'bank_transfer' => 'Benki',
                                'mobile_money' => 'Mobile Money',
                                'cash' => 'Taslimu',
                                'cheque' => 'Hundi'
                            ];
                            echo $method_labels[$payment['payment_method']] ?? ucfirst($payment['payment_method'] ?? '-');
                            ?>
                        </td>
                        <td class="font-mono text-xs"><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                        <td>
                            <span class="status-badge <?php echo $payment['payment_status']; ?>">
                                <?php 
                                $status_labels = [
                                    'pending' => '⏳ Yanatarajiwa',
                                    'processed' => '🔄 Yanachakatwa',
                                    'completed' => '✅ Yamekamilika'
                                ];
                                echo $status_labels[$payment['payment_status']] ?? ucfirst($payment['payment_status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary"><?php echo $payment['paid_at'] ? date('d/m/Y', strtotime($payment['paid_at'])) : '-'; ?></td>
                        <td class="text-center">
                            <a href="view-payment.php?id=<?php echo $payment['id']; ?>" class="view-details-btn" title="Angalia Maelezo">
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
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_payments); ?> kati ya <?php echo $total_payments; ?>
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
                <p class="text-sm font-semibold text-blue-800">Kuhusu Malipo</p>
                <p class="text-sm text-blue-700 mt-1">Malipo yanafanywa baada ya tathmini kukamilika na kuidhinishwa. Ukiona malipo yako yamechelewa, tafadhali wasiliana na ofisi yetu iliyo karibu nawe au tumia ukurasa wa <a href="help.php" class="underline font-semibold">Msaada</a>.</p>
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

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>