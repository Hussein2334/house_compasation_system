<?php
// admin/add-payment.php - Direct Payment Entry Page
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and has access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'finance_officer') {
    header("Location: ../dashboard.php");
    exit();
}

$page_title = 'Add Payment';
$page_heading = 'Ingiza Malipo';

$conn = getDB();
$current_user_id = $_SESSION['user_id'];

// Get all approved claims (claims that are approved but not yet paid)
$claims_query = "SELECT c.id, c.claim_number, c.project_name, c.district, 
                u.full_name, u.email, u.phone,
                COALESCE(v.total_compensation, 0) as total_compensation,
                p.id as existing_payment_id
                FROM claims c
                JOIN users u ON c.claimant_id = u.id
                LEFT JOIN valuations v ON c.id = v.claim_id
                LEFT JOIN payments p ON c.id = p.claim_id
                WHERE c.status = 'approved' AND (p.id IS NULL OR p.payment_status != 'completed')
                ORDER BY c.created_at ASC";
$claims_result = mysqli_query($conn, $claims_query);
$claims = [];
while ($row = mysqli_fetch_assoc($claims_result)) {
    $claims[] = $row;
}

// Get claims that are already paid (for reference)
$paid_claims_query = "SELECT p.id, c.id as claim_id, c.claim_number, c.project_name, u.full_name,
                      p.amount, p.payment_status, p.paid_at
                      FROM payments p
                      JOIN claims c ON p.claim_id = c.id
                      JOIN users u ON c.claimant_id = u.id
                      WHERE p.payment_status = 'completed'
                      ORDER BY p.paid_at DESC
                      LIMIT 10";
$paid_claims_result = mysqli_query($conn, $paid_claims_query);
$paid_claims = [];
while ($row = mysqli_fetch_assoc($paid_claims_result)) {
    $paid_claims[] = $row;
}

