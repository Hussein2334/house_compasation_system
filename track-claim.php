<?php
// track-claim.php - Track Claim Status Page
session_start();

// Include required files
require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/audit.php';

$claim_data = null;
$error_message = '';
$search_performed = false;

// Handle claim search
if (isset($_GET['claim_number']) && !empty($_GET['claim_number'])) {
    $claim_number = sanitizeInput($_GET['claim_number']);
    $search_performed = true;
    
    $conn = getDB();
    
    // Get claim details with claimant information
    $query = "SELECT c.*, 
                     u.full_name as claimant_name, 
                     u.email, 
                     u.phone,
                     v.property_value,
                     v.disturbance_allowance,
                     v.transport_allowance,
                     v.total_compensation,
                     v.valuation_report,
                     v.created_at as valuation_date
              FROM claims c
              LEFT JOIN users u ON c.claimant_id = u.id
              LEFT JOIN valuations v ON c.id = v.claim_id
              WHERE c.claim_number = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $claim_number);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $claim_data = $row;
        
        // Log this search for audit
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        logAudit($conn, $user_id, 'TRACK_CLAIM', 'claims', $row['id'], null, ['claim_number' => $claim_number]);
    } else {
        $error_message = "Dai lenye namba '$claim_number' halijapatikana. Tafadhali hakiki namba yako na ujaribu tena.";
    }
}

// Define status steps and their order
$status_steps = [
    'submitted' => ['name' => 'Imewasilishwa', 'icon' => 'check', 'description' => 'Ombi lako limepokelewa na kusajiliwa kwenye mfumo.'],
    'valuation' => ['name' => 'Tathmini ya Mali', 'icon' => 'real_estate_agent', 'description' => 'Ukaguzi wa mali na makadirio ya thamani yanaendelea.'],
    'legal_review' => ['name' => 'Uhakiki wa Kisheria', 'icon' => 'gavel', 'description' => 'Nyaraka zako zinahakikiwa na jopo la wanasheria.'],
    'approved' => ['name' => 'Kuidhinishwa', 'icon' => 'verified', 'description' => 'Dai lako limeidhinishwa na kukubaliwa.'],
    'rejected' => ['name' => 'Kukataliwa', 'icon' => 'cancel', 'description' => 'Dai lako limekataliwa kwa sababu zilizoelezwa.'],
    'paid' => ['name' => 'Malipo', 'icon' => 'payments', 'description' => 'Malipo yako yamefanywa na kukamilika.']
];

// Get current step index
function getStepIndex($status) {
    $order = ['submitted', 'valuation', 'legal_review', 'approved', 'paid'];
    $index = array_search($status, $order);
    return $index !== false ? $index : 0;
}

// NOTE: formatDateSw() function is already defined in includes/functions.php
// Do NOT redeclare it here - it will cause a fatal error
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#006e2c"/>
<title>Fuatilia Dai | HCS - Mfumo wa Fidia ya Nyumba</title>

<!-- TailwindCSS CDN -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet"/>

