<?php
// legal/view-claim.php - View single claim details for legal officer
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is legal officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'legal_officer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');
$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id <= 0) {
    header("Location: claims.php");
    exit();
}

// Get claim details
$query = "SELECT c.*, 
          u.full_name as claimant_name, u.email, u.phone, u.nin,
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          vu.full_name as valuer_name,
          a.id as approval_id, a.approval_stage, a.remarks as approval_remarks, 
          a.status as approval_status, a.created_at as approval_date,
          au.full_name as approved_by_name
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN users vu ON v.valuer_id = vu.id
          LEFT JOIN approvals a ON c.id = a.claim_id AND a.approval_stage = 'legal_review'
          LEFT JOIN users au ON a.approved_by = au.id
          WHERE c.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $claim_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$claim = mysqli_fetch_assoc($result);

if (!$claim) {
    header("Location: claims.php");
    exit();
}

$page_title = 'Claim Details';
$page_heading = 'Maelezo ya Dai';

require_once __DIR__ . '/includes/legal-header.php';
?>

<style>
    .details-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    .details-card {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1rem;
    }
    .details-header {
        padding: 0.75rem 1rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .details-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .details-body {
        padding: 1rem;
    }
    .info-row {
        display: flex;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e8f0e4;
    }
    .info-label {
        width: 35%;
        font-weight: 600;
        color: #3d4a3d;
        font-size: 0.8rem;
    }
    .info-value {
        width: 65%;
        color: #1e2a1e;
        font-size: 0.8rem;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 700;
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
    .btn-secondary {
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
    .btn-secondary:hover {
        background-color: #eef6ea;
    }
    
    .action-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 1rem;
    }
    
    hr {
        margin: 0.5rem 0;
        border: none;
        border-top: 1px solid #e8f0e4;
    }
    
    .remarks-box {
        background: #fef3c7;
        border-left: 3px solid #f59e0b;
        padding: 0.75rem;
        margin-top: 0.5rem;
        border-radius: 0.5rem;
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
        .action-buttons {
            flex-direction: column;
            align-items: center;
        }
        .btn-primary, .btn-secondary {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="details-container">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div class="flex items-center gap-3">
            <a href="claims.php" class="p-1 hover:bg-surface-container-low rounded-lg transition">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h2 class="font-headline-lg text-on-background text-xl font-bold">Maelezo ya Dai</h2>
                <p class="text-secondary text-xs mt-0.5">Namba ya Dai: <?php echo htmlspecialchars($claim['claim_number']); ?></p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($claim['status'] === 'legal_review'): ?>
            <button onclick="openReviewModal()" class="btn-primary">
                <span class="material-symbols-outlined text-sm">gavel</span>
                Kagua na Uamue
            </button>
            <?php endif; ?>
            <button onclick="window.print()" class="btn-secondary">
                <span class="material-symbols-outlined text-sm">print</span>
                Chapisha
            </button>
        </div>
    </div>
    
    <!-- Claim Information -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">description</span>
                Taarifa za Dai
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Namba ya Dai:</div>
                <div class="info-value font-mono"><?php echo htmlspecialchars($claim['claim_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mwombaji:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Barua Pepe:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Namba ya Simu:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['phone'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">NIN:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['nin'] ?? '-'); ?></div>
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
            <div class="info-row">
                <div class="info-label">Tarehe ya Kuwasilisha:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($claim['created_at'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Hali ya Dai:</div>
                <div class="info-value">
                    <span class="status-badge <?php echo $claim['status']; ?>">
                        <?php echo getStatusLabel($claim['status']); ?>
                    </span>
                </div>
            </div>
            <?php if ($claim['decision_date']): ?>
            <div class="info-row">
                <div class="info-label">Tarehe ya Uamuzi:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($claim['decision_date'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Property Information -->
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">home</span>
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
    
    <!-- Valuation Details -->
    <?php if ($claim['valuation_id']): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">real_estate_agent</span>
                Taarifa za Tathmini
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Thamani ya Mali:</div>
                <div class="info-value">TZS <?php echo number_format($claim['property_value'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usumbufu:</div>
                <div class="info-value">TZS <?php echo number_format($claim['disturbance_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usafiri:</div>
                <div class="info-value">TZS <?php echo number_format($claim['transport_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row" style="border-top: 2px solid #e8f0e4; margin-top: 0.25rem; padding-top: 0.5rem;">
                <div class="info-label font-bold">Jumla ya Fidia Iliyopendekezwa:</div>
                <div class="info-value amount-positive font-bold">TZS <?php echo number_format($claim['total_compensation'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mkaguzi:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Valuation Report -->
    <?php if (!empty($claim['valuation_report'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">notes</span>
                Ripoti ya Tathmini
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($claim['valuation_report'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    
    <!-- Claim Description -->
    <?php if (!empty($claim['description'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">description</span>
                Maelezo ya Dai kutoka kwa Mwombaji
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($claim['description'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Approval/Rejection Details -->
    <?php if ($claim['approval_id']): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">verified</span>
                Taarifa za Uamuzi
            </h3>
        </div>
        <div class="details-body">
            <div class="info-row">
                <div class="info-label">Uamuzi:</div>
                <div class="info-value">
                    <span class="status-badge <?php echo $claim['approval_status']; ?>">
                        <?php echo $claim['approval_status'] == 'approved' ? 'Imeidhinishwa' : 'Imekataliwa'; ?>
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Aliyeamua:</div>
                <div class="info-value"><?php echo htmlspecialchars($claim['approved_by_name'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Uamuzi:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($claim['approval_date'])); ?></div>
            </div>
            <?php if ($claim['approval_remarks']): ?>
            <div class="remarks-box">
                <div class="text-xs font-semibold text-yellow-800 mb-1">Maelezo ya Uamuzi:</div>
                <div class="text-sm"><?php echo nl2br(htmlspecialchars($claim['approval_remarks'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Review Modal (for pending claims) -->
    <?php if ($claim['status'] === 'legal_review'): ?>
    <div id="reviewModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center opacity-0 invisible transition-all duration-300">
        <div class="bg-white rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto transform scale-95 transition-all duration-300">
            <div class="sticky top-0 bg-white border-b px-5 py-3 flex justify-between items-center">
                <h3 class="text-lg font-semibold">Kagua na Uamue</h3>
                <button onclick="closeReviewModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-5 space-y-4">
                <div class="bg-gray-50 p-3 rounded-lg">
                    <div class="flex justify-between text-sm mb-2">
                        <span class="text-secondary">Jumla ya Fidia:</span>
                        <span class="font-bold text-primary text-lg">TZS <?php echo number_format($claim['total_compensation'] ?? 0, 0, '.', ','); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-secondary">Mkaguzi:</span>
                        <span><?php echo htmlspecialchars($claim['valuer_name'] ?? '-'); ?></span>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <div class="flex gap-3">
                        <button onclick="makeDecision('approved')" class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 transition">
                            ✅ Idhinisha
                        </button>
                        <button onclick="makeDecision('rejected')" class="flex-1 bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition">
                            ❌ Kataa
                        </button>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-secondary uppercase mb-1">Sababu (kwa uamuzi wa kukataa)</label>
                        <textarea id="decision_remarks" rows="3" class="w-full px-3 py-2 border rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none" placeholder="Taja sababu za kukataa au maelezo ya ziada..."></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<script>
    let currentClaimId = <?php echo $claim_id; ?>;
    
    function openReviewModal() {
        const modal = document.getElementById('reviewModal');
        if (modal) {
            modal.classList.remove('opacity-0', 'invisible');
            modal.querySelector('.transform').classList.remove('scale-95');
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeReviewModal() {
        const modal = document.getElementById('reviewModal');
        if (modal) {
            modal.classList.add('opacity-0', 'invisible');
            modal.querySelector('.transform').classList.add('scale-95');
            document.body.style.overflow = '';
        }
    }
    
    async function makeDecision(status) {
        const remarks = document.getElementById('decision_remarks')?.value || '';
        
        if (status === 'rejected' && !remarks.trim()) {
            Swal.fire({
                icon: 'warning',
                title: 'Sababu Inahitajika',
                text: 'Tafadhali jaza sababu ya kukataa dai hili',
                confirmButtonColor: '#006e2c'
            });
            return;
        }
        
        const confirmText = status === 'approved' ? 'Je, una uhakika unataka kuidhinisha dai hili?' : 'Je, una uhakika unataka kukataa dai hili?';
        
        const result = await Swal.fire({
            title: status === 'approved' ? 'Thibitisha Uidhinishaji' : 'Thibitisha Kukataa',
            text: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: status === 'approved' ? '#006e2c' : '#ba1a1a',
            cancelButtonColor: '#6d7b6c',
            confirmButtonText: 'Ndiyo',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inachakata...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            try {
                const response = await fetch(`?ajax_update_status=1&claim_id=${currentClaimId}&new_status=${status}&remarks=${encodeURIComponent(remarks)}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: data.message, confirmButtonColor: '#006e2c', timer: 2000 });
                    setTimeout(() => { window.location.href = 'claims.php'; }, 2000);
                } else {
                    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
            }
        }
    }
    
    // Close modal when clicking outside
    document.getElementById('reviewModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeReviewModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/legal-footer.php'; ?>