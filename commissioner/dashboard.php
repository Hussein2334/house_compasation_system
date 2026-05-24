<?php
// commissioner/dashboard.php - Commissioner Dashboard
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
$page_title = 'Commissioner Dashboard';
$page_heading = 'Dashibodi ya Kamishna';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get statistics
$stats = [];

// Total claims
$query = "SELECT COUNT(*) as total FROM claims";
$result = mysqli_query($conn, $query);
$stats['total_claims'] = mysqli_fetch_assoc($result)['total'];

// Claims by status
$status_query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
$stats['claims_by_status'] = [];
while ($row = mysqli_fetch_assoc($status_result)) {
    $stats['claims_by_status'][$row['status']] = $row['count'];
}

// Total compensation approved
$query = "SELECT SUM(v.total_compensation) as total FROM valuations v JOIN claims c ON v.claim_id = c.id WHERE c.status = 'approved' OR c.status = 'paid'";
$result = mysqli_query($conn, $query);
$stats['total_compensation'] = mysqli_fetch_assoc($result)['total'] ?? 0;

// Total payments made
$query = "SELECT SUM(amount) as total FROM payments WHERE payment_status = 'completed'";
$result = mysqli_query($conn, $query);
$stats['total_paid'] = mysqli_fetch_assoc($result)['total'] ?? 0;

// Monthly claims (last 6 months)
$monthly_query = "SELECT DATE_FORMAT(created_at, '%M %Y') as month, COUNT(*) as count 
                  FROM claims 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                  GROUP BY YEAR(created_at), MONTH(created_at)
                  ORDER BY created_at ASC";
$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_claims = [];
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_claims[] = $row;
}

// Recent claims (last 5)
$recent_query = "SELECT c.*, u.full_name as claimant_name 
                 FROM claims c 
                 JOIN users u ON c.claimant_id = u.id 
                 ORDER BY c.created_at DESC 
                 LIMIT 5";
$recent_result = mysqli_query($conn, $recent_query);
$recent_claims = [];
while ($row = mysqli_fetch_assoc($recent_result)) {
    $recent_claims[] = $row;
}

// Recent payments (last 5)
$recent_payments_query = "SELECT p.*, c.claim_number, u.full_name as claimant_name 
                          FROM payments p 
                          JOIN claims c ON p.claim_id = c.id 
                          JOIN users u ON c.claimant_id = u.id 
                          ORDER BY p.paid_at DESC 
                          LIMIT 5";
$recent_payments_result = mysqli_query($conn, $recent_payments_query);
$recent_payments = [];
while ($row = mysqli_fetch_assoc($recent_payments_result)) {
    $recent_payments[] = $row;
}

