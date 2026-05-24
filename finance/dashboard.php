<?php
// finance/dashboard.php - Finance Officer Dashboard
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
$page_title = 'Finance Dashboard';
$page_heading = 'Dashibodi ya Fedha';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get financial statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM payments) as total_payments,
    (SELECT COUNT(*) FROM payments WHERE payment_status = 'completed') as completed_payments,
    (SELECT COUNT(*) FROM payments WHERE payment_status = 'pending') as pending_payments,
    (SELECT COUNT(*) FROM payments WHERE payment_status = 'processed') as processed_payments,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'completed') as total_paid_amount,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(paid_at) = MONTH(CURRENT_DATE()) AND YEAR(paid_at) = YEAR(CURRENT_DATE())) as monthly_paid_amount,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(paid_at) = CURDATE()) as today_paid_amount,
    (SELECT COALESCE(AVG(amount), 0) FROM payments WHERE payment_status = 'completed') as avg_payment_amount";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get recent payments - FIXED: Use paid_at instead of created_at
$recent_payments_query = "SELECT p.*, c.claim_number, c.project_name, u.full_name as claimant_name
                          FROM payments p
                          JOIN claims c ON p.claim_id = c.id
                          JOIN users u ON c.claimant_id = u.id
                          ORDER BY p.paid_at DESC
                          LIMIT 10";
$recent_payments_result = mysqli_query($conn, $recent_payments_query);
$recent_payments = [];
while ($row = mysqli_fetch_assoc($recent_payments_result)) {
    $recent_payments[] = $row;
}

// Get payment method distribution
$method_stats_query = "SELECT 
    payment_method,
    COUNT(*) as count,
    SUM(amount) as total
    FROM payments
    WHERE payment_status = 'completed'
    GROUP BY payment_method";
$method_stats_result = mysqli_query($conn, $method_stats_query);
$method_stats = [];
while ($row = mysqli_fetch_assoc($method_stats_result)) {
    $method_stats[] = $row;
}

// Get monthly payment trend (last 6 months) - FIXED: Use paid_at
$monthly_trend_query = "SELECT 
    DATE_FORMAT(paid_at, '%M %Y') as month,
    COUNT(*) as count,
    SUM(amount) as total
    FROM payments
    WHERE payment_status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND paid_at IS NOT NULL
    GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
    ORDER BY paid_at ASC";
$monthly_trend_result = mysqli_query($conn, $monthly_trend_query);
$monthly_trend = [];
while ($row = mysqli_fetch_assoc($monthly_trend_result)) {
    $monthly_trend[] = $row;
}

// If no data in monthly_trend, show empty array
if (!$monthly_trend) {
    $monthly_trend = [];
}

require_once __DIR__ . '/includes/finance-header.php';
?>

