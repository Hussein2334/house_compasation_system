<?php
// commissioner/reports.php - Commissioner Reports
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
$page_title = 'Reports';
$page_heading = 'Ripoti na Takwimu';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get report type from URL
$report_type = $_GET['type'] ?? 'dashboard';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Note: formatCurrency() function is already defined in includes/functions.php
// Do NOT redeclare it here

// ==================== DATA COLLECTION ====================

// 1. Claims Summary
$claims_summary = [];
$claims_query = "SELECT 
    COUNT(*) as total_claims,
    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
    SUM(CASE WHEN status = 'valuation' THEN 1 ELSE 0 END) as valuation,
    SUM(CASE WHEN status = 'legal_review' THEN 1 ELSE 0 END) as legal_review,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
    FROM claims";
$claims_result = mysqli_query($conn, $claims_query);
$claims_summary = mysqli_fetch_assoc($claims_result);

// 2. Financial Summary
$financial_summary = [];
$financial_query = "SELECT 
    COALESCE(SUM(v.total_compensation), 0) as total_compensation,
    COALESCE(SUM(p.amount), 0) as total_paid,
    COALESCE(SUM(v.total_compensation) - SUM(p.amount), 0) as balance
    FROM valuations v
    LEFT JOIN payments p ON v.claim_id = p.claim_id AND p.payment_status = 'completed'
    LEFT JOIN claims c ON v.claim_id = c.id
    WHERE c.status IN ('approved', 'paid')";
$financial_result = mysqli_query($conn, $financial_query);
$financial_summary = mysqli_fetch_assoc($financial_result);

// 3. Monthly Claims (Last 12 months)
$monthly_claims = [];
$monthly_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    DATE_FORMAT(created_at, '%M %Y') as month_name,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
    SUM(CASE WHEN status = 'valuation' THEN 1 ELSE 0 END) as valuation,
    SUM(CASE WHEN status = 'legal_review' THEN 1 ELSE 0 END) as legal_review,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
    FROM claims 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC";
$monthly_result = mysqli_query($conn, $monthly_query);
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_claims[] = $row;
}

// 4. Monthly Payments (Last 12 months)
$monthly_payments = [];
$payments_query = "SELECT 
    DATE_FORMAT(paid_at, '%Y-%m') as month,
    DATE_FORMAT(paid_at, '%M %Y') as month_name,
    COUNT(*) as total_payments,
    COALESCE(SUM(amount), 0) as total_amount
    FROM payments 
    WHERE payment_status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY YEAR(paid_at), MONTH(paid_at)
    ORDER BY paid_at ASC";
$payments_result = mysqli_query($conn, $payments_query);
while ($row = mysqli_fetch_assoc($payments_result)) {
    $monthly_payments[] = $row;
}

// 5. Claims by District
$district_claims = [];
$district_query = "SELECT 
    COALESCE(district, 'Not Specified') as district,
    COUNT(*) as total,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.status IN ('approved', 'paid')
    GROUP BY district
    ORDER BY total DESC
    LIMIT 10";
$district_result = mysqli_query($conn, $district_query);
while ($row = mysqli_fetch_assoc($district_result)) {
    $district_claims[] = $row;
}

// 6. Claims by Property Type
$property_claims = [];
$property_query = "SELECT 
    COALESCE(property_type, 'Not Specified') as property_type,
    COUNT(*) as total,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.status IN ('approved', 'paid')
    GROUP BY property_type
    ORDER BY total DESC";
$property_result = mysqli_query($conn, $property_query);
while ($row = mysqli_fetch_assoc($property_result)) {
    $property_claims[] = $row;
}

// 7. Top 10 Claims by Amount
$top_claims = [];
$top_claims_query = "SELECT 
    c.claim_number,
    u.full_name as claimant_name,
    c.project_name,
    c.district,
    v.total_compensation,
    c.status,
    c.created_at
    FROM claims c
    JOIN users u ON c.claimant_id = u.id
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE v.total_compensation IS NOT NULL AND c.status IN ('approved', 'paid')
    ORDER BY v.total_compensation DESC
    LIMIT 10";
$top_claims_result = mysqli_query($conn, $top_claims_query);
while ($row = mysqli_fetch_assoc($top_claims_result)) {
    $top_claims[] = $row;
}

