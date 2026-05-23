<?php
// admin/audit-logs.php - View System Audit Logs
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is super admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Only super admin can view audit logs
if ($_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Audit Logs';
$page_heading = 'Rekodi za Shughuli za Mfumo';

// Get database connection
$conn = getDB();

// Get filter parameters
$action_filter = $_GET['action'] ?? 'all';
$user_filter = $_GET['user_id'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['created_at', 'action_performed', 'full_name', 'ip_address'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'created_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($action_filter !== 'all') {
    $where_clauses[] = "al.action_performed LIKE ?";
    $params[] = "%$action_filter%";
    $types .= "s";
}

if ($user_filter !== 'all') {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if (!empty($search_term)) {
    $where_clauses[] = "(al.action_performed LIKE ? OR al.ip_address LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total logs count
$count_query = "SELECT COUNT(*) as total 
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_logs = mysqli_fetch_assoc($count_result)['total'];

// Pagination - Limit 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_logs / $per_page);

// Get audit logs data
$query = "SELECT al.*, u.full_name, u.email, u.role
          FROM audit_logs al
          LEFT JOIN users u ON al.user_id = u.id
          $where_sql
          ORDER BY ";
          
if ($sort_by === 'full_name') {
    $query .= "u.full_name $sort_order";
} elseif ($sort_by === 'action_performed') {
    $query .= "al.action_performed $sort_order";
} elseif ($sort_by === 'ip_address') {
    $query .= "al.ip_address $sort_order";
} else {
    $query .= "al.created_at $sort_order";
}

$query .= " LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $logs[] = $row;
}

// Get unique actions for filter
$actions_query = "SELECT DISTINCT 
                    SUBSTRING_INDEX(SUBSTRING_INDEX(action_performed, ' ON ', 1), ' - ', 1) as action_name 
                  FROM audit_logs 
                  ORDER BY action_name";
$actions_result = mysqli_query($conn, $actions_query);
$actions = [];
while ($row = mysqli_fetch_assoc($actions_result)) {
    if (!empty($row['action_name'])) {
        $actions[] = $row['action_name'];
    }
}
$actions = array_unique($actions);
sort($actions);

// Get users for filter
$users_query = "SELECT id, full_name, email FROM users WHERE role IN ('super_admin', 'admin') ORDER BY full_name";
$users_result = mysqli_query($conn, $users_query);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}

// Get summary statistics
$summary_query = "SELECT 
    COUNT(*) as total_logs,
    COUNT(DISTINCT DATE(created_at)) as active_days,
    COUNT(DISTINCT user_id) as active_users,
    SUBSTRING_INDEX(SUBSTRING_INDEX(action_performed, ' ON ', 1), ' - ', 1) as most_common_action
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY most_common_action
    ORDER BY COUNT(*) DESC
    LIMIT 1";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

if (!$summary) {
    $summary = ['total_logs' => 0, 'active_days' => 0, 'active_users' => 0, 'most_common_action' => '-'];
}

// Get daily activity for chart (last 7 days)
$daily_activity_query = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as count,
    COUNT(DISTINCT user_id) as unique_users
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC";
$daily_activity_result = mysqli_query($conn, $daily_activity_query);
$daily_activity = [];
while ($row = mysqli_fetch_assoc($daily_activity_result)) {
    $daily_activity[] = $row;
}

// Handle export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Time', 'User', 'Email', 'Role', 'Action', 'IP Address']);
    
    $export_query = "SELECT al.created_at, u.full_name, u.email, u.role, al.action_performed, al.ip_address
                     FROM audit_logs al
                     LEFT JOIN users u ON al.user_id = u.id
                     ORDER BY al.created_at DESC";
    $export_result = mysqli_query($conn, $export_query);
    
    while ($row = mysqli_fetch_assoc($export_result)) {
        $datetime = explode(' ', $row['created_at']);
        fputcsv($output, [
            $datetime[0],
            $datetime[1] ?? '',
            $row['full_name'] ?? 'System',
            $row['email'] ?? '-',
            $row['role'] ?? '-',
            $row['action_performed'],
            $row['ip_address']
        ]);
    }
    fclose($output);
    exit();
}

// Handle clear old logs
if (isset($_GET['clear_old']) && $_GET['clear_old'] == 'true') {
    $days = intval($_GET['days'] ?? 90);
    $delete_query = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $delete_stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($delete_stmt, "i", $days);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        $deleted = mysqli_stmt_affected_rows($delete_stmt);
        $_SESSION['success_message'] = "Rekodi $deleted za zamani zaidi ya siku $days zimefutwa.";
        logAudit($conn, $_SESSION['user_id'], 'CLEAR_OLD_AUDIT_LOGS', 'audit_logs', null, null, ['days' => $days, 'deleted' => $deleted]);
    } else {
        $_SESSION['error_message'] = "Hitilafu katika kufuta rekodi za zamani.";
    }
    
    header("Location: audit-logs.php");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Main Layout - Full width on PC */
    .page-container {
        width: 100%;
        max-width: 100%;
        margin: 0 auto;
        padding: 0;
    }
    
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
        margin-bottom: 0.75rem;
    }
    .stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e2a1e;
        line-height: 1.2;
    }
    .stat-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #6d7b6c;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1.5rem;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-select, .filter-input {
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
        width: 100%;
    }
    .filter-select:focus, .filter-input:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .btn-filter, .btn-export, .btn-clear {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    .btn-filter {
        background-color: #006e2c;
        color: white;
        border: none;
    }
    .btn-filter:hover {
        background-color: #005a24;
    }
    .btn-export {
        background-color: white;
        color: #3d4a3d;
        border: 1px solid #bccab9;
    }
    .btn-export:hover {
        background-color: #eef6ea;
        border-color: #006e2c;
    }
    .btn-clear {
        background-color: #fee2e2;
        color: #991b1b;
        border: none;
    }
    .btn-clear:hover {
        background-color: #fecaca;
    }
    .button-group {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    /* Table Styles */
    .audit-table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .audit-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }
    .audit-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .audit-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .audit-table tr:hover {
        background-color: #f4fcef;
    }
    
    /* Action Badge */
    .action-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
        white-space: nowrap;
    }
    .action-badge.CREATE_CLAIM { background: #d1fae5; color: #065f46; }
    .action-badge.UPDATE_CLAIM { background: #fef3c7; color: #92400e; }
    .action-badge.UPDATE_CLAIM_STATUS { background: #fed7aa; color: #9a3412; }
    .action-badge.DELETE_CLAIM { background: #fee2e2; color: #991b1b; }
    .action-badge.CREATE_USER { background: #d1fae5; color: #065f46; }
    .action-badge.UPDATE_USER { background: #fef3c7; color: #92400e; }
    .action-badge.DELETE_USER { background: #fee2e2; color: #991b1b; }
    .action-badge.CREATE_VALUATION { background: #e0e7ff; color: #4338ca; }
    .action-badge.UPDATE_VALUATION { background: #cffafe; color: #0891b2; }
    .action-badge.CREATE_PAYMENT { background: #d1fae5; color: #065f46; }
    .action-badge.UPDATE_PAYMENT { background: #fef3c7; color: #92400e; }
    .action-badge.DELETE_PAYMENT { background: #fee2e2; color: #991b1b; }
    .action-badge.LOGIN { background: #d1fae5; color: #065f46; }
    .action-badge.LOGOUT { background: #fee2e2; color: #991b1b; }
    .action-badge.default { background: #e8f0e4; color: #3d4a3d; }
    
    .ip-address {
        font-family: monospace;
        font-size: 0.75rem;
        background: #f4fcef;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        display: inline-block;
    }
    
    /* Daily Activity Chart */
    .daily-activity {
        display: flex;
        align-items: flex-end;
        gap: 1rem;
        height: 200px;
        padding: 0.5rem 0;
    }
    .activity-bar-container {
        flex: 1;
        min-width: 60px;
        text-align: center;
    }
    .activity-bar {
        background-color: #006e2c;
        border-radius: 0.5rem 0.5rem 0 0;
        transition: height 0.3s ease;
        cursor: pointer;
        min-height: 20px;
    }
    .activity-bar:hover {
        background-color: #1eb050;
    }
    .activity-label {
        text-align: center;
        font-size: 0.7rem;
        margin-top: 0.5rem;
        color: #6d7b6c;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
        align-items: center;
    }
    .pagination-btn {
        padding: 0.5rem 0.875rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        transition: all 0.15s ease;
        text-decoration: none;
        color: #3d4a3d;
        background: white;
        cursor: pointer;
    }
    .pagination-btn:hover:not(.active) {
        background-color: #eef6ea;
        border-color: #006e2c;
    }
    .pagination-btn.active {
        background-color: #006e2c;
        color: white;
        border-color: #006e2c;
    }
    .pagination-btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    /* Responsive Breakpoints */
    @media (min-width: 1280px) {
        .page-container {
            padding: 0 0.5rem;
        }
        .stats-grid {
            gap: 1.5rem;
        }
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .stat-value {
            font-size: 1.5rem;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
        .button-group {
            flex-direction: column;
        }
        .btn-filter, .btn-export, .btn-clear {
            width: 100%;
        }
        .stat-card {
            padding: 0.875rem;
        }
        .stat-value {
            font-size: 1.25rem;
        }
        .daily-activity {
            gap: 0.5rem;
            height: 150px;
        }
        .activity-bar-container {
            min-width: 40px;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .audit-table th, .audit-table td {
            padding: 0.625rem;
        }
        .action-badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.5rem;
        }
    }
</style>

<!-- Page Content -->
<div class="page-container">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Rekodi za Shughuli za Mfumo</h2>
            <p class="text-secondary text-sm mt-1">Fuatilia na kagua shughuli zote za wasimamizi kwenye mfumo</p>
        </div>
        <div class="button-group">
            <button onclick="exportLogs()" class="btn-export">
                <span class="material-symbols-outlined text-sm">download</span> Export CSV
            </button>
            <button onclick="confirmClearLogs()" class="btn-clear">
                <span class="material-symbols-outlined text-sm">delete_sweep</span> Futa Rekodi za Zamani
            </button>
        </div>
    </div>
    
    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">history</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['total_logs']); ?></div>
            <div class="stat-label">Jumla ya Rekodi (Siku 30)</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">event</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['active_days']); ?></div>
            <div class="stat-label">Siku za Shughuli</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">people</span>
            </div>
            <div class="stat-value"><?php echo number_format($summary['active_users']); ?></div>
            <div class="stat-label">Watumiaji Waliofanya Shughuli</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                <span class="material-symbols-outlined">trending_up</span>
            </div>
            <div class="stat-value text-base"><?php echo htmlspecialchars($summary['most_common_action'] ?? '-'); ?></div>
            <div class="stat-label">Kitendo Kinachorudiwa Sana</div>
        </div>
    </div>
    
    <!-- Daily Activity Chart -->
    <?php if (!empty($daily_activity)): ?>
    <div class="bg-white border border-outline-variant rounded-xl p-5 mb-6">
        <h3 class="font-semibold text-lg mb-4">Shughuli za Kila Siku (Siku 7 zilizopita)</h3>
        <div class="daily-activity">
            <?php 
            $max_count = max(array_column($daily_activity, 'count'));
            $max_count = $max_count > 0 ? $max_count : 1;
            foreach ($daily_activity as $day): 
                $height = ($day['count'] / $max_count) * 150;
                $height = max($height, 30);
            ?>
            <div class="activity-bar-container">
                <div class="activity-bar" style="height: <?php echo $height; ?>px;" title="<?php echo $day['count']; ?> shughuli">
                    <span class="text-xs font-semibold absolute -mt-6 w-full text-center"><?php echo $day['count']; ?></span>
                </div>
                <div class="activity-label"><?php echo date('d M', strtotime($day['date'])); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Kitendo</label>
                <select name="action" class="filter-select">
                    <option value="all">-- Zote --</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter == $action ? 'selected' : ''; ?>>
                            <?php echo str_replace('_', ' ', $action); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mtumiaji</label>
                <select name="user_id" class="filter-select">
                    <option value="all">-- Wote --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Kuanzia Tarehe</label>
                <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Mpaka Tarehe</label>
                <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Kitendo, IP, au mtumiaji..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="button-group">
                <button type="submit" class="btn-filter">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
                <a href="audit-logs.php" class="btn-export">
                    <span class="material-symbols-outlined text-sm">refresh</span> Reset
                </a>
            </div>
        </form>
    </div>
    
    <!-- Audit Logs Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="audit-table-container">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th style="width: 15%"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Tarehe na Saa</a></th>
                        <th style="width: 18%"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Mtumiaji</a></th>
                        <th style="width: 18%"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'action_performed', 'order' => $sort_by == 'action_performed' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Kitendo</a></th>
                        <th style="width: 35%">Maelezo</th>
                        <th style="width: 14%"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'ip_address', 'order' => $sort_by == 'ip_address' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Anwani ya IP</a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">history</span>
                            Hakuna rekodi za shughuli zinazoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="whitespace-nowrap">
                            <span class="font-mono text-sm"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></span>
                        </td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($log['full_name'] ?? 'System'); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($log['email'] ?? '-'); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($log['role'] ?? '-'); ?></div>
                        </td>
                        <td>
                            <?php
                            $action_name = explode(' ON ', $log['action_performed'])[0];
                            $action_name = explode(' - ', $action_name)[0];
                            ?>
                            <span class="action-badge <?php echo str_replace(' ', '', $action_name) ?: 'default'; ?>">
                                <span class="material-symbols-outlined text-sm">
                                    <?php 
                                    $icons = [
                                        'CREATE_CLAIM' => 'add_circle',
                                        'UPDATE_CLAIM' => 'edit',
                                        'UPDATE_CLAIM_STATUS' => 'swap_horiz',
                                        'DELETE_CLAIM' => 'delete',
                                        'CREATE_USER' => 'person_add',
                                        'UPDATE_USER' => 'edit',
                                        'DELETE_USER' => 'delete',
                                        'CREATE_VALUATION' => 'real_estate_agent',
                                        'UPDATE_VALUATION' => 'edit',
                                        'CREATE_PAYMENT' => 'payments',
                                        'UPDATE_PAYMENT' => 'edit',
                                        'DELETE_PAYMENT' => 'delete',
                                        'LOGIN' => 'login',
                                        'LOGOUT' => 'logout',
                                    ];
                                    echo $icons[$action_name] ?? 'info';
                                    ?>
                                </span>
                                <?php echo str_replace('_', ' ', $action_name); ?>
                            </span>
                        </td>
                        <td class="max-w-md">
                            <?php 
                            $description = $log['action_performed'];
                            $description = str_replace($action_name, '', $description);
                            $description = trim($description, ' - ');
                            if (strlen($description) > 100) {
                                echo nl2br(htmlspecialchars(substr($description, 0, 100) . '...'));
                            } else {
                                echo nl2br(htmlspecialchars($description));
                            }
                            ?>
                        </td>
                        <td>
                            <code class="ip-address"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-outline-variant bg-surface-container-low gap-3">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_logs); ?> kati ya <?php echo $total_logs; ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">« Awali</a>
                <?php else: ?>
                <span class="pagination-btn disabled">« Awali</span>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">Inayofuata »</a>
                <?php else: ?>
                <span class="pagination-btn disabled">Inayofuata »</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Info Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800 mt-6">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <span>Rekodi za shughuli huhifadhiwa kwa muda wa siku 90. Rekodi za zamani zaidi ya hapo zinaweza kufutwa kwa kutumia kitufe cha "Futa Rekodi za Zamani".</span>
        </div>
    </div>
    
</div>

<script>
    // Export logs
    function exportLogs() {
        Swal.fire({
            title: 'Export Rekodi',
            text: 'Je, unataka kupakua ripoti ya rekodi za shughuli?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?export=1';
            }
        });
    }
    
    // Confirm clear old logs
    function confirmClearLogs() {
        Swal.fire({
            title: 'Futa Rekodi za Zamani',
            html: `
                <p class="mb-3">Je, unataka kufuta rekodi za zamani?</p>
                <div class="mb-3">
                    <label class="text-sm font-medium">Futa rekodi zenye umri wa siku:</label>
                    <select id="clear_days" class="mt-2 p-2 border rounded w-full">
                        <option value="30">Siku 30</option>
                        <option value="60">Siku 60</option>
                        <option value="90" selected>Siku 90</option>
                        <option value="180">Siku 180</option>
                        <option value="365">Mwaka 1</option>
                    </select>
                </div>
                <p class="text-sm text-red-600">⚠️ Tahadhari: Hatua hii haiwezi kutenduliwa!</p>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana',
            preConfirm: () => {
                const days = document.getElementById('clear_days').value;
                if (!days) {
                    Swal.showValidationMessage('Tafadhali chagua idadi ya siku');
                    return false;
                }
                return days;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?clear_old=true&days=${result.value}`;
            }
        });
    }
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>