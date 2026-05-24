<?php
// legal/dashboard.php - Legal Officer Dashboard
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
$page_title = 'Legal Dashboard';
$page_heading = 'Dashibodi ya Kisheria';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get legal statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM claims WHERE status = 'legal_review') as pending_review,
    (SELECT COUNT(*) FROM claims WHERE status = 'approved') as approved_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'rejected') as rejected_claims,
    (SELECT COUNT(*) FROM claims WHERE status = 'legal_review' AND DATE(created_at) = CURDATE()) as today_pending,
    (SELECT COUNT(*) FROM claims WHERE status IN ('approved', 'rejected') AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())) as monthly_processed,
    (SELECT COALESCE(AVG(v.total_compensation), 0) FROM valuations v JOIN claims c ON v.claim_id = c.id WHERE c.status = 'approved') as avg_approved_amount,
    (SELECT COUNT(DISTINCT claimant_id) FROM claims WHERE status = 'approved') as unique_claimants";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get pending claims for review (legal_review status)
$pending_query = "SELECT c.id, c.claim_number, c.project_name, c.district, c.property_type,
                  u.full_name as claimant_name, u.email, u.phone,
                  v.total_compensation, v.property_value, v.disturbance_allowance, v.transport_allowance,
                  v.valuation_report, vu.full_name as valuer_name,
                  c.created_at
                  FROM claims c
                  JOIN users u ON c.claimant_id = u.id
                  LEFT JOIN valuations v ON c.id = v.claim_id
                  LEFT JOIN users vu ON v.valuer_id = vu.id
                  WHERE c.status = 'legal_review'
                  ORDER BY c.created_at ASC
                  LIMIT 10";
$pending_result = mysqli_query($conn, $pending_query);
$pending_claims = [];
while ($row = mysqli_fetch_assoc($pending_result)) {
    $pending_claims[] = $row;
}

// Get recent approved/rejected claims
$recent_processed_query = "SELECT c.id, c.claim_number, c.project_name, c.status,
                           u.full_name as claimant_name,
                           v.total_compensation,
                           c.updated_at as decision_date
                           FROM claims c
                           JOIN users u ON c.claimant_id = u.id
                           LEFT JOIN valuations v ON c.id = v.claim_id
                           WHERE c.status IN ('approved', 'rejected')
                           ORDER BY c.updated_at DESC
                           LIMIT 10";
$recent_processed_result = mysqli_query($conn, $recent_processed_query);
$recent_processed = [];
while ($row = mysqli_fetch_assoc($recent_processed_result)) {
    $recent_processed[] = $row;
}

// Get monthly statistics for chart
$monthly_query = "SELECT 
    DATE_FORMAT(created_at, '%M %Y') as month,
    SUM(CASE WHEN status = 'legal_review' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM claims
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY created_at ASC";
$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_stats = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_stats[] = $row;
}