$payment_methods = [
    'bank_transfer' => 'Bank Transfer (Benki)',
    'mobile_money' => 'Mobile Money (M-Pesa, Tigo Pesa, Airtel Money)',
    'cash' => 'Cash (Fedha Taslimu)',
    'cheque' => 'Cheque (Hundi)'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $claim_id = intval($_POST['claim_id']);
    $amount = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    $transaction_reference = trim($_POST['transaction_reference']);
    $paid_at = date('Y-m-d H:i:s');
    $notes = trim($_POST['notes'] ?? '');
    
    $errors = [];
    
    if ($claim_id <= 0) {
        $errors[] = "Tafadhali chagua dai";
    }
    if ($amount <= 0) {
        $errors[] = "Kiasi cha malipo kinahitajika";
    }
    if (empty($payment_method)) {
        $errors[] = "Tafadhali chagua njia ya malipo";
    }
    
    // Verify claim is approved
    $check_claim = "SELECT status, claimant_id FROM claims WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_claim);
    mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $claim_check = mysqli_fetch_assoc($check_result);
    
    if (!$claim_check) {
        $errors[] = "Dai halijapatikana";
    } elseif ($claim_check['status'] !== 'approved') {
        $errors[] = "Dai hili halijaidhinishwa. Tafadhali idhinisha dai kwanza.";
    }
    
    if (empty($errors)) {
        // Check if payment already exists
        $check_payment = "SELECT id FROM payments WHERE claim_id = ?";
        $check_pay_stmt = mysqli_prepare($conn, $check_payment);
        mysqli_stmt_bind_param($check_pay_stmt, "i", $claim_id);
        mysqli_stmt_execute($check_pay_stmt);
        mysqli_stmt_store_result($check_pay_stmt);
        
        if (mysqli_stmt_num_rows($check_pay_stmt) > 0) {
            // Update existing payment - FIXED: No created_at column
            $update_query = "UPDATE payments SET 
                             amount = ?, 
                             payment_method = ?, 
                             transaction_reference = ?, 
                             payment_status = 'completed',
                             paid_at = ?
                             WHERE claim_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "dsssi", 
                $amount, $payment_method, $transaction_reference, $paid_at, $claim_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                mysqli_query($conn, "UPDATE claims SET status = 'paid', updated_at = NOW() WHERE id = $claim_id");
                $_SESSION['success_message'] = "Malipo yamesasishwa kikamilifu.";
                logAudit($conn, $current_user_id, 'UPDATE_PAYMENT', 'payments', $claim_id);
                
                // Send notification to claimant
                $notif_title = "Malipo Yamesasishwa";
                $notif_message = "Malipo yako ya TZS " . number_format($amount, 0) . " yamesasishwa kikamilifu.";
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'payment', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $claim_check['claimant_id'], $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kusasisha malipo: " . mysqli_error($conn);
            }
        } else {
            // Insert new payment - FIXED: No created_by, notes, created_at columns
            $insert_query = "INSERT INTO payments (claim_id, amount, payment_method, transaction_reference, payment_status, paid_at) 
                             VALUES (?, ?, ?, ?, 'completed', ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "idsss", 
                $claim_id, $amount, $payment_method, $transaction_reference, $paid_at);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                mysqli_query($conn, "UPDATE claims SET status = 'paid', updated_at = NOW() WHERE id = $claim_id");
                $_SESSION['success_message'] = "Malipo yameingizwa kikamilifu.";
                logAudit($conn, $current_user_id, 'CREATE_PAYMENT', 'payments', $claim_id);
                
                // Send notification to claimant
                $notif_title = "Malipo Yamefanywa";
                $notif_message = "Malipo yako ya TZS " . number_format($amount, 0) . " yamefanywa kikamilifu.";
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'payment', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $claim_check['claimant_id'], $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kuingiza malipo: " . mysqli_error($conn);
            }
        }
        
        header("Location: add-payment.php");
        exit();
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    .form-container { max-width: 1200px; margin: 0 auto; }
    .form-card { background: white; border-radius: 1rem; border: 1px solid #e8f0e4; overflow: hidden; margin-bottom: 1.5rem; }
    .form-card-header { padding: 1.25rem 1.5rem; background: #f4fcef; border-bottom: 1px solid #e8f0e4; }
    .form-card-header h3 { font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .form-card-body { padding: 1.5rem; }
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.5rem; }
    .form-label.required::after { content: "*"; color: #dc2626; margin-left: 0.25rem; }
    .form-control, .form-select, .form-textarea { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .form-control:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    .form-hint { font-size: 0.7rem; color: #6d7b6c; margin-top: 0.25rem; }
    .btn-submit { background-color: #006e2c; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
    .btn-submit:hover { background-color: #005a24; }
    .btn-cancel { background-color: white; color: #3d4a3d; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; border: 1px solid #bccab9; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
    .btn-cancel:hover { background-color: #f4fcef; }
    .alert-error { background-color: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .alert-success { background-color: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    
    .claims-table { width: 100%; border-collapse: collapse; }
    .claims-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .claims-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; font-size: 0.875rem; }
    .claims-table tr:hover { background-color: #f4fcef; cursor: pointer; }
    
    .status-badge { display: inline-flex; align-items: center; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.completed { background: #a7f3d0; color: #064e3b; }
    
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; }
    
    @media (max-width: 768px) {
        .grid-2 { grid-template-columns: 1fr; gap: 1rem; }
        .claims-table { min-width: 600px; }
        .table-container { overflow-x: auto; }
    }
</style>

<div class="form-container">
    
    <!-- Page Header with Back Button -->
    <div class="flex items-center gap-3 mb-6">
        <a href="payments.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Ingiza Malipo</h2>
            <p class="text-secondary text-sm mt-1">Ingiza taarifa za malipo kwa wadai walioidhinishwa</p>
        </div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="alert-success">
        <span class="material-symbols-outlined">check_circle</span>
        <span><?php echo $success_message; ?></span>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="alert-error">
        <span class="material-symbols-outlined">error</span>
        <span><?php echo $error_message; ?></span>
    </div>
    <?php endif; ?>
    
    <!-- Main Form Card -->
    <div class="form-card">
        <div class="form-card-header">
            <h3>
                <span class="material-symbols-outlined text-primary">payments</span>
                Taarifa za Malipo
            </h3>
        </div>
        <div class="form-card-body">
            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="add_payment" value="1">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Chagua Dai</label>
                        <select name="claim_id" id="claim_id" class="form-select" required onchange="updateClaimInfo()">
                            <option value="">-- Chagua Dai --</option>
                            <?php foreach ($claims as $claim): ?>
                                <option value="<?php echo $claim['id']; ?>" 
                                        data-claim-number="<?php echo $claim['claim_number']; ?>"
                                        data-claimant-name="<?php echo htmlspecialchars($claim['full_name']); ?>"
                                        data-project="<?php echo htmlspecialchars($claim['project_name']); ?>"
                                        data-district="<?php echo htmlspecialchars($claim['district']); ?>"
                                        data-amount="<?php echo $claim['total_compensation']; ?>"
                                        data-email="<?php echo $claim['email']; ?>"
                                        data-phone="<?php echo $claim['phone']; ?>">
                                    <?php echo $claim['claim_number']; ?> - <?php echo htmlspecialchars($claim['full_name']); ?> (TZS <?php echo number_format($claim['total_compensation'], 0); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($claims)): ?>
                        <div class="form-hint" style="color: #dc2626;">⚠️ Hakuna dai lililoidhinishwa. Tafadhali idhinisha dai kwanza.</div>
                        <?php else: ?>
                        <div class="form-hint">Madai yanayoonyeshwa ni yale yaliyoidhinishwa lakini haijalipwa</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Kiasi cha Malipo (TZS)</label>
                        <input type="number" name="amount" id="amount" class="form-control" step="1000" required placeholder="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Njia ya Malipo</label>
                        <select name="payment_method" id="payment_method" class="form-select" required>
                            <option value="">-- Chagua Njia --</option>
                            <?php foreach ($payment_methods as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Namba ya Marejeleo (Transaction Reference)</label>
                        <input type="text" name="transaction_reference" id="transaction_reference" class="form-control" placeholder="Mfano: TRX-2024-001">
                        <div class="form-hint">Kwa bank transfer au mobile money, ingiza namba ya marejeleo</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maelezo (Hiari)</label>
                        <textarea name="notes" id="notes" class="form-textarea" rows="2" placeholder="Maelezo ya ziada kuhusu malipo..."></textarea>
                    </div>
                </div>
                
                <!-- Claim Information Preview -->
                <div class="bg-surface-container-low p-4 rounded-lg mt-4" id="claimInfoPreview" style="display: none;">
                    <h4 class="font-semibold text-sm mb-2">Taarifa za Dai Lililochaguliwa</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                        <div><span class="text-secondary">Namba ya Dai:</span><br><span id="preview_claim_number" class="font-mono font-semibold">-</span></div>
                        <div><span class="text-secondary">Mwombaji:</span><br><span id="preview_claimant_name">-</span></div>
                        <div><span class="text-secondary">Mradi:</span><br><span id="preview_project">-</span></div>
                        <div><span class="text-secondary">Fidia Iliyoidhinishwa:</span><br><span id="preview_amount" class="font-semibold text-primary">-</span></div>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <a href="payments.php" class="btn-cancel">
                        <span class="material-symbols-outlined text-sm">cancel</span>
                        Ghairi
                    </a>
                    <button type="submit" class="btn-submit" <?php echo empty($claims) ? 'disabled' : ''; ?>>
                        <span class="material-symbols-outlined text-sm">save</span>
                        Hifadhi Malipo
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Recent Payments Section -->
    <div class="form-card">
        <div class="form-card-header">
            <h3>
                <span class="material-symbols-outlined text-primary">history</span>
                Malipo ya Hivi Karibuni
            </h3>
        </div>
        <div class="form-card-body">
            <div class="table-container">
                <table class="claims-table">
                    <thead>
                        <tr>
                            <th>Namba ya Dai</th>
                            <th>Mwombaji</th>
                            <th>Mradi</th>
                            <th>Kiasi (TZS)</th>
                            <th>Hali</th>
                            <th>Tarehe</th>
                            <th>Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paid_claims)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-8 text-secondary">Hakuna malipo yaliyorekodiwa bado</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($paid_claims as $paid): ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($paid['claim_number']); ?></td>
                            <td><?php echo htmlspecialchars($paid['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($paid['project_name'] ?? '-'); ?></td>
                            <td class="font-semibold text-primary">TZS <?php echo number_format($paid['amount'], 0, '.', ','); ?></td>
                            <td><span class="status-badge completed">Imelipwa</span></td>
                            <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($paid['paid_at'])); ?></td>
                            <td>
                                <a href="payments.php?search=<?php echo $paid['claim_number']; ?>" class="text-primary hover:underline text-sm">Angalia</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Maelekezo</p>
                <ul class="text-sm text-blue-700 mt-1 space-y-1 list-disc list-inside">
                    <li>Hakikisha dai limeidhinishwa kabla ya kuingiza malipo</li>
                    <li>Kiasi cha malipo kinapaswa kuwa sawa na fidia iliyoidhinishwa</li>
                    <li>Ingiza namba ya marejeleo kwa malipo ya benki au mobile money</li>
                    <li>Baada ya kuingiza malipo, hali ya dai itabadilika kuwa "Imelipwa"</li>
                    <li>Mwombaji atapata arifa ya malipo yake</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // Update claim information preview when claim is selected
    function updateClaimInfo() {
        const select = document.getElementById('claim_id');
        const selectedOption = select.options[select.selectedIndex];
        const previewDiv = document.getElementById('claimInfoPreview');
        const amountField = document.getElementById('amount');
        
        if (select.value && selectedOption) {
            const claimNumber = selectedOption.getAttribute('data-claim-number');
            const claimantName = selectedOption.getAttribute('data-claimant-name');
            const project = selectedOption.getAttribute('data-project');
            const compensation = parseFloat(selectedOption.getAttribute('data-amount')) || 0;
            
            document.getElementById('preview_claim_number').innerHTML = claimNumber || '-';
            document.getElementById('preview_claimant_name').innerHTML = claimantName || '-';
            document.getElementById('preview_project').innerHTML = project || '-';
            document.getElementById('preview_amount').innerHTML = 'TZS ' + compensation.toLocaleString();
            
            // Auto-fill the amount field with the approved compensation
            if (compensation > 0) {
                amountField.value = compensation;
            }
            
            previewDiv.style.display = 'block';
        } else {
            previewDiv.style.display = 'none';
        }
    }
    
    // Form validation
    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            const claimId = document.getElementById('claim_id').value;
            const amount = document.getElementById('amount').value;
            const paymentMethod = document.getElementById('payment_method').value;
            
            if (!claimId) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali chagua dai',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali ingiza kiasi cha malipo',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!paymentMethod) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu',
                    text: 'Tafadhali chagua njia ya malipo',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            // Show confirmation
            e.preventDefault();
            const selectedOption = document.getElementById('claim_id').options[document.getElementById('claim_id').selectedIndex];
            const claimDisplay = selectedOption ? selectedOption.text.split(' - ')[0] : '';
            
            Swal.fire({
                title: 'Thibitisha Malipo',
                html: `Je, una uhakika unataka kuingiza malipo haya?<br><br>
                       <strong>Dai:</strong> ${claimDisplay}<br>
                       <strong>Kiasi:</strong> TZS ${parseFloat(amount).toLocaleString()}<br>
                       <strong>Njia:</strong> ${paymentMethod.replace('_', ' ')}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Hifadhi',
                cancelButtonText: 'Hapana'
            }).then((result) => {
                if (result.isConfirmed) {
                    paymentForm.submit();
                }
            });
            
            return false;
        });
    }
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>