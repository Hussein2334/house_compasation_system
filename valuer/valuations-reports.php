<?php
// valuer/valuations-reports.php - Valuation Reports for Valuer
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
$page_title = 'Valuation Reports';
$page_heading = 'Ripoti za Tathmini';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$project_filter = $_GET['project'] ?? 'all';
$district_filter = $_GET['district'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'summary';

// Build where clause for valuer's valuations
$where_clauses = [];
$params = [];
$types = "";

if (!$is_super_admin) {
    $where_clauses[] = "v.valuer_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(v.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if ($project_filter !== 'all') {
    $where_clauses[] = "c.project_name = ?";
    $params[] = $project_filter;
    $types .= "s";
}

if ($district_filter !== 'all') {
    $where_clauses[] = "c.district = ?";
    $params[] = $district_filter;
    $types .= "s";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// ========== SUMMARY REPORT ==========
$summary_query = "SELECT 
    COUNT(v.id) as total_valuations,
    SUM(v.property_value) as total_property_value,
    SUM(v.disturbance_allowance) as total_disturbance,
    SUM(v.transport_allowance) as total_transport,
    SUM(v.total_compensation) as total_compensation,
    AVG(v.total_compensation) as avg_compensation
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    $where_sql";

$summary_stmt = mysqli_prepare($conn, $summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

// ========== VALUATIONS BY PROJECT ==========
$by_project_query = "SELECT 
    c.project_name,
    COUNT(v.id) as valuation_count,
    SUM(v.property_value) as total_property_value,
    SUM(v.disturbance_allowance) as total_disturbance,
    SUM(v.transport_allowance) as total_transport,
    SUM(v.total_compensation) as total_compensation,
    AVG(v.total_compensation) as avg_compensation
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    $where_sql
    GROUP BY c.project_name
    ORDER BY total_compensation DESC
    LIMIT 10";

$by_project_stmt = mysqli_prepare($conn, $by_project_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($by_project_stmt, $types, ...$params);
}
mysqli_stmt_execute($by_project_stmt);
$by_project_result = mysqli_stmt_get_result($by_project_stmt);
$project_stats = [];
while ($row = mysqli_fetch_assoc($by_project_result)) {
    $project_stats[] = $row;
}

// ========== VALUATIONS BY DISTRICT ==========
$by_district_query = "SELECT 
    c.district,
    COUNT(v.id) as valuation_count,
    SUM(v.property_value) as total_property_value,
    SUM(v.disturbance_allowance) as total_disturbance,
    SUM(v.transport_allowance) as total_transport,
    SUM(v.total_compensation) as total_compensation,
    AVG(v.total_compensation) as avg_compensation
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    $where_sql
    GROUP BY c.district
    ORDER BY total_compensation DESC
    LIMIT 10";

$by_district_stmt = mysqli_prepare($conn, $by_district_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($by_district_stmt, $types, ...$params);
}
mysqli_stmt_execute($by_district_stmt);
$by_district_result = mysqli_stmt_get_result($by_district_stmt);
$district_stats = [];
while ($row = mysqli_fetch_assoc($by_district_result)) {
    $district_stats[] = $row;
}

// ========== MONTHLY TREND ==========
$monthly_query = "SELECT 
    DATE_FORMAT(v.created_at, '%Y-%m') as month,
    DATE_FORMAT(v.created_at, '%M %Y') as month_name,
    COUNT(v.id) as valuation_count,
    SUM(v.total_compensation) as total_compensation
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    $where_sql
    GROUP BY DATE_FORMAT(v.created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12";

$monthly_stmt = mysqli_prepare($conn, $monthly_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($monthly_stmt, $types, ...$params);
}
mysqli_stmt_execute($monthly_stmt);
$monthly_result = mysqli_stmt_get_result($monthly_stmt);
$monthly_trend = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_trend[] = $row;
}

// ========== RECENT VALUATIONS ==========
$recent_query = "SELECT 
    v.id,
    c.claim_number,
    u.full_name as claimant_name,
    c.project_name,
    c.district,
    c.property_type,
    v.property_value,
    v.disturbance_allowance,
    v.transport_allowance,
    v.total_compensation,
    v.created_at as valuation_date
    FROM valuations v
    JOIN claims c ON v.claim_id = c.id
    JOIN users u ON c.claimant_id = u.id
    $where_sql
    ORDER BY v.created_at DESC
    LIMIT 20";

$recent_stmt = mysqli_prepare($conn, $recent_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($recent_stmt, $types, ...$params);
}
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);
$recent_valuations = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_valuations[] = $row;
}

// Get projects for filter
$projects_query = "SELECT DISTINCT project_name FROM claims WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name";
$projects_result = mysqli_query($conn, $projects_query);
$projects = [];
while ($row = mysqli_fetch_assoc($projects_result)) {
    $projects[] = $row['project_name'];
}

// Get districts for filter
$districts_query = "SELECT DISTINCT district FROM claims WHERE district IS NOT NULL AND district != '' ORDER BY district";
$districts_result = mysqli_query($conn, $districts_query);
$districts_list = [];
while ($row = mysqli_fetch_assoc($districts_result)) {
    $districts_list[] = $row['district'];
}

// Handle export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="valuations_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Claim Number', 'Claimant Name', 'Project Name', 'District',
        'Property Type', 'Property Value (TZS)', 'Disturbance Allowance (TZS)',
        'Transport Allowance (TZS)', 'Total Compensation (TZS)', 'Valuation Date'
    ]);
    
    $export_query = "SELECT c.claim_number, u.full_name, c.project_name, c.district, c.property_type,
                     v.property_value, v.disturbance_allowance, v.transport_allowance,
                     v.total_compensation, v.created_at as valuation_date
                     FROM valuations v
                     JOIN claims c ON v.claim_id = c.id
                     JOIN users u ON c.claimant_id = u.id
                     $where_sql
                     ORDER BY v.created_at DESC";
    
    $export_stmt = mysqli_prepare($conn, $export_query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($export_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($export_stmt);
    $export_result = mysqli_stmt_get_result($export_stmt);
    
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, [
            $row['claim_number'],
            $row['full_name'],
            $row['project_name'],
            $row['district'],
            $row['property_type'],
            number_format($row['property_value'], 2),
            number_format($row['disturbance_allowance'], 2),
            number_format($row['transport_allowance'], 2),
            number_format($row['total_compensation'], 2),
            $row['valuation_date']
        ]);
    }
    fclose($output);
    exit();
}

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
    
    /* Report Tables */
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
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-select, .filter-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
        width: 100%;
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
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 1rem;
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
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Ripoti za Tathmini</h2>
            <p class="text-secondary text-sm mt-1">Takwimu na ripoti za kina za tathmini ulizofanya</p>
        </div>
        <div class="flex gap-3">
            <button onclick="exportReport()" class="btn-export flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">download</span> Export CSV
            </button>
        </div>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?report_type=summary&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
            📊 Muhtasari
        </a>
        <a href="?report_type=by_project&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'by_project' ? 'active' : ''; ?>">
            📋 Kwa Mradi
        </a>
        <a href="?report_type=by_district&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'by_district' ? 'active' : ''; ?>">
            📍 Kwa Wilaya
        </a>
        <a href="?report_type=trend&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'trend' ? 'active' : ''; ?>">
            📈 Mwelekeo
        </a>
        <a href="?report_type=recent&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'recent' ? 'active' : ''; ?>">
            🕒 Tathmini za Hivi Karibuni
        </a>
    </div>
    
    <!-- Filter Bar -->
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
                <label class="text-xs font-semibold text-secondary block mb-1">Mradi</label>
                <select name="project" class="filter-select">
                    <option value="all">-- Mradi Wote --</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo htmlspecialchars($project); ?>" <?php echo $project_filter == $project ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Wilaya</label>
                <select name="district" class="filter-select">
                    <option value="all">-- Wilaya Zote --</option>
                    <?php foreach ($districts_list as $district): ?>
                        <option value="<?php echo htmlspecialchars($district); ?>" <?php echo $district_filter == $district ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($district); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-filter w-full">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <?php if ($report_type === 'summary'): ?>
    <!-- SUMMARY REPORT -->
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
                <span class="material-symbols-outlined">attach_money</span>
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
            <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                <span class="material-symbols-outlined">bar_chart</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($summary['total_property_value'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Thamani ya Mali</div>
        </div>
    </div>
    
    <div class="grid-2">
        <div class="report-section">
            <div class="report-header">
                <h3>💰 Muhtasari wa Kiasi</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Thamani ya Mali:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['total_property_value'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Posho ya Usumbufu:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['total_disturbance'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Posho ya Usafiri:</span>
                        <span class="amount-positive">TZS <?php echo number_format($summary['total_transport'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <span class="font-semibold">Jumla ya Fidia:</span>
                        <span class="font-bold text-primary text-lg">TZS <?php echo number_format($summary['total_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3>📊 Maelezo ya Kipindi</h3>
            </div>
            <div class="report-body">
                <div class="space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Kipindi:</span>
                        <span class="font-semibold"><?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Jumla ya Tathmini:</span>
                        <span class="font-semibold"><?php echo number_format($summary['total_valuations'] ?? 0); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b">
                        <span class="text-secondary">Wastani wa Fidia:</span>
                        <span class="font-semibold">TZS <?php echo number_format($summary['avg_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <?php if ($project_filter !== 'all'): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Mradi:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($project_filter); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($district_filter !== 'all'): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-secondary">Wilaya:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($district_filter); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($report_type === 'by_project'): ?>
    <!-- VALUATIONS BY PROJECT -->
    <div class="report-section">
        <div class="report-header">
            <h3>📋 Tathmini Kwa Mradi</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mradi</th>
                        <th class="text-right">Idadi ya Tathmini</th>
                        <th class="text-right">Jumla ya Thamani</th>
                        <th class="text-right">Posho ya Usumbufu</th>
                        <th class="text-right">Posho ya Usafiri</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th class="text-right">Wastani</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($project_stats)): ?>
                    <tr><td colspan="7" class="text-center py-8 text-secondary">Hakuna data ya tathmini kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php foreach ($project_stats as $stat): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($stat['project_name'] ?? '-'); ?></td>
                        <td class="text-right"><?php echo number_format($stat['valuation_count']); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_disturbance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_transport'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($stat['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['avg_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'by_district'): ?>
    <!-- VALUATIONS BY DISTRICT -->
    <div class="report-section">
        <div class="report-header">
            <h3>📍 Tathmini Kwa Wilaya</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Wilaya</th>
                        <th class="text-right">Idadi ya Tathmini</th>
                        <th class="text-right">Jumla ya Thamani</th>
                        <th class="text-right">Posho ya Usumbufu</th>
                        <th class="text-right">Posho ya Usafiri</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th class="text-right">Wastani</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($district_stats)): ?>
                    <tr><td colspan="7" class="text-center py-8 text-secondary">Hakuna data ya tathmini kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php foreach ($district_stats as $stat): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($stat['district'] ?? '-'); ?></td>
                        <td class="text-right"><?php echo number_format($stat['valuation_count']); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_disturbance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['total_transport'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($stat['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($stat['avg_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'trend'): ?>
    <!-- MONTHLY TREND -->
    <div class="report-section">
        <div class="report-header">
            <h3>📈 Mwelekeo wa Tathmini Kwa Mwezi</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Idadi ya Tathmini</th>
                        <th class="text-right">Jumla ya Fidia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_trend)): ?>
                    <tr><td colspan="3" class="text-center py-8 text-secondary">Hakuna data ya mwelekeo</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_trend as $trend): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($trend['month_name']); ?></td>
                        <td class="text-right"><?php echo number_format($trend['valuation_count']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($trend['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'recent'): ?>
    <!-- RECENT VALUATIONS -->
    <div class="report-section">
        <div class="report-header">
            <h3>🕒 Tathmini za Hivi Karibuni</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Wilaya</th>
                        <th>Thamani ya Mali</th>
                        <th>Posho ya Usumbufu</th>
                        <th>Posho ya Usafiri</th>
                        <th>Jumla ya Fidia</th>
                        <th>Tarehe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_valuations)): ?>
                    <tr><td colspan="9" class="text-center py-8 text-secondary">Hakuna tathmini za hivi karibuni</td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_valuations as $valuation): ?>
                    <tr>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($valuation['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($valuation['claimant_name']); ?></td>
                        <td><?php echo htmlspecialchars($valuation['project_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($valuation['district'] ?? '-'); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['disturbance_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($valuation['transport_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($valuation['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($valuation['valuation_date'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<script>
    function exportReport() {
        Swal.fire({
            title: 'Export Ripoti',
            text: 'Je, unataka kupakua ripoti ya tathmini?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?export=1&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>';
            }
        });
    }
</script>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>