<!-- Material Icons -->
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "tertiary-container": "#fb6787",
                    "tertiary": "#ad2c4e",
                    "on-tertiary": "#ffffff",
                    "on-secondary-fixed-variant": "#564500",
                    "on-secondary-fixed": "#231b00",
                    "on-primary": "#ffffff",
                    "on-tertiary-fixed-variant": "#8d0f38",
                    "background": "#f4fcef",
                    "secondary": "#725c00",
                    "on-surface-variant": "#3d4a3d",
                    "error": "#ba1a1a",
                    "on-primary-container": "#003a14",
                    "on-error-container": "#93000a",
                    "surface-container-low": "#eef6ea",
                    "outline": "#6d7b6c",
                    "error-container": "#ffdad6",
                    "on-secondary": "#ffffff",
                    "inverse-surface": "#2b322a",
                    "surface-dim": "#d4dcd1",
                    "primary-container": "#1eb050",
                    "primary-fixed-dim": "#5be079",
                    "tertiary-fixed-dim": "#ffb2bd",
                    "on-background": "#161d16",
                    "inverse-primary": "#5be079",
                    "surface-variant": "#dde5d9",
                    "on-surface": "#161d16",
                    "surface-tint": "#006e2c",
                    "surface-container": "#e8f0e4",
                    "on-tertiary-fixed": "#400014",
                    "on-primary-fixed": "#002108",
                    "secondary-fixed-dim": "#edc200",
                    "primary": "#006e2c",
                    "secondary-container": "#fed000",
                    "on-tertiary-container": "#690025",
                    "secondary-fixed": "#ffe07f",
                    "primary-fixed": "#79fd92",
                    "on-primary-fixed-variant": "#005320",
                    "surface-container-high": "#e3eadf",
                    "surface-container-lowest": "#ffffff",
                    "tertiary-fixed": "#ffd9dd",
                    "surface": "#f4fcef",
                    "on-error": "#ffffff",
                    "inverse-on-surface": "#ebf3e7",
                    "surface-container-highest": "#dde5d9",
                    "on-secondary-container": "#6f5900",
                    "surface-bright": "#f4fcef",
                    "outline-variant": "#bccab9"
                },
                borderRadius: {
                    DEFAULT: "0.125rem",
                    lg: "0.25rem",
                    xl: "0.5rem",
                    full: "0.75rem"
                },
                spacing: {
                    "margin-desktop": "64px",
                    "xs": "4px",
                    "md": "24px",
                    "max-width": "1280px",
                    "xl": "80px",
                    "margin-mobile": "16px",
                    "base": "8px",
                    "lg": "48px",
                    "sm": "12px",
                    "gutter": "24px"
                },
                fontFamily: {
                    "headline-md": ["Inter"],
                    "body-lg": ["Inter"],
                    "label-md": ["Inter"],
                    "body-md": ["Inter"],
                    "label-sm": ["Inter"],
                    "display-lg": ["Inter"],
                    "display-lg-mobile": ["Inter"],
                    "headline-lg-mobile": ["Inter"],
                    "headline-lg": ["Inter"]
                },
                fontSize: {
                    "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                    "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                    "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                    "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                    "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}],
                    "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                    "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                    "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                    "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}]
                }
            },
        },
    }
</script>
<style>
    .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        display: inline-block;
        vertical-align: middle;
    }
    .step-line::after {
        content: '';
        position: absolute;
        left: 19px;
        top: 40px;
        bottom: -10px;
        width: 2px;
        background-color: #bccab9;
        transition: background-color 0.3s ease;
    }
    .step-line-active::after {
        background-color: #1eb050;
    }
    .step-item:last-child .step-line::after {
        display: none;
    }
    .bg-pattern {
        background-image: radial-gradient(#006e2c 0.5px, transparent 0.5px);
        background-size: 24px 24px;
        opacity: 0.05;
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
    .shake { animation: shake 0.2s ease-in-out 0s 2; }
    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.8; transform: scale(0.98); }
    }
    .loading-pulse {
        animation: pulse 1.5s ease-in-out infinite;
    }
</style>
</head>
<body class="bg-surface font-body-md text-on-surface min-h-screen flex flex-col">

<!-- TopAppBar -->
<header class="bg-surface border-b-2 border-secondary sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto h-16">
        <div class="flex items-center gap-4">
            <a href="index.php" class="hover:opacity-80 transition-opacity">
                <span class="material-symbols-outlined text-primary">arrow_back</span>
            </a>
            <a href="index.php" class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">account_balance</span>
                <h1 class="font-headline-md text-headline-md font-black text-primary">HCS Tracking</h1>
            </a>
        </div>
        <div class="flex items-center gap-4">
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="w-10 h-10 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-bold overflow-hidden hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined">account_circle</span>
                </a>
            <?php else: ?>
                <a href="auth/login.php" class="font-label-md text-label-md text-primary hover:underline">Ingia</a>
                <a href="auth/register.php" class="bg-primary text-on-primary px-4 py-2 rounded-lg font-label-md text-label-md hover:bg-primary-container transition-colors">Jisajili</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="flex-grow pb-32 relative overflow-hidden">
    <!-- Background Pattern -->
    <div class="absolute inset-0 bg-pattern pointer-events-none"></div>
    
    <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop pt-6 md:pt-8">
        
        <!-- Hero Section -->
        <div class="mb-6 md:mb-lg">
            <h2 class="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary mb-3 md:mb-4">
                Fuatilia Dai Lako
            </h2>
            <p class="text-on-surface-variant font-body-md max-w-2xl">
                Ingiza namba ya dai uliyopewa wakati wa usajili kuona hatua iliyofikiwa katika mchakato wa fidia.
            </p>
        </div>
        
        <!-- Search Section -->
        <div class="mb-8 md:mb-xl">
            <div class="bg-surface-container-lowest p-5 md:p-6 rounded-xl border border-outline-variant max-w-xl">
                <form method="GET" action="" id="trackForm">
                    <label class="block font-label-md text-label-md mb-2 text-on-surface" for="claim-number">
                        Namba ya Dai
                    </label>
                    <div class="flex flex-col sm:flex-row gap-3 md:gap-4">
                        <div class="relative flex-grow">
                            <input class="w-full h-12 px-4 rounded-lg border border-outline focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all" 
                                   id="claim-number" 
                                   name="claim_number" 
                                   placeholder="Mfano: HCS-2024-001" 
                                   type="text"
                                   value="<?php echo isset($_GET['claim_number']) ? htmlspecialchars($_GET['claim_number']) : ''; ?>"/>
                            <span class="material-symbols-outlined absolute right-3 top-3 text-outline">search</span>
                        </div>
                        <button type="submit" id="searchBtn" 
                                class="bg-primary hover:bg-primary-container text-on-primary font-bold px-6 md:px-8 h-12 rounded-lg transition-all active:scale-95">
                            Tafuta
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($search_performed): ?>
            <?php if ($claim_data): ?>
                <!-- Results Section -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 md:gap-8">
                    
                    <!-- Summary Card -->
                    <div class="lg:col-span-4">
                        <div class="bg-surface-container-high border border-outline-variant p-5 md:p-6 rounded-xl flex flex-col gap-4 md:gap-6 sticky top-24">
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant">Hali ya Sasa</span>
                                    <div class="mt-2 inline-flex items-center px-3 py-1.5 rounded-full font-bold text-sm"
                                         style="background-color: <?php 
                                            switch($claim_data['status']) {
                                                case 'submitted': echo '#eab308'; break;
                                                case 'valuation': echo '#f97316'; break;
                                                case 'legal_review': echo '#8b5cf6'; break;
                                                case 'approved': echo '#22c55e'; break;
                                                case 'rejected': echo '#ef4444'; break;
                                                case 'paid': echo '#10b981'; break;
                                                default: echo '#6b7280';
                                            }
                                         ?>20; color: <?php 
                                            switch($claim_data['status']) {
                                                case 'submitted': echo '#854d0e'; break;
                                                case 'valuation': echo '#9a3412'; break;
                                                case 'legal_review': echo '#5b21b6'; break;
                                                case 'approved': echo '#166534'; break;
                                                case 'rejected': echo '#991b1b'; break;
                                                case 'paid': echo '#065f46'; break;
                                                default: echo '#374151';
                                            }
                                         ?>">
                                        <?php echo getStatusLabel($claim_data['status']); ?>
                                    </div>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-white flex items-center justify-center border border-outline-variant shadow-sm">
                                    <span class="material-symbols-outlined text-primary text-3xl">description</span>
                                </div>
                            </div>
                            
                            <div class="space-y-3 md:space-y-4">
                                <div>
                                    <h4 class="text-label-sm font-label-sm text-on-surface-variant">Namba ya Dai</h4>
                                    <p class="text-headline-md font-headline-md text-primary text-xl md:text-2xl"><?php echo htmlspecialchars($claim_data['claim_number']); ?></p>
                                </div>
                                
                                <div class="border-t border-outline-variant pt-3 md:pt-4">
                                    <h4 class="text-label-sm font-label-sm text-on-surface-variant">Jina la Mradi</h4>
                                    <p class="text-body-lg font-bold text-on-surface"><?php echo htmlspecialchars($claim_data['project_name'] ?? 'Haijajazwa'); ?></p>
                                </div>
                                
                                <div class="border-t border-outline-variant pt-3 md:pt-4">
                                    <h4 class="text-label-sm font-label-sm text-on-surface-variant">Wilaya/District</h4>
                                    <p class="text-body-md text-on-surface"><?php echo htmlspecialchars($claim_data['district'] ?? 'Haijajazwa'); ?></p>
                                </div>
                                
                                <?php if ($claim_data['total_compensation'] && $claim_data['total_compensation'] > 0): ?>
                                <div class="border-t border-outline-variant pt-3 md:pt-4">
                                    <h4 class="text-label-sm font-label-sm text-on-surface-variant">Kiasi cha Fidia</h4>
                                    <p class="text-headline-md font-headline-md text-primary text-xl md:text-2xl">
                                        <?php echo number_format($claim_data['total_compensation'], 0, '.', ','); ?> TZS
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($claim_data['status'] == 'paid'): ?>
                            <button onclick="window.print()" 
                                    class="mt-4 flex items-center justify-center gap-2 border border-primary text-primary h-12 rounded-lg font-bold hover:bg-primary hover:text-on-primary transition-all">
                                <span class="material-symbols-outlined">download</span>
                                Pakua Risiti
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Progress Stepper -->
                    <div class="lg:col-span-8">
                        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-5 md:p-8">
                            <h3 class="font-headline-md text-headline-md text-on-surface mb-6 md:mb-8">Maendeleo ya Dai</h3>
                            
                            <div class="flex flex-col gap-0">
                                <?php 
                                $current_step = getStepIndex($claim_data['status']);
                                $steps_to_show = ['submitted', 'valuation', 'legal_review', 'approved', 'paid'];
                                $step_icons = [
                                    'submitted' => 'checklist',
                                    'valuation' => 'real_estate_agent',
                                    'legal_review' => 'gavel',
                                    'approved' => 'verified',
                                    'paid' => 'payments'
                                ];
                                
                                foreach ($steps_to_show as $index => $step_key):
                                    $step = $status_steps[$step_key];
                                    $is_completed = $index < $current_step;
                                    $is_current = $index == $current_step;
                                    $is_pending = $index > $current_step;
                                    $status_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'pending');
                                ?>
                                <div class="step-item flex gap-4 md:gap-6 pb-8 md:pb-12 relative">
                                    <div class="step-line <?php echo ($is_completed || $is_current) ? 'step-line-active' : ''; ?> relative z-10">
                                        <div class="w-9 h-9 md:w-10 md:h-10 rounded-full flex items-center justify-center ring-4 md:ring-8 transition-all
                                            <?php 
                                            if ($is_completed) {
                                                echo 'bg-primary text-white ring-primary-container/20';
                                            } elseif ($is_current) {
                                                echo 'bg-secondary-container text-on-secondary-container ring-secondary-container/30 animate-pulse';
                                            } else {
                                                echo 'bg-surface-container-highest text-outline-variant ring-transparent';
                                            }
                                            ?>">
                                            <?php if ($is_completed): ?>
                                                <span class="material-symbols-outlined text-base md:text-xl" style="font-variation-settings: 'wght' 700">check</span>
                                            <?php else: ?>
                                                <span class="material-symbols-outlined text-base md:text-xl"><?php echo $step_icons[$step_key]; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex-grow pt-0.5">
                                        <h4 class="font-bold text-body-md md:text-body-lg 
                                            <?php echo $is_completed ? 'text-primary' : ($is_current ? 'text-on-secondary-fixed-variant' : 'text-outline-variant'); ?>">
                                            Hatua ya <?php echo $index + 1; ?>: <?php echo $step['name']; ?>
                                        </h4>
                                        <p class="text-on-surface-variant text-sm md:text-base mt-1"><?php echo $step['description']; ?></p>
                                        
                                        <?php if ($is_current && $claim_data['status'] == 'legal_review'): ?>
                                        <div class="mt-3 md:mt-4 p-3 md:p-4 bg-secondary/5 rounded-lg border-l-4 border-secondary">
                                            <p class="text-label-sm italic font-medium">
                                                <span class="material-symbols-outlined text-sm mr-1">info</span>
                                                Inatarajiwa kukamilika ndani ya siku 7 za kazi.
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($is_current && $claim_data['status'] == 'valuation'): ?>
                                        <div class="mt-3 md:mt-4 p-3 md:p-4 bg-primary/5 rounded-lg border-l-4 border-primary">
                                            <p class="text-label-sm italic font-medium">
                                                <span class="material-symbols-outlined text-sm mr-1">schedule</span>
                                                Timu ya wakadiriaji imepangiwa kukagua mali yako.
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($claim_data['created_at'] && $index == 0): ?>
                                        <span class="text-label-sm font-label-sm text-outline mt-2 block text-xs md:text-sm">
                                            <?php if ($is_completed || $is_current): ?>
                                                <?php echo $is_completed ? 'Kimekamilika' : 'Imeanza'; ?> - <?php echo formatDateSw($claim_data['created_at']); ?>
                                            <?php else: ?>
                                                Inasubiri
                                            <?php endif; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Error Message -->
                <div class="bg-error-container border border-error rounded-xl p-6 md:p-8 text-center max-w-2xl mx-auto">
                    <span class="material-symbols-outlined text-error text-5xl md:text-6xl mb-3 md:mb-4">error_outline</span>
                    <h3 class="font-headline-md text-headline-md text-on-error-container mb-2">Dai Halijapatikana</h3>
                    <p class="text-on-error-container/80 mb-4 md:mb-6"><?php echo htmlspecialchars($error_message); ?></p>
                    <button onclick="document.getElementById('claim-number').focus()" 
                            class="bg-primary text-on-primary px-6 py-2 rounded-lg font-bold hover:bg-primary-container transition-colors">
                        Jaribu Tena
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Info Section when no search performed -->
        <?php if (!$search_performed): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mt-6 md:mt-8">
            <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant text-center">
                <span class="material-symbols-outlined text-primary text-3xl md:text-4xl mb-2">info</span>
                <h4 class="font-bold mb-1">Namba ya Dai ni nini?</h4>
                <p class="text-sm text-on-surface-variant">Namba ya dai utapokea baada ya kuwasilisha maombi yako kwenye mfumo.</p>
            </div>
            <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant text-center">
                <span class="material-symbols-outlined text-primary text-3xl md:text-4xl mb-2">contact_support</span>
                <h4 class="font-bold mb-1">Umesahau namba yako?</h4>
                <p class="text-sm text-on-surface-variant">Wasiliana na ofisi za HCS au tuma barua pepe kwa msaada.</p>
            </div>
            <div class="bg-surface-container-lowest p-4 md:p-5 rounded-xl border border-outline-variant text-center">
                <span class="material-symbols-outlined text-primary text-3xl md:text-4xl mb-2">schedule</span>
                <h4 class="font-bold mb-1">Inachukua muda gani?</h4>
                <p class="text-sm text-on-surface-variant">Mchakato mzima unachukua siku 30-45 za kazi kukamilika.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Footer -->
<footer class="bg-on-background text-primary-fixed border-t border-outline-variant mt-auto">
    <div class="w-full py-6 px-margin-mobile md:px-margin-desktop grid grid-cols-1 md:grid-cols-2 gap-4 max-w-max-width mx-auto">
        <div>
            <h3 class="font-black text-headline-sm text-primary-fixed mb-1">HCS</h3>
            <p class="font-label-sm text-surface-variant text-sm">© 2025 House Compensation System. Tanzania.</p>
        </div>
        <div class="flex gap-6 justify-start md:justify-end">
            <a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-sm" href="privacy.php">Sera ya Faragha</a>
            <a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-sm" href="contact.php">Wasiliana</a>
            <a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-sm" href="faq.php">Maswali</a>
        </div>
    </div>
</footer>

<!-- Bottom Navigation Bar for Mobile -->
<nav class="fixed bottom-0 left-0 w-full flex justify-around items-center py-2 px-margin-mobile bg-surface shadow-[0_-1px_0_0_rgba(0,0,0,0.1)] z-50 h-14 md:hidden">
    <a class="flex flex-col items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-90" href="index.php">
        <span class="material-symbols-outlined text-xl">home</span>
        <span class="font-label-sm text-label-sm text-xs">Nyumbani</span>
    </a>
    <a class="flex flex-col items-center justify-center bg-primary-container text-on-primary-container rounded-full py-1 px-4 active:scale-90 transition-transform" href="#">
        <span class="material-symbols-outlined text-xl">query_stats</span>
        <span class="font-label-sm text-label-sm text-xs">Fuatilia</span>
    </a>
    <a class="flex flex-col items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-90" href="notices.php">
        <span class="material-symbols-outlined text-xl">campaign</span>
        <span class="font-label-sm text-label-sm text-xs">Taarifa</span>
    </a>
    <a class="flex flex-col items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-90" href="<?php echo isset($_SESSION['user_id']) ? 'dashboard.php' : 'auth/login.php'; ?>">
        <span class="material-symbols-outlined text-xl">person</span>
        <span class="font-label-sm text-label-sm text-xs">Akaunti</span>
    </a>
</nav>

<script>
    // Form submission with loading state
    const trackForm = document.getElementById('trackForm');
    const searchBtn = document.getElementById('searchBtn');
    const claimInput = document.getElementById('claim-number');
    
    if (trackForm) {
        trackForm.addEventListener('submit', function(e) {
            const claimNumber = claimInput.value.trim();
            
            if (!claimNumber) {
                e.preventDefault();
                claimInput.classList.add('border-error', 'shake');
                setTimeout(() => {
                    claimInput.classList.remove('border-error', 'shake');
                }, 500);
                
                Swal.fire({
                    icon: 'error',
                    title: 'Taarifa Inahitajika',
                    text: 'Tafadhali ingiza namba ya dai unayotaka kufuatilia.',
                    confirmButtonColor: '#006e2c'
                });
                return;
            }
            
            // Show loading state
            if (searchBtn) {
                const originalText = searchBtn.innerHTML;
                searchBtn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Inatafuta...';
                searchBtn.disabled = true;
                
                setTimeout(() => {
                    searchBtn.innerHTML = originalText;
                    searchBtn.disabled = false;
                }, 3000);
            }
        });
    }
    
    // Remove error class on input
    if (claimInput) {
        claimInput.addEventListener('input', function() {
            this.classList.remove('border-error', 'shake');
        });
    }
    
    // Display error message if exists
    <?php if ($search_performed && !$claim_data && !empty($error_message)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Dai Halijapatikana',
        text: '<?php echo addslashes($error_message); ?>',
        confirmButtonColor: '#006e2c'
    });
    <?php endif; ?>
</script>

</body>
</html>