require_once __DIR__ . '/includes/commissioner-header.php';
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
    
    /* Status Cards */
    .status-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .status-card {
        background: white;
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
        border: 1px solid #e8f0e4;
    }
    .status-count {
        font-size: 1.25rem;
        font-weight: 700;
    }
    .status-label {
        font-size: 0.6rem;
        text-transform: uppercase;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    .status-submitted .status-count { color: #6b21a5; }
    .status-valuation .status-count { color: #d97706; }
    .status-legal_review .status-count { color: #0891b2; }
    .status-approved .status-count { color: #065f46; }
    .status-rejected .status-count { color: #991b1b; }
    .status-paid .status-count { color: #006e2c; }
    
    /* Table Styles */
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
    .data-table tr:hover {
        background-color: #f4fcef;
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
    .status-badge.submitted { background: #e9d5ff; color: #6b21a5; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #cffafe; color: #0891b2; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #d1fae5; color: #006e2c; }
    
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
    
    .amount-positive {
        color: #006e2c;
        font-weight: 600;
    }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .status-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .status-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Welcome Section -->
    <div class="bg-gradient-to-r from-primary/10 to-primary-container/20 rounded-xl p-4">
        <h2 class="text-xl font-bold text-primary">Karibu, Kamishna <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
        <p class="text-sm text-secondary mt-1">Hapa unaweza kuona muhtasari wa shughuli zote za mfumo wa fidia</p>
    </div>
    
    <!-- Main Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['total_claims']); ?></div>
            <div class="stat-label">Jumla ya Madai</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">TZS <?php echo number_format($stats['total_compensation'], 0); ?></div>
            <div class="stat-label">Jumla ya Fidia Iliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">TZS <?php echo number_format($stats['total_paid'], 0); ?></div>
            <div class="stat-label">Jumla ya Malipo Yaliyofanywa</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">TZS <?php echo number_format($stats['total_compensation'] - $stats['total_paid'], 0); ?></div>
            <div class="stat-label">Salio la Malipo</div>
            <div class="stat-total">(Fidia - Malipo)</div>
        </div>
    </div>
    
    <!-- Claims by Status -->
    <div class="status-grid">
        <div class="status-card status-submitted">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['submitted'] ?? 0); ?></div>
            <div class="status-label">Yaliyowasilishwa</div>
        </div>
        <div class="status-card status-valuation">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['valuation'] ?? 0); ?></div>
            <div class="status-label">Katika Tathmini</div>
        </div>
        <div class="status-card status-legal_review">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['legal_review'] ?? 0); ?></div>
            <div class="status-label">Uhakiki wa Kisheria</div>
        </div>
        <div class="status-card status-approved">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['approved'] ?? 0); ?></div>
            <div class="status-label">Yaliyoidhinishwa</div>
        </div>
        <div class="status-card status-rejected">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['rejected'] ?? 0); ?></div>
            <div class="status-label">Yaliyokataliwa</div>
        </div>
        <div class="status-card status-paid">
            <div class="status-count"><?php echo number_format($stats['claims_by_status']['paid'] ?? 0); ?></div>
            <div class="status-label">Yaliyolipwa</div>
        </div>
    </div>
    
    <!-- Monthly Claims Chart -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">show_chart</span>
                Madai kwa Mwezi (Miezi 6 iliyopita)
            </h3>
        </div>
        <div class="section-body">
            <?php if (empty($monthly_claims)): ?>
            <p class="text-center text-secondary py-4">Hakuna data ya madai ya miezi 6 iliyopita</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($monthly_claims as $month): ?>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span><?php echo $month['month']; ?></span>
                        <span class="font-semibold"><?php echo number_format($month['count']); ?> madai</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <?php 
                        $max_count = max(array_column($monthly_claims, 'count'));
                        $percentage = ($month['count'] / max($max_count, 1)) * 100;
                        ?>
                        <div class="bg-primary h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Claims -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">description</span>
                Madai ya Hivi Karibuni
            </h3>
        </div>
        <div class="section-body p-0">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Namba ya Dai</th><th>Mwombaji</th><th>Mradi</th><th>Kiasi Kilichoombwa</th><th>Tarehe</th><th>Hali</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_claims)): ?>
                        <tr><td colspan="6" class="text-center py-8 text-secondary">Hakuna madai ya hivi karibuni</td></tr>
                        <?php else: foreach ($recent_claims as $claim): ?>
                        <tr>
                            <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($claim['claimant_name']); ?></td>
                            <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                            <td class="amount-positive">TZS <?php echo number_format($claim['claim_amount'] ?? 0, 0); ?></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                            <td><span class="status-badge <?php echo $claim['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $claim['status'])); ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Recent Payments -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">payments</span>
                Malipo ya Hivi Karibuni
            </h3>
        </div>
        <div class="section-body p-0">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Namba ya Dai</th><th>Mwombaji</th><th>Kiasi</th><th>Njia ya Malipo</th><th>Tarehe ya Malipo</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                        <tr><td colspan="5" class="text-center py-8 text-secondary">Hakuna malipo ya hivi karibuni</td></tr>
                        <?php else: foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($payment['claimant_name']); ?></td>
                            <td class="amount-positive">TZS <?php echo number_format($payment['amount'], 0); ?></td>
                            <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? '-')); ?></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($payment['paid_at'])); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="reports.php" class="bg-white border border-outline-variant rounded-lg p-3 text-center hover:shadow-md transition">
            <span class="material-symbols-outlined text-primary text-2xl">analytics</span>
            <p class="text-xs font-medium mt-1">Ripoti za Ukaguzi</p>
        </a>
        <a href="claims.php" class="bg-white border border-outline-variant rounded-lg p-3 text-center hover:shadow-md transition">
            <span class="material-symbols-outlined text-primary text-2xl">description</span>
            <p class="text-xs font-medium mt-1">Madai Yote</p>
        </a>
        <a href="payments.php" class="bg-white border border-outline-variant rounded-lg p-3 text-center hover:shadow-md transition">
            <span class="material-symbols-outlined text-primary text-2xl">payments</span>
            <p class="text-xs font-medium mt-1">Malipo Yote</p>
        </a>
        <a href="help.php" class="bg-white border border-outline-variant rounded-lg p-3 text-center hover:shadow-md transition">
            <span class="material-symbols-outlined text-primary text-2xl">help</span>
            <p class="text-xs font-medium mt-1">Msaada</p>
        </a>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/commissioner-footer.php'; ?>