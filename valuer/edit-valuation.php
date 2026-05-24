<?php
// valuer/edit-valuation.php - Edit valuation
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

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

// Get valuation data
$query = "SELECT v.*, c.claim_number, c.project_name, c.district, c.property_type, c.property_size,
          u.full_name as claimant_name, u.email, u.phone
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
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

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_valuation'])) {
    $property_value = floatval($_POST['property_value']);
    $disturbance_allowance = floatval($_POST['disturbance_allowance']);
    $transport_allowance = floatval($_POST['transport_allowance']);
    $total_compensation = $property_value + $disturbance_allowance + $transport_allowance;
    $valuation_report = trim($_POST['valuation_report']);
    
    $update_query = "UPDATE valuations SET 
                     property_value = ?, 
                     disturbance_allowance = ?, 
                     transport_allowance = ?, 
                     total_compensation = ?, 
                     valuation_report = ?
                     WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "ddddss", 
        $property_value, $disturbance_allowance, $transport_allowance, 
        $total_compensation, $valuation_report, $valuation_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $_SESSION['success_message'] = "Tathmini imesasishwa kikamilifu.";
        logAudit($conn, $user_id, 'UPDATE_VALUATION', 'valuations', $valuation_id);
        header("Location: view-valuation.php?id=$valuation_id");
        exit();
    } else {
        $error_message = "Hitilafu katika kusasisha tathmini.";
    }
}

$page_title = 'Edit Valuation';
$page_heading = 'Hariri Tathmini';

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
    .form-container {
        max-width: 700px;
        margin: 0 auto;
    }
    .form-card {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
    }
    .form-header {
        padding: 0.75rem 1rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    .form-header h3 {
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .form-body {
        padding: 1rem;
    }
    .form-footer {
        padding: 0.75rem 1rem;
        border-top: 1px solid #e8f0e4;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        background: white;
    }
    .form-group {
        margin-bottom: 1rem;
    }
    .form-label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.25rem;
    }
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
    }
    .form-control, .form-textarea {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
    }
    .form-control:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .btn-save {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-cancel {
        background-color: white;
        color: #3d4a3d;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    .info-box {
        background: #f4fcef;
        padding: 0.75rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
    }
    .info-row {
        display: flex;
        padding: 0.25rem 0;
        font-size: 0.8rem;
    }
    .info-label {
        width: 35%;
        font-weight: 600;
        color: #3d4a3d;
    }
    .info-value {
        width: 65%;
    }
    .total-box {
        background: #eef6ea;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        padding: 0.75rem;
        text-align: center;
        margin-top: 0.5rem;
    }
    .total-box .amount {
        font-size: 1.25rem;
        font-weight: 700;
        color: #006e2c;
    }
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    @media (max-width: 640px) {
        .grid-2 {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="form-container">
    
    <div class="flex items-center gap-3 mb-4">
        <a href="view-valuation.php?id=<?php echo $valuation_id; ?>" class="p-1 hover:bg-surface-container-low rounded-lg transition">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="text-xl font-bold">Hariri Tathmini</h2>
            <p class="text-secondary text-xs">Dai: <?php echo htmlspecialchars($valuation['claim_number']); ?></p>
        </div>
    </div>
    
    <?php if (isset($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-red-800 text-sm mb-4">
        <?php echo $error_message; ?>
    </div>
    <?php endif; ?>
    
    <form method="POST" action="" class="form-card">
        <div class="form-header">
            <h3>
                <span class="material-symbols-outlined text-primary text-sm">edit</span>
                Tathmini ya Mali
            </h3>
        </div>
        <div class="form-body">
            <!-- Claim Info -->
            <div class="info-box">
                <div class="info-row">
                    <div class="info-label">Mwombaji:</div>
                    <div class="info-value"><?php echo htmlspecialchars($valuation['claimant_name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Mradi:</div>
                    <div class="info-value"><?php echo htmlspecialchars($valuation['project_name'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Aina ya Mali:</div>
                    <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $valuation['property_type'] ?? '-')); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Wilaya:</div>
                    <div class="info-value"><?php echo htmlspecialchars($valuation['district'] ?? '-'); ?></div>
                </div>
            </div>
            
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label required">Thamani ya Mali (TZS)</label>
                    <input type="number" name="property_value" class="form-control" step="1000" value="<?php echo $valuation['property_value']; ?>" oninput="calculateTotal()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Posho ya Usumbufu (TZS)</label>
                    <input type="number" name="disturbance_allowance" id="disturbance_allowance" class="form-control" step="1000" value="<?php echo $valuation['disturbance_allowance']; ?>" oninput="calculateTotal()">
                </div>
                <div class="form-group">
                    <label class="form-label">Posho ya Usafiri (TZS)</label>
                    <input type="number" name="transport_allowance" id="transport_allowance" class="form-control" step="1000" value="<?php echo $valuation['transport_allowance']; ?>" oninput="calculateTotal()">
                </div>
            </div>
            
            <div class="total-box">
                <div class="label">Jumla ya Fidia</div>
                <div class="amount" id="total_display">TZS <?php echo number_format($valuation['total_compensation'], 0); ?></div>
                <input type="hidden" name="total_compensation" id="total_compensation" value="<?php echo $valuation['total_compensation']; ?>">
            </div>
            
            <div class="form-group mt-3">
                <label class="form-label">Ripoti ya Tathmini</label>
                <textarea name="valuation_report" rows="4" class="form-textarea" placeholder="Maelezo ya tathmini..."><?php echo htmlspecialchars($valuation['valuation_report']); ?></textarea>
            </div>
        </div>
        <div class="form-footer">
            <a href="view-valuation.php?id=<?php echo $valuation_id; ?>" class="btn-cancel">
                <span class="material-symbols-outlined text-sm">cancel</span>
                Ghairi
            </a>
            <button type="submit" name="update_valuation" value="1" class="btn-save">
                <span class="material-symbols-outlined text-sm">save</span>
                Hifadhi
            </button>
        </div>
    </form>
</div>

<script>
    function calculateTotal() {
        let property = parseFloat(document.querySelector('input[name="property_value"]').value) || 0;
        let disturbance = parseFloat(document.querySelector('input[name="disturbance_allowance"]').value) || 0;
        let transport = parseFloat(document.querySelector('input[name="transport_allowance"]').value) || 0;
        let total = property + disturbance + transport;
        document.getElementById('total_compensation').value = total;
        document.getElementById('total_display').innerHTML = 'TZS ' + total.toLocaleString();
    }
</script>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>