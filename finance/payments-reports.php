<?php
// finance/payments-reports.php - Detailed Payment Reports
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is finance officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'finance_officer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Payment Reports';
$page_heading = 'Ripoti za Malipo';

// Get database connection
$conn = getDB();

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'all_payments';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$payment_method_filter = $_GET['payment_method'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'paid_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['paid_at', 'amount', 'payment_status', 'claim_number', 'full_name'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'paid_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Get years for filter
$years_query = "SELECT DISTINCT YEAR(paid_at) as year FROM payments WHERE paid_at IS NOT NULL ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $years[] = $row['year'];
}

// Get months
$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Machi',
    '04' => 'Aprili', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Julai', '08' => 'Agosti', '09' => 'Septemba',
    '10' => 'Oktoba', '11' => 'Novemba', '12' => 'Disemba'
];

// Payment methods
$payment_methods = [
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'cash' => 'Cash',
    'cheque' => 'Cheque'
];

// Build where clause
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($payment_method_filter !== 'all') {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $payment_method_filter;
    $types .= "s";
}

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(p.paid_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR p.transaction_reference LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// ========== ALL PAYMENTS REPORT ==========
$all_payments_query = "SELECT p.*, 
                       c.claim_number, c.project_name,
                       u.full_name as claimant_name, u.email, u.phone,
                       COALESCE(v.total_compensation, 0) as approved_amount
                       FROM payments p
                       JOIN claims c ON p.claim_id = c.id
                       JOIN users u ON c.claimant_id = u.id
                       LEFT JOIN valuations v ON c.id = v.claim_id
                       $where_sql
                       ORDER BY $sort_by $sort_order";

$all_payments_stmt = mysqli_prepare($conn, $all_payments_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($all_payments_stmt, $types, ...$params);
}
mysqli_stmt_execute($all_payments_stmt);
$all_payments_result = mysqli_stmt_get_result($all_payments_stmt);
$all_payments = [];
while ($row = mysqli_fetch_assoc($all_payments_result)) {
    $all_payments[] = $row;
}
$total_payments = count($all_payments);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_payments / $per_page);
$paginated_payments = array_slice($all_payments, $offset, $per_page);

// ========== SUMMARY STATISTICS ==========
$summary_query = "SELECT 
    COUNT(p.id) as total_payments,
    COALESCE(SUM(p.amount), 0) as total_amount,
    COALESCE(AVG(p.amount), 0) as avg_amount,
    COALESCE(MAX(p.amount), 0) as max_amount,
    COALESCE(MIN(p.amount), 0) as min_amount
    FROM payments p
    $where_sql";
