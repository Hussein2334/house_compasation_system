<?php
// admin/reports.php - Comprehensive System Reports (FIXED with table prefixes)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and has access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'finance_officer' && $_SESSION['role'] !== 'commissioner') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'System Reports';
$page_heading = 'Ripoti za Mfumo';

// Get database connection
$conn = getDB();

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'dashboard';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$year = $_GET['year'] ?? date('Y');
$quarter = $_GET['quarter'] ?? date('Y') . '-Q' . ceil(date('n') / 3);

// Get years for filter - FIXED: Use proper table prefixes
$years_query = "SELECT DISTINCT YEAR(created_at) as year FROM claims 
                UNION 
                SELECT DISTINCT YEAR(paid_at) as year FROM payments WHERE paid_at IS NOT NULL 
                ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $years[] = $row['year'];
}

// ========== DASHBOARD STATISTICS ==========
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM claims) as total_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'submitted') as submitted_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'valuation') as valuation_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'legal_review') as legal_review_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'approved') as approved_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'rejected') as rejected_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'paid') as paid_claims,
    (SELECT COUNT(*) FROM users WHERE role = 'claimant') as total_claimants,
    (SELECT COUNT(*) FROM users WHERE role IN ('valuer', 'legal_officer', 'finance_officer', 'commissioner')) as total_staff,
    (SELECT COUNT(*) FROM payments) as total_payments,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'completed') as total_paid_amount,
    (SELECT COALESCE(SUM(total_compensation), 0) FROM valuations) as total_valuation_amount,
    (SELECT COALESCE(AVG(total_compensation), 0) FROM valuations) as avg_compensation";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// ========== MONTHLY CLAIMS TREND ==========
$monthly_claims_query = "SELECT 
    DATE_FORMAT(c.created_at, '%Y-%m') as month,
    DATE_FORMAT(c.created_at, '%M %Y') as month_name,
    COUNT(*) as total_claims,
    SUM(CASE WHEN c.status = 'submitted' THEN 1 ELSE 0 END) as submitted,
    SUM(CASE WHEN c.status = 'valuation' THEN 1 ELSE 0 END) as valuation,
    SUM(CASE WHEN c.status = 'legal_review' THEN 1 ELSE 0 END) as legal_review,
    SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN c.status = 'paid' THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM claims c
    WHERE YEAR(c.created_at) = ?
    GROUP BY DATE_FORMAT(c.created_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_stmt = mysqli_prepare($conn, $monthly_claims_query);
mysqli_stmt_bind_param($monthly_stmt, "s", $year);
mysqli_stmt_execute($monthly_stmt);
$monthly_claims_result = mysqli_stmt_get_result($monthly_stmt);
$monthly_claims = [];
while ($row = mysqli_fetch_assoc($monthly_claims_result)) {
    $monthly_claims[] = $row;
}

// ========== CLAIMS BY PROJECT ==========
$by_project_query = "SELECT 
    c.project_name,
    COUNT(*) as total_claims,
    SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN c.status = 'paid' THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.project_name IS NOT NULL AND c.project_name != ''
    GROUP BY c.project_name
    ORDER BY total_claims DESC
    LIMIT 10";
$by_project_result = mysqli_query($conn, $by_project_query);
$project_stats = [];
while ($row = mysqli_fetch_assoc($by_project_result)) {
    $project_stats[] = $row;
}

// ========== CLAIMS BY DISTRICT ==========
$by_district_query = "SELECT 
    c.district,
    COUNT(*) as total_claims,
    SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN c.status = 'paid' THEN 1 ELSE 0 END) as paid,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.district IS NOT NULL AND c.district != ''
    GROUP BY c.district
    ORDER BY total_claims DESC
    LIMIT 10";
$by_district_result = mysqli_query($conn, $by_district_query);
$district_stats = [];
while ($row = mysqli_fetch_assoc($by_district_result)) {
    $district_stats[] = $row;
}

