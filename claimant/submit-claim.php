<?php
// claimant/submit-claim.php - Submit New Claim
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
$page_title = 'Submit New Claim';
$page_heading = 'Wasilisha Dai Jipya';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Project types
$project_types = [
    'SGR Railway Project' => 'SGR Railway Project',
    'Road Expansion' => 'Road Expansion',
    'Dar Port Expansion' => 'Dar Port Expansion',
    'Mwanza Port' => 'Mwanza Port',
    'Kigoma Port' => 'Kigoma Port',
    'Tanga Port' => 'Tanga Port',
    'Dodoma Ring Road' => 'Dodoma Ring Road',
    'Arusha Tourism' => 'Arusha Tourism',
    'Zanzibar Development' => 'Zanzibar Development',
    'Mbeya Coal Mines' => 'Mbeya Coal Mines',
    'Iringa Agriculture' => 'Iringa Agriculture',
    'Other' => 'Other'
];

// Property types
$property_types = [
    'residential' => 'Residential (Makazi)',
    'commercial' => 'Commercial (Biashara)',
    'agricultural' => 'Agricultural (Kilimo)',
    'industrial' => 'Industrial (Viwanada)',
    'other' => 'Other (Nyingine)'
];

// Districts of Tanzania
$districts = [
    'Arusha' => 'Arusha',
    'Dar es Salaam' => 'Dar es Salaam',
    'Dodoma' => 'Dodoma',
    'Geita' => 'Geita',
    'Iringa' => 'Iringa',
    'Kagera' => 'Kagera',
    'Katavi' => 'Katavi',
    'Kigoma' => 'Kigoma',
    'Kilimanjaro' => 'Kilimanjaro',
    'Lindi' => 'Lindi',
    'Manyara' => 'Manyara',
    'Mara' => 'Mara',
    'Mbeya' => 'Mbeya',
    'Morogoro' => 'Morogoro',
    'Mtwara' => 'Mtwara',
    'Mwanza' => 'Mwanza',
    'Njombe' => 'Njombe',
    'Pemba North' => 'Pemba North',
    'Pemba South' => 'Pemba South',
    'Pwani' => 'Pwani (Coast)',
    'Rukwa' => 'Rukwa',
    'Ruvuma' => 'Ruvuma',
    'Shinyanga' => 'Shinyanga',
    'Simiyu' => 'Simiyu',
    'Singida' => 'Singida',
    'Songwe' => 'Songwe',
    'Tabora' => 'Tabora',
    'Tanga' => 'Tanga',
    'Unguja North' => 'Unguja North',
    'Unguja South' => 'Unguja South',
    'Unguja West' => 'Unguja West (Mjini Magharibi)'
];

// Get admin users for notifications
$admin_query = "SELECT id FROM users WHERE role IN ('super_admin', 'commissioner') LIMIT 1";
$admin_result = mysqli_query($conn, $admin_query);
$admin = mysqli_fetch_assoc($admin_result);
$admin_id = $admin ? $admin['id'] : null;

