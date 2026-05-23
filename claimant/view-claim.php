<?php
// claimant/view-claim.php - View single claim details
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    header("Location: ../dashboard.php");
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id <= 0) {
    header("Location: my-claims.php");
    exit();
}

// Get claim details
$query = "SELECT c.*, 
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          p.id as payment_id, p.amount as paid_amount, p.payment_status, p.paid_at
          FROM claims c
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN payments p ON c.id = p.claim_id
          WHERE c.id = ? AND c.claimant_id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $claim_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$claim = mysqli_fetch_assoc($result);

if (!$claim) {
    header("Location: my-claims.php");
    exit();
}

$page_title = 'Claim Details';
$page_heading = 'Maelezo ya Dai';

require_once __DIR__ . '/includes/claimant-header.php';
?>

<style>
    .details-card {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .details-header {
        padding: 1.25rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .details-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .details-body {
        padding: 1.5rem;
    }
    .info-row {
        display: flex;
        padding: 0.75rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-label {
        width: 35%;
        font-weight: 600;
        color: #3d4a3d;
    }
    .info-value {
        width: 65%;
        color: #1e2a1e;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    
    .btn-back {
        background-color: white;
        color: #3d4a3d;
        padding: 0.75rem 1.5rem;
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
    .btn-back:hover {
        background-color: #f4fcef;
    }
    
    @media (max-width: 640px) {
        .info-row {
            flex-direction: column;
        }
        .info-label {
            width: 100%;
            margin-bottom: 0.25rem;
        }
        .info-value {
            width: 100%;
        }
    }
</style>

<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex items-center gap-3">
        <a href="my-claims.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Maelezo ya Dai</h2>
            <p class="text-secondary text-sm mt-1">Namba ya Dai: <?php echo htmlspecialchars($claim['claim_number']); ?></p>
        </div>
    </div>
    
    <!-- Claim Details -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">description</span>
                Taarifa za Msingi
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Namba ya Dai:</div>
                <div class="info-value font-mono"><?php echo htmlspecialchars($claim['claim_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Kuwasilisha:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($claim['created_at'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Hali:</div>
                <div class="info-value"><span class="status-badge <?php echo $claim['status']; ?>"><?php echo getStatusLabel($claim['status']); ?></span></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mradi:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Wilaya:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['district'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kata:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['ward'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kijiji:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['village'] ?? '-'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Property Details -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">home</span>
                Taarifa za Mali
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Aina ya Mali:</div>
                <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Ukubwa:</div>
                <div class="info-value"><?php echo $claim['property_size'] ? $claim['property_size'] . ' sqm' : '-'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">GPS Coordinates:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['gps_coordinates'] ?? '-'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Financial Details -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">payments</span>
                Taarifa za Kifedha
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Kiasi Kinachodaiwa:</div>
                <div class="info-value font-semibold">TZS <?php echo number_format($claim['claim_amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <?php if ($claim['property_value'] > 0): ?>
            <div class="info-row">
                <div class="info-label">Thamani ya Mali:</div>
                <div class="info-value">TZS <?php echo number_format($claim['property_value'], 0, '.', ','); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($claim['disturbance_allowance'] > 0): ?>
            <div class="info-row">
                <div class="info-label">Posho ya Usumbufu:</div>
                <div class="info-value">TZS <?php echo number_format($claim['disturbance_allowance'], 0, '.', ','); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($claim['transport_allowance'] > 0): ?>
            <div class="info-row">
                <div class="info-label">Posho ya Usafiri:</div>
                <div class="info-value">TZS <?php echo number_format($claim['transport_allowance'], 0, '.', ','); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($claim['total_compensation'] > 0): ?>
            <div class="info-row">
                <div class="info-label font-bold">Jumla ya Fidia Iliyoidhinishwa:</div>
                <div class="info-value font-bold text-primary text-lg">TZS <?php echo number_format($claim['total_compensation'], 0, '.', ','); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payment Details (if paid) -->
    <?php if ($claim['payment_status'] === 'completed'): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">receipt</span>
                Taarifa za Malipo
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Kiasi Kilicholipwa:</div>
                <div class="info-value font-semibold text-primary">TZS <?php echo number_format($claim['paid_amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Malipo:</div>
                <div class="info-value"><?php echo $claim['paid_at'] ? date('d/m/Y', strtotime($claim['paid_at'])) : '-'; ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Description -->
    <?php if (!empty($claim['description'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">notes</span>
                Maelezo ya Dai
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm"><?php echo nl2br(htmlspecialchars($claim['description'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Valuation Report -->
    <?php if (!empty($claim['valuation_report'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                Ripoti ya Tathmini
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm"><?php echo nl2br(htmlspecialchars($claim['valuation_report'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Back Button -->
    <div class="flex justify-center">
        <a href="my-claims.php" class="btn-back">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Rudi kwenye Madai Yangu
        </a>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>