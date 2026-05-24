<?php
// commissioner/payments.php - Commissioner Payments Overview
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
$page_title = 'Payments Overview';
$page_heading = 'Muhtasari wa Malipo';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$payment_method_filter = $_GET['method'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$sort_by = $_GET['sort'] ?? 'paid_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
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

// Get total payments count
$count_query = "SELECT COUNT(*) as total 
                FROM payments p
                JOIN claims c ON p.claim_id = c.id
                JOIN users u ON c.claimant_id = u.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_payments = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 15 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_payments / $per_page);

// Get payments data
$query = "SELECT p.*, c.claim_number, c.project_name, u.full_name as claimant_name, u.email, u.phone
          FROM payments p
          JOIN claims c ON p.claim_id = c.id
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

$payments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $payments[] = $row;
}

// Get summary statistics
$summary_query = "SELECT 
    COUNT(*) as total_payments,
    COALESCE(SUM(amount), 0) as total_amount,
    COALESCE(AVG(amount), 0) as avg_amount,
    COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as completed_count,
    COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END), 0) as completed_amount,
    COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_count,
    COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount,
    COUNT(CASE WHEN payment_status = 'processed' THEN 1 END) as processed_count,
    COALESCE(SUM(CASE WHEN payment_status = 'processed' THEN amount ELSE 0 END), 0) as processed_amount
    FROM payments p
    JOIN claims c ON p.claim_id = c.id";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

// Get payment methods breakdown
$methods_query = "SELECT 
    payment_method,
    COUNT(*) as count,
    COALESCE(SUM(amount), 0) as total_amount
    FROM payments
    WHERE payment_status = 'completed'
    GROUP BY payment_method
    ORDER BY total_amount DESC";
$methods_result = mysqli_query($conn, $methods_query);
$payment_methods = [];
while ($row = mysqli_fetch_assoc($methods_result)) {
    $payment_methods[] = $row;
}

// Get monthly payments
$monthly_query = "SELECT 
    DATE_FORMAT(paid_at, '%Y-%m') as month,
    DATE_FORMAT(paid_at, '%M %Y') as month_name,
    COUNT(*) as total_payments,
    COALESCE(SUM(amount), 0) as total_amount
    FROM payments
    WHERE payment_status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(paid_at), MONTH(paid_at)
    ORDER BY paid_at ASC";
