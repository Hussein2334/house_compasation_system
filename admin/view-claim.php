<?php
// admin/view-claim.php - View Full Claim Details
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Get claim ID from URL
$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id <= 0) {
    header("Location: claims.php");
    exit();
}

// Get database connection
$conn = getDB();

// Get claim details with related information
$query = "SELECT c.*, 
                 u.full_name as claimant_name, 
                 u.email as claimant_email, 
                 u.phone as claimant_phone,
                 u.nin as claimant_nin,
                 v.property_value,
                 v.disturbance_allowance,
                 v.transport_allowance,
                 v.total_compensation as valuation_total,
                 v.valuation_report,
                 v.created_at as valuation_date,
                 v.valuer_id,
                 vu.full_name as valuer_name
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN users vu ON v.valuer_id = vu.id
          WHERE c.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $claim_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($claim = mysqli_fetch_assoc($result)) {
    // Log view action
    logAudit($conn, $_SESSION['user_id'], 'VIEW_CLAIM', 'claims', $claim_id, null, [
        'claim_number' => $claim['claim_number']
    ]);
} else {
    header("Location: claims.php");
    exit();
}

// Get approval history
$approval_query = "SELECT a.*, u.full_name as approved_by_name 
                   FROM approvals a 
                   LEFT JOIN users u ON a.approved_by = u.id 
                   WHERE a.claim_id = ? 
                   ORDER BY a.created_at DESC";
$approval_stmt = mysqli_prepare($conn, $approval_query);
mysqli_stmt_bind_param($approval_stmt, "i", $claim_id);
mysqli_stmt_execute($approval_stmt);
$approval_result = mysqli_stmt_get_result($approval_stmt);
$approvals = [];
while ($row = mysqli_fetch_assoc($approval_result)) {
    $approvals[] = $row;
}

// Get payment history
$payment_query = "SELECT p.* FROM payments p WHERE p.claim_id = ? ORDER BY p.paid_at DESC";
$payment_stmt = mysqli_prepare($conn, $payment_query);
mysqli_stmt_bind_param($payment_stmt, "i", $claim_id);
mysqli_stmt_execute($payment_stmt);
$payment_result = mysqli_stmt_get_result($payment_stmt);
$payments = [];
while ($row = mysqli_fetch_assoc($payment_result)) {
    $payments[] = $row;
}

// Get documents
$doc_query = "SELECT * FROM documents WHERE claim_id = ? ORDER BY uploaded_at DESC";
$doc_stmt = mysqli_prepare($conn, $doc_query);
mysqli_stmt_bind_param($doc_stmt, "i", $claim_id);
mysqli_stmt_execute($doc_stmt);
$doc_result = mysqli_stmt_get_result($doc_stmt);
$documents = [];
while ($row = mysqli_fetch_assoc($doc_result)) {
    $documents[] = $row;
}