// ========== PAYMENT SUMMARY ==========
$payment_summary_query = "SELECT 
    p.payment_method,
    COUNT(*) as payment_count,
    SUM(p.amount) as total_amount,
    AVG(p.amount) as avg_amount
    FROM payments p
    WHERE p.payment_status = 'completed'
    GROUP BY p.payment_method";
$payment_summary_result = mysqli_query($conn, $payment_summary_query);
$payment_methods_stats = [];
while ($row = mysqli_fetch_assoc($payment_summary_result)) {
    $payment_methods_stats[] = $row;
}

// ========== MONTHLY PAYMENTS ==========
$monthly_payments_query = "SELECT 
    DATE_FORMAT(p.paid_at, '%Y-%m') as month,
    DATE_FORMAT(p.paid_at, '%M %Y') as month_name,
    COUNT(*) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE p.payment_status = 'completed' AND YEAR(p.paid_at) = ? AND p.paid_at IS NOT NULL
    GROUP BY DATE_FORMAT(p.paid_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_payments_stmt = mysqli_prepare($conn, $monthly_payments_query);
mysqli_stmt_bind_param($monthly_payments_stmt, "s", $year);
mysqli_stmt_execute($monthly_payments_stmt);
$monthly_payments_result = mysqli_stmt_get_result($monthly_payments_stmt);
$monthly_payments = [];
while ($row = mysqli_fetch_assoc($monthly_payments_result)) {
    $monthly_payments[] = $row;
}

// ========== QUARTERLY REPORTS ==========
$quarter_parts = explode('-Q', $quarter);
$quarter_year = $quarter_parts[0];
$quarter_num = $quarter_parts[1];

// Calculate quarter months
$quarter_months = [
    1 => ['01', '02', '03'],
    2 => ['04', '05', '06'],
    3 => ['07', '08', '09'],
    4 => ['10', '11', '12']
];
$quarter_months_list = $quarter_months[$quarter_num];

$quarterly_claims_query = "SELECT 
    COUNT(*) as total_claims,
    SUM(CASE WHEN c.status = 'submitted' THEN 1 ELSE 0 END) as submitted,
    SUM(CASE WHEN c.status = 'valuation' THEN 1 ELSE 0 END) as valuation,
    SUM(CASE WHEN c.status = 'legal_review' THEN 1 ELSE 0 END) as legal_review,
    SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN c.status = 'paid' THEN 1 ELSE 0 END) as paid,
    SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation
    FROM claims c
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE YEAR(c.created_at) = ? AND MONTH(c.created_at) IN (?, ?, ?)";
$quarterly_stmt = mysqli_prepare($conn, $quarterly_claims_query);
mysqli_stmt_bind_param($quarterly_stmt, "siii", $quarter_year, $quarter_months_list[0], $quarter_months_list[1], $quarter_months_list[2]);
mysqli_stmt_execute($quarterly_stmt);
$quarterly_claims_result = mysqli_stmt_get_result($quarterly_stmt);
$quarterly_claims = mysqli_fetch_assoc($quarterly_claims_result);

$quarterly_payments_query = "SELECT 
    COUNT(*) as payment_count,
    SUM(p.amount) as total_amount
    FROM payments p
    WHERE p.payment_status = 'completed' AND YEAR(p.paid_at) = ? AND MONTH(p.paid_at) IN (?, ?, ?) AND p.paid_at IS NOT NULL";
$quarterly_payments_stmt = mysqli_prepare($conn, $quarterly_payments_query);
mysqli_stmt_bind_param($quarterly_payments_stmt, "siii", $quarter_year, $quarter_months_list[0], $quarter_months_list[1], $quarter_months_list[2]);
mysqli_stmt_execute($quarterly_payments_stmt);
$quarterly_payments_result = mysqli_stmt_get_result($quarterly_payments_stmt);
$quarterly_payments = mysqli_fetch_assoc($quarterly_payments_result);

// ========== TOP CLAIMANTS ==========
$top_claimants_query = "SELECT 
    u.full_name, u.email, u.phone,
    COUNT(c.id) as claim_count,
    COALESCE(SUM(v.total_compensation), 0) as total_compensation,
    MAX(c.status) as latest_status
    FROM users u
    JOIN claims c ON u.id = c.claimant_id
    LEFT JOIN valuations v ON c.id = v.claim_id
    GROUP BY u.id
    ORDER BY total_compensation DESC
    LIMIT 10";