<style>
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
    
    .welcome-banner {
        background: linear-gradient(135deg, #006e2c 0%, #1eb050 100%);
        border-radius: 0.75rem;
        padding: 1.25rem;
        color: white;
        margin-bottom: 1.5rem;
    }
    
    .section-card {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1rem;
    }
    .section-header {
        padding: 0.75rem 1rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .section-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .section-body {
        padding: 1rem;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    .data-table th {
        padding: 0.5rem 0.75rem;
        text-align: left;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .data-table td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #e8f0e4;
        font-size: 0.8rem;
    }
    .data-table tr:hover {
        background-color: #f4fcef;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
        gap: 0.2rem;
    }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.processed { background: #fef3c7; color: #92400e; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .method-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .method-name {
        font-weight: 500;
    }
    .method-stats {
        text-align: right;
    }
    .method-count {
        font-size: 0.75rem;
        color: #6d7b6c;
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .data-table {
            min-width: 500px;
        }
        .table-container {
            overflow-x: auto;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">Karibu, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
                <p class="text-green-100 text-xs mt-0.5">Karibu kwenye Mfumo wa Usimamizi wa Malipo</p>
            </div>
            <div>
                <a href="payments.php?status=pending" class="inline-flex items-center gap-1 bg-white text-primary px-3 py-1.5 rounded-lg font-semibold text-sm hover:bg-green-50 transition">
                    <span class="material-symbols-outlined text-sm">payments</span>
                    Fanya Malipo
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($stats['total_paid_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">check_circle</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['completed_payments'] ?? 0); ?></div>
            <div class="stat-label">Yamekamilika</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_payments'] ?? 0); ?></div>
            <div class="stat-label">Yanatarajiwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">trending_up</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($stats['today_paid_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Malipo ya Leo</div>
        </div>
    </div>
    
    <!-- Recent Payments and Payment Methods -->
    <div class="grid-2">
        
        <!-- Recent Payments -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary text-sm">history</span>
                    Malipo ya Hivi Karibuni
                </h3>
                <a href="payments.php" class="text-xs text-primary hover:underline">Angalia Yote →</a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Kiasi</th>
                            <th>Hali</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-6 text-secondary">Hakuna malipo ya hivi karibuni</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td class="font-mono text-xs"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($payment['claimant_name']); ?></td>
                            <td class="font-semibold text-primary">TZS <?php echo number_format($payment['amount'] ?? 0, 0, '.', ','); ?></td>
                            <td><span class="status-badge <?php echo $payment['payment_status']; ?>"><?php echo ucfirst($payment['payment_status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Payment Methods Distribution -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary text-sm">pie_chart</span>
                    Njia za Malipo
                </h3>
            </div>
            <div class="section-body">
                <?php if (empty($method_stats)): ?>
                <div class="text-center py-6 text-secondary">Hakuna data ya njia za malipo</div>
                <?php else: ?>
                <?php foreach ($method_stats as $method): ?>
                <div class="method-item">
                    <div>
                        <div class="method-name">
                            <?php 
                            $method_labels = [
                                'bank_transfer' => '🏦 Benki',
                                'mobile_money' => '📱 Mobile Money',
                                'cash' => '💰 Taslimu',
                                'cheque' => '📝 Hundi'
                            ];
                            echo $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']);
                            ?>
                        </div>
                        <div class="method-count">Malipo: <?php echo number_format($method['count']); ?></div>
                    </div>
                    <div class="method-stats">
                        <div class="amount-positive">TZS <?php echo number_format($method['total'], 0, '.', ','); ?></div>
                        <div class="method-count"><?php $stats['total_paid_amount'] > 0 ? number_format(($method['total'] / $stats['total_paid_amount']) * 100, 1) : 0; ?>%</div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Monthly Payment Trend -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">trending_up</span>
                Mwelekeo wa Malipo Kwa Mwezi
            </h3>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla ya Malipo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_trend)): ?>
                    <tr>
                        <td colspan="3" class="text-center py-6 text-secondary">Hakuna data ya mwelekeo wa malipo</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($monthly_trend as $trend): ?>
                    <tr>
                        <td class="font-medium"><?php echo $trend['month']; ?></td>
                        <td class="text-right"><?php echo number_format($trend['count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($trend['total'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">bolt</span>
                Vitendo vya Haraka
            </h3>
        </div>
        <div class="section-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <a href="payments.php?status=pending" class="flex items-center gap-2 p-2 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition">
                    <span class="material-symbols-outlined text-yellow-600 text-sm">pending</span>
                    <span class="text-xs font-medium">Malipo Yanayosubiri</span>
                </a>
                <a href="payments.php" class="flex items-center gap-2 p-2 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <span class="material-symbols-outlined text-blue-600 text-sm">payments</span>
                    <span class="text-xs font-medium">Malipo Yote</span>
                </a>
                <a href="reports.php" class="flex items-center gap-2 p-2 bg-green-50 rounded-lg hover:bg-green-100 transition">
                    <span class="material-symbols-outlined text-green-600 text-sm">assessment</span>
                    <span class="text-xs font-medium">Ripoti</span>
                </a>
                <a href="help.php" class="flex items-center gap-2 p-2 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                    <span class="material-symbols-outlined text-purple-600 text-sm">help</span>
                    <span class="text-xs font-medium">Msaada</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Info Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-blue-600 text-sm">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Taarifa</p>
                <p class="text-xs text-blue-700">Hakikisha unathibitisha malipo yote kabla ya kuweka kama yamekamilika. Malipo yote yanapaswa kuwa na namba ya marejeleo kwa ajili ya kumbukumbu.</p>
            </div>
        </div>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/finance-footer.php'; ?>