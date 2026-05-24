<?php
// valuer/reports.php - System Reports for Valuer
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'System Reports';
$page_heading = 'Ripoti za Mfumo';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'dashboard';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$year = $_GET['year'] ?? date('Y');

// Get years for filter
$years_query = "SELECT DISTINCT YEAR(created_at) as year FROM claims ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $years[] = $row['year'];
}

// ========== DASHBOARD STATISTICS (Valuer-specific) ==========
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM claims WHERE status = 'valuation') as pending_valuations,
    (SELECT COUNT(*) FROM claims) as total_claims,
    (SELECT COUNT(*) FROM valuations WHERE valuer_id = $user_id) as my_valuations,
    (SELECT COUNT(*) FROM valuations WHERE valuer_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as monthly_valuations,
    (SELECT COUNT(DISTINCT claim_id) FROM valuations WHERE valuer_id = $user_id) as unique_claims,
    (SELECT COALESCE(SUM(total_compensation), 0) FROM valuations WHERE valuer_id = $user_id) as my_total_compensation,
    (SELECT COALESCE(AVG(total_compensation), 0) FROM valuations WHERE valuer_id = $user_id) as my_avg_compensation";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// ========== MONTHLY VALUATIONS TREND (Valuer only) ==========
$monthly_valuations_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    DATE_FORMAT(created_at, '%M %Y') as month_name,
    COUNT(*) as total_valuations,
    SUM(total_compensation) as total_compensation
    FROM valuations 
    WHERE valuer_id = ? AND YEAR(created_at) = ?
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_stmt = mysqli_prepare($conn, $monthly_valuations_query);
mysqli_stmt_bind_param($monthly_stmt, "is", $user_id, $year);
mysqli_stmt_execute($monthly_stmt);
$monthly_valuations_result = mysqli_stmt_get_result($monthly_stmt);
$monthly_valuations = [];
while ($row = mysqli_fetch_assoc($monthly_valuations_result)) {
    $monthly_valuations[] = $row;
}

// ========== VALUATIONS BY PROPERTY TYPE ==========
$by_type_query = "SELECT 
    c.property_type,
    COUNT(v.id) as valuation_count,
    SUM(v.property_value) as total_property_value,
    SUM(v.disturbance_allowance) as total_disturbance,
    SUM(v.transport_allowance) as total_transport,
    SUM(v.total_compensation) as total_compensation,
    AVG(v.total_compensation) as avg_compensation
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    WHERE v.valuer_id = ?
    GROUP BY c.property_type
    ORDER BY valuation_count DESC";
$by_type_stmt = mysqli_prepare($conn, $by_type_query);
mysqli_stmt_bind_param($by_type_stmt, "i", $user_id);
mysqli_stmt_execute($by_type_stmt);
$by_type_result = mysqli_stmt_get_result($by_type_stmt);
$type_stats = [];
while ($row = mysqli_fetch_assoc($by_type_result)) {
    $type_stats[] = $row;
}

// ========== TOP VALUATION AMOUNTS ==========
$top_valuations_query = "SELECT 
    v.id,
    c.claim_number,
    u.full_name as claimant_name,
    c.project_name,
    v.total_compensation,
    v.created_at
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    JOIN users u ON c.claimant_id = u.id
    WHERE v.valuer_id = ?
    ORDER BY v.total_compensation DESC
    LIMIT 10";
$top_stmt = mysqli_prepare($conn, $top_valuations_query);
mysqli_stmt_bind_param($top_stmt, "i", $user_id);
mysqli_stmt_execute($top_stmt);
$top_result = mysqli_stmt_get_result($top_stmt);
$top_valuations = [];
while ($row = mysqli_fetch_assoc($top_result)) {
    $top_valuations[] = $row;
}

// ========== RECENT VALUATIONS ACTIVITY ==========
$recent_activity_query = "SELECT 
    v.id,
    c.claim_number,
    u.full_name as claimant_name,
    c.project_name,
    v.total_compensation,
    v.created_at as valuation_date,
    'valuation' as type
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    JOIN users u ON c.claimant_id = u.id
    WHERE v.valuer_id = ?
    ORDER BY v.created_at DESC
    LIMIT 10";
$recent_stmt = mysqli_prepare($conn, $recent_activity_query);
mysqli_stmt_bind_param($recent_stmt, "i", $user_id);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);
$recent_activities = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_activities[] = $row;
}