$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_payments = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_payments[] = $row;
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
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-pending { background: #fed7aa; color: #9a3412; }
    .status-processed { background: #cffafe; color: #0891b2; }
    
    /* Filter Sections */
    .filter-section {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    .filter-tabs {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
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
    .payments-table {
        width: 100%;
        border-collapse: collapse;
    }
    .payments-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .payments-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .payments-table tr:hover {
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
        max-width: 600px;
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
        .payments-table {
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
    }
</style>

<div class="space-y-4">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Muhtasari wa Malipo</h2>
            <p class="text-secondary text-xs">Angalia malipo yote, njia za malipo na takwimu za kifedha</p>
        </div>
        <div class="flex gap-2">
            <a href="reports.php?type=financial" class="btn-outline">
                <span class="material-symbols-outlined text-sm">analytics</span>
                Ripoti za Kina
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($summary['total_payments'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($summary['total_amount'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Kiasi Kilicholipwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($summary['avg_amount'] ?? 0); ?></div>
            <div class="stat-label">Wastani wa Kiasi kwa Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($summary['pending_count'] ?? 0); ?></div>
            <div class="stat-label">Malipo Yanayosubiri</div>
            <div class="stat-total">Kiasi: <?php echo formatCurrency($summary['pending_amount'] ?? 0); ?></div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="filter-section">
        <!-- Status Tabs -->
        <div class="filter-tabs">
            <a href="?status=all&method=<?php echo $payment_method_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                Zote (<?php echo number_format($summary['total_payments'] ?? 0); ?>)
            </a>
            <a href="?status=completed&method=<?php echo $payment_method_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                Yaliyokamilika (<?php echo number_format($summary['completed_count'] ?? 0); ?>)
            </a>
            <a href="?status=processed&method=<?php echo $payment_method_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'processed' ? 'active' : ''; ?>">
                Yanayochakatwa (<?php echo number_format($summary['processed_count'] ?? 0); ?>)
            </a>
            <a href="?status=pending&method=<?php echo $payment_method_filter; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                Yanayosubiri (<?php echo number_format($summary['pending_count'] ?? 0); ?>)
            </a>
        </div>
        
        <!-- Search and Filter -->
        <form method="GET" action="" class="space-y-3">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 filter-row">
                <div>
                    <select name="method" class="form-select">
                        <option value="all" <?php echo $payment_method_filter === 'all' ? 'selected' : ''; ?>>-- Njia Zote za Malipo --</option>
                        <option value="bank_transfer" <?php echo $payment_method_filter === 'bank_transfer' ? 'selected' : ''; ?>>Uhamisho wa Benki</option>
                        <option value="mobile_money" <?php echo $payment_method_filter === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                        <option value="cash" <?php echo $payment_method_filter === 'cash' ? 'selected' : ''; ?>>Taslimu</option>
                        <option value="cheque" <?php echo $payment_method_filter === 'cheque' ? 'selected' : ''; ?>>Hundi</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <input type="text" name="search" class="search-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, namba ya marejeleo..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary flex-1">Tafuta</button>
                    <a href="payments.php" class="btn-outline">Reset</a>
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
    
    <!-- Payment Methods Breakdown -->
    <?php if (!empty($payment_methods)): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b">
            <h3 class="font-semibold text-sm">Usambazaji wa Njia za Malipo</h3>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php 
                $method_labels = [
                    'bank_transfer' => '🏦 Uhamisho wa Benki',
                    'mobile_money' => '📱 Mobile Money',
                    'cash' => '💰 Taslimu',
                    'cheque' => '📝 Hundi'
                ];
                foreach ($payment_methods as $method):
                    $method_name = $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']);
                ?>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <div class="text-lg font-bold text-primary"><?php echo number_format($method['count']); ?></div>
                    <div class="text-xs text-secondary">Malipo</div>
                    <div class="text-sm amount-positive mt-1"><?php echo formatCurrency($method['total_amount']); ?></div>
                    <div class="text-xs text-secondary mt-1"><?php echo $method_name; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Monthly Payments Trend -->
    <?php if (!empty($monthly_payments)): ?>
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b">
            <h3 class="font-semibold text-sm">Mwenendo wa Malipo kwa Mwezi</h3>
        </div>
        <div class="p-4">
            <?php 
            $max_amount = !empty($monthly_payments) ? max(array_column($monthly_payments, 'total_amount')) : 1;
            foreach ($monthly_payments as $payment): 
                $percentage = ($payment['total_amount'] / $max_amount) * 100;
            ?>
            <div class="flex items-center gap-3 mb-3">
                <div class="w-24 text-sm"><?php echo $payment['month_name']; ?></div>
                <div class="flex-1">
                    <div class="h-7 rounded-lg" style="width: <?php echo $percentage; ?>%; background: #006e2c; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px;">
                        <span class="text-white text-xs font-semibold"><?php echo number_format($payment['total_payments']); ?> malipo</span>
                    </div>
                </div>
                <div class="w-32 text-right text-sm font-semibold text-primary"><?php echo formatCurrency($payment['total_amount']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Payments Table -->
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th class="text-right">Kiasi</th>
                        <th>Njia ya Malipo</th>
                        <th>Namba ya Marejeleo</th>
                        <th>Tarehe ya Malipo</th>
                        <th>Hali</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">payments</span>
                            Hakuna malipo yanayoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                    <tr id="row-<?php echo $payment['id']; ?>">
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($payment['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($payment['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($payment['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive"><?php echo formatCurrency($payment['amount']); ?></td>
                        <td>
                            <?php 
                            $method_labels = [
                                'bank_transfer' => 'Uhamisho wa Benki',
                                'mobile_money' => 'Mobile Money',
                                'cash' => 'Taslimu',
                                'cheque' => 'Hundi'
                            ];
                            echo $method_labels[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($payment['paid_at'])); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $payment['payment_status']; ?>">
                                <?php 
                                $status_labels = [
                                    'completed' => 'Imekamilika',
                                    'processed' => 'Inachakatwa',
                                    'pending' => 'Inasubiri'
                                ];
                                echo $status_labels[$payment['payment_status']] ?? ucfirst($payment['payment_status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="action-btn" onclick="viewPaymentDetails(<?php echo $payment['id']; ?>)" title="Angalia Maelezo">
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
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_payments); ?> kati ya <?php echo $total_payments; ?>
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
                <p class="text-xs mt-1">Unaweza kuona muhtasari wa malipo yote, kuchuja kwa hali, njia ya malipo, tarehe, na kutafuta. Bonyeza kwenye malipo kuona maelezo kamili.</p>
            </div>
        </div>
    </div>
</div>

<!-- Payment Details Modal -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="text-lg font-semibold">Maelezo ya Malipo</h3>
            <button onclick="closeModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="paymentModalBody">
            <div class="text-center py-4">Bonyeza kwenye malipo kuona maelezo</div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal()" class="btn-outline">Funga</button>
        </div>
    </div>
</div>

<script>
    let currentPaymentId = null;
    
    // View payment details
    async function viewPaymentDetails(paymentId) {
        if (!paymentId || paymentId <= 0) {
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Payment ID si sahihi' });
            return;
        }
        
        currentPaymentId = paymentId;
        const modal = document.getElementById('paymentModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ 
            title: 'Inapakia...', 
            allowOutsideClick: false, 
            didOpen: () => Swal.showLoading()
        });
        
        try {
            const response = await fetch(`get-payment-details.php?id=${paymentId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success && data.data) {
                const payment = data.data;
                document.getElementById('paymentModalBody').innerHTML = `
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Taarifa za Malipo</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Namba ya Dai:</span><span class="font-mono">${escapeHtml(payment.claim_number)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Mwombaji:</span><span>${escapeHtml(payment.claimant_name)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Barua Pepe:</span><span>${escapeHtml(payment.email)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Simu:</span><span>${escapeHtml(payment.phone || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Mradi:</span><span>${escapeHtml(payment.project_name || '-')}</span></div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <h4 class="font-semibold text-sm mb-2">Maelezo ya Kifedha</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Kiasi cha Malipo:</span><span class="amount-positive">${formatCurrencyNumber(payment.amount)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Njia ya Malipo:</span><span>${getPaymentMethodLabel(payment.payment_method)}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Namba ya Marejeleo:</span><span>${escapeHtml(payment.transaction_reference || '-')}</span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Hali:</span><span><span class="status-badge status-${payment.payment_status}">${getPaymentStatusLabel(payment.payment_status)}</span></span></div>
                                <div class="flex justify-between py-1 border-b"><span class="font-medium">Tarehe ya Malipo:</span><span>${new Date(payment.paid_at).toLocaleString()}</span></div>
                                ${payment.notes ? `<div class="flex justify-between py-1 border-b"><span class="font-medium">Maelezo:</span><span>${escapeHtml(payment.notes)}</span></div>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata maelezo ya malipo' });
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
    
    function getPaymentMethodLabel(method) {
        const labels = {
            'bank_transfer': 'Uhamisho wa Benki',
            'mobile_money': 'Mobile Money',
            'cash': 'Taslimu',
            'cheque': 'Hundi'
        };
        return labels[method] || method;
    }
    
    function getPaymentStatusLabel(status) {
        const labels = {
            'completed': 'Imekamilika',
            'processed': 'Inachakatwa',
            'pending': 'Inasubiri'
        };
        return labels[status] || status;
    }
    
    function formatCurrencyNumber(amount) {
        return 'TZS ' + new Intl.NumberFormat().format(amount);
    }
    
    function closeModal() {
        const modal = document.getElementById('paymentModal');
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
    document.getElementById('paymentModal')?.addEventListener('click', function(e) {
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