$summary_stmt = mysqli_prepare($conn, $summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

// ========== PAYMENTS BY STATUS ==========
$status_summary_query = "SELECT 
    p.payment_status,
    COUNT(p.id) as count,
    SUM(p.amount) as total_amount
    FROM payments p
    $where_sql
    GROUP BY p.payment_status";
$status_summary_stmt = mysqli_prepare($conn, $status_summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($status_summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($status_summary_stmt);
$status_summary_result = mysqli_stmt_get_result($status_summary_stmt);
$status_summary = [];
while ($row = mysqli_fetch_assoc($status_summary_result)) {
    $status_summary[] = $row;
}

// ========== PAYMENTS BY METHOD ==========
$method_summary_query = "SELECT 
    p.payment_method,
    COUNT(p.id) as count,
    SUM(p.amount) as total_amount
    FROM payments p
    $where_sql
    GROUP BY p.payment_method";
$method_summary_stmt = mysqli_prepare($conn, $method_summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($method_summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($method_summary_stmt);
$method_summary_result = mysqli_stmt_get_result($method_summary_stmt);
$method_summary = [];
while ($row = mysqli_fetch_assoc($method_summary_result)) {
    $method_summary[] = $row;
}

// ========== MONTHLY SUMMARY (for selected year) ==========
$monthly_summary_query = "SELECT 
    MONTH(p.paid_at) as month_num,
    COUNT(p.id) as count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE YEAR(p.paid_at) = ? AND p.paid_at IS NOT NULL
    GROUP BY MONTH(p.paid_at)
    ORDER BY month_num ASC";
$monthly_summary_stmt = mysqli_prepare($conn, $monthly_summary_query);
mysqli_stmt_bind_param($monthly_summary_stmt, "s", $year);
mysqli_stmt_execute($monthly_summary_stmt);
$monthly_summary_result = mysqli_stmt_get_result($monthly_summary_stmt);
$monthly_data = [];
while ($row = mysqli_fetch_assoc($monthly_summary_result)) {
    $monthly_data[$row['month_num']] = $row;
}

// Get total for year
$year_total_query = "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(paid_at) = ? AND paid_at IS NOT NULL";
$year_total_stmt = mysqli_prepare($conn, $year_total_query);
mysqli_stmt_bind_param($year_total_stmt, "s", $year);
mysqli_stmt_execute($year_total_stmt);
$year_total_result = mysqli_stmt_get_result($year_total_stmt);
$year_total = mysqli_fetch_assoc($year_total_result)['total'];

// ========== DAILY SUMMARY (for selected month) ==========
$daily_summary_query = "SELECT 
    DAY(p.paid_at) as day,
    COUNT(p.id) as count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE YEAR(p.paid_at) = ? AND MONTH(p.paid_at) = ? AND p.paid_at IS NOT NULL
    GROUP BY DAY(p.paid_at)
    ORDER BY day ASC";
$daily_summary_stmt = mysqli_prepare($conn, $daily_summary_query);
mysqli_stmt_bind_param($daily_summary_stmt, "ss", $year, $month);
mysqli_stmt_execute($daily_summary_stmt);
$daily_summary_result = mysqli_stmt_get_result($daily_summary_stmt);
$daily_data = [];
while ($row = mysqli_fetch_assoc($daily_summary_result)) {
    $daily_data[$row['day']] = $row;
}

// Get month total
$month_total_query = "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE YEAR(paid_at) = ? AND MONTH(paid_at) = ? AND paid_at IS NOT NULL";
$month_total_stmt = mysqli_prepare($conn, $month_total_query);
mysqli_stmt_bind_param($month_total_stmt, "ss", $year, $month);
mysqli_stmt_execute($month_total_stmt);
$month_total_result = mysqli_stmt_get_result($month_total_stmt);
$month_total = mysqli_fetch_assoc($month_total_result)['total'];

require_once __DIR__ . '/includes/finance-header.php';
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
    .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
    }
    .stat-value {
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
    
    /* Report Sections */
    .report-section {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1rem;
    }
    .report-header {
        padding: 0.75rem 1rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .report-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .report-body {
        padding: 1rem;
    }
    
    /* Tables */
    .report-table {
        width: 100%;
        border-collapse: collapse;
    }
    .report-table th {
        padding: 0.5rem 0.75rem;
        text-align: left;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .report-table td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #e8f0e4;
        font-size: 0.8rem;
    }
    .report-table tr:hover {
        background-color: #f4fcef;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1rem;
    }
    .filter-select, .filter-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        background: white;
        width: 100%;
    }
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
        text-decoration: none;
    }
    .btn-outline:hover {
        background-color: #eef6ea;
    }
    
    /* Report Tabs */
    .report-tab {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.2s ease;
        background: none;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    .report-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .report-tab:not(.active):hover {
        background-color: #e8f0e4;
        color: #1e2a1e;
    }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.processed { background: #fef3c7; color: #92400e; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
        align-items: end;
    }
    
    .pagination-btn {
        padding: 0.3rem 0.6rem;
        border: 1px solid #bccab9;
        border-radius: 0.4rem;
        font-size: 0.7rem;
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
    
    .progress-bar {
        background-color: #e8f0e4;
        border-radius: 9999px;
        overflow: hidden;
        height: 6px;
    }
    .progress-fill {
        background-color: #006e2c;
        height: 100%;
        border-radius: 9999px;
        transition: width 0.3s ease;
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
        .filter-grid {
            grid-template-columns: 1fr;
        }
        .report-table {
            min-width: 700px;
        }
        .table-container {
            overflow-x: auto;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-4">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Ripoti za Malipo</h2>
            <p class="text-secondary text-xs">Ripoti za kina za malipo ya fidia</p>
        </div>
        <div>
            <button onclick="exportReport()" class="btn-outline">
                <span class="material-symbols-outlined text-sm">download</span>
                Export Ripoti
            </button>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b pb-2">
        <a href="?report_type=all_payments" class="report-tab <?php echo $report_type === 'all_payments' ? 'active' : ''; ?>">
            📋 Malipo Yote
        </a>
        <a href="?report_type=summary" class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
            📊 Muhtasari
        </a>
        <a href="?report_type=monthly" class="report-tab <?php echo $report_type === 'monthly' ? 'active' : ''; ?>">
            📅 Kwa Mwezi
        </a>
        <a href="?report_type=daily" class="report-tab <?php echo $report_type === 'daily' ? 'active' : ''; ?>">
            📆 Kwa Siku
        </a>
    </div>
    
    <?php if ($report_type === 'all_payments'): ?>
    <!-- ALL PAYMENTS REPORT -->
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <input type="hidden" name="report_type" value="all_payments">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Kuanzia Tarehe</label>
                <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mpaka Tarehe</label>
                <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Njia ya Malipo</label>
                <select name="payment_method" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <?php foreach ($payment_methods as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $payment_method_filter == $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Hali</label>
                <select name="status" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Yamekamilika</option>
                    <option value="processed" <?php echo $status_filter == 'processed' ? 'selected' : ''; ?>>Yanachakatwa</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Yanatarajiwa</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Namba ya dai, jina, marejeleo..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="payments-reports.php?report_type=all_payments" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_payments'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Kiasi</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">calculate</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['avg_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Wastani</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">trending_up</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['max_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Kiwango cha Juu</div>
        </div>
    </div>
    
    <!-- Payments Table -->
    <div class="report-section">
        <div class="report-header">
            <h3>📋 Orodha ya Malipo</h3>
            <div class="text-xs text-secondary">Inaonyesha <?php echo count($paginated_payments); ?> kati ya <?php echo $total_payments; ?> malipo</div>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'paid_at', 'order' => $sort_by == 'paid_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mwombaji</a></th>
                        <th>Mradi</th>
                        <th class="text-right"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'amount', 'order' => $sort_by == 'amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Kiasi (TZS)</a></th>
                        <th>Njia ya Malipo</th>
                        <th>Namba ya Marejeleo</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'payment_status', 'order' => $sort_by == 'payment_status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Hali</a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paginated_payments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-8 text-secondary">
                            <span class="material-symbols-outlined text-4xl mb-1 block">payments</span>
                            Hakuna malipo yanayoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($paginated_payments as $payment): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?php echo date('d/m/Y H:i', strtotime($payment['paid_at'])); ?></td>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($payment['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($payment['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($payment['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($payment['amount'], 0, '.', ','); ?></td>
                        <td><?php echo $payment_methods[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?></td>
                        <td class="font-mono text-xs"><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                        <td><span class="status-badge <?php echo $payment['payment_status']; ?>"><?php echo ucfirst($payment['payment_status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-3 py-2 border-t gap-2">
            <div class="text-xs text-secondary">
                Ukurasa <?php echo $page; ?> kati ya <?php echo $total_pages; ?>
            </div>
            <div class="flex gap-1">
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
    
    <?php elseif ($report_type === 'summary'): ?>
    <!-- SUMMARY REPORT -->
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <input type="hidden" name="report_type" value="summary">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Kuanzia Tarehe</label>
                <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mpaka Tarehe</label>
                <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="payments-reports.php?report_type=summary" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Summary Cards -->
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
            <div class="stat-label">Jumla ya Kiasi</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">calculate</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['avg_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Wastani</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">trending_up</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['max_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Kiwango cha Juu</div>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- By Status -->
        <div class="report-section">
            <div class="report-header">
                <h3>📊 Kwa Hali ya Malipo</h3>
            </div>
            <div class="report-body">
                <div class="space-y-2">
                    <?php foreach ($status_summary as $status): ?>
                    <?php $percentage = ($summary['total_amount'] > 0) ? ($status['total_amount'] / $summary['total_amount']) * 100 : 0; ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="status-badge <?php echo $status['payment_status']; ?>"><?php echo ucfirst($status['payment_status']); ?></span>
                            <span>TZS <?php echo number_format($status['total_amount'], 0, '.', ','); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $percentage; ?>%;"></div></div>
                        <div class="text-xs text-secondary mt-1">Malipo: <?php echo number_format($status['count']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- By Method -->
        <div class="report-section">
            <div class="report-header">
                <h3>💳 Kwa Njia ya Malipo</h3>
            </div>
            <div class="report-body">
                <div class="space-y-2">
                    <?php foreach ($method_summary as $method): ?>
                    <?php $percentage = ($summary['total_amount'] > 0) ? ($method['total_amount'] / $summary['total_amount']) * 100 : 0; ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span><?php echo $payment_methods[$method['payment_method']] ?? ucfirst($method['payment_method']); ?></span>
                            <span>TZS <?php echo number_format($method['total_amount'], 0, '.', ','); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $percentage; ?>%;"></div></div>
                        <div class="text-xs text-secondary mt-1">Malipo: <?php echo number_format($method['count']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($report_type === 'monthly'): ?>
    <!-- MONTHLY REPORT -->
    
    <!-- Year Filter -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="report_type" value="monthly">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mwaka</label>
                <select name="year" class="filter-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-primary">Filter</button>
            </div>
        </form>
    </div>
    
    <div class="report-section">
        <div class="report-header">
            <h3>📅 Muhtasari wa Malipo Kwa Mwezi - <?php echo $year; ?></h3>
            <div class="text-xs text-secondary">Jumla ya Mwaka: TZS <?php echo number_format($year_total, 0, '.', ','); ?></div>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla (TZS)</th>
                        <th class="text-right">Asilimia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($m = 1; $m <= 12; $m++): 
                        $month_num = str_pad($m, 2, '0', STR_PAD_LEFT);
                        $data = $monthly_data[$m] ?? ['count' => 0, 'total_amount' => 0];
                        $percentage = ($year_total > 0) ? ($data['total_amount'] / $year_total) * 100 : 0;
                    ?>
                    <tr>
                        <td class="font-medium"><?php echo $months[$month_num]; ?></td>
                        <td class="text-right"><?php echo number_format($data['count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($data['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'daily'): ?>
    <!-- DAILY REPORT -->
    
    <!-- Month Filter -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="report_type" value="daily">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mwaka</label>
                <select name="year" class="filter-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mwezi</label>
                <select name="month" class="filter-select">
                    <?php foreach ($months as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-primary">Filter</button>
            </div>
        </form>
    </div>
    
    <div class="report-section">
        <div class="report-header">
            <h3>📆 Muhtasari wa Malipo Kwa Siku - <?php echo $months[$month]; ?> <?php echo $year; ?></h3>
            <div class="text-xs text-secondary">Jumla ya Mwezi: TZS <?php echo number_format($month_total, 0, '.', ','); ?></div>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Siku</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla (TZS)</th>
                        <th class="text-right">Asilimia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $days_in_month = cal_days_in_month(CAL_GREGORIAN, intval($month), intval($year));
                    for ($d = 1; $d <= $days_in_month; $d++):
                        $day_num = str_pad($d, 2, '0', STR_PAD_LEFT);
                        $data = $daily_data[$d] ?? ['count' => 0, 'total_amount' => 0];
                        $percentage = ($month_total > 0) ? ($data['total_amount'] / $month_total) * 100 : 0;
                    ?>
                    <tr>
                        <td class="font-medium"><?php echo $d; ?> <?php echo $months[$month]; ?></td>
                        <td class="text-right"><?php echo number_format($data['count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($data['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; ?>
    
</div>

<script>
    function exportReport() {
        const reportType = '<?php echo $report_type; ?>';
        const dateFrom = '<?php echo $date_from; ?>';
        const dateTo = '<?php echo $date_to; ?>';
        const year = '<?php echo $year; ?>';
        const month = '<?php echo $month; ?>';
        const paymentMethod = '<?php echo $payment_method_filter; ?>';
        const status = '<?php echo $status_filter; ?>';
        const search = '<?php echo urlencode($search_term); ?>';
        
        window.location.href = `?export=1&report_type=${reportType}&date_from=${dateFrom}&date_to=${dateTo}&year=${year}&month=${month}&payment_method=${paymentMethod}&status=${status}&search=${search}`;
    }
</script>

<?php
// Handle export
if (isset($_GET['export'])) {
    $export_type = $_GET['report_type'] ?? 'all_payments';
    $export_date_from = $_GET['date_from'] ?? date('Y-m-01');
    $export_date_to = $_GET['date_to'] ?? date('Y-m-d');
    $export_year = $_GET['year'] ?? date('Y');
    $export_month = $_GET['month'] ?? date('m');
    $export_method = $_GET['payment_method'] ?? 'all';
    $export_status = $_GET['status'] ?? 'all';
    $export_search = $_GET['search'] ?? '';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type === 'all_payments') {
        fputcsv($output, ['Payment Reports - All Payments']);
        fputcsv($output, ['Report Period', $export_date_from . ' to ' . $export_date_to]);
        fputcsv($output, []);
        
        $export_where = [];
        $export_params = [];
        $export_types = "";
        
        if ($export_status !== 'all') {
            $export_where[] = "p.payment_status = ?";
            $export_params[] = $export_status;
            $export_types .= "s";
        }
        if ($export_method !== 'all') {
            $export_where[] = "p.payment_method = ?";
            $export_params[] = $export_method;
            $export_types .= "s";
        }
        if (!empty($export_date_from) && !empty($export_date_to)) {
            $export_where[] = "DATE(p.paid_at) BETWEEN ? AND ?";
            $export_params[] = $export_date_from;
            $export_params[] = $export_date_to;
            $export_types .= "ss";
        }
        if (!empty($export_search)) {
            $export_where[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR p.transaction_reference LIKE ?)";
            $search_param = "%$export_search%";
            $export_params[] = $search_param;
            $export_params[] = $search_param;
            $export_params[] = $search_param;
            $export_params[] = $search_param;
            $export_types .= "ssss";
        }
        $export_where_sql = empty($export_where) ? "" : "WHERE " . implode(" AND ", $export_where);
        
        $export_query = "SELECT 
            DATE(p.paid_at) as payment_date,
            c.claim_number,
            u.full_name as claimant_name,
            u.email,
            c.project_name,
            p.amount,
            p.payment_method,
            p.transaction_reference,
            p.payment_status
            FROM payments p
            JOIN claims c ON p.claim_id = c.id
            JOIN users u ON c.claimant_id = u.id
            $export_where_sql
            ORDER BY p.paid_at DESC";
        
        $export_stmt = mysqli_prepare($conn, $export_query);
        if (!empty($export_params)) {
            mysqli_stmt_bind_param($export_stmt, $export_types, ...$export_params);
        }
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Date', 'Claim Number', 'Claimant Name', 'Email', 'Project', 'Amount (TZS)', 'Payment Method', 'Transaction Ref', 'Status']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['payment_date'],
                $row['claim_number'],
                $row['claimant_name'],
                $row['email'],
                $row['project_name'],
                number_format($row['amount'], 2),
                $row['payment_method'],
                $row['transaction_reference'],
                $row['payment_status']
            ]);
        }
    } elseif ($export_type === 'monthly') {
        fputcsv($output, ['Monthly Payment Report - ' . $export_year]);
        fputcsv($output, []);
        
        $export_monthly_query = "SELECT 
            MONTH(p.paid_at) as month_num,
            COUNT(p.id) as payment_count,
            SUM(p.amount) as total_amount
            FROM payments p
            WHERE YEAR(p.paid_at) = ? AND p.paid_at IS NOT NULL
            GROUP BY MONTH(p.paid_at)
            ORDER BY month_num ASC";
        $export_stmt = mysqli_prepare($conn, $export_monthly_query);
        mysqli_stmt_bind_param($export_stmt, "s", $export_year);
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        $months_list = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Disemba'];
        fputcsv($output, ['Month', 'Payment Count', 'Total Amount (TZS)']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $months_list[$row['month_num'] - 1],
                $row['payment_count'],
                number_format($row['total_amount'], 2)
            ]);
        }
    }
    
    fclose($output);
    exit();
}
?>

<?php require_once __DIR__ . '/includes/finance-footer.php'; ?>