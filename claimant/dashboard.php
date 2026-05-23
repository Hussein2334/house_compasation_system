<?php
// claimant/dashboard.php - Claimant Dashboard
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Claimant Dashboard';
$page_heading = 'Dashibodi ya Mwombaji';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get claimant statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id) as total_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'submitted') as pending_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'valuation') as valuation_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'legal_review') as legal_review_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'approved') as approved_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'paid') as paid_claims,
    (SELECT COUNT(*) FROM claims WHERE claimant_id = $user_id AND status = 'rejected') as rejected_claims,
    (SELECT COALESCE(SUM(v.total_compensation), 0) FROM valuations v JOIN claims c ON v.claim_id = c.id WHERE c.claimant_id = $user_id) as total_compensation,
    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN claims c ON p.claim_id = c.id WHERE c.claimant_id = $user_id AND p.payment_status = 'completed') as total_paid";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get recent claims
$recent_claims_query = "SELECT claim_number, project_name, claim_amount, status, created_at 
                        FROM claims 
                        WHERE claimant_id = $user_id 
                        ORDER BY created_at DESC 
                        LIMIT 5";
$recent_claims_result = mysqli_query($conn, $recent_claims_query);
$recent_claims = [];
while ($row = mysqli_fetch_assoc($recent_claims_result)) {
    $recent_claims[] = $row;
}

// Get recent payments
$recent_payments_query = "SELECT p.amount, p.payment_method, p.payment_status, p.paid_at, c.claim_number
                          FROM payments p
                          JOIN claims c ON p.claim_id = c.id
                          WHERE c.claimant_id = $user_id AND p.payment_status = 'completed'
                          ORDER BY p.paid_at DESC
                          LIMIT 5";
$recent_payments_result = mysqli_query($conn, $recent_payments_query);
$recent_payments = [];
while ($row = mysqli_fetch_assoc($recent_payments_result)) {
    $recent_payments[] = $row;
}

// Get notification count from your existing notifications table
$notification_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0";
$notification_result = mysqli_query($conn, $notification_query);
$notification_count = mysqli_fetch_assoc($notification_result)['count'] ?? 0;

// Pass notification count to session for header
$_SESSION['notification_count'] = $notification_count;

