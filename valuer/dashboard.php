<?php
// valuer/dashboard.php - Valuer Dashboard (Fixed to show real data)
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    if ($_SESSION['role'] === 'claimant') {
        header("Location: ../claimant/dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit();
}

$page_title = 'Valuer Dashboard';
$page_heading = 'Dashibodi ya Mkaguzi';

$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get valuer statistics
if (!$is_super_admin) {
    // For regular valuer
    $stats_query = "SELECT 
        (SELECT COUNT(*) FROM valuations WHERE valuer_id = $user_id) as total_valuations,
        (SELECT COUNT(*) FROM claims WHERE status = 'valuation' AND NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = claims.id)) as pending_valuations,
        (SELECT COUNT(*) FROM valuations WHERE valuer_id = $user_id AND DATE(created_at) = CURDATE()) as today_valuations,
        (SELECT COUNT(*) FROM valuations WHERE valuer_id = $user_id AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as monthly_valuations,
        (SELECT COALESCE(SUM(total_compensation), 0) FROM valuations WHERE valuer_id = $user_id) as total_compensation,
        (SELECT COALESCE(AVG(total_compensation), 0) FROM valuations WHERE valuer_id = $user_id) as avg_compensation,
        (SELECT COUNT(DISTINCT claim_id) FROM valuations WHERE valuer_id = $user_id) as total_claims_valued";
} else {
    // For super admin
    $stats_query = "SELECT 
        (SELECT COUNT(*) FROM valuations) as total_valuations,
        (SELECT COUNT(*) FROM claims WHERE status = 'valuation') as pending_valuations,
        (SELECT COUNT(*) FROM valuations WHERE DATE(created_at) = CURDATE()) as today_valuations,
        (SELECT COUNT(*) FROM valuations WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as monthly_valuations,
        (SELECT COALESCE(SUM(total_compensation), 0) FROM valuations) as total_compensation,
        (SELECT COALESCE(AVG(total_compensation), 0) FROM valuations) as avg_compensation,
        (SELECT COUNT(DISTINCT claim_id) FROM valuations) as total_claims_valued";
}
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

if (!$stats) {
    $stats = [
        'total_valuations' => 0,
        'pending_valuations' => 0,
        'today_valuations' => 0,
        'monthly_valuations' => 0,
        'total_compensation' => 0,
        'avg_compensation' => 0,
        'total_claims_valued' => 0
    ];
}

// Get pending valuations (claims that need valuation)
$pending_query = "SELECT c.id, c.claim_number, c.project_name, c.district, 
                  c.property_type, c.property_size,
                  u.full_name as claimant_name, u.email, u.phone,
                  c.created_at
                  FROM claims c
                  JOIN users u ON c.claimant_id = u.id
                  WHERE c.status = 'valuation'
                  AND NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = c.id)
                  ORDER BY c.created_at ASC
                  LIMIT 10";
$pending_result = mysqli_query($conn, $pending_query);
$pending_valuations = [];
while ($row = mysqli_fetch_assoc($pending_result)) {
    $pending_valuations[] = $row;
}

// Get recent valuations done by this valuer
$recent_query = "SELECT v.id, v.total_compensation, v.created_at,
                  c.claim_number, c.project_name, c.district,
                  u.full_name as claimant_name
                  FROM valuations v
                  JOIN claims c ON v.claim_id = c.id
                  JOIN users u ON c.claimant_id = u.id
                  WHERE v.valuer_id = $user_id
                  ORDER BY v.created_at DESC
                  LIMIT 10";
$recent_result = mysqli_query($conn, $recent_query);
$recent_valuations = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_valuations[] = $row;
}

// Get monthly stats for chart
$monthly_query = "SELECT 
    DATE_FORMAT(v.created_at, '%M %Y') as month,
    COUNT(*) as count,
    SUM(v.total_compensation) as total
    FROM valuations v
    WHERE v.valuer_id = $user_id AND v.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(v.created_at, '%Y-%m')
    ORDER BY v.created_at DESC
    LIMIT 6";