$top_claimants_result = mysqli_query($conn, $top_claimants_query);
$top_claimants = [];
while ($row = mysqli_fetch_assoc($top_claimants_result)) {
    $top_claimants[] = $row;
}

// ========== RECENT ACTIVITIES ==========
$recent_activities_query = "(
    SELECT 'claim' as type, c.id, c.claim_number as reference, c.status, c.created_at as activity_date 
    FROM claims c
    ORDER BY c.created_at DESC
    LIMIT 5
)
UNION ALL
(
    SELECT 'payment' as type, p.id, CONCAT('Payment for claim ', p.claim_id) as reference, p.payment_status as status, p.paid_at as activity_date 
    FROM payments p
    WHERE p.payment_status = 'completed' AND p.paid_at IS NOT NULL
    ORDER BY p.paid_at DESC
    LIMIT 5
)
UNION ALL
(
    SELECT 'valuation' as type, v.id, CONCAT('Valuation for claim ', v.claim_id) as reference, 'completed' as status, v.created_at as activity_date 
    FROM valuations v
    ORDER BY v.created_at DESC
    LIMIT 5
)
ORDER BY activity_date DESC
LIMIT 10";
$recent_activities_result = mysqli_query($conn, $recent_activities_query);
$recent_activities = [];
while ($row = mysqli_fetch_assoc($recent_activities_result)) {
    $recent_activities[] = $row;
}

// Handle export
if (isset($_GET['export']) && isset($_GET['export_type'])) {
    $export_type = $_GET['export_type'];
    
    if ($export_type === 'claims_summary') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="claims_summary_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Month', 'Total Claims', 'Submitted', 'Valuation', 'Legal Review', 'Approved', 'Paid', 'Rejected']);
        foreach ($monthly_claims as $row) {
            fputcsv($output, [
                $row['month_name'],
                $row['total_claims'],
                $row['submitted'],
                $row['valuation'],
                $row['legal_review'],
                $row['approved'],
                $row['paid'],
                $row['rejected']
            ]);
        }
        fclose($output);
        exit();
    } elseif ($export_type === 'payments_summary') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="payments_summary_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Month', 'Payment Count', 'Total Amount (TZS)']);
        foreach ($monthly_payments as $row) {
            fputcsv($output, [
                $row['month_name'],
                $row['payment_count'],
                number_format($row['total_amount'], 2)
            ]);
        }
        fclose($output);
        exit();
    } elseif ($export_type === 'projects') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="projects_summary_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Project Name', 'Total Claims', 'Approved', 'Paid', 'Rejected', 'Total Compensation (TZS)']);
        foreach ($project_stats as $row) {
            fputcsv($output, [
                $row['project_name'],
                $row['total_claims'],
                $row['approved'],
                $row['paid'],
                $row['rejected'],
                number_format($row['total_compensation'], 2)
            ]);
        }
        fclose($output);
        exit();
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<!-- The rest of the HTML/CSS remains the same as before -->
<!-- [Keep all the CSS styles from the previous version] -->

<style>
    /* Stats Cards */
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        transition: all 0.2s;
    }
    .stat-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transform: translateY(-2px);
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
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
    }
    
    /* Report Sections */
    .report-section {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .report-header {
        padding: 1rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .report-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .report-body {
        padding: 1.5rem;
    }
    
    /* Tables */
    .report-table {
        width: 100%;
        border-collapse: collapse;
    }
    .report-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .report-table td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .report-table tr:hover {
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
    .filter-select, .filter-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
    }
    .filter-select:focus, .filter-input:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .btn-filter {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .btn-filter:hover {
        background-color: #005a24;
    }
    .btn-export {
        background-color: white;
        color: #3d4a3d;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-export:hover {
        background-color: #eef6ea;
        border-color: #006e2c;
    }
    
    /* Report Tabs */
    .report-tab {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
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
    .amount-negative {
        color: #dc2626;
        font-weight: 600;
    }
    
    .grid-4 {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
    }
    .grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
    }
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .progress-bar {
        background-color: #e8f0e4;
        border-radius: 9999px;
        overflow: hidden;
        height: 8px;
    }
    .progress-fill {
        background-color: #006e2c;
        height: 100%;
        border-radius: 9999px;
        transition: width 0.3s ease;
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
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    
    @media (max-width: 768px) {
        .grid-4, .grid-3 {
            grid-template-columns: repeat(2, 1fr);
        }
        .grid-2 {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 640px) {
        .grid-4, .grid-3 {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Ripoti za Mfumo</h2>
            <p class="text-secondary text-sm mt-1">Takwimu kamili na ripoti za uchambuzi wa mfumo wa fidia</p>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?report_type=dashboard" class="report-tab <?php echo $report_type === 'dashboard' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">dashboard</span> Dashboard
        </a>
        <a href="?report_type=claims_analysis" class="report-tab <?php echo $report_type === 'claims_analysis' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">analytics</span> Uchambuzi wa Madai
        </a>
        <a href="?report_type=financial" class="report-tab <?php echo $report_type === 'financial' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">payments</span> Ripoti za Kifedha
        </a>
        <a href="?report_type=performance" class="report-tab <?php echo $report_type === 'performance' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">speed</span> Utendaji
        </a>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
            
            <?php if ($report_type === 'claims_analysis' || $report_type === 'financial'): ?>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mwaka</label>
                <select name="year" class="filter-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if ($report_type === 'performance'): ?>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Robo ya Mwaka</label>
                <select name="quarter" class="filter-select">
                    <option value="<?php echo date('Y'); ?>-Q1" <?php echo $quarter == date('Y') . '-Q1' ? 'selected' : ''; ?>>Q1 (Jan-Mar)</option>
                    <option value="<?php echo date('Y'); ?>-Q2" <?php echo $quarter == date('Y') . '-Q2' ? 'selected' : ''; ?>>Q2 (Apr-Jun)</option>
                    <option value="<?php echo date('Y'); ?>-Q3" <?php echo $quarter == date('Y') . '-Q3' ? 'selected' : ''; ?>>Q3 (Jul-Sep)</option>
                    <option value="<?php echo date('Y'); ?>-Q4" <?php echo $quarter == date('Y') . '-Q4' ? 'selected' : ''; ?>>Q4 (Oct-Dec)</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div>
                <button type="submit" class="btn-filter flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <?php if ($report_type === 'dashboard'): ?>
    <!-- DASHBOARD REPORT -->
    
    <!-- Key Statistics -->
    <div class="grid-4">
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                    <span class="material-symbols-outlined">description</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_claims']); ?></div>
            <div class="stat-label">Jumla ya Madai</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                    <span class="material-symbols-outlined">payments</span>
                </div>
            </div>
            <div class="stat-value">TZS <?php echo number_format($stats['total_paid_amount'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Malipo</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                    <span class="material-symbols-outlined">people</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_claimants']); ?></div>
            <div class="stat-label">Jumla ya Wadai</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                    <span class="material-symbols-outlined">badge</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_staff']); ?></div>
            <div class="stat-label">Wafanyakazi</div>
        </div>
    </div>
    
    <!-- Claim Status Distribution -->
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">pie_chart</span> Mgawanyo wa Hali za Madai</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imewasilishwa</span>
                            <span><?php echo number_format($stats['submitted_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['submitted_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['submitted_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #fef3c7;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Tathmini</span>
                            <span><?php echo number_format($stats['valuation_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['valuation_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['valuation_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #fed7aa;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Uhakiki</span>
                            <span><?php echo number_format($stats['legal_review_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['legal_review_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['legal_review_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #e9d5ff;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imeidhinishwa</span>
                            <span><?php echo number_format($stats['approved_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['approved_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['approved_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #d1fae5;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imelipwa</span>
                            <span><?php echo number_format($stats['paid_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['paid_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['paid_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #a7f3d0;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imekataliwa</span>
                            <span><?php echo number_format($stats['rejected_claims']); ?> (<?php echo $stats['total_claims'] > 0 ? round(($stats['rejected_claims'] / $stats['total_claims']) * 100, 1) : 0; ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $stats['total_claims'] > 0 ? ($stats['rejected_claims'] / $stats['total_claims']) * 100 : 0; ?>%; background: #fee2e2;"></div></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Financial Summary -->
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">attach_money</span> Muhtasari wa Kifedha</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Thamani ya Tathmini:</span>
                        <span class="amount-positive">TZS <?php echo number_format($stats['total_valuation_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Malipo Yaliyofanywa:</span>
                        <span class="amount-positive">TZS <?php echo number_format($stats['total_paid_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Wastani wa Fidia kwa Dai:</span>
                        <span class="amount-positive">TZS <?php echo number_format($stats['avg_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <span class="font-semibold">Idadi ya Malipo:</span>
                        <span class="font-semibold"><?php echo number_format($stats['total_payments']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Top Projects and Districts -->
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">construction</span> Madai Kwa Mradi (Top 5)</h3>
                <button onclick="window.location.href='?export=1&export_type=projects'" class="btn-export text-xs">Export</button>
            </div>
            <div class="report-body">
                <table class="report-table">
                    <thead>
                        <tr><th>Mradi</th><th class="text-right">Madai</th><th class="text-right">Fidia (TZS)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($project_stats, 0, 5) as $project): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                            <td class="text-right"><?php echo number_format($project['total_claims']); ?></td>
                            <td class="text-right amount-positive"><?php echo number_format($project['total_compensation'], 0, '.', ','); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">location_on</span> Madai Kwa Wilaya (Top 5)</h3>
            </div>
            <div class="report-body">
                <table class="report-table">
                    <thead>
                        <tr><th>Wilaya</th><th class="text-right">Madai</th><th class="text-right">Fidia (TZS)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($district_stats, 0, 5) as $district): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($district['district']); ?></td>
                            <td class="text-right"><?php echo number_format($district['total_claims']); ?></td>
                            <td class="text-right amount-positive"><?php echo number_format($district['total_compensation'], 0, '.', ','); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($report_type === 'claims_analysis'): ?>
    <!-- CLAIMS ANALYSIS REPORT -->
    
    <!-- Monthly Claims Trend -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">trending_up</span> Mwelekeo wa Madai Kwa Mwezi - <?php echo $year; ?></h3>
            <button onclick="window.location.href='?export=1&export_type=claims_summary&year=<?php echo $year; ?>'" class="btn-export text-xs">Export CSV</button>
        </div>
        <div class="report-body overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Jumla</th>
                        <th class="text-right">Imewasilishwa</th>
                        <th class="text-right">Tathmini</th>
                        <th class="text-right">Uhakiki</th>
                        <th class="text-right">Imeidhinishwa</th>
                        <th class="text-right">Imelipwa</th>
                        <th class="text-right">Imekataliwa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_claims)): ?>
                    <tr><td colspan="8" class="text-center py-8 text-secondary">Hakuna data ya madai kwa mwaka huu</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_claims as $month): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($month['month_name']); ?></td>
                        <td class="text-right font-semibold"><?php echo number_format($month['total_claims']); ?></td>
                        <td class="text-right"><?php echo number_format($month['submitted']); ?></td>
                        <td class="text-right"><?php echo number_format($month['valuation']); ?></td>
                        <td class="text-right"><?php echo number_format($month['legal_review']); ?></td>
                        <td class="text-right"><?php echo number_format($month['approved']); ?></td>
                        <td class="text-right amount-positive"><?php echo number_format($month['paid']); ?></td>
                        <td class="text-right amount-negative"><?php echo number_format($month['rejected']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
    
    <!-- Top Claimants -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">leaderboard</span> Wadai Bora</h3>
        </div>
        <div class="report-body overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Jina Kamili</th>
                        <th>Barua Pepe</th>
                        <th class="text-right">Idadi ya Madai</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th>Hali</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_claimants as $claimant): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($claimant['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($claimant['email']); ?></td>
                        <td class="text-right"><?php echo number_format($claimant['claim_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($claimant['total_compensation'], 0, '.', ','); ?></td>
                        <td><span class="status-badge <?php echo $claimant['latest_status']; ?>"><?php echo getStatusLabel($claimant['latest_status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'financial'): ?>
    <!-- FINANCIAL REPORT -->
    
    <!-- Monthly Payments -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">payments</span> Malipo Kwa Mwezi - <?php echo $year; ?></h3>
            <button onclick="window.location.href='?export=1&export_type=payments_summary&year=<?php echo $year; ?>'" class="btn-export text-xs">Export CSV</button>
        </div>
        <div class="report-body overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Malipo</th>
                        <th class="text-right">Jumla ya Malipo (TZS)</th>
                        <th class="text-right">Wastani kwa Malipo (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_payments)): ?>
                    <tr><td colspan="4" class="text-center py-8 text-secondary">Hakuna malipo kwa mwaka huu</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_payments as $payment): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($payment['month_name']); ?></td>
                        <td class="text-right"><?php echo number_format($payment['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($payment['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($payment['total_amount'] / $payment['payment_count'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Payment Methods -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">credit_card</span> Njia za Malipo</h3>
        </div>
        <div class="report-body overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Njia ya Malipo</th>
                        <th class="text-right">Idadi</th>
                        <th class="text-right">Jumla (TZS)</th>
                        <th class="text-right">Wastani (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_methods_stats as $method): ?>
                    <tr>
                        <td><?php echo str_replace('_', ' ', ucfirst($method['payment_method'])); ?></td>
                        <td class="text-right"><?php echo number_format($method['payment_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($method['total_amount'], 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($method['avg_amount'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'performance'): ?>
    <!-- PERFORMANCE REPORT -->
    
    <!-- Quarterly Performance -->
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">event</span> Utendaji wa Robo - <?php echo $quarter; ?></h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between pb-2 border-b">
                        <span>Jumla ya Madai:</span>
                        <span class="font-semibold"><?php echo number_format($quarterly_claims['total_claims'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between pb-2 border-b">
                        <span>Imeidhinishwa:</span>
                        <span class="font-semibold text-green-600"><?php echo number_format($quarterly_claims['approved'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between pb-2 border-b">
                        <span>Imelipwa:</span>
                        <span class="font-semibold amount-positive"><?php echo number_format($quarterly_claims['paid'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between pb-2 border-b">
                        <span>Imekataliwa:</span>
                        <span class="font-semibold text-red-600"><?php echo number_format($quarterly_claims['rejected'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between pt-2">
                        <span class="font-semibold">Jumla ya Fidia:</span>
                        <span class="amount-positive">TZS <?php echo number_format($quarterly_claims['total_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">payments</span> Malipo ya Robo</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between pb-2 border-b">
                        <span>Idadi ya Malipo:</span>
                        <span class="font-semibold"><?php echo number_format($quarterly_payments['payment_count'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between pt-2">
                        <span class="font-semibold">Jumla ya Malipo:</span>
                        <span class="amount-positive">TZS <?php echo number_format($quarterly_payments['total_amount'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activities -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">history</span> Shughuli za Hivi Karibuni</h3>
        </div>
        <div class="report-body overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Aina</th>
                        <th>Maelezo</th>
                        <th>Hali</th>
                        <th>Tarehe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_activities as $activity): ?>
                    <tr>
                        <td>
                            <?php if ($activity['type'] == 'claim'): ?>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">Dai</span>
                            <?php elseif ($activity['type'] == 'payment'): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">Malipo</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded text-xs">Tathmini</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($activity['reference']); ?></td>
                        <td><span class="status-badge <?php echo $activity['status']; ?>"><?php echo ucfirst($activity['status']); ?></span></td>
                        <td class="text-sm text-secondary"><?php echo formatDate($activity['activity_date'], 'd M Y H:i'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; ?>
    
</div>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>