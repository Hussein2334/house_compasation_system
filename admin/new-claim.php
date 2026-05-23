<?php
// admin/new-claim.php - Create new claim with valuation fields
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

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'valuer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'New Claim';
$page_heading = 'Kuwasilisha Dai Jipya';

// Get database connection
$conn = getDB();

// Get all claimants (users with role 'claimant')
$claimants_query = "SELECT id, full_name, email, phone FROM users WHERE role = 'claimant' ORDER BY full_name";
$claimants_result = mysqli_query($conn, $claimants_query);
$claimants = [];
while ($row = mysqli_fetch_assoc($claimants_result)) {
    $claimants[] = $row;
}

// Get valuators for assignment
$valuators_query = "SELECT id, full_name, email FROM users WHERE role = 'valuer' AND status = 'active' ORDER BY full_name";
$valuators_result = mysqli_query($conn, $valuators_query);
$valuators = [];
while ($row = mysqli_fetch_assoc($valuators_result)) {
    $valuators[] = $row;
}

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

// Handle form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $claimant_id = intval($_POST['claimant_id'] ?? 0);
    $project_name = trim($_POST['project_name'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $ward = trim($_POST['ward'] ?? '');
    $village = trim($_POST['village'] ?? '');
    $property_type = trim($_POST['property_type'] ?? '');
    $property_size = trim($_POST['property_size'] ?? '');
    $claim_amount = !empty($_POST['claim_amount']) ? floatval($_POST['claim_amount']) : null;
    $gps_coordinates = trim($_POST['gps_coordinates'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Valuation fields
    $property_value = !empty($_POST['property_value']) ? floatval($_POST['property_value']) : 0;
    $disturbance_allowance = !empty($_POST['disturbance_allowance']) ? floatval($_POST['disturbance_allowance']) : 0;
    $transport_allowance = !empty($_POST['transport_allowance']) ? floatval($_POST['transport_allowance']) : 0;
    $valuation_report = trim($_POST['valuation_report'] ?? '');
    $valuer_id = !empty($_POST['valuer_id']) ? intval($_POST['valuer_id']) : $_SESSION['user_id'];
    
    $total_compensation = $property_value + $disturbance_allowance + $transport_allowance;
    
    // Determine status based on whether valuation is provided
    if ($property_value > 0 || $disturbance_allowance > 0 || $transport_allowance > 0) {
        $status = 'legal_review'; // If valuation provided, go to legal review
    } else {
        $status = 'submitted'; // Default status for new claims without valuation
    }
    
    // Validation
    $errors = [];
    
    if ($claimant_id <= 0) {
        $errors[] = "Tafadhali chagua mwombaji";
    }
    
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
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate claim number
            $claim_number = generateClaimNumber($conn);
            
            // Insert claim
            $insert_query = "INSERT INTO claims (
                claimant_id, claim_number, project_name, district, ward, village,
                property_type, property_size, claim_amount, gps_coordinates, 
                description, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "issssssddsss", 
                $claimant_id, $claim_number, $project_name, $district, $ward, $village,
                $property_type, $property_size, $claim_amount, $gps_coordinates,
                $description, $status
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Hitilafu katika kuwasilisha dai: " . mysqli_error($conn));
            }
            
            $new_claim_id = mysqli_insert_id($conn);
            
            // Insert valuation if values are provided
            if ($property_value > 0 || $disturbance_allowance > 0 || $transport_allowance > 0) {
                $insert_val_query = "INSERT INTO valuations (
                    claim_id, valuer_id, property_value, disturbance_allowance, 
                    transport_allowance, total_compensation, valuation_report
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $val_stmt = mysqli_prepare($conn, $insert_val_query);
                mysqli_stmt_bind_param($val_stmt, "iidddds", 
                    $new_claim_id, $valuer_id, $property_value, 
                    $disturbance_allowance, $transport_allowance, 
                    $total_compensation, $valuation_report
                );
                
                if (!mysqli_stmt_execute($val_stmt)) {
                    throw new Exception("Hitilafu katika kuongeza tathmini: " . mysqli_error($conn));
                }
            }
            
            mysqli_commit($conn);
            
            // Log audit
            logAudit($conn, $_SESSION['user_id'], 'CREATE_CLAIM', 'claims', $new_claim_id, null, [
                'claim_number' => $claim_number,
                'claimant_id' => $claimant_id,
                'project_name' => $project_name,
                'has_valuation' => ($property_value > 0 || $disturbance_allowance > 0 || $transport_allowance > 0)
            ]);
            
            $_SESSION['success_message'] = "Dai limewasilishwa kikamilifu. Namba ya dai: $claim_number";
            header("Location: claims.php");
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

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    .form-container {
        max-width: 950px;
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
    
    .grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
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
    
    .valuation-section {
        background: #f4fcef;
        border-radius: 0.75rem;
        padding: 1.25rem;
        margin-top: 0.5rem;
        border: 1px solid #d1e0c8;
    }
    
    .valuation-section h4 {
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 1rem;
        color: #006e2c;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .total-box {
        background: #e8f0e4;
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
    
    .total-box .label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #3d4a3d;
    }
    
    @media (max-width: 640px) {
        .grid-2, .grid-3 {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
    }
</style>

<div class="form-container">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="claims.php" class="text-secondary hover:text-primary">
                    <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <h2 class="font-headline-lg text-on-background">Kuwasilisha Dai Jipya</h2>
            </div>
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
    
    <!-- Claim Form -->
    <form method="POST" action="" id="claimForm">
        <!-- Section 1: Claimant Information -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">person</span>
                    Taarifa za Mwombaji
                </h3>
            </div>
            <div class="form-card-body">
                <div class="form-group">
                    <label class="form-label required">Chagua Mwombaji</label>
                    <select name="claimant_id" id="claimant_id" class="form-select" required>
                        <option value="">-- Chagua Mwombaji --</option>
                        <?php foreach ($claimants as $claimant): ?>
                            <option value="<?php echo $claimant['id']; ?>" <?php echo (isset($_POST['claimant_id']) && $_POST['claimant_id'] == $claimant['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($claimant['full_name']); ?> - <?php echo htmlspecialchars($claimant['email']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Mwombaji lazima awe amesajiliwa kwenye mfumo</div>
                </div>
            </div>
        </div>
        
        <!-- Section 2: Project Information -->
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
        
        <!-- Section 3: Property Information -->
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
                               placeholder="Mfano: 500" step="0.01">
                        <div class="form-hint">Ukubwa wa mali katika mita za mraba</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kiasi cha Dai (TZS)</label>
                        <input type="number" name="claim_amount" id="claim_amount" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['claim_amount'] ?? ''); ?>"
                               placeholder="Mfano: 5000000" step="1000">
                        <div class="form-hint">Kiasi anachodai mwombaji (Shilingi za Tanzania)</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">GPS Coordinates</label>
                        <input type="text" name="gps_coordinates" id="gps_coordinates" class="form-input"
                               value="<?php echo htmlspecialchars($_POST['gps_coordinates'] ?? ''); ?>"
                               placeholder="Mfano: -6.792354, 39.208328">
                        <div class="form-hint">Viwianishi vya eneo la mali</div>
                    </div>
                </div>
                
                <hr>
                
                <div class="form-group">
                    <label class="form-label">Maelezo ya Dai</label>
                    <textarea name="description" id="description" rows="3" class="form-textarea"
                              placeholder="Eleza kwa kina kuhusu mali na madai ya fidia..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="form-hint">Maelezo ya kina kuhusu mali, historia, na madai ya fidia</div>
                </div>
            </div>
        </div>
        
        <!-- Section 4: Valuation Information (Optional) -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                    Taarifa za Tathmini (Si Lazima)
                </h3>
            </div>
            <div class="form-card-body">
                <div class="valuation-section">
                    <h4>
                        <span class="material-symbols-outlined" style="font-size: 1.2rem;">calculate</span>
                        Thamani ya Mali na Fidia
                    </h4>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Thamani ya Mali (TZS)</label>
                            <input type="number" name="property_value" id="property_value" class="form-input" 
                                   step="1000" value="<?php echo htmlspecialchars($_POST['property_value'] ?? '0'); ?>"
                                   oninput="calculateTotal()">
                            <div class="form-hint">Thamani ya jumla ya ardhi na majengo</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Posho ya Usumbufu (TZS)</label>
                            <input type="number" name="disturbance_allowance" id="disturbance_allowance" class="form-input" 
                                   step="1000" value="<?php echo htmlspecialchars($_POST['disturbance_allowance'] ?? '0'); ?>"
                                   oninput="calculateTotal()">
                            <div class="form-hint">Kwa usumbufu wa makazi/biashara</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Posho ya Usafiri (TZS)</label>
                            <input type="number" name="transport_allowance" id="transport_allowance" class="form-input" 
                                   step="1000" value="<?php echo htmlspecialchars($_POST['transport_allowance'] ?? '0'); ?>"
                                   oninput="calculateTotal()">
                            <div class="form-hint">Gharama za kubebea mali</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Mkaguzi (Valuer)</label>
                            <select name="valuer_id" id="valuer_id" class="form-select">
                                <option value="<?php echo $_SESSION['user_id']; ?>">-- Mtumiaji wa Sasa (Mimi) --</option>
                                <?php foreach ($valuators as $valuator): ?>
                                    <option value="<?php echo $valuator['id']; ?>">
                                        <?php echo htmlspecialchars($valuator['full_name']); ?> - <?php echo htmlspecialchars($valuator['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Mkaguzi anayefanya tathmini hii</div>
                        </div>
                    </div>
                    
                    <div class="total-box">
                        <div class="label">Jumla ya Fidia Inayopendekezwa</div>
                        <div class="amount" id="total_display">TZS 0</div>
                        <input type="hidden" name="total_compensation" id="total_compensation" value="0">
                    </div>
                    
                    <div class="form-group" style="margin-top: 1rem;">
                        <label class="form-label">Ripoti / Maelezo ya Tathmini</label>
                        <textarea name="valuation_report" id="valuation_report" rows="3" class="form-textarea" 
                                  placeholder="Weka maelezo ya mbinu ya tathmini, vigezo vilivyotumika, na taarifa nyingine muhimu..."><?php echo htmlspecialchars($_POST['valuation_report'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-hint" style="margin-top: 0.75rem;">
                    <span class="material-symbols-outlined" style="font-size: 0.9rem; vertical-align: middle;">info</span>
                    Ikiwa utajaza sehemu ya tathmini, dai litaenda moja kwa moja kwenye hatua ya Uhakiki (Legal Review). 
                    Vinginevyo, litabaki kwenye hatua ya Imewasilishwa (Submitted).
                </div>
            </div>
        </div>
        
        <!-- Section 5: Preview & Submit -->
        <div class="form-card">
            <div class="form-card-header">
                <h3>
                    <span class="material-symbols-outlined text-primary">preview</span>
                    Hakiki na Wasilisha
                </h3>
            </div>
            <div class="form-card-body">
                <div class="flex justify-end gap-3">
                    <a href="claims.php" class="btn-cancel">
                        <span class="material-symbols-outlined text-sm">cancel</span>
                        Ghairi
                    </a>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-outlined text-sm">send</span>
                        Wasilisha Dai
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    // Calculate total compensation
    function calculateTotal() {
        let propertyValue = parseFloat(document.getElementById('property_value').value) || 0;
        let disturbanceAllowance = parseFloat(document.getElementById('disturbance_allowance').value) || 0;
        let transportAllowance = parseFloat(document.getElementById('transport_allowance').value) || 0;
        let total = propertyValue + disturbanceAllowance + transportAllowance;
        
        document.getElementById('total_compensation').value = total;
        document.getElementById('total_display').innerHTML = 'TZS ' + total.toLocaleString('en-US');
    }
    
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
    
    // Form validation before submit
    const claimForm = document.getElementById('claimForm');
    if (claimForm) {
        claimForm.addEventListener('submit', function(e) {
            const claimantId = document.getElementById('claimant_id').value;
            const projectName = document.getElementById('project_name').value;
            const district = document.getElementById('district').value;
            const propertyType = document.getElementById('property_type').value;
            
            if (!claimantId) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Hitilafu ya Uthibitishaji',
                    text: 'Tafadhali chagua mwombaji',
                    confirmButtonColor: '#006e2c'
                });
                return false;
            }
            
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
                text: 'Je, una uhakika unataka kuwasilisha dai hili?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#006e2c',
                cancelButtonColor: '#ba1a1a',
                confirmButtonText: 'Ndiyo, Wasilisha',
                cancelButtonText: 'Hapana, Rudi'
            }).then((result) => {
                if (result.isConfirmed) {
                    claimForm.submit();
                }
            });
            
            return false;
        });
    }
    
    // Initialize total calculation
    calculateTotal();
    
    // Display success/error messages using SweetAlert
    <?php if (!empty($success_message)): ?>
    Swal.fire({
        icon: 'success',
        title: 'Mafanikio!',
        text: '<?php echo addslashes($success_message); ?>',
        confirmButtonColor: '#006e2c',
        timer: 3000
    });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Hitilafu!',
        text: '<?php echo addslashes($error_message); ?>',
        confirmButtonColor: '#006e2c'
    });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>