// Handle form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_claim'])) {
    // Get form data
    $project_name = trim($_POST['project_name'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $ward = trim($_POST['ward'] ?? '');
    $village = trim($_POST['village'] ?? '');
    $property_type = trim($_POST['property_type'] ?? '');
    $property_size = trim($_POST['property_size'] ?? '');
    $claim_amount = !empty($_POST['claim_amount']) ? floatval($_POST['claim_amount']) : null;
    $gps_coordinates = trim($_POST['gps_coordinates'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validation
    $errors = [];
    
    if (empty($project_name)) {
        $errors[] = "Tafadhali jaza jina la mradi";
    }
    
    if (empty($district)) {
        $errors[] = "Tafadhali jaza wilaya";
    }
    
    if (empty($property_type)) {
        $errors[] = "Tafadhali chagua aina ya mali";
    }
    
    if (!empty($property_size) && !is_numeric($property_size)) {
        $errors[] = "Ukubwa wa mali lazima uwe namba";
    }
    
    if (!empty($claim_amount) && !is_numeric($claim_amount)) {
        $errors[] = "Kiasi cha dai lazima kiwe namba";
    }
    
    if ($claim_amount && $claim_amount < 0) {
        $errors[] = "Kiasi cha dai hakiwezi kuwa chini ya sifuri";
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate claim number
            $claim_number = generateClaimNumber($conn);
            $status = 'submitted'; // Default status for new claims
            
            // Insert claim
            $insert_query = "INSERT INTO claims (
                claimant_id, claim_number, project_name, district, ward, village,
                property_type, property_size, claim_amount, gps_coordinates, 
                description, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "issssssddsss", 
                $user_id, $claim_number, $project_name, $district, $ward, $village,
                $property_type, $property_size, $claim_amount, $gps_coordinates,
                $description, $status
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Hitilafu katika kuwasilisha dai: " . mysqli_error($conn));
            }
            
            $new_claim_id = mysqli_insert_id($conn);
            
            // Create notification for admin if admin exists
            if ($admin_id) {
                $notif_title = "Dai Jipya Limewasilishwa";
                $notif_message = "Mwombaji {$_SESSION['full_name']} amewasilisha dai jipya: $claim_number";
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'claim', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $admin_id, $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            }
            
            // Create notification for claimant (confirmation)
            $claimant_notif_title = "Dai Limewasilishwa Kikamilifu";
            $claimant_notif_message = "Dai lako namba $claim_number limepokelewa na linachakatwa. Tafadhali subiri maendeleo zaidi.";
            $claimant_notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                                    VALUES (?, ?, ?, 'claim', NOW())";
            $claimant_notif_stmt = mysqli_prepare($conn, $claimant_notif_query);
            mysqli_stmt_bind_param($claimant_notif_stmt, "iss", $user_id, $claimant_notif_title, $claimant_notif_message);
            mysqli_stmt_execute($claimant_notif_stmt);
            
            // Log audit
            logAudit($conn, $user_id, 'CREATE_CLAIM', 'claims', $new_claim_id, null, [
                'claim_number' => $claim_number,
                'project_name' => $project_name
            ]);
            
            mysqli_commit($conn);
            
            $_SESSION['success_message'] = "Dai limewasilishwa kikamilifu. Namba ya dai: $claim_number";
            header("Location: my-claims.php");
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error_message = $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Get success/error messages from session
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/claimant-header.php';
?>

<style>
    .form-container {
        max-width: 900px;
        margin: 0 auto;
    }
    
    .form-card {
        background: white;
        border-radius: 1rem;
        border: 1px solid #e8f0e4;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    
    .form-card-header {
        padding: 1.25rem 1.5rem;
        background: #f4fcef;
        border-bottom: 1px solid #e8f0e4;
    }
    
    .form-card-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #1e2a1e;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .form-card-body {
        padding: 1.5rem;
    }
    
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.5rem;
    }
    
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    
    .form-input[readonly] {
        background: #f4fcef;
        cursor: not-allowed;
    }
    
    .form-hint {
        font-size: 0.7rem;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .btn-submit {
        background-color: #006e2c;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-submit:hover {
        background-color: #005a24;
    }
    
    .btn-cancel {
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
    }
    
    .btn-cancel:hover {
        background-color: #f4fcef;
    }
    
    .alert-error {
        background-color: #fee2e2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background-color: #d1fae5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        padding: 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1.5rem;
    }
    
    hr {
        margin: 1rem 0;
        border: none;
        border-top: 1px solid #e8f0e4;
    }
    
    /* Loading overlay */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        visibility: hidden;
    }
    .loading-overlay.active {
        visibility: visible;
    }
    .loading-spinner {
        background: white;
        padding: 2rem;
        border-radius: 1rem;
        text-align: center;
    }
    
    @media (max-width: 640px) {
        .grid-2 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
    }
</style>

<div class="form-container">
    
    <!-- Page Header with Back Button -->
    <div class="flex items-center gap-3 mb-6">
        <a href="dashboard.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Wasilisha Dai Jipya</h2>
            <p class="text-secondary text-sm mt-1">Jaza taarifa zote zinazohitajika kwa ajili ya dai la fidia</p>
        </div>
    </div>
    
    <!-- Error/Success Messages -->
    <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined">error</span>
                <span><?php echo $error_message; ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
        <div class="alert-success">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined">check_circle</span>
                <span><?php echo $success_message; ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Claim Form -->
    <form method="POST" action="" id="claimForm">
        <input type="hidden" name="submit_claim" value="1">
        
        <!-- Section 1: Project Information -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">construction</span>
                    Taarifa za Mradi
                </h3>
            </div>
            <div class="form-card-body">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Jina la Mradi</label>
                        <select name="project_name" id="project_name" class="form-select" required>
                            <option value="">-- Chagua Mradi --</option>
                            <?php foreach ($project_types as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (isset($_POST['project_name']) && $_POST['project_name'] == $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Wilaya / District</label>
                        <select name="district" id="district" class="form-select" required>
                            <option value="">-- Chagua Wilaya --</option>
                            <?php foreach ($districts as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (isset($_POST['district']) && $_POST['district'] == $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kata / Ward</label>
                        <input type="text" name="ward" id="ward" class="form-input" 
                               value="<?php echo htmlspecialchars($_POST['ward'] ?? ''); ?>"
                               placeholder="Mfano: Kijitonyama">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kijiji / Village</label>
                        <input type="text" name="village" id="village" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['village'] ?? ''); ?>"
                               placeholder="Mfano: Msasani">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Section 2: Property Information -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">home</span>
                    Taarifa za Mali
                </h3>
            </div>
            <div class="form-card-body">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Aina ya Mali</label>
                        <select name="property_type" id="property_type" class="form-select" required>
                            <option value="">-- Chagua Aina --</option>
                            <?php foreach ($property_types as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo (isset($_POST['property_type']) && $_POST['property_type'] == $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Ukubwa (Square Meters)</label>
                        <input type="number" name="property_size" id="property_size" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['property_size'] ?? ''); ?>"
                               placeholder="Mfano: 500" step="0.01" min="0">
                        <div class="form-hint">Ukubwa wa mali katika mita za mraba</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kiasi cha Dai (TZS)</label>
                        <input type="number" name="claim_amount" id="claim_amount" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['claim_amount'] ?? ''); ?>"
                               placeholder="Mfano: 5000000" step="1000" min="0">
                        <div class="form-hint">Kiasi unachodai (Shilingi za Tanzania)</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">GPS Coordinates</label>
                        <input type="text" name="gps_coordinates" id="gps_coordinates" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['gps_coordinates'] ?? ''); ?>"
                               placeholder="Mfano: -6.792354, 39.208328">
                        <div class="form-hint">Viwianishi vya eneo la mali (lat, long)</div>
                    </div>
                </div>
                
                <hr>
                
                <div class="form-group">
                    <label class="form-label">Maelezo ya Dai</label>
                    <textarea name="description" id="description" rows="4" class="form-textarea"
                              placeholder="Eleza kwa kina kuhusu mali yako, historia ya umiliki, na madai ya fidia..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="form-hint">Toa maelezo ya kina kuhusu mali na sababu za kulipa fidia</div>
                </div>
            </div>
        </div>
        
        <!-- Section 3: Required Documents Info -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">description</span>
                    Nyaraka Zinazohitajika
                </h3>
            </div>
            <div class="form-card-body">
                <div class="bg-blue-50 rounded-lg p-4">
                    <p class="text-sm text-blue-800 mb-2">Tafadhali hakikisha una nyaraka zifuatazo kwa ajili ya kuthibitisha dai lako:</p>
                    <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                        <li>Hati ya umiliki wa ardhi (Title Deed / CCRO)</li>
                        <li>Nakala ya Kitambulisho (ID/NIDA)</li>
                        <li>Picha za mali kabla ya uondoaji</li>
                        <li>Hati nyingine za kuthibitisha umiliki</li>
                    </ul>
                    <p class="text-sm text-blue-800 mt-3">Nyaraka hizi utazipakia baada ya kuwasilisha dai lako.</p>
                </div>
            </div>
        </div>
        
        <!-- Section 4: Preview & Submit -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">preview</span>
                    Hakiki na Wasilisha
                </h3>
            </div>
            <div class="form-card-body">
                <div class="flex justify-end gap-3">
                    <a href="dashboard.php" class="btn-cancel">
                        <span class="material-symbols-outlined text-sm">cancel</span>
                        Ghairi
                    </a>
                    <button type="submit" id="submitBtn" class="btn-submit">
                        <span class="material-symbols-outlined text-sm">send</span>
                        Wasilisha Dai
                    </button>
                </div>
                <p class="text-xs text-secondary text-center mt-4">
                    Kwa kuwasilisha dai lako, unakubali kuwa taarifa zote ulizotoa ni sahihi na za kweli.
                </p>
            </div>
        </div>
    </form>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <span class="material-symbols-outlined animate-spin text-primary text-4xl">progress_activity</span>
        <p class="mt-2">Inawasilisha dai lako...</p>
    </div>
</div>

<script>
    // Auto-format currency input
    const claimAmountInput = document.getElementById('claim_amount');
    if (claimAmountInput) {
        claimAmountInput.addEventListener('blur', function() {
            let value = this.value;
            if (value && !isNaN(value)) {
                let formatted = parseInt(value).toLocaleString('en-US');
                this.setAttribute('data-formatted', formatted);
            }
        });
        
        claimAmountInput.addEventListener('focus', function() {
            let value = this.value;
            if (value && !isNaN(value)) {
                this.value = parseInt(value).toString();
            }
        });
    }
    
    // Validate GPS coordinates format
    const gpsInput = document.getElementById('gps_coordinates');
    if (gpsInput) {
        gpsInput.addEventListener('blur', function() {
            let value = this.value.trim();
            if (value) {
                const pattern = /^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/;
                if (!pattern.test(value)) {
                    this.style.borderColor = '#dc2626';
                    let hint = this.nextElementSibling;
                    if (hint && hint.classList.contains('form-hint')) {
                        hint.style.color = '#dc2626';
                        hint.innerHTML = '⚠️ Format sahihi: lat, long (mfano: -6.792354, 39.208328)';
                    }
                } else {
                    this.style.borderColor = '#bccab9';
                    let hint = this.nextElementSibling;
                    if (hint && hint.classList.contains('form-hint')) {
                        hint.style.color = '#6d7b6c';
                        hint.innerHTML = 'Viwianishi vya eneo la mali';
                    }
                }
            }
        });
        
        gpsInput.addEventListener('focus', function() {
            this.style.borderColor = '#bccab9';
            let hint = this.nextElementSibling;
            if (hint && hint.classList.contains('form-hint')) {
                hint.style.color = '#6d7b6c';
                hint.innerHTML = 'Viwianishi vya eneo la mali';
            }
        });
    }
    
    // Show loading overlay
    function showLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.add('active');
        }
    }
    
    // Hide loading overlay
    function hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.remove('active');
        }
    }
    
    // Form validation before submit
    const claimForm = document.getElementById('claimForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (claimForm) {
        claimForm.addEventListener('submit', function(e) {
            const projectName = document.getElementById('project_name').value;
            const district = document.getElementById('district').value;
            const propertyType = document.getElementById('property_type').value;
            
            if (!projectName) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu ya Uthibitishaji',
                    text: 'Tafadhali jaza jina la mradi',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!district) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu ya Uthibitishaji',
                    text: 'Tafadhali jaza wilaya',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            if (!propertyType) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu ya Uthibitishaji',
                    text: 'Tafadhali chagua aina ya mali',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
            // Show confirmation
            e.preventDefault();
            Swal.fire({
                title: 'Thibitisha Uwasilishaji',
                html: 'Je, una uhakika unataka kuwasilisha dai hili?<br><small class="text-secondary">Hakikisha taarifa zote ni sahihi kabla ya kuwasilisha.</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Wasilisha',
                cancelButtonText: 'Hapana, Rudi'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading overlay
                    showLoading();
                    // Disable submit button
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="material-symbols-outlined text-sm">progress_activity</span> Inawasilisha...';
                    }
                    // Submit the form
                    claimForm.submit();
                }
            });
            
            return false;
        });
    }
</script>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>