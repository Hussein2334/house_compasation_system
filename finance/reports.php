<?php
// finance/reports.php - Financial Reports for Finance Officer
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
$page_title = 'Financial Reports';
$page_heading = 'Ripoti za Kifedha';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$year = $_GET['year'] ?? date('Y');
$payment_method_filter = $_GET['payment_method'] ?? 'all';

// Get years for filter
$years_query = "SELECT DISTINCT YEAR(paid_at) as year FROM payments WHERE paid_at IS NOT NULL ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $years[] = $row['year'];
}

// Build where clause for date range
$where_clauses = [];
$params = [];
$types = "";

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(p.paid_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if ($payment_method_filter !== 'all') {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $payment_method_filter;
    $types .= "s";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// ========== SUMMARY STATISTICS ==========
$summary_query = "SELECT 
    COUNT(p.id) as total_payments,
    COUNT(DISTINCT p.claim_id) as total_claims_paid,
    COALESCE(SUM(p.amount), 0) as total_amount,
    COALESCE(AVG(p.amount), 0) as average_amount,
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

// ========== PAYMENTS BY MONTH ==========
$monthly_query = "SELECT 
    DATE_FORMAT(p.paid_at, '%Y-%m') as month,
    DATE_FORMAT(p.paid_at, '%M %Y') as month_name,
    COUNT(p.id) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE p.paid_at IS NOT NULL AND YEAR(p.paid_at) = ?
    GROUP BY DATE_FORMAT(p.paid_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_stmt = mysqli_prepare($conn, $monthly_query);
mysqli_stmt_bind_param($monthly_stmt, "s", $year);
mysqli_stmt_execute($monthly_stmt);
$monthly_result = mysqli_stmt_get_result($monthly_stmt);
$monthly_payments = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_payments[] = $row;
}

// ========== PAYMENTS BY METHOD ==========
$by_method_query = "SELECT 
    p.payment_method,
    COUNT(p.id) as payment_count,
    SUM(p.amount) as total_amount,
    AVG(p.amount) as average_amount
    FROM payments p
    $where_sql
    GROUP BY p.payment_method
    ORDER BY total_amount DESC";
$by_method_stmt = mysqli_prepare($conn, $by_method_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($by_method_stmt, $types, ...$params);
}
mysqli_stmt_execute($by_method_stmt);
$by_method_result = mysqli_stmt_get_result($by_method_stmt);
$method_stats = [];
while ($row = mysqli_fetch_assoc($by_method_result)) {
    $method_stats[] = $row;
}

// ========== DAILY PAYMENT TREND (LAST 30 DAYS) ==========
$daily_query = "SELECT 
    DATE(p.paid_at) as date,
    COUNT(p.id) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE p.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND p.paid_at IS NOT NULL
    GROUP BY DATE(p.paid_at)
    ORDER BY date ASC";
$daily_result = mysqli_query($conn, $daily_query);
$daily_payments = [];
while ($row = mysqli_fetch_assoc($daily_result)) {
    $daily_payments[] = $row;
}

// ========== TOP CLAIMANTS BY PAYMENT ==========
$top_claimants_query = "SELECT 
    u.full_name, u.email,
    COUNT(p.id) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    JOIN claims c ON p.claim_id = c.id
    JOIN users u ON c.claimant_id = u.id
    $where_sql
    GROUP BY u.id
    ORDER BY total_amount DESC
    LIMIT 10";
$top_claimants_stmt = mysqli_prepare($conn, $top_claimants_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($top_claimants_stmt, $types, ...$params);
}
mysqli_stmt_execute($top_claimants_stmt);
$top_claimants_result = mysqli_stmt_get_result($top_claimants_stmt);
$top_claimants = [];
while ($row = mysqli_fetch_assoc($top_claimants_result)) {
    $top_claimants[] = $row;
}

// ========== QUARTERLY SUMMARY ==========
$quarterly_query = "SELECT 
    CONCAT(YEAR(p.paid_at), '-Q', QUARTER(p.paid_at)) as quarter,
    CONCAT('Robo ', QUARTER(p.paid_at), ' ', YEAR(p.paid_at)) as quarter_name,
    COUNT(p.id) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE p.paid_at IS NOT NULL AND YEAR(p.paid_at) = ?
    GROUP BY YEAR(p.paid_at), QUARTER(p.paid_at)
    ORDER BY quarter ASC";
$quarterly_stmt = mysqli_prepare($conn, $quarterly_query);
mysqli_stmt_bind_param($quarterly_stmt, "s", $year);
mysqli_stmt_execute($quarterly_stmt);
$quarterly_result = mysqli_stmt_get_result($quarterly_stmt);
$quarterly_stats = [];
while ($row = mysqli_fetch_assoc($quarterly_result)) {
    $quarterly_stats[] = $row;
}

// ========== PAYMENT STATUS SUMMARY ==========
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

// Get payment methods list for filter
$methods_list = ['bank_transfer', 'mobile_money', 'cash', 'cheque'];
$method_labels = [
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'cash' => 'Cash',
    'cheque' => 'Cheque'
];

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
            min-width: 500px;
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
            <h2 class="text-xl font-bold">Ripoti za Kifedha</h2>
            <p class="text-secondary text-xs">Takwimu na ripoti za kina za malipo na fedha</p>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b pb-2">
        <a href="?report_type=summary" class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
            📊 Muhtasari
        </a>
        <a href="?report_type=monthly" class="report-tab <?php echo $report_type === 'monthly' ? 'active' : ''; ?>">
            📅 Kwa Mwezi
        </a>
        <a href="?report_type=method" class="report-tab <?php echo $report_type === 'method' ? 'active' : ''; ?>">
            💳 Kwa Njia ya Malipo
        </a>
        <a href="?report_type=claimants" class="report-tab <?php echo $report_type === 'claimants' ? 'active' : ''; ?>">
            👥 Wadai Bora
        </a>
        <a href="?report_type=daily" class="report-tab <?php echo $report_type === 'daily' ? 'active' : ''; ?>">
            📈 Mwelekeo wa Kila Siku
        </a>
    </div>
    
    <!-- Filter Bar (for reports that need date range) -->
    <?php if ($report_type === 'summary' || $report_type === 'method' || $report_type === 'claimants'): ?>
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
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
                    <?php foreach ($methods_list as $method): ?>
                        <option value="<?php echo $method; ?>" <?php echo $payment_method_filter == $method ? 'selected' : ''; ?>>
                            <?php echo $method_labels[$method]; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="reports.php?report_type=<?php echo $report_type; ?>" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <?php if ($report_type === 'summary'): ?>
    <!-- SUMMARY REPORT -->
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">receipt</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_payments'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">calculate</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['average_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Wastani kwa Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">people</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_claims_paid'] ?? 0); ?></div>
            <div class="stat-label">Madai Yaliyolipwa</div>
        </div>
    </div>
    
    <!-- Additional Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="report-section">
            <div class="report-header">
                <h3>💰 Muhtasari wa Kiasi</h3>
            </div>
            <div class="report-body">
                <div class="space-y-2">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Malipo Yote:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['total_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Kiwango cha Juu cha Malipo:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['max_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Kiwango cha Chini cha Malipo:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['min_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Wastani wa Malipo:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['average_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3>📋 Muhtasari wa Hali za Malipo</h3>
            </div>
            <div class="report-body">
                <div class="space-y-2">
                    <?php foreach ($status_summary as $status): ?>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="status-badge <?php echo $status['payment_status']; ?>"><?php echo ucfirst($status['payment_status']); ?></span>
                        <div class="text-right">
                            <div class="font-semibold"><?php echo number_format($status['count']); ?> malipo</div>
                            <div class="text-xs text-secondary">TZS <?php echo number_format($status['total_amount'], 0, '.', ','); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods Summary -->
    <div class="report-section">
        <div class="report-header">
            <h3>💳 Mgawanyo kwa Njia za Malipo</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Njia ya Malipo</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla (TZS)</th>
                        <th class="text-right">Wastani (TZS)</th>
                        <th class="text-right">Asilimia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($method_stats)): ?>
                    <tr><td colspan="5" class="text-center py-6 text-secondary">Hakuna data ya njia za malipo</td></tr>
                    <?php else: ?>
                    <?php foreach ($method_stats as $method): ?>
                    <?php $percentage = ($summary['total_amount'] > 0) ? ($method['total_amount'] / $summary['total_amount']) * 100 : 0; ?>
                    <tr>
                        <td class="font-medium"><?php echo $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']); ?></td>
                        <td class="text-right"><?php echo number_format($method['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($method['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($method['average_amount'], 0, '.', ','); ?></td>
                        <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'monthly'): ?>
    <!-- MONTHLY PAYMENTS REPORT -->
    
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
            <h3>📅 Malipo Kwa Mwezi - <?php echo $year; ?></h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla ya Malipo (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_payments)): ?>
                    <tr><td colspan="3" class="text-center py-6 text-secondary">Hakuna data ya malipo kwa mwaka huu</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_payments as $month): ?>
                    <tr>
                        <td class="font-medium"><?php echo $month['month_name']; ?></td>
                        <td class="text-right"><?php echo number_format($month['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($month['total_amount'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Quarterly Summary -->
                    <tr class="bg-gray-50">
                        <td colspan="3" class="pt-3"><strong>Muhtasari wa Robo</strong></td>
                    </tr>
                    <?php foreach ($quarterly_stats as $quarter): ?>
                    <tr class="bg-gray-50">
                        <td class="font-medium"><?php echo $quarter['quarter_name']; ?></td>
                        <td class="text-right"><?php echo number_format($quarter['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($quarter['total_amount'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'method'): ?>
    <!-- PAYMENT METHODS REPORT -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>💳 Uchambuzi wa Njia za Malipo</h3>
            <div class="text-xs text-secondary">Kipindi: <?php echo date('d/m/Y', strtotime($date_from)); ?> - <?php echo date('d/m/Y', strtotime($date_to)); ?></div>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Njia ya Malipo</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla (TZS)</th>
                        <th class="text-right">Wastani (TZS)</th>
                        <th class="text-right">Asilimia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($method_stats)): ?>
                    <tr><td colspan="5" class="text-center py-6 text-secondary">Hakuna data ya malipo kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php 
                    $total_all = array_sum(array_column($method_stats, 'total_amount'));
                    foreach ($method_stats as $method): 
                    $percentage = ($total_all > 0) ? ($method['total_amount'] / $total_all) * 100 : 0;
                    ?>
                    <tr>
                        <td class="font-medium"><?php echo $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']); ?></td>
                        <td class="text-right"><?php echo number_format($method['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($method['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($method['average_amount'], 0, '.', ','); ?></td>
                        <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'claimants'): ?>
    <!-- TOP CLAIMANTS REPORT -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>👥 Wadai Bora Kwa Malipo Yaliyopokelewa</h3>
            <div class="text-xs text-secondary">Kipindi: <?php echo date('d/m/Y', strtotime($date_from)); ?> - <?php echo date('d/m/Y', strtotime($date_to)); ?></div>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Jina Kamili</th>
                        <th>Barua Pepe</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla ya Malipo (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_claimants)): ?>
                    <tr><td colspan="5" class="text-center py-6 text-secondary">Hakuna data ya wadai kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php $rank = 1; foreach ($top_claimants as $claimant): ?>
                    <tr>
                        <td class="text-center"><?php echo $rank++; ?></td>
                        <td class="font-medium"><?php echo htmlspecialchars($claimant['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($claimant['email']); ?></td>
                        <td class="text-right"><?php echo number_format($claimant['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($claimant['total_amount'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            <td>
        </div>
    </div>
    
    <?php elseif ($report_type === 'daily'): ?>
    <!-- DAILY PAYMENT TREND -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>📈 Mwelekeo wa Malipo Kila Siku (Siku 30 zilizopita)</h3>
        </div>
        <div class="report-body">
            <?php if (empty($daily_payments)): ?>
            <div class="text-center py-6 text-secondary">Hakuna data ya mwelekeo wa malipo</div>
            <?php else: ?>
            <div class="space-y-4">
                <!-- Simple bar chart representation -->
                <div class="space-y-2">
                    <?php 
                    $max_amount = max(array_column($daily_payments, 'total_amount'));
                    $max_amount = $max_amount > 0 ? $max_amount : 1;
                    foreach ($daily_payments as $day):
                        $height = ($day['total_amount'] / $max_amount) * 100;
                        $height = max($height, 10);
                    ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span><?php echo date('d M Y', strtotime($day['date'])); ?></span>
                            <span class="font-semibold">TZS <?php echo number_format($day['total_amount'], 0, '.', ','); ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-6 overflow-hidden">
                            <div class="bg-primary h-6 rounded-full flex items-center justify-end px-2 text-xs text-white" style="width: <?php echo $height; ?>%;">
                                <?php echo number_format($day['payment_count']); ?> malipo
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Summary Table -->
                <div class="table-container overflow-x-auto mt-4">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Tarehe</th>
                                <th class="text-right">Idadi ya Malipo</th>
                                <th class="text-right">Jumla (TZS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_payments as $day): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($day['date'])); ?></td>
                                <td class="text-right"><?php echo number_format($day['payment_count']); ?></td>
                                <td class="text-right amount-positive">TZS <?php echo number_format($day['total_amount'], 0, '.', ','); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>
    
    <!-- Export Button -->
    <div class="flex justify-end">
        <button onclick="exportReport()" class="btn-outline">
            <span class="material-symbols-outlined text-sm">download</span>
            Export Report
        </button>
    </div>
    
</div>

<script>
    function exportReport() {
        Swal.fire({
            title: 'Export Ripoti',
            text: 'Je, unataka kupakua ripoti hii?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                const reportType = '<?php echo $report_type; ?>';
                const dateFrom = '<?php echo $date_from; ?>';
                const dateTo = '<?php echo $date_to; ?>';
                const year = '<?php echo $year; ?>';
                const paymentMethod = '<?php echo $payment_method_filter; ?>';
                window.location.href = `?export=1&report_type=${reportType}&date_from=${dateFrom}&date_to=${dateTo}&year=${year}&payment_method=${paymentMethod}`;
            }
        });
    }
</script>

<?php
// Handle export
if (isset($_GET['export'])) {
    $export_type = $_GET['report_type'] ?? 'summary';
    $export_date_from = $_GET['date_from'] ?? date('Y-m-01');
    $export_date_to = $_GET['date_to'] ?? date('Y-m-d');
    $export_year = $_GET['year'] ?? date('Y');
    $export_method = $_GET['payment_method'] ?? 'all';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="financial_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type === 'summary') {
        fputcsv($output, ['Financial Report Summary']);
        fputcsv($output, ['Report Period', $export_date_from . ' to ' . $export_date_to]);
        fputcsv($output, []);
        
        // Get data for export
        $export_where = [];
        $export_params = [];
        $export_types = "";
        
        if (!empty($export_date_from) && !empty($export_date_to)) {
            $export_where[] = "DATE(p.paid_at) BETWEEN ? AND ?";
            $export_params[] = $export_date_from;
            $export_params[] = $export_date_to;
            $export_types .= "ss";
        }
        if ($export_method !== 'all') {
            $export_where[] = "p.payment_method = ?";
            $export_params[] = $export_method;
            $export_types .= "s";
        }
        $export_where_sql = empty($export_where) ? "" : "WHERE " . implode(" AND ", $export_where);
        
        $export_summary_query = "SELECT 
            DATE(p.paid_at) as payment_date,
            c.claim_number,
            u.full_name as claimant_name,
            p.amount,
            p.payment_method,
            p.transaction_reference,
            p.payment_status
            FROM payments p
            JOIN claims c ON p.claim_id = c.id
            JOIN users u ON c.claimant_id = u.id
            $export_where_sql
            ORDER BY p.paid_at DESC";
        
        $export_stmt = mysqli_prepare($conn, $export_summary_query);
        if (!empty($export_params)) {
            mysqli_stmt_bind_param($export_stmt, $export_types, ...$export_params);
        }
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Date', 'Claim Number', 'Claimant Name', 'Amount (TZS)', 'Payment Method', 'Transaction Reference', 'Status']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['payment_date'],
                $row['claim_number'],
                $row['claimant_name'],
                number_format($row['amount'], 2),
                $row['payment_method'],
                $row['transaction_reference'],
                $row['payment_status']
            ]);
        }
    } elseif ($export_type === 'monthly') {
        fputcsv($output, ['Monthly Payments Report - ' . $export_year]);
        fputcsv($output, []);
        
        $export_monthly_query = "SELECT 
            DATE_FORMAT(p.paid_at, '%M %Y') as month,
            COUNT(p.id) as payment_count,
            SUM(p.amount) as total_amount
            FROM payments p
            WHERE YEAR(p.paid_at) = ? AND p.paid_at IS NOT NULL
            GROUP BY DATE_FORMAT(p.paid_at, '%Y-%m')
            ORDER BY month ASC";
        $export_stmt = mysqli_prepare($conn, $export_monthly_query);
        mysqli_stmt_bind_param($export_stmt, "s", $export_year);
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Month', 'Payment Count', 'Total Amount (TZS)']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['month'],
                $row['payment_count'],
                number_format($row['total_amount'], 2)
            ]);
        }
    } elseif ($export_type === 'claimants') {
        fputcsv($output, ['Top Claimants Report']);
        fputcsv($output, ['Period', $export_date_from . ' to ' . $export_date_to]);
        fputcsv($output, []);
        
        $export_claimants_query = "SELECT 
            u.full_name, u.email,
            COUNT(p.id) as payment_count,
            SUM(p.amount) as total_amount
            FROM payments p
            JOIN claims c ON p.claim_id = c.id
            JOIN users u ON c.claimant_id = u.id
            WHERE DATE(p.paid_at) BETWEEN ? AND ?
            GROUP BY u.id
            ORDER BY total_amount DESC
            LIMIT 50";
        $export_stmt = mysqli_prepare($conn, $export_claimants_query);
        mysqli_stmt_bind_param($export_stmt, "ss", $export_date_from, $export_date_to);
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Claimant Name', 'Email', 'Payment Count', 'Total Amount (TZS)']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['full_name'],
                $row['email'],
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