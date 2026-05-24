<?php
// valuer/view-valuation.php - View single valuation details
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Check if user is logged in and is valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');
$valuation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($valuation_id <= 0) {
    header("Location: valuations.php");
    exit();
}

// Get valuation details with verification
$query = "SELECT v.*, 
          c.claim_number, c.project_name, c.status as claim_status, 
          c.property_type, c.property_size, c.district, c.ward, c.village,
          c.gps_coordinates, c.description as claim_description,
          c.created_at as claim_date,
          u.full_name as claimant_name, u.email, u.phone, u.nin,
          vu.full_name as valuer_name
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN users vu ON v.valuer_id = vu.id
          WHERE v.id = ?";

if (!$is_super_admin) {
    $query .= " AND v.valuer_id = ?";
}

$stmt = mysqli_prepare($conn, $query);
if (!$is_super_admin) {
    mysqli_stmt_bind_param($stmt, "ii", $valuation_id, $user_id);
} else {
    mysqli_stmt_bind_param($stmt, "i", $valuation_id);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$valuation = mysqli_fetch_assoc($result);

if (!$valuation) {
    header("Location: valuations.php");
    exit();
}

$page_title = 'Valuation Details';
$page_heading = 'Maelezo ya Tathmini';

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
    .details-container {
        max-width: 900px;
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
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    .amount-positive {
        color: #006e2c;
        font-weight: 700;
    }
    
    .btn-back {
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
        font-size: 0.8rem;
    }
    .btn-back:hover {
        background-color: #f4fcef;
    }
    
    .btn-edit {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        font-size: 0.8rem;
    }
    .btn-edit:hover {
        background-color: #005a24;
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
        .btn-back, .btn-edit {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="details-container">
    
    <!-- Page Header -->
    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div class="flex items-center gap-3">
            <a href="valuations.php" class="p-1 hover:bg-surface-container-low rounded-lg transition">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h2 class="font-headline-lg text-on-background text-xl font-bold">Maelezo ya Tathmini</h2>
                <p class="text-secondary text-xs mt-0.5">Dai: <?php echo htmlspecialchars($valuation['claim_number']); ?></p>
            </div>
        </div>
        <div class="action-buttons">
            <a href="valuations.php" class="btn-back">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                Rudi
            </a>
            <a href="edit-valuation.php?id=<?php echo $valuation_id; ?>" class="btn-edit">
                <span class="material-symbols-outlined text-sm">edit</span>
                Hariri Tathmini
            </a>
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
                <div class="info-value font-mono"><?php echo htmlspecialchars($valuation['claim_number']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mwombaji:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['claimant_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Barua Pepe:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Namba ya Simu:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['phone'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">NIN:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['nin'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mradi:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['project_name'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Kuwasilisha:</div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($valuation['claim_date'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Hali ya Dai:</div>
                <div class="info-value">
                    <span class="status-badge <?php echo $valuation['claim_status']; ?>">
                        <?php 
                        $status_labels = [
                            'valuation' => 'Inachakatwa',
                            'legal_review' => 'Uhakiki',
                            'approved' => 'Imeidhinishwa',
                            'paid' => 'Imelipwa'
                        ];
                        echo $status_labels[$valuation['claim_status']] ?? ucfirst($valuation['claim_status']);
                        ?>
                    </span>
                </div>
            </div>
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
                <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $valuation['property_type'] ?? '-')); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Ukubwa:</div>
                <div class="info-value"><?php echo $valuation['property_size'] ? $valuation['property_size'] . ' sqm' : '-'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Wilaya:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['district'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kata:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['ward'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Kijiji:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['village'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">GPS Coordinates:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['gps_coordinates'] ?? '-'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Valuation Details -->
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
                <div class="info-value">TZS <?php echo number_format($valuation['property_value'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usumbufu:</div>
                <div class="info-value">TZS <?php echo number_format($valuation['disturbance_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Posho ya Usafiri:</div>
                <div class="info-value">TZS <?php echo number_format($valuation['transport_allowance'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row" style="border-top: 2px solid #e8f0e4; margin-top: 0.25rem; padding-top: 0.5rem;">
                <div class="info-label font-bold">Jumla ya Fidia:</div>
                <div class="info-value amount-positive font-bold">TZS <?php echo number_format($valuation['total_compensation'] ?? 0, 0, '.', ','); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Mkaguzi:</div>
                <div class="info-value"><?php echo htmlspecialchars($valuation['valuer_name'] ?? '-'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tarehe ya Tathmini:</div>
                <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($valuation['created_at'])); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Valuation Report -->
    <?php if (!empty($valuation['valuation_report'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">notes</span>
                Ripoti ya Tathmini
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($valuation['valuation_report'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Claim Description -->
    <?php if (!empty($valuation['claim_description'])): ?>
    <div class="details-card">
        <div class="details-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">description</span>
                Maelezo ya Dai kutoka kwa Mwombaji
            </h3>
        </div>
        <div class="details-body">
            <p class="text-sm" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($valuation['claim_description'])); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Status Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mt-3">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-blue-600 text-sm">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Taarifa</p>
                <p class="text-xs text-blue-700">
                    <?php if ($valuation['claim_status'] == 'valuation'): ?>
                    Tathmini hii imekamilika na inasubiri kukaguliwa na idara ya uhakiki.
                    <?php elseif ($valuation['claim_status'] == 'legal_review'): ?>
                    Tathmini hii iko kwenye hatua ya uhakiki. Ukiona kuna makosa, unaweza kuihariri.
                    <?php elseif ($valuation['claim_status'] == 'approved'): ?>
                    Tathmini hii imekubaliwa na dai linaendelea kwenye hatua ya malipo.
                    <?php elseif ($valuation['claim_status'] == 'paid'): ?>
                    Malipo yamekamilika. Hongera kwa kazi nzuri!
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    
</div>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>