// Set page variables
$page_title = 'View Claim - ' . $claim['claim_number'];
$page_heading = 'Maelezo ya Dai';

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
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
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    /* Info Cards */
    .info-card {
        background: white;
        border: 1px solid #bccab9;
        border-radius: 1rem;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .info-card-header {
        padding: 1rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #bccab9;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .info-card-body {
        padding: 1.5rem;
    }
    .info-row {
        display: flex;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-row:last-child {
        border-bottom: none;
    }
    .info-label {
        width: 180px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
    }
    .info-value {
        flex: 1;
        font-size: 0.875rem;
        color: #161d16;
    }
    
    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 2rem;
    }
    .timeline::before {
        content: '';
        position: absolute;
        left: 0.5rem;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #bccab9;
    }
    .timeline-item {
        position: relative;
        padding-bottom: 1.5rem;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -1.5rem;
        top: 0.25rem;
        width: 0.75rem;
        height: 0.75rem;
        border-radius: 50%;
        background: #bccab9;
    }
    .timeline-item.completed::before {
        background: #006e2c;
    }
    .timeline-item.current::before {
        background: #fed000;
        box-shadow: 0 0 0 3px rgba(254, 208, 0, 0.2);
    }
    .timeline-date {
        font-size: 0.7rem;
        color: #6d7b6c;
        margin-bottom: 0.25rem;
    }
    .timeline-title {
        font-weight: 600;
        font-size: 0.875rem;
        color: #161d16;
    }
    .timeline-desc {
        font-size: 0.75rem;
        color: #6d7b6c;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }
    .btn {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        transition: all 0.2s;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    .btn-primary {
        background: #006e2c;
        color: white;
    }
    .btn-primary:hover {
        background: #005320;
    }
    .btn-secondary {
        background: white;
        border: 1px solid #bccab9;
        color: #3d4a3d;
    }
    .btn-secondary:hover {
        background: #eef6ea;
    }
    .btn-warning {
        background: #fed000;
        color: #564500;
    }
    .btn-warning:hover {
        background: #edc200;
    }
    .btn-danger {
        background: #ba1a1a;
        color: white;
    }
    .btn-danger:hover {
        background: #991b1b;
    }
    
    /* Grid */
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }
    @media (max-width: 768px) {
        .grid-2 {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Page Content -->
<div class="max-w-5xl mx-auto px-4 md:px-6 py-4 md:py-6">
    
    <!-- Header with Back Button -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="claims.php" class="text-primary hover:opacity-80 transition">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl md:text-2xl font-bold text-on-background">Maelezo ya Dai</h1>
                <p class="text-sm text-secondary">Namba ya Dai: <strong><?php echo htmlspecialchars($claim['claim_number']); ?></strong></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="status-badge <?php echo $claim['status']; ?>">
                <span class="material-symbols-outlined">
                    <?php 
                    $icons = [
                        'submitted' => 'pending',
                        'valuation' => 'real_estate_agent',
                        'legal_review' => 'gavel',
                        'approved' => 'verified',
                        'rejected' => 'cancel',
                        'paid' => 'payments'
                    ];
                    echo $icons[$claim['status']] ?? 'info';
                    ?>
                </span>
                <?php echo getStatusLabel($claim['status']); ?>
            </span>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="edit-claim.php?id=<?php echo $claim_id; ?>" class="btn btn-primary">
            <span class="material-symbols-outlined text-sm">edit</span> Hariri Dai
        </a>
        <button onclick="printClaim()" class="btn btn-secondary">
            <span class="material-symbols-outlined text-sm">print</span> Chapisha
        </button>
        <a href="claims.php" class="btn btn-secondary">
            <span class="material-symbols-outlined text-sm">list</span> Orodha ya Madai
        </a>
    </div>
    
    <!-- Claim Information Grid -->
    <div class="grid-2">
        <!-- Main Claim Info -->
        <div class="info-card">
            <div class="info-card-header">
                <span class="material-symbols-outlined text-primary">description</span>
                Taarifa za Dai
            </div>
            <div class="info-card-body">
                <div class="info-row">
                    <div class="info-label">Namba ya Dai</div>
                    <div class="info-value font-mono"><?php echo htmlspecialchars($claim['claim_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Jina la Mradi</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Hali ya Dai</div>
                    <div class="info-value">
                        <span class="status-badge <?php echo $claim['status']; ?>">
                            <?php echo getStatusLabel($claim['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Tarehe ya Uwasilishaji</div>
                    <div class="info-value"><?php echo formatDateSw($claim['created_at']); ?></div>
                </div>
                <?php if ($claim['decision_date']): ?>
                <div class="info-row">
                    <div class="info-label">Tarehe ya Uamuzi</div>
                    <div class="info-value"><?php echo formatDateSw($claim['decision_date']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Claimant Info -->
        <div class="info-card">
            <div class="info-card-header">
                <span class="material-symbols-outlined text-primary">person</span>
                Taarifa za Mwombaji
            </div>
            <div class="info-card-body">
                <div class="info-row">
                    <div class="info-label">Jina Kamili</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Barua Pepe</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['claimant_email']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Namba ya Simu</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['claimant_phone'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">NIN</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['claimant_nin'] ?? '-'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Location Info -->
        <div class="info-card">
            <div class="info-card-header">
                <span class="material-symbols-outlined text-primary">location_on</span>
                Taarifa za Eneo
            </div>
            <div class="info-card-body">
                <div class="info-row">
                    <div class="info-label">Wilaya</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['district'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Kata</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['ward'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Kijiji</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['village'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">GPS Coordinates</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['gps_coordinates'] ?? '-'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Property Info -->
        <div class="info-card">
            <div class="info-card-header">
                <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                Taarifa za Mali
            </div>
            <div class="info-card-body">
                <div class="info-row">
                    <div class="info-label">Aina ya Mali</div>
                    <div class="info-value"><?php echo ucfirst(htmlspecialchars($claim['property_type'] ?? '-')); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Ukubwa (sqm)</div>
                    <div class="info-value"><?php echo htmlspecialchars($claim['property_size'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Kiasi Kinachodaiwa</div>
                    <div class="info-value font-semibold text-primary">
                        <?php echo $claim['claim_amount'] ? number_format($claim['claim_amount'], 0) . ' TZS' : '-'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Valuation Information -->
    <?php if ($claim['valuation_total']): ?>
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">analytics</span>
            Taarifa za Tathmini
        </div>
        <div class="info-card-body">
            <div class="grid-2">
                <div>
                    <div class="info-row">
                        <div class="info-label">Thamani ya Mali</div>
                        <div class="info-value"><?php echo number_format($claim['property_value'], 0) . ' TZS'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Posho ya Usumbufu</div>
                        <div class="info-value"><?php echo number_format($claim['disturbance_allowance'], 0) . ' TZS'; ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Posho ya Usafiri</div>
                        <div class="info-value"><?php echo number_format($claim['transport_allowance'], 0) . ' TZS'; ?></div>
                    </div>
                </div>
                <div>
                    <div class="info-row">
                        <div class="info-label">Jumla ya Fidia</div>
                        <div class="info-value font-bold text-primary text-lg">
                            <?php echo number_format($claim['valuation_total'], 0) . ' TZS'; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Mkaguzi</div>
                        <div class="info-value"><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tarehe ya Tathmini</div>
                        <div class="info-value"><?php echo formatDateSw($claim['valuation_date']); ?></div>
                    </div>
                </div>
            </div>
            <?php if ($claim['valuation_report']): ?>
            <div class="info-row mt-3">
                <div class="info-label">Ripoti</div>
                <div class="info-value">
                    <a href="#" class="text-primary hover:underline">Pakua Ripoti ya Tathmini</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Description -->
    <?php if ($claim['description']): ?>
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">notes</span>
            Maelezo ya Dai
        </div>
        <div class="info-card-body">
            <p class="text-sm text-on-surface"><?php echo nl2br(htmlspecialchars($claim['description'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Approval History -->
    <?php if (!empty($approvals)): ?>
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">history</span>
            Historia ya Uidhinishaji
        </div>
        <div class="info-card-body">
            <div class="space-y-3">
                <?php foreach ($approvals as $approval): ?>
                <div class="flex items-start gap-3 p-3 bg-surface-container-low rounded-lg">
                    <span class="material-symbols-outlined text-sm text-primary">
                        <?php echo $approval['status'] === 'approved' ? 'verified' : ($approval['status'] === 'rejected' ? 'cancel' : 'pending'); ?>
                    </span>
                    <div class="flex-1">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="font-semibold text-sm">
                                    <?php echo $approval['status'] === 'approved' ? 'Imeidhinishwa' : ($approval['status'] === 'rejected' ? 'Imekataliwa' : 'Inasubiri'); ?>
                                </span>
                                <span class="text-xs text-secondary ml-2">
                                    Hatua: <?php echo htmlspecialchars($approval['approval_stage']); ?>
                                </span>
                            </div>
                            <span class="text-xs text-secondary"><?php echo formatDateSw($approval['created_at']); ?></span>
                        </div>
                        <p class="text-xs text-secondary mt-1">
                            Mwisho: <?php echo htmlspecialchars($approval['approved_by_name']); ?>
                        </p>
                        <?php if ($approval['remarks']): ?>
                        <p class="text-xs mt-1"><?php echo htmlspecialchars($approval['remarks']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Payment History -->
    <?php if (!empty($payments)): ?>
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">payments</span>
            Historia ya Malipo
        </div>
        <div class="info-card-body">
            <div class="space-y-3">
                <?php foreach ($payments as $payment): ?>
                <div class="flex items-start gap-3 p-3 bg-surface-container-low rounded-lg">
                    <span class="material-symbols-outlined text-sm text-primary">receipt</span>
                    <div class="flex-1">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="font-semibold text-sm">
                                    <?php echo number_format($payment['amount'], 0); ?> TZS
                                </span>
                                <span class="text-xs text-secondary ml-2">
                                    <?php echo ucfirst($payment['payment_method']); ?>
                                </span>
                            </div>
                            <span class="text-xs text-secondary">
                                <?php echo $payment['paid_at'] ? formatDateSw($payment['paid_at']) : '-'; ?>
                            </span>
                        </div>
                        <p class="text-xs text-secondary mt-1">
                            Rejea: <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                        </p>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">
                            <?php echo ucfirst($payment['payment_status']); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Documents -->
    <?php if (!empty($documents)): ?>
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">folder</span>
            Nyaraka zilizopakiwa
        </div>
        <div class="info-card-body">
            <div class="space-y-2">
                <?php foreach ($documents as $doc): ?>
                <div class="flex items-center justify-between p-2 bg-surface-container-low rounded-lg">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary">description</span>
                        <span class="text-sm"><?php echo htmlspecialchars($doc['document_name']); ?></span>
                    </div>
                    <a href="#" class="text-primary text-sm hover:underline">Pakua</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Status Timeline -->
    <div class="info-card">
        <div class="info-card-header">
            <span class="material-symbols-outlined text-primary">timeline</span>
            Mwendo wa Dai
        </div>
        <div class="info-card-body">
            <div class="timeline">
                <?php
                $status_order = ['submitted', 'valuation', 'legal_review', 'approved', 'paid'];
                $current_index = array_search($claim['status'], $status_order);
                $status_labels = [
                    'submitted' => 'Dai Limewasilishwa',
                    'valuation' => 'Tathmini ya Mali',
                    'legal_review' => 'Uhakiki wa Kisheria',
                    'approved' => 'Dai Limeidhinishwa',
                    'paid' => 'Malipo Yamefanywa'
                ];
                $status_descriptions = [
                    'submitted' => 'Dai lako limepokelewa na kusajiliwa kwenye mfumo.',
                    'valuation' => 'Timu ya wakadiriaji inafanya ukaguzi wa mali.',
                    'legal_review' => 'Nyaraka zako zinahakikiwa na jopo la wanasheria.',
                    'approved' => 'Dai lako limeidhinishwa na kukubaliwa.',
                    'paid' => 'Malipo yako yamefanywa na kukamilika.'
                ];
                
                foreach ($status_order as $index => $status_key):
                    $is_completed = $index < $current_index;
                    $is_current = $index == $current_index;
                ?>
                <div class="timeline-item <?php echo $is_completed ? 'completed' : ($is_current ? 'current' : ''); ?>">
                    <div class="timeline-date">
                        <?php
                        if ($is_completed || $is_current) {
                            if ($status_key === 'submitted') echo formatDateSw($claim['created_at']);
                            elseif ($status_key === 'paid' && $claim['status'] === 'paid') echo formatDateSw($payments[0]['paid_at'] ?? '');
                            else echo 'Inaendelea';
                        } else {
                            echo 'Inasubiri';
                        }
                        ?>
                    </div>
                    <div class="timeline-title">Hatua ya <?php echo $index + 1; ?>: <?php echo $status_labels[$status_key]; ?></div>
                    <div class="timeline-desc"><?php echo $status_descriptions[$status_key]; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function printClaim() {
        window.print();
    }
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>