require_once __DIR__ . '/includes/legal-header.php';
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
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    
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
        text-decoration: none;
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
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .progress-bar {
        background-color: #e8f0e4;
        border-radius: 9999px;
        overflow: hidden;
        height: 6px;
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
            gap: 0.75rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
    }
    
    @media (max-width: 768px) {
        .data-table {
            min-width: 600px;
        }
        .table-container {
            overflow-x: auto;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">Karibu, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
                <p class="text-green-100 text-xs mt-0.5">Karibu kwenye Mfumo wa Uhakiki wa Kisheria</p>
            </div>
            <div>
                <a href="claims.php?status=legal_review" class="btn-primary">
                    <span class="material-symbols-outlined text-sm">gavel</span>
                    Kagua Madai
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e9d5ff; color: #6b21a5;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_review'] ?? 0); ?></div>
            <div class="stat-label">Yanayosubiri Uhakiki</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">verified</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['approved_claims'] ?? 0); ?></div>
            <div class="stat-label">Yaliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
                <span class="material-symbols-outlined">cancel</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['rejected_claims'] ?? 0); ?></div>
            <div class="stat-label">Yaliyokataliwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">people</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['unique_claimants'] ?? 0); ?></div>
            <div class="stat-label">Wadai Walioidhinishwa</div>
        </div>
    </div>
    
    <!-- Pending Claims for Review -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">pending</span>
                Madai Yanayosubiri Uhakiki
            </h3>
            <a href="claims.php?status=legal_review" class="text-xs text-primary hover:underline">Angalia Zote →</a>
        </div>
        <div class="table-container overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Fidia Iliyopendekezwa</th>
                        <th>Mkaguzi</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_claims)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-8 text-secondary">
                            <span class="material-symbols-outlined text-3xl mb-1 block">check_circle</span>
                            Hakuna madai yanayosubiri uhakiki
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($pending_claims as $claim): ?>
                    <tr>
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td class="font-semibold text-primary">TZS <?php echo number_format($claim['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                        <td class="text-sm"><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td class="text-center">
                            <a href="review-claim.php?id=<?php echo $claim['id']; ?>" class="btn-primary text-xs py-1 px-2">
                                Kagua
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Recent Decisions and Monthly Stats -->
    <div class="grid-2">
        
        <!-- Recent Decisions -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary text-sm">history</span>
                    Maamuzi ya Hivi Karibuni
                </h3>
            </div>
            <div class="table-container overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Uamuzi</th>
                            <th>Fidia</th>
                            <th>Tarehe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_processed)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-6 text-secondary">Hakuna maamuzi ya hivi karibuni</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_processed as $decision): ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($decision['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($decision['claimant_name']); ?></td>
                            <td><span class="status-badge <?php echo $decision['status']; ?>"><?php echo ucfirst($decision['status']); ?></span></td>
                            <td class="amount-positive">TZS <?php echo number_format($decision['total_compensation'] ?? 0, 0, '.', ','); ?></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($decision['decision_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Monthly Statistics -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary text-sm">trending_up</span>
                    Takwimu za Kila Mwezi
                </h3>
            </div>
            <div class="section-body">
                <?php if (empty($monthly_stats)): ?>
                <div class="text-center py-6 text-secondary">Hakuna data ya takwimu</div>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($monthly_stats as $month): ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-medium"><?php echo $month['month']; ?></span>
                            <div class="flex gap-3">
                                <span class="text-purple-600">Pending: <?php echo $month['pending']; ?></span>
                                <span class="text-green-600">Approved: <?php echo $month['approved']; ?></span>
                                <span class="text-red-600">Rejected: <?php echo $month['rejected']; ?></span>
                            </div>
                        </div>
                        <?php $total = $month['pending'] + $month['approved'] + $month['rejected']; ?>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $total > 0 ? ($month['approved'] / $total) * 100 : 0; ?>%; background: #d1fae5;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
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
                <a href="claims.php?status=legal_review" class="flex items-center gap-2 p-2 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                    <span class="material-symbols-outlined text-purple-600 text-sm">gavel</span>
                    <span class="text-xs font-medium">Kagua Madai</span>
                </a>
                <a href="claims.php" class="flex items-center gap-2 p-2 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <span class="material-symbols-outlined text-blue-600 text-sm">description</span>
                    <span class="text-xs font-medium">Madai Yote</span>
                </a>
                <a href="reports.php" class="flex items-center gap-2 p-2 bg-green-50 rounded-lg hover:bg-green-100 transition">
                    <span class="material-symbols-outlined text-green-600 text-sm">assessment</span>
                    <span class="text-xs font-medium">Ripoti</span>
                </a>
                <a href="help.php" class="flex items-center gap-2 p-2 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition">
                    <span class="material-symbols-outlined text-yellow-600 text-sm">help</span>
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
                <p class="text-sm font-semibold text-blue-800">Maelekezo ya Uhakiki</p>
                <p class="text-xs text-blue-700 mt-1">Kagua tathmini kwa makini, hakikisha inafuata kanuni za serikali. Unaweza kuikubali au kuikataa kwa sababu za kisheria. Baada ya kuidhinisha, dai litaenda kwa idara ya fedha kwa malipo.</p>
            </div>
        </div>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/legal-footer.php'; ?>