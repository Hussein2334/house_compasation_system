<?php
// legal/reports.php - Legal Reports for Legal Officer
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is legal officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'legal_officer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Legal Reports';
$page_heading = 'Ripoti za Kisheria';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$year = $_GET['year'] ?? date('Y');
$status_filter = $_GET['status'] ?? 'all';

// Get years for filter
$years_query = "SELECT DISTINCT YEAR(created_at) as year FROM claims ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $years[] = $row['year'];
}

// Build where clause
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(c.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// ========== SUMMARY STATISTICS ==========
$summary_query = "SELECT 
    COUNT(CASE WHEN c.status = 'legal_review' THEN 1 END) as pending_review,
    COUNT(CASE WHEN c.status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN c.status = 'rejected' THEN 1 END) as rejected,
    COUNT(CASE WHEN c.status IN ('approved', 'rejected') THEN 1 END) as processed,
    COUNT(*) as total
    FROM claims c
    $where_sql";
$summary_stmt = mysqli_prepare($conn, $summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

// ========== MONTHLY TREND ==========
$monthly_query = "SELECT 
    DATE_FORMAT(c.created_at, '%Y-%m') as month,
    DATE_FORMAT(c.created_at, '%M %Y') as month_name,
    COUNT(CASE WHEN c.status = 'legal_review' THEN 1 END) as pending,
    COUNT(CASE WHEN c.status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN c.status = 'rejected' THEN 1 END) as rejected,
    COUNT(*) as total
    FROM claims c
    WHERE YEAR(c.created_at) = ?
    GROUP BY DATE_FORMAT(c.created_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_stmt = mysqli_prepare($conn, $monthly_query);
mysqli_stmt_bind_param($monthly_stmt, "s", $year);
mysqli_stmt_execute($monthly_stmt);
$monthly_result = mysqli_stmt_get_result($monthly_stmt);
$monthly_trend = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_trend[] = $row;
}

// ========== CLAIMS BY PROJECT ==========
$by_project_query = "SELECT 
    c.project_name,
    COUNT(*) as total_claims,
    COUNT(CASE WHEN c.status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN c.status = 'rejected' THEN 1 END) as rejected,
    COUNT(CASE WHEN c.status = 'legal_review' THEN 1 END) as pending,
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
    COUNT(CASE WHEN c.status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN c.status = 'rejected' THEN 1 END) as rejected,
    COUNT(CASE WHEN c.status = 'legal_review' THEN 1 END) as pending,
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

// ========== RECENT DECISIONS ==========
$recent_decisions_query = "SELECT 
    c.id, c.claim_number, c.project_name, c.status, c.decision_date,
    u.full_name as claimant_name,
    v.total_compensation
    FROM claims c
    JOIN users u ON c.claimant_id = u.id
    LEFT JOIN valuations v ON c.id = v.claim_id
    WHERE c.status IN ('approved', 'rejected')
    ORDER BY c.decision_date DESC
    LIMIT 20";
$recent_decisions_result = mysqli_query($conn, $recent_decisions_query);
$recent_decisions = [];
while ($row = mysqli_fetch_assoc($recent_decisions_result)) {
    $recent_decisions[] = $row;
}

// ========== APPROVAL RATE ==========
$approval_rate_query = "SELECT 
    COUNT(CASE WHEN c.status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN c.status = 'rejected' THEN 1 END) as rejected
    FROM claims c
    WHERE c.status IN ('approved', 'rejected')";
$approval_rate_result = mysqli_query($conn, $approval_rate_query);
$approval_data = mysqli_fetch_assoc($approval_rate_result);
$total_decisions = ($approval_data['approved'] + $approval_data['rejected']);
$approval_rate = $total_decisions > 0 ? ($approval_data['approved'] / $total_decisions) * 100 : 0;

// ========== AVERAGE PROCESSING TIME ==========
$avg_time_query = "SELECT 
    AVG(TIMESTAMPDIFF(DAY, c.created_at, c.decision_date)) as avg_days
    FROM claims c
    WHERE c.status IN ('approved', 'rejected') AND c.decision_date IS NOT NULL";
$avg_time_result = mysqli_query($conn, $avg_time_query);
$avg_time = mysqli_fetch_assoc($avg_time_result);

require_once __DIR__ . '/includes/legal-header.php';
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
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    
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
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
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
            <h2 class="text-xl font-bold">Ripoti za Kisheria</h2>
            <p class="text-secondary text-xs">Takwimu na ripoti za uchambuzi wa maamuzi ya kisheria</p>
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
        <a href="?report_type=summary" class="report-tab <?php echo $report_type === 'summary' ? 'active' : ''; ?>">
            📊 Muhtasari
        </a>
        <a href="?report_type=monthly" class="report-tab <?php echo $report_type === 'monthly' ? 'active' : ''; ?>">
            📅 Kwa Mwezi
        </a>
        <a href="?report_type=projects" class="report-tab <?php echo $report_type === 'projects' ? 'active' : ''; ?>">
            🏗️ Kwa Mradi
        </a>
        <a href="?report_type=districts" class="report-tab <?php echo $report_type === 'districts' ? 'active' : ''; ?>">
            📍 Kwa Wilaya
        </a>
        <a href="?report_type=decisions" class="report-tab <?php echo $report_type === 'decisions' ? 'active' : ''; ?>">
            📋 Maamuzi ya Hivi Karibuni
        </a>
    </div>
    
    <?php if ($report_type === 'summary'): ?>
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
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Hali</label>
                <select name="status" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <option value="legal_review" <?php echo $status_filter == 'legal_review' ? 'selected' : ''; ?>>Yanayosubiri</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Yaliyoidhinishwa</option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Yaliyokataliwa</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="reports.php?report_type=summary" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['pending_review'] ?? 0); ?></div>
            <div class="stat-label">Yanayosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">verified</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['approved'] ?? 0); ?></div>
            <div class="stat-label">Yaliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
                <span class="material-symbols-outlined">cancel</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['rejected'] ?? 0); ?></div>
            <div class="stat-label">Yaliyokataliwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">checklist</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['processed'] ?? 0); ?></div>
            <div class="stat-label">Yamechakatwa</div>
        </div>
    </div>
    
    <!-- Additional Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="report-section">
            <div class="report-header">
                <h3>📊 Kiwango cha Uidhinishaji</h3>
            </div>
            <div class="report-body">
                <div class="text-center mb-3">
                    <div class="text-3xl font-bold text-primary"><?php echo number_format($approval_rate, 1); ?>%</div>
                    <div class="text-xs text-secondary">Kiwango cha uidhinishaji</div>
                </div>
                <div class="space-y-2">
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imeidhinishwa</span>
                            <span><?php echo number_format($approval_data['approved']); ?> (<?php echo number_format($approval_rate, 1); ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $approval_rate; ?>%; background: #d1fae5;"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span>Imekataliwa</span>
                            <span><?php echo number_format($approval_data['rejected']); ?> (<?php echo number_format(100 - $approval_rate, 1); ?>%)</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo 100 - $approval_rate; ?>%; background: #fee2e2;"></div></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="report-section">
            <div class="report-header">
                <h3>⏱️ Muda wa Usindikaji</h3>
            </div>
            <div class="report-body">
                <div class="text-center">
                    <div class="text-3xl font-bold text-primary"><?php echo round($avg_time['avg_days'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Siku za wastani kwa uamuzi</div>
                </div>
                <div class="mt-3 text-center text-sm text-secondary">
                    <p>Muda kutoka kuwasilisha hadi kuamuliwa</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($report_type === 'monthly'): ?>
    <!-- MONTHLY TREND REPORT -->
    
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
            <h3>📈 Mwelekeo wa Maamuzi Kwa Mwezi - <?php echo $year; ?></h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mwezi</th>
                        <th class="text-right">Yanayosubiri</th>
                        <th class="text-right">Yaliyoidhinishwa</th>
                        <th class="text-right">Yaliyokataliwa</th>
                        <th class="text-right">Jumla</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthly_trend)): ?>
                    <tr><td colspan="5" class="text-center py-8 text-secondary">Hakuna data ya mwelekeo kwa mwaka huu</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthly_trend as $month): ?>
                    <tr>
                        <td class="font-medium"><?php echo $month['month_name']; ?></td>
                        <td class="text-right text-purple-600"><?php echo number_format($month['pending']); ?></td>
                        <td class="text-right text-green-600"><?php echo number_format($month['approved']); ?></td>
                        <td class="text-right text-red-600"><?php echo number_format($month['rejected']); ?></td>
                        <td class="text-right font-semibold"><?php echo number_format($month['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'projects'): ?>
    <!-- PROJECTS REPORT -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>🏗️ Madai Kwa Mradi</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Mradi</th>
                        <th class="text-right">Jumla</th>
                        <th class="text-right">Imeidhinishwa</th>
                        <th class="text-right">Imekataliwa</th>
                        <th class="text-right">Inasubiri</th>
                        <th class="text-right">Fidia (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($project_stats)): ?>
                    <tr><td colspan="6" class="text-center py-8 text-secondary">Hakuna data ya miradi</td></tr>
                    <?php else: ?>
                    <?php foreach ($project_stats as $project): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($project['project_name']); ?></td>
                        <td class="text-right"><?php echo number_format($project['total_claims']); ?></td>
                        <td class="text-right text-green-600"><?php echo number_format($project['approved']); ?></td>
                        <td class="text-right text-red-600"><?php echo number_format($project['rejected']); ?></td>
                        <td class="text-right text-purple-600"><?php echo number_format($project['pending']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($project['total_compensation'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'districts'): ?>
    <!-- DISTRICTS REPORT -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>📍 Madai Kwa Wilaya</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Wilaya</th>
                        <th class="text-right">Jumla</th>
                        <th class="text-right">Imeidhinishwa</th>
                        <th class="text-right">Imekataliwa</th>
                        <th class="text-right">Inasubiri</th>
                        <th class="text-right">Fidia (TZS)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($district_stats)): ?>
                    <tr><td colspan="6" class="text-center py-8 text-secondary">Hakuna data ya wilaya</td></tr>
                    <?php else: ?>
                    <?php foreach ($district_stats as $district): ?>
                    <tr>
                        <td class="font-medium"><?php echo htmlspecialchars($district['district']); ?></td>
                        <td class="text-right"><?php echo number_format($district['total_claims']); ?></td>
                        <td class="text-right text-green-600"><?php echo number_format($district['approved']); ?></td>
                        <td class="text-right text-red-600"><?php echo number_format($district['rejected']); ?></td>
                        <td class="text-right text-purple-600"><?php echo number_format($district['pending']); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($district['total_compensation'], 0, '.', ','); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($report_type === 'decisions'): ?>
    <!-- RECENT DECISIONS REPORT -->
    
    <div class="report-section">
        <div class="report-header">
            <h3>📋 Maamuzi ya Hivi Karibuni</h3>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th class="text-right">Fidia</th>
                        <th>Uamuzi</th>
                        <th>Tarehe ya Uamuzi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_decisions)): ?>
                    <tr><td colspan="6" class="text-center py-8 text-secondary">Hakuna maamuzi ya hivi karibuni</td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_decisions as $decision): ?>
                    <tr>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($decision['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($decision['claimant_name']); ?></td>
                        <td><?php echo htmlspecialchars($decision['project_name'] ?? '-'); ?></td>
                        <td class="text-right amount-positive">TZS <?php echo number_format($decision['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td><span class="status-badge <?php echo $decision['status']; ?>"><?php echo ucfirst($decision['status']); ?></span></td>
                        <td class="text-sm"><?php echo date('d/m/Y', strtotime($decision['decision_date'])); ?></td>
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
        const reportType = '<?php echo $report_type; ?>';
        const dateFrom = '<?php echo $date_from; ?>';
        const dateTo = '<?php echo $date_to; ?>';
        const year = '<?php echo $year; ?>';
        const status = '<?php echo $status_filter; ?>';
        
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
                window.location.href = `?export=1&report_type=${reportType}&date_from=${dateFrom}&date_to=${dateTo}&year=${year}&status=${status}`;
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
    $export_status = $_GET['status'] ?? 'all';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="legal_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type === 'summary') {
        fputcsv($output, ['Legal Report Summary']);
        fputcsv($output, ['Report Period', $export_date_from . ' to ' . $export_date_to]);
        fputcsv($output, []);
        
        $export_where = [];
        $export_params = [];
        $export_types = "";
        
        if ($export_status !== 'all') {
            $export_where[] = "status = ?";
            $export_params[] = $export_status;
            $export_types .= "s";
        }
        if (!empty($export_date_from) && !empty($export_date_to)) {
            $export_where[] = "DATE(created_at) BETWEEN ? AND ?";
            $export_params[] = $export_date_from;
            $export_params[] = $export_date_to;
            $export_types .= "ss";
        }
        $export_where_sql = empty($export_where) ? "" : "WHERE " . implode(" AND ", $export_where);
        
        $export_query = "SELECT 
            c.claim_number, u.full_name as claimant_name, c.project_name, c.district,
            c.property_type, c.status, c.created_at, c.decision_date,
            v.total_compensation
            FROM claims c
            JOIN users u ON c.claimant_id = u.id
            LEFT JOIN valuations v ON c.id = v.claim_id
            $export_where_sql
            ORDER BY c.created_at DESC";
        
        $export_stmt = mysqli_prepare($conn, $export_query);
        if (!empty($export_params)) {
            mysqli_stmt_bind_param($export_stmt, $export_types, ...$export_params);
        }
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Claim Number', 'Claimant Name', 'Project', 'District', 'Property Type', 'Status', 'Created Date', 'Decision Date', 'Compensation (TZS)']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['claim_number'],
                $row['claimant_name'],
                $row['project_name'],
                $row['district'],
                $row['property_type'],
                $row['status'],
                $row['created_at'],
                $row['decision_date'],
                number_format($row['total_compensation'], 2)
            ]);
        }
    } elseif ($export_type === 'monthly') {
        fputcsv($output, ['Monthly Trend Report - ' . $export_year]);
        fputcsv($output, []);
        
        $export_monthly_query = "SELECT 
            DATE_FORMAT(created_at, '%M %Y') as month,
            COUNT(CASE WHEN status = 'legal_review' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
            COUNT(*) as total
            FROM claims
            WHERE YEAR(created_at) = ?
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY created_at ASC";
        $export_stmt = mysqli_prepare($conn, $export_monthly_query);
        mysqli_stmt_bind_param($export_stmt, "s", $export_year);
        mysqli_stmt_execute($export_stmt);
        $export_result = mysqli_stmt_get_result($export_stmt);
        
        fputcsv($output, ['Month', 'Pending', 'Approved', 'Rejected', 'Total']);
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['month'],
                $row['pending'],
                $row['approved'],
                $row['rejected'],
                $row['total']
            ]);
        }
    }
    
    fclose($output);
    exit();
}
?>

<?php require_once __DIR__ . '/includes/legal-footer.php'; ?>