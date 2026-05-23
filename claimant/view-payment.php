<?php
// claimant/view-payment.php - View single payment details for claimant
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
$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($payment_id <= 0) {
    header("Location: my-payments.php");
    exit();
}

// Get payment details - verify payment belongs to claimant's claims
$query = "SELECT p.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          v.total_compensation as approved_amount,
          v.property_value, v.disturbance_allowance, v.transport_allowance
          FROM payments p
          JOIN claims c ON p.claim_id = c.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          WHERE p.id = ? AND c.claimant_id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $payment_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$payment = mysqli_fetch_assoc($result);

if (!$payment) {
    header("Location: my-payments.php");
    exit();
}

$page_title = 'Payment Details';
$page_heading = 'Maelezo ya Malipo';

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
        gap: 0.25rem;
    }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.processed { background: #fef3c7; color: #92400e; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    
    .claim-status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .claim-status-badge.approved { background: #d1fae5; color: #065f46; }
    .claim-status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 700;
    }
    
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
    
    .print-btn {
        background-color: #006e2c;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    .print-btn:hover {
        background-color: #005a24;
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
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <a href="my-payments.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h2 class="font-headline-lg text-on-background text-2xl font-bold">Maelezo ya Malipo</h2>
                <p class="text-secondary text-sm mt-1">Dai: <?php echo htmlspecialchars($payment['claim_number']); ?></p>
            </div>
        </div>
        <button onclick="window.print()" class="print-btn">
            <span class="material-symbols-outlined text-sm">print</span>
            Chapisha
        </button>
    </div>
    
    <!-- Payment Information -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">payments</span>
                Taarifa za Malipo
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Namba ya Dai:</div>
                <div class="info-value font-mono"><?php echo htmlspecialchars($payment['claim_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mradi:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['project_name'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kiasi Kilicholipwa:</div>
                <div class="info-value amount-positive">TZS <?php echo number_format($payment['amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Fidia Iliyoidhinishwa:</div>
                <div class="info-value">TZS <?php echo number_format($payment['approved_amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Njia ya Malipo:</div>
                <div class="info-value">
                    <?php 
                    $method_labels = [
                        'bank_transfer' => 'Benki (Bank Transfer)',
                        'mobile_money' => 'Mobile Money (M-Pesa, Tigo Pesa, Airtel Money)',
                        'cash' => 'Taslimu (Cash)',
                        'cheque' => 'Hundi (Cheque)'
                    ];
                    echo $method_labels[$payment['payment_method']] ?? ucfirst($payment['payment_method'] ?? '-');
                    ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Namba ya Marejeleo:</div>
                <div class="info-value font-mono"><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Hali ya Malipo:</div>
                <div class="info-value">
                    <span class="status-badge <?php echo $payment['payment_status']; ?>">
                        <?php 
                        $status_labels = [
                            'pending' => '⏳ Yanatarajiwa',
                            'processed' => '🔄 Yanachakatwa',
                            'completed' => '✅ Yamekamilika'
                        ];
                        echo $status_labels[$payment['payment_status']] ?? ucfirst($payment['payment_status']);
                        ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Malipo:</div>
                <div class="info-value"><?php echo $payment['paid_at'] ? date('d/m/Y H:i', strtotime($payment['paid_at'])) : '-'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Hali ya Dai:</div>
                <div class="info-value">
                    <span class="claim-status-badge <?php echo $payment['claim_status']; ?>">
                        <?php echo $payment['claim_status'] == 'paid' ? 'Imelipwa' : ucfirst($payment['claim_status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Valuation Breakdown -->
    <?php if (($payment['property_value'] ?? 0) > 0 || ($payment['disturbance_allowance'] ?? 0) > 0 || ($payment['transport_allowance'] ?? 0) > 0): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                Maelezo ya Tathmini ya Mali
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Thamani ya Mali:</div>
                <div class="info-value">TZS <?php echo number_format($payment['property_value'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usumbufu:</div>
                <div class="info-value">TZS <?php echo number_format($payment['disturbance_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usafiri:</div>
                <div class="info-value">TZS <?php echo number_format($payment['transport_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row" style="border-top: 2px solid #e8f0e4; margin-top: 0.5rem; padding-top: 0.75rem;">
                <div class="info-label font-bold">Jumla ya Fidia:</div>
                <div class="info-value amount-positive font-bold">TZS <?php echo number_format($payment['approved_amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Receipt Section for Printing -->
    <div class="details-card print-only" style="display: none;">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary">receipt</span>
                Stakabadhi ya Malipo
            </h3>
        </div>
        <div class="details-body">
            <div class="text-center mb-4">
                <h2 class="text-xl font-bold">HOUSE COMPENSATION SYSTEM</h2>
                <p class="text-sm">Sokoine Drive, Dar es Salaam, Tanzania</p>
                <p class="text-sm">Tel: +255 22 123 4567 | Email: info@hcs.go.tz</p>
                <hr class="my-3">
                <h3 class="font-bold">STAKABADHI YA MALIPO</h3>
                <p class="text-sm">Receipt No: <?php echo str_pad($payment['id'], 8, '0', STR_PAD_LEFT); ?></p>
            </div>
            <div class="info-row">
                <div class="info-label">Jina la Mwombaji:</div>
                <div class="info-value"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Namba ya Dai:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['claim_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kiasi:</div>
                <div class="info-value amount-positive">TZS <?php echo number_format($payment['amount'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Njia ya Malipo:</div>
                <div class="info-value"><?php echo $method_labels[$payment['payment_method']] ?? ucfirst($payment['payment_method'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe:</div>
                <div class="info-value"><?php echo $payment['paid_at'] ? date('d/m/Y H:i', strtotime($payment['paid_at'])) : '-'; ?></div>
            </div>
            <div class="text-center mt-4">
                <hr class="my-3">
                <p class="text-sm">Asante kwa ushirikiano wako!</p>
                <p class="text-xs">Hati hii ni uthibitisho wa malipo yako</p>
            </div>
        </div>
    </div>
    
    <!-- Back Button -->
    <div class="flex justify-center">
        <a href="my-payments.php" class="btn-back">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Rudi kwenye Malipo Yangu
        </a>
    </div>
    
</div>

<style>
    @media print {
        body * {
            visibility: hidden;
        }
        .print-only, .print-only * {
            visibility: visible;
        }
        .print-only {
            display: block !important;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            margin: 0;
            padding: 20px;
        }
        .btn-back, .print-btn, .p-2, .flex.items-center.justify-between {
            display: none !important;
        }
    }
</style>

<script>
    // Add print styles dynamically
    const printBtn = document.querySelector('.print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
</script>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>