// 8. Performance Metrics
$performance_metrics = [];
$metrics_query = "SELECT 
    (SELECT COUNT(*) FROM claims WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as claims_last_30days,
    (SELECT COUNT(*) FROM claims WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as claims_last_7days,
    (SELECT COUNT(*) FROM valuations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as valuations_last_30days,
    (SELECT COUNT(*) FROM payments WHERE payment_status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as payments_last_30days,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as amount_last_30days,
    (SELECT AVG(DATEDIFF(decision_date, created_at)) FROM claims WHERE decision_date IS NOT NULL AND status IN ('approved', 'rejected')) as avg_processing_days";
$metrics_result = mysqli_query($conn, $metrics_query);
$performance_metrics = mysqli_fetch_assoc($metrics_result);

// 9. Payment Methods Summary
$payment_methods = [];
$methods_query = "SELECT 
    COALESCE(payment_method, 'Not Specified') as payment_method,
    COUNT(*) as total,
    COALESCE(SUM(amount), 0) as total_amount
    FROM payments
    WHERE payment_status = 'completed'
    GROUP BY payment_method
    ORDER BY total_amount DESC";
$methods_result = mysqli_query($conn, $methods_query);
while ($row = mysqli_fetch_assoc($methods_result)) {
    $payment_methods[] = $row;
}

// Helper function to format currency (check if exists, if not define it)
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return 'TZS ' . number_format($amount, 0, '.', ',');
    }
}

require_once __DIR__ . '/includes/commissioner-header.php';
?>

<style>
    /* Report Container */
    .report-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    
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
        font-size: 0.75rem;
        color: #006e2c;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    
    /* Section Cards */
    .section-card {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .section-header {
        padding: 0.75rem 1rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .section-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .section-body {
        padding: 1rem;
    }
    
    /* Report Tabs */
    .report-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        border-bottom: 1px solid #e8f0e4;
        padding-bottom: 0.5rem;
    }
    .report-tab {
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
    .report-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .report-tab:not(.active) {
        background-color: white;
        color: #3d4a3d;
        border: 1px solid #bccab9;
    }
    .report-tab:not(.active):hover {
        background-color: #eef6ea;
    }
    
    /* Date Filter */
    .date-filter {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        padding: 0.75rem;
        margin-bottom: 1.5rem;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .date-filter .form-group {
        margin-bottom: 0;
    }
    .date-filter .form-label {
        font-size: 0.65rem;
        margin-bottom: 0.2rem;
    }
    .date-filter input {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
    }
    
    /* Table Styles */
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    .data-table th {
        padding: 0.75rem 0.75rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .data-table td {
        padding: 0.75rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.8rem;
    }
    .data-table tr:hover {
        background-color: #f4fcef;
    }
    
    /* Chart Bars */
    .chart-bar {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }
    .chart-label {
        width: 100px;
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
    
    /* Status Colors */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.6rem;
        font-weight: 600;
    }
    .status-submitted { background: #e9d5ff; color: #6b21a5; }
    .status-valuation { background: #fed7aa; color: #9a3412; }
    .status-legal_review { background: #cffafe; color: #0891b2; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .status-paid { background: #d1fae5; color: #006e2c; }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    .amount-negative {
        color: #dc2626;
        font-weight: 600;
    }
    
    /* Export Buttons */
    .export-buttons {
        display: flex;
        gap: 0.5rem;
    }
    .btn-export {
        background: white;
        border: 1px solid #bccab9;
        padding: 0.4rem 0.8rem;
        border-radius: 0.5rem;
        font-size: 0.7rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: all 0.2s;
    }
    .btn-export:hover {
        background: #eef6ea;
    }
    
    .btn-primary {
        background-color: #006e2c;
        color: white;
        padding: 0.4rem 1rem;
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
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .report-tabs {
            flex-direction: column;
        }
        .report-tab {
            justify-content: center;
        }
        .date-filter {
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
        .data-table {
            font-size: 0.7rem;
        }
        .data-table th, .data-table td {
            padding: 0.5rem;
        }
    }
    
    @media print {
        .report-tabs, .date-filter, .export-buttons, .btn-export, .action-btn {
            display: none !important;
        }
        .section-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        body {
            background: white;
            padding: 0;
            margin: 0;
        }
        .stat-card {
            border: 1px solid #ddd;
        }
    }
</style>

<div class="report-container">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-bold">Ripoti na Takwimu</h2>
            <p class="text-secondary text-xs mt-1">Takwimu za madai, malipo na tathmini za mfumo</p>
        </div>
        <div class="export-buttons">
            <button onclick="exportReport('pdf')" class="btn-export">
                <span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                PDF
            </button>
            <button onclick="exportReport('excel')" class="btn-export">
                <span class="material-symbols-outlined text-sm">table_chart</span>
                Excel
            </button>
            <button onclick="window.print()" class="btn-export">
                <span class="material-symbols-outlined text-sm">print</span>
                Chapisha
            </button>
        </div>
    </div>
    
    <!-- Report Tabs -->
    <div class="report-tabs">
        <a href="?type=dashboard" class="report-tab <?php echo $report_type == 'dashboard' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined text-sm">dashboard</span> Dashibodi
        </a>
        <a href="?type=claims" class="report-tab <?php echo $report_type == 'claims' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined text-sm">description</span> Ripoti za Madai
        </a>
        <a href="?type=financial" class="report-tab <?php echo $report_type == 'financial' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined text-sm">payments</span> Ripoti za Kifedha
        </a>
        <a href="?type=performance" class="report-tab <?php echo $report_type == 'performance' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined text-sm">speed</span> Utendaji
        </a>
    </div>
    
    <!-- Date Filter -->
    <div class="date-filter">
        <div class="form-group">
            <label class="form-label">Kuanzia Tarehe</label>
            <input type="date" id="date_from" class="form-input" value="<?php echo $date_from; ?>" style="width: auto;">
        </div>
        <div class="form-group">
            <label class="form-label">Mpaka Tarehe</label>
            <input type="date" id="date_to" class="form-input" value="<?php echo $date_to; ?>" style="width: auto;">
        </div>
        <button onclick="filterByDate()" class="btn-primary" style="padding: 0.4rem 1rem;">Tumia</button>
    </div>
    
    <?php if ($report_type == 'dashboard'): ?>
    
    <!-- ==================== DASHBOARD VIEW ==================== -->
    
    <!-- Main Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($claims_summary['total_claims'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Madai</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['total_compensation'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Fidia</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['total_paid'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['balance'] ?? 0); ?></div>
            <div class="stat-label">Salio la Malipo</div>
        </div>
    </div>
    
    <!-- Claims Status Distribution -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">pie_chart</span> Usambazaji wa Madai kwa Hali</h3>
        </div>
        <div class="section-body">
            <div class="space-y-2">
                <?php 
                $statuses = [
                    'submitted' => ['label' => 'Yaliyowasilishwa', 'color' => '#6b21a5'],
                    'valuation' => ['label' => 'Katika Tathmini', 'color' => '#d97706'],
                    'legal_review' => ['label' => 'Uhakiki wa Kisheria', 'color' => '#0891b2'],
                    'approved' => ['label' => 'Yaliyoidhinishwa', 'color' => '#065f46'],
                    'rejected' => ['label' => 'Yaliyokataliwa', 'color' => '#991b1b'],
                    'paid' => ['label' => 'Yaliyolipwa', 'color' => '#006e2c']
                ];
                $max_count = max(array_values(array_intersect_key($claims_summary, $statuses)));
                $max_count = $max_count > 0 ? $max_count : 1;
                foreach ($statuses as $key => $info):
                    $count = $claims_summary[$key] ?? 0;
                    $percentage = ($count / $max_count) * 100;
                ?>
                <div class="chart-bar">
                    <div class="chart-label"><?php echo $info['label']; ?></div>
                    <div class="flex-1">
                        <div class="h-7 rounded-lg" style="width: <?php echo $percentage; ?>%; background: <?php echo $info['color']; ?>; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px;">
                            <span class="text-white text-xs font-semibold"><?php echo number_format($count); ?></span>
                        </div>
                    </div>
                    <div class="chart-value"><?php echo number_format($count); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Monthly Claims Trend -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">show_chart</span> Mwenendo wa Madai kwa Mwezi</h3>
        </div>
        <div class="section-body">
            <?php if (empty($monthly_claims)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya madai kwa miezi 12 iliyopita</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Mwezi</th><th>Yaliyowasilishwa</th><th>Tathmini</th><th>Uhakiki</th><th>Yaliyoidhinishwa</th><th>Yaliyolipwa</th><th>Jumla</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_claims as $month): ?>
                        <tr>
                            <td><strong><?php echo $month['month_name']; ?></strong></td>
                            <td><?php echo number_format($month['submitted']); ?></td>
                            <td><?php echo number_format($month['valuation']); ?></td>
                            <td><?php echo number_format($month['legal_review']); ?></td>
                            <td class="amount-positive"><?php echo number_format($month['approved']); ?></td>
                            <td class="amount-positive"><?php echo number_format($month['paid']); ?></td>
                            <td class="font-semibold"><?php echo number_format($month['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Top Claims -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">trending_up</span> Madai 10 Bora kwa Kiasi</h3>
        </div>
        <div class="section-body p-0">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Namba ya Dai</th><th>Mwombaji</th><th>Mradi</th><th>Wilaya</th><th>Kiasi</th><th>Hali</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_claims)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-secondary">Hakuna madai yaliyoidhinishwa bado</td></tr>
                        <?php else: foreach ($top_claims as $claim): ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($claim['claimant_name']); ?></td>
                            <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($claim['district'] ?? '-'); ?></td>
                            <td class="amount-positive"><?php echo formatCurrency($claim['total_compensation']); ?></td>
                            <td><span class="status-badge status-<?php echo $claim['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $claim['status'])); ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($report_type == 'claims'): ?>
    
    <!-- ==================== CLAIMS REPORT ==================== -->
    
    <!-- Claims by District -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">location_on</span> Madai kwa Wilaya</h3>
        </div>
        <div class="section-body">
            <?php if (empty($district_claims)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya madai kwa wilaya</p>
            <?php else: ?>
            <?php 
            $max_district = !empty($district_claims) ? max(array_column($district_claims, 'total')) : 1;
            foreach ($district_claims as $district): 
                $percentage = ($district['total'] / $max_district) * 100;
            ?>
            <div class="chart-bar">
                <div class="chart-label"><?php echo htmlspecialchars($district['district']); ?></div>
                <div class="flex-1">
                    <div class="h-7 rounded-lg" style="width: <?php echo $percentage; ?>%; background: #006e2c; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px;">
                        <span class="text-white text-xs font-semibold"><?php echo number_format($district['total']); ?> madai</span>
                    </div>
                </div>
                <div class="chart-value"><?php echo formatCurrency($district['total_compensation']); ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Claims by Property Type -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">home</span> Madai kwa Aina ya Mali</h3>
        </div>
        <div class="section-body">
            <?php if (empty($property_claims)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya madai kwa aina ya mali</p>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($property_claims as $property): 
                    $type_labels = [
                        'land' => 'Shamba/Ardhi',
                        'building' => 'Jengo',
                        'crop' => 'Mazao',
                        'business' => 'Biashara',
                        'other' => 'Nyingine'
                    ];
                    $type_name = $type_labels[$property['property_type']] ?? ucfirst($property['property_type']);
                ?>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="font-semibold text-primary"><?php echo $type_name; ?></div>
                    <div class="text-2xl font-bold mt-1"><?php echo number_format($property['total']); ?></div>
                    <div class="text-xs text-secondary">Madai</div>
                    <div class="text-sm amount-positive mt-2"><?php echo formatCurrency($property['total_compensation']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif ($report_type == 'financial'): ?>
    
    <!-- ==================== FINANCIAL REPORT ==================== -->
    
    <!-- Financial Summary -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['total_compensation'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Fidia Iliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['total_paid'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Malipo Yaliyofanywa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($financial_summary['balance'] ?? 0); ?></div>
            <div class="stat-label">Salio la Malipo</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($performance_metrics['avg_processing_days'] ?? 0); ?> siku</div>
            <div class="stat-label">Wastani wa Siku za Usindikaji</div>
        </div>
    </div>
    
    <!-- Monthly Payments Trend -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">trending_up</span> Mwenendo wa Malipo kwa Mwezi</h3>
        </div>
        <div class="section-body">
            <?php if (empty($monthly_payments)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya malipo kwa miezi 12 iliyopita</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Mwezi</th><th>Idadi ya Malipo</th><th>Jumla ya Kiasi</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_payments as $payment): ?>
                        <tr>
                            <td><strong><?php echo $payment['month_name']; ?></strong></td>
                            <td><?php echo number_format($payment['total_payments']); ?> malipo</td>
                            <td class="amount-positive"><?php echo formatCurrency($payment['total_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payment Methods -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">credit_card</span> Usambazaji wa Njia za Malipo</h3>
        </div>
        <div class="section-body">
            <?php if (empty($payment_methods)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya njia za malipo</p>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($payment_methods as $method):
                    $method_labels = [
                        'bank_transfer' => 'Uhamisho wa Benki',
                        'mobile_money' => 'Mobile Money',
                        'cash' => 'Taslimu',
                        'cheque' => 'Hundi'
                    ];
                    $method_name = $method_labels[$method['payment_method']] ?? ucfirst($method['payment_method']);
                ?>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="font-semibold text-primary"><?php echo $method_name; ?></div>
                    <div class="text-2xl font-bold mt-1"><?php echo number_format($method['total']); ?></div>
                    <div class="text-xs text-secondary">Malipo</div>
                    <div class="text-sm amount-positive mt-2"><?php echo formatCurrency($method['total_amount']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif ($report_type == 'performance'): ?>
    
    <!-- ==================== PERFORMANCE REPORT ==================== -->
    
    <!-- Performance Metrics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($performance_metrics['claims_last_7days'] ?? 0); ?></div>
            <div class="stat-label">Madai Siku 7 Zilizopita</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($performance_metrics['claims_last_30days'] ?? 0); ?></div>
            <div class="stat-label">Madai Siku 30 Zilizopita</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($performance_metrics['valuations_last_30days'] ?? 0); ?></div>
            <div class="stat-label">Tathmini Siku 30 Zilizopita</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo formatCurrency($performance_metrics['amount_last_30days'] ?? 0); ?></div>
            <div class="stat-label">Malipo Siku 30 Zilizopita</div>
        </div>
    </div>
    
    <!-- Processing Time Info -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">schedule</span> Muda wa Usindikaji</h3>
        </div>
        <div class="section-body">
            <div class="text-center p-4">
                <div class="text-3xl font-bold text-primary"><?php echo number_format($performance_metrics['avg_processing_days'] ?? 0); ?> siku</div>
                <p class="text-secondary text-sm mt-2">Wastani wa siku zinazochukuliwa kusindika dai (kuanzia kuwasilishwa hadi kuamuliwa)</p>
            </div>
        </div>
    </div>
    
    <!-- Monthly Performance -->
    <div class="section-card">
        <div class="section-header">
            <h3><span class="material-symbols-outlined text-primary text-sm">analytics</span> Utendaji kwa Mwezi</h3>
        </div>
        <div class="section-body">
            <?php if (empty($monthly_claims)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya utendaji kwa miezi 12 iliyopita</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Mwezi</th><th>Madai Yaliyowasilishwa</th><th>Yaliyoidhinishwa</th><th>Yaliyolipwa</th><th>Kiwango cha Uidhinishaji</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_claims as $month):
                            $approval_rate = $month['total'] > 0 ? round(($month['approved'] / $month['total']) * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo $month['month_name']; ?></strong></td>
                            <td><?php echo number_format($month['submitted']); ?></td>
                            <td class="amount-positive"><?php echo number_format($month['approved']); ?></td>
                            <td class="amount-positive"><?php echo number_format($month['paid']); ?></td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="w-24 bg-gray-200 rounded-full h-2">
                                        <div class="bg-primary h-2 rounded-full" style="width: <?php echo $approval_rate; ?>%"></div>
                                    </div>
                                    <span class="text-xs font-semibold"><?php echo $approval_rate; ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>
    
</div>

<script>
    // Filter by date
    function filterByDate() {
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('date_from', dateFrom);
        currentUrl.searchParams.set('date_to', dateTo);
        window.location.href = currentUrl.toString();
    }
    
    // Export report
    function exportReport(format) {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('export', format);
        window.location.href = currentUrl.toString();
    }
</script>

<?php require_once __DIR__ . '/includes/commissioner-footer.php'; ?>