require_once __DIR__ . '/includes/claimant-header.php';
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
    
    /* Welcome Banner */
    .welcome-banner {
        background: linear-gradient(135deg, #006e2c 0%, #1eb050 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        color: white;
        margin-bottom: 1.5rem;
    }
    
    /* Status Badge */
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
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    
    /* Table */
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
    
    /* Section Card */
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
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
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
                <p class="text-green-100 mt-1 text-sm">Karibu kwenye Mfumo wa Fidia ya Nyumba. Wasilisha na fuatilia madai yako kwa urahisi.</p>
            </div>
            <div>
                <a href="submit-claim.php" class="inline-flex items-center gap-2 bg-white text-primary px-4 py-2 rounded-lg font-semibold hover:bg-green-50 transition">
                    <span class="material-symbols-outlined text-sm">add</span>
                    Wasilisha Dai Jipya
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">description</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_claims']); ?></div>
            <div class="stat-label">Jumla ya Madai</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                <span class="material-symbols-outlined">pending</span>
            </div>
            <div class="stat-value"><?php echo number_format(($stats['pending_claims'] ?? 0) + ($stats['valuation_claims'] ?? 0) + ($stats['legal_review_claims'] ?? 0)); ?></div>
            <div class="stat-label">Madai Yanayosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">verified</span>
            </div>
            <div class="stat-value"><?php echo number_format($stats['approved_claims'] ?? 0); ?></div>
            <div class="stat-label">Madai Yaliyoidhinishwa</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #a7f3d0; color: #064e3b;">
                <span class="material-symbols-outlined">payments</span>
            </div>
            <div class="stat-value">TZS <?php echo number_format($stats['total_paid'] ?? 0, 0, '.', ','); ?></div>
            <div class="stat-label">Jumla ya Malipo Yaliyopokelewa</div>
        </div>
    </div>
    
    <!-- Claim Status Summary -->
    <div class="section-card">
        <div class="section-header">
            <h3>
                <span class="material-symbols-outlined text-primary">pie_chart</span>
                Muhtasari wa Hali za Madai
            </h3>
            <a href="my-claims.php" class="text-sm text-primary hover:underline">Angalia Yote →</a>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['pending_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Imewasilishwa</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-orange-600"><?php echo number_format($stats['valuation_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Tathmini</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['legal_review_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Uhakiki</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-600"><?php echo number_format($stats['approved_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Imeidhinishwa</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['paid_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Imelipwa</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-red-600"><?php echo number_format($stats['rejected_claims'] ?? 0); ?></div>
                    <div class="text-xs text-secondary">Imekataliwa</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Claims and Payments -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Recent Claims -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">history</span>
                    Madai ya Hivi Karibuni
                </h3>
                <a href="my-claims.php" class="text-sm text-primary hover:underline">Angalia Yote</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mradi</th>
                            <th>Kiasi</th>
                            <th>Hali</th>
                            <th>Tarehe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_claims)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-8 text-secondary">
                                <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
                                Hakuna madai yaliyowasilishwa
                             </td>
                         </tr>
                        <?php else: ?>
                        <?php foreach ($recent_claims as $claim): ?>
                         <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                            <td><?php echo $claim['claim_amount'] ? 'TZS ' . number_format($claim['claim_amount'], 0, '.', ',') : '-'; ?></td>
                            <td><span class="status-badge <?php echo $claim['status']; ?>"><?php echo getStatusLabel($claim['status']); ?></span></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                         </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="section-card">
            <div class="section-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">payments</span>
                    Malipo ya Hivi Karibuni
                </h3>
                <a href="my-payments.php" class="text-sm text-primary hover:underline">Angalia Yote</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Kiasi</th>
                            <th>Njia ya Malipo</th>
                            <th>Tarehe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-8 text-secondary">
                                <span class="material-symbols-outlined text-4xl mb-2 block">payments</span>
                                Hakuna malipo yaliyopokelewa
                             </td>
                         </tr>
                        <?php else: ?>
                        <?php foreach ($recent_payments as $payment): ?>
                         <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                            <td class="font-semibold text-primary">TZS <?php echo number_format($payment['amount'], 0, '.', ','); ?></td>
                            <td><?php echo str_replace('_', ' ', ucfirst($payment['payment_method'])); ?></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($payment['paid_at'])); ?></td>
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
                <a href="submit-claim.php" class="flex items-center gap-3 p-3 bg-green-50 rounded-lg hover:bg-green-100 transition">
                    <span class="material-symbols-outlined text-primary">add_circle</span>
                    <span class="text-sm font-medium">Wasilisha Dai</span>
                </a>
                <a href="my-claims.php" class="flex items-center gap-3 p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <span class="material-symbols-outlined text-blue-600">description</span>
                    <span class="text-sm font-medium">Angalia Madai</span>
                </a>
                <a href="my-valuations.php" class="flex items-center gap-3 p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                    <span class="material-symbols-outlined text-purple-600">real_estate_agent</span>
                    <span class="text-sm font-medium">Angalia Tathmini</span>
                </a>
                <a href="help.php" class="flex items-center gap-3 p-3 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition">
                    <span class="material-symbols-outlined text-yellow-600">help</span>
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
                <p class="text-sm font-semibold text-blue-800">Je, unahitaji msaada?</p>
                <p class="text-sm text-blue-700 mt-1">Kama una maswali au unahitaji usaidizi kuhusu dai lako, tafadhali wasiliana nasi kupitia ukurasa wa <a href="help.php" class="underline font-semibold">Msaada</a> au tembelea ofisi yetu iliyo karibu nawe.</p>
            </div>
        </div>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>