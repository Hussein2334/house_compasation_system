<?php
// admin/valuations-reports.php - Valuation Reports and Analytics
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin or valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'finance_officer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Valuation Reports';
$page_heading = 'Ripoti za Tathmini';

// Get database connection
$conn = getDB();

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$project_filter = $_GET['project'] ?? 'all';
$district_filter = $_GET['district'] ?? 'all';

// Get all projects for filter
$projects_query = "SELECT DISTINCT project_name FROM claims WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name";
$projects_result = mysqli_query($conn, $projects_query);
$projects = [];
while ($row = mysqli_fetch_assoc($projects_result)) {
    $projects[] = $row['project_name'];
}

// Get all districts for filter
$districts_query = "SELECT DISTINCT district FROM claims WHERE district IS NOT NULL AND district != '' ORDER BY district";
$districts_result = mysqli_query($conn, $districts_query);
$districts_list = [];
while ($row = mysqli_fetch_assoc($districts_result)) {
    $districts_list[] = $row['district'];
}

// Build where clause for reports
$where_clauses = [];
$params = [];
$types = "";

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

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(v.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// ========== SUMMARY REPORT DATA ==========
$summary_query = "SELECT 
                    COUNT(DISTINCT c.id) as total_valuations,
                    COUNT(DISTINCT CASE WHEN c.status = 'approved' THEN c.id END) as approved_valuations,
                    COUNT(DISTINCT CASE WHEN c.status = 'legal_review' THEN c.id END) as pending_valuations,
                    COUNT(DISTINCT CASE WHEN c.status = 'paid' THEN c.id END) as paid_valuations,
                    SUM(v.property_value) as total_property_value,
                    SUM(v.disturbance_allowance) as total_disturbance_allowance,
                    SUM(v.transport_allowance) as total_transport_allowance,
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
                      ORDER BY total_compensation DESC";

$by_project_stmt = mysqli_prepare($conn, $by_project_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($by_project_stmt, $types, ...$params);
}
mysqli_stmt_execute($by_project_stmt);
$by_project_result = mysqli_stmt_get_result($by_project_stmt);
$project_valuations = [];
while ($row = mysqli_fetch_assoc($by_project_result)) {
    $project_valuations[] = $row;
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
                      ORDER BY total_compensation DESC";

$by_district_stmt = mysqli_prepare($conn, $by_district_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($by_district_stmt, $types, ...$params);
}
mysqli_stmt_execute($by_district_stmt);
$by_district_result = mysqli_stmt_get_result($by_district_stmt);
$district_valuations = [];
while ($row = mysqli_fetch_assoc($by_district_result)) {
    $district_valuations[] = $row;
}

// ========== MONTHLY TREND ==========
$monthly_query = "SELECT 
                    DATE_FORMAT(v.created_at, '%Y-%m') as month,
                    DATE_FORMAT(v.created_at, '%M %Y') as month_name,
                    COUNT(v.id) as valuation_count,
                    SUM(v.total_compensation) as total_compensation
                  FROM valuations v
                  JOIN claims c ON v.claim_id = c.id
                  WHERE v.created_at IS NOT NULL
                  GROUP BY DATE_FORMAT(v.created_at, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 12";

$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_trend = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_trend[] = $row;
}

// ========== RECENT VALUATIONS ==========
$recent_query = "SELECT 
                    c.claim_number, 
                    u.full_name as claimant_name,
                    c.project_name,
                    c.district,
                    v.property_value,
                    v.disturbance_allowance,
                    v.transport_allowance,
                    v.total_compensation,
                    v.created_at as valuation_date,
                    vu.full_name as valuer_name,
                    c.status
                  FROM valuations v
                  JOIN claims c ON v.claim_id = c.id
                  JOIN users u ON c.claimant_id = u.id
                  LEFT JOIN users vu ON v.valuer_id = vu.id
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

// Handle PDF Export
if (isset($_GET['export_pdf'])) {
    // For PDF export - you would need to implement with a library like TCPDF or FPDF
    // For now, we'll redirect to CSV export
    header("Location: ?export_csv=1&report_type=$report_type&date_from=$date_from&date_to=$date_to&project=$project_filter&district=$district_filter");
    exit();
}

// Handle CSV Export
if (isset($_GET['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="valuation_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, [
        'Claim Number', 'Claimant Name', 'Project Name', 'District',
        'Property Value (TZS)', 'Disturbance Allowance (TZS)', 
        'Transport Allowance (TZS)', 'Total Compensation (TZS)',
        'Valuation Date', 'Valuer Name', 'Status'
    ]);
    
    // Write data
    $export_query = "SELECT 
                        c.claim_number, 
                        u.full_name as claimant_name,
                        c.project_name,
                        c.district,
                        v.property_value,
                        v.disturbance_allowance,
                        v.transport_allowance,
                        v.total_compensation,
                        v.created_at as valuation_date,
                        vu.full_name as valuer_name,
                        c.status
                    FROM valuations v
                    JOIN claims c ON v.claim_id = c.id
                    JOIN users u ON c.claimant_id = u.id
                    LEFT JOIN users vu ON v.valuer_id = vu.id
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
            $row['claimant_name'],
            $row['project_name'],
            $row['district'],
            number_format($row['property_value'] ?? 0, 2),
            number_format($row['disturbance_allowance'] ?? 0, 2),
            number_format($row['transport_allowance'] ?? 0, 2),
            number_format($row['total_compensation'] ?? 0, 2),
            $row['valuation_date'],
            $row['valuer_name'] ?? 'N/A',
            $row['status']
        ]);
    }
    fclose($output);
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

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
    
    /* Tab Navigation */
    .report-tab {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s ease;
        background: none;
        border: none;
        cursor: pointer;
    }
    .report-tab.active {
        background-color: #006e2c;
        color: white;
    }
    .report-tab:not(.active):hover {
        background-color: #e8f0e4;
    }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    .grid-4 {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .grid-4 {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Ripoti za Tathmini</h2>
            <p class="text-secondary text-sm mt-1">Takwimu na ripoti za kina za tathmini za mali na fidia</p>
        </div>
        <div class="flex gap-3">
            <button onclick="window.location.href='?export_csv=1&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>'" class="btn-export flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">download</span> Export CSV
            </button>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
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
                <button type="submit" class="btn-filter flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Report Type Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?report_type=summary&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
            Muhtasari
        </a>
        <a href="?report_type=by_project&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'by_project' ? 'active' : ''; ?>">
            Kwa Mradi
        </a>
        <a href="?report_type=by_district&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'by_district' ? 'active' : ''; ?>">
            Kwa Wilaya
        </a>
        <a href="?report_type=trend&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'trend' ? 'active' : ''; ?>">
            Mwelekeo
        </a>
        <a href="?report_type=recent&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&project=<?php echo $project_filter; ?>&district=<?php echo $district_filter; ?>" 
           class="report-tab <?php echo $report_type === 'recent' ? 'active' : ''; ?>">
            Tathmini za Hivi Karibuni
        </a>
    </div>
    
    <!-- Report Content -->
    <?php if ($report_type === 'summary'): ?>
    <!-- SUMMARY REPORT -->
    <div class="grid-4">
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                    <span class="material-symbols-outlined">real_estate_agent</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_valuations'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                    <span class="material-symbols-outlined">verified</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($summary['approved_valuations'] ?? 0); ?></div>
            <div class="stat-label">Imeidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                    <span class="material-symbols-outlined">pending</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($summary['pending_valuations'] ?? 0); ?></div>
            <div class="stat-label">Inasubiri</div>
        </div>
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="stat-icon" style="background: #a7f3d0; color: #064e3b;">
                    <span class="material-symbols-outlined">payments</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($summary['paid_valuations'] ?? 0); ?></div>
            <div class="stat-label">Imelipwa</div>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
            <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
                <h3 class="font-semibold">💰 Muhtasari wa Kiasi</h3>
            </div>
            <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-secondary">Thamani ya Mali:</span>
                    <span class="font-semibold amount-positive">TZS <?php echo number_format($summary['total_property_value'] ?? 0, 0, '.', ','); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-secondary">Posho ya Usumbufu:</span>
                    <span class="font-semibold">TZS <?php echo number_format($summary['total_disturbance_allowance'] ?? 0, 0, '.', ','); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-secondary">Posho ya Usafiri:</span>
                    <span class="font-semibold">TZS <?php echo number_format($summary['total_transport_allowance'] ?? 0, 0, '.', ','); ?></span>
                </div>
                <div class="border-t pt-3 mt-2">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-primary">Jumla ya Fidia:</span>
                        <span class="font-bold text-primary text-lg">TZS <?php echo number_format($summary['total_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <span class="text-secondary text-sm">Wastani wa Fidia kwa Tathmini:</span>
                        <span class="font-semibold">TZS <?php echo number_format($summary['avg_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
            <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
                <h3 class="font-semibold">📊 Maelezo ya Kipindi</h3>
            </div>
            <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-secondary">Kipindi:</span>
                    <span class="font-semibold"><?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-secondary">Jumla ya Tathmini:</span>
                    <span class="font-semibold"><?php echo number_format($summary['total_valuations'] ?? 0); ?></span>
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
    
    <?php elseif ($report_type === 'by_project'): ?>
    <!-- VALUATIONS BY PROJECT -->
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
        <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
            <h3 class="font-semibold">📋 Tathmini Kwa Mradi</h3>
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
                    <?php if (empty($project_valuations)): ?>
                    <tr><td colspan="7" class="text-center py-8 text-secondary">Hakuna data ya tathmini kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php foreach ($project_valuations as $pv): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($pv['project_name'] ?? '-'); ?></td>
                        <td class="text-right"><?php echo number_format($pv['valuation_count']); ?></td>
                        <td class="text-right">TZS <?php echo number_format($pv['total_property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($pv['total_disturbance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($pv['total_transport'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($pv['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($pv['avg_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'by_district'): ?>
    <!-- VALUATIONS BY DISTRICT -->
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
        <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
            <h3 class="font-semibold">📍 Tathmini Kwa Wilaya</h3>
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
                    <?php if (empty($district_valuations)): ?>
                    <tr><td colspan="7" class="text-center py-8 text-secondary">Hakuna data ya tathmini kwa kipindi hiki</td></tr>
                    <?php else: ?>
                    <?php foreach ($district_valuations as $dv): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($dv['district'] ?? '-'); ?></td>
                        <td class="text-right"><?php echo number_format($dv['valuation_count']); ?></td>
                        <td class="text-right">TZS <?php echo number_format($dv['total_property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($dv['total_disturbance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($dv['total_transport'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($dv['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($dv['avg_compensation'] ?? 0, 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'trend'): ?>
    <!-- MONTHLY TREND -->
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
        <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
            <h3 class="font-semibold">📈 Mwelekeo wa Tathmini Kwa Mwezi</h3>
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
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden">
        <div class="px-4 py-3 bg-surface-container-low border-b border-outline-variant">
            <h3 class="font-semibold">🕒 Tathmini za Hivi Karibuni</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Wilaya</th>
                        <th class="text-right">Thamani ya Mali</th>
                        <th class="text-right">Posho ya Usumbufu</th>
                        <th class="text-right">Posho ya Usafiri</th>
                        <th class="text-right">Jumla ya Fidia</th>
                        <th>Mkaguzi</th>
                        <th>Tarehe</th>
                        <th>Hali</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_valuations)): ?>
                    <tr><td colspan="11" class="text-center py-8 text-secondary">Hakuna tathmini za hivi karibuni</td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_valuations as $rv): ?>
                    <tr>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($rv['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($rv['claimant_name']); ?></td>
                        <td><?php echo htmlspecialchars($rv['project_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($rv['district'] ?? '-'); ?></td>
                        <td class="text-right">TZS <?php echo number_format($rv['property_value'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($rv['disturbance_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right">TZS <?php echo number_format($rv['transport_allowance'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($rv['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td><?php echo htmlspecialchars($rv['valuer_name'] ?? '-'); ?></td>
                        <td class="text-sm"><?php echo formatDate($rv['valuation_date'], 'd M Y'); ?></td>
                        <td><span class="status-badge <?php echo $rv['status']; ?>"><?php echo getStatusLabel($rv['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>