$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_stats = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_stats[] = $row;
}

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
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
        font-size: 1.75rem;
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
    .welcome-banner {
        background: linear-gradient(135deg, #006e2c 0%, #1eb050 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        color: white;
        margin-bottom: 1.5rem;
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
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    .data-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .data-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .section-card {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .section-header {
        padding: 1rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .section-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
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
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="space-y-6">
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold">Karibu, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
                <p class="text-green-100 mt-1 text-sm">Karibu kwenye Mfumo wa Tathmini za Fidia. Kagua na tathmini madai ya wadai.</p>
            </div>
            <div>
                <a href="claims.php" class="inline-flex items-center gap-2 bg-white text-primary px-4 py-2 rounded-lg font-semibold hover:bg-green-50 transition">
                    <span class="material-symbols-outlined text-sm">real_estate_agent</span>
                    Tathmini Mpya
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">real_estate_agent</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_valuations'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Tathmini</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_valuations'] ?? 0); ?></div>
            <div class="stat-label">Tathmini Zinazosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">today</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['today_valuations'] ?? 0); ?></div>
            <div class="stat-label">Tathmini za Leo</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">calendar_month</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['monthly_valuations'] ?? 0); ?></div>
            <div class="stat-label">Tathmini za Mwezi</div>
        </div>
    </div>
    
    <div class="grid-2">
        <!-- Pending Valuations -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">pending</span>
                    Tathmini Zinazosubiri
                </h3>
                <a href="claims.php" class="text-sm text-primary hover:underline">Angalia Zote →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Mradi</th>
                            <th>Aina ya Mali</th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_valuations)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-8 text-secondary">
                                <span class="material-symbols-outlined text-4xl mb-2 block">check_circle</span>
                                Hakuna tathmini zinazosubiri
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($pending_valuations as $pending): ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($pending['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($pending['claimant_name']); ?></td>
                            <td><?php echo htmlspecialchars($pending['project_name'] ?? '-'); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $pending['property_type'] ?? '-')); ?></td>
                            <td class="text-center">
                                <a href="claims.php" class="text-primary hover:underline text-sm">Fanya Tathmini</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Valuations -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">history</span>
                    Tathmini Zangu za Hivi Karibuni
                </h3>
                <a href="my-valuations.php" class="text-sm text-primary hover:underline">Angalia Zote</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Jumla ya Fidia</th>
                            <th>Tarehe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_valuations)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-8 text-secondary">
                                <span class="material-symbols-outlined text-4xl mb-2 block">real_estate_agent</span>
                                Hakuna tathmini zilizofanywa bado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_valuations as $recent): ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($recent['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($recent['claimant_name']); ?></td>
                            <td class="amount-positive">TZS <?php echo number_format($recent['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($recent['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary">bolt</span>
                Vitendo vya Haraka
            </h3>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <a href="claims.php" class="flex items-center gap-3 p-3 bg-orange-50 rounded-lg hover:bg-orange-100 transition">
                    <span class="material-symbols-outlined text-orange-600">real_estate_agent</span>
                    <span class="text-sm font-medium">Tathmini Mpya</span>
                </a>
                <a href="valuations.php" class="flex items-center gap-3 p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                    <span class="material-symbols-outlined text-purple-600">list_alt</span>
                    <span class="text-sm font-medium">Tathmini Zote</span>
                </a>
                <a href="valuations-reports.php" class="flex items-center gap-3 p-3 bg-green-50 rounded-lg hover:bg-green-100 transition">
                    <span class="material-symbols-outlined text-green-600">assessment</span>
                    <span class="text-sm font-medium">Ripoti</span>
                </a>
                <a href="help.php" class="flex items-center gap-3 p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <span class="material-symbols-outlined text-blue-600">help</span>
                    <span class="text-sm font-medium">Msaada</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Help Info -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Maelekezo ya Tathmini</p>
                <p class="text-sm text-blue-700 mt-1">Hakikisha unakagua nyaraka zote za mwombaji kabla ya kufanya tathmini. Tumia thamani ya soko ya mali na kanuni za fidia za serikali.</p>
            </div>
        </div>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>