// ========== PERFORMANCE SUMMARY ==========
$performance_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as this_month,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH) THEN 1 ELSE 0 END) as last_month,
    SUM(CASE WHEN QUARTER(created_at) = QUARTER(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as this_quarter
    FROM valuations 
    WHERE valuer_id = ?";
$perf_stmt = mysqli_prepare($conn, $performance_query);
mysqli_stmt_bind_param($perf_stmt, "i", $user_id);
mysqli_stmt_execute($perf_stmt);
$perf_result = mysqli_stmt_get_result($perf_stmt);
$performance = mysqli_fetch_assoc($perf_result);

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/valuer-header.php';
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
        margin-top: 0.5rem;
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
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
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
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }
    
    @media (max-width: 768px) {
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

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Ripoti za Mfumo</h2>
            <p class="text-secondary text-sm mt-1">Takwimu na ripoti za uchambuzi wa tathmini zako</p>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?report_type=dashboard" class="report-tab <?php echo $report_type === 'dashboard' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">dashboard</span> Dashboard
        </a>
        <a href="?report_type=valuations" class="report-tab <?php echo $report_type === 'valuations' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">real_estate_agent</span> Uchambuzi wa Tathmini
        </a>
        <a href="?report_type=performance" class="report-tab <?php echo $report_type === 'performance' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined" style="font-size: 1rem;">speed</span> Utendaji
        </a>
    </div>
    
    <!-- Filter Bar (for reports that need it) -->
    <?php if ($report_type === 'valuations'): ?>
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mwaka</label>
                <select name="year" class="filter-select">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-filter flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <?php if ($report_type === 'dashboard'): ?>
    <!-- DASHBOARD REPORT -->
    
    <!-- Key Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                    <span class="material-symbols-outlined">real_estate_agent</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['my_valuations']); ?></div>
            <div class="stat-label">Jumla ya Tathmini Zangu</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                    <span class="material-symbols-outlined">payments</span>
                </div>
            </div>
            <div class="stat-value">TZS <?php echo number_format($stats['my_total_compensation'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Fidia</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                    <span class="material-symbols-outlined">pending</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_valuations']); ?></div>
            <div class="stat-label">Tathmini Zinazosubiri</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                    <span class="material-symbols-outlined">trending_up</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['monthly_valuations']); ?></div>
            <div class="stat-label">Tathmini za Mwezi Huu</div>
        </div>
    </div>
    
    <!-- Performance Overview -->
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">analytics</span> Muhtasari wa Utendaji</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Tathmini:</span>
                        <span class="font-semibold"><?php echo number_format($stats['my_valuations']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Wastani wa Fidia kwa Tathmini:</span>
                        <span class="font-semibold amount-positive">TZS <?php echo number_format($stats['my_avg_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Mradi Mbalimbali Zilizotathminiwa:</span>
                        <span class="font-semibold"><?php echo number_format($stats['unique_claims']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Tathmini Zinazosubiri Kwenye Mfumo:</span>
                        <span class="font-semibold text-orange-600"><?php echo number_format($stats['pending_valuations']); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">attach_money</span> Muhtasari wa Kifedha</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Fidia Iliyopendekezwa:</span>
                        <span class="amount-positive">TZS <?php echo number_format($stats['my_total_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Kiwango cha Juu cha Fidia:</span>
                        <span class="amount-positive"><?php echo !empty($top_valuations) ? 'TZS ' . number_format($top_valuations[0]['total_compensation'] ?? 0, 0, '.', ',') : '-'; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Wastani wa Fidia:</span>
                        <span class="amount-positive">TZS <?php echo number_format($stats['my_avg_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activities -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">history</span> Shughuli Zangu za Hivi Karibuni</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th>Tarehe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_activities)): ?>
                    <tr><td colspan="5" class="text-center py-8 text-secondary">Hakuna shughuli za hivi karibuni</td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                    <tr>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($activity['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($activity['claimant_name']); ?></td>
                        <td><?php echo htmlspecialchars($activity['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($activity['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($activity['valuation_date'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'valuations'): ?>
    <!-- VALUATIONS ANALYSIS REPORT -->
    
    <!-- Monthly Valuations Trend -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">trending_up</span> Mwelekeo wa Tathmini Kwa Mwezi - <?php echo $year; ?></h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Tathmini</th>
                        <th class="text-right">Jumla ya Fidia (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_valuations)): ?>
                    <tr><td colspan="3" class="text-center py-8 text-secondary">Hakuna data ya tathmini kwa mwaka huu</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_valuations as $month): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($month['month_name']); ?></td>
                        <td class="text-right"><?php echo number_format($month['total_valuations']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($month['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Valuations by Property Type -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">pie_chart</span> Tathmini Kwa Aina ya Mali</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Aina ya Mali</th>
                        <th class="text-right">Idadi</th>
                        <th class="text-right">Jumla ya Thamani</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th class="text-right">Wastani</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($type_stats)): ?>
                    <tr><td colspan="5" class="text-center py-8 text-secondary">Hakuna data ya aina za mali</td></tr>
                    <?php else: ?>
                    <?php foreach ($type_stats as $type): ?>
                    <tr>
                        <td><?php echo ucfirst(str_replace('_', ' ', $type['property_type'] ?? 'Nyingine')); ?></td>
                        <td class="text-right"><?php echo number_format($type['valuation_count']); ?></td>
                        <td class="text-right">TZS <?php echo number_format($type['total_property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($type['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($type['avg_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Top Valuations -->
    <div class="report-section">
        <div class="report-header">
            <h3><span class="material-symbols-outlined">leaderboard</span> Tathmini Bora (Kwa Fidia)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th class="text-right">Jumla ya Fidia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_valuations)): ?>
                    <tr><td colspan="4" class="text-center py-8 text-secondary">Hakuna data ya tathmini bora</td></tr>
                    <?php else: ?>
                    <?php foreach ($top_valuations as $top): ?>
                    <tr>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($top['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($top['claimant_name']); ?></td>
                        <td><?php echo htmlspecialchars($top['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($top['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'performance'): ?>
    <!-- PERFORMANCE REPORT -->
    
    <!-- Performance Metrics -->
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">event</span> Utendaji wa Muda</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Tathmini za Mwezi Huu</span>
                            <span><?php echo number_format($performance['this_month'] ?? 0); ?> / <?php echo number_format($performance['last_month'] ?? 0); ?> (Mwezi uliopita)</span>
                        </div>
                        <div class="progress-bar">
                            <?php $percent = ($performance['last_month'] > 0) ? (($performance['this_month'] / $performance['last_month']) * 100) : 0; ?>
                            <div class="progress-fill" style="width: <?php echo min($percent, 100); ?>%;"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Tathmini za Robo Hii</span>
                            <span><?php echo number_format($performance['this_quarter'] ?? 0); ?></span>
                        </div>
                        <div class="progress-bar">
                            <?php $target = 30; $progress = min(($performance['this_quarter'] / $target) * 100, 100); ?>
                            <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                        </div>
                        <div class="text-xs text-secondary mt-1">Lengo: 30 tathmini kwa robo</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3><span class="material-symbols-outlined">insights</span> Takwimu za Jumla</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Tathmini:</span>
                        <span class="font-semibold"><?php echo number_format($stats['my_valuations']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Mradi Mbalimbali:</span>
                        <span class="font-semibold"><?php echo number_format($stats['unique_claims']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Kiwango cha Wastani kwa Mwezi:</span>
                        <span class="font-semibold"><?php 
                            $months_active = max(1, ceil($stats['my_valuations'] / 5));
                            echo number_format(round($stats['my_valuations'] / $months_active));
                        ?> tathmini</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tips for Improvement -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">lightbulb</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Mapendekezo ya Kuboresha Utendaji</p>
                <ul class="text-sm text-blue-700 mt-1 space-y-1 list-disc list-inside">
                    <li>Fanya tathmini angalau 3-5 kwa siku kuongeza tija</li>
                    <li>Hakikisha ripoti za tathmini ni za kina na sahihi</li>
                    <li>Fuata maelekezo ya serikali kuhusu viwango vya fidia</li>
                    <li>Wasiliana na wadai kwa taarifa za ziada inapobidi</li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>