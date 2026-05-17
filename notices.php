<?php
// notices.php - Public Notices and Announcements Page (Dynamic from Database)
session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/audit.php';

$conn = getDB();

// Fetch notices from database
$notices = [];
$categories = ['all', 'general', 'claims', 'payments', 'deadlines'];

$selected_category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'all';
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// ============================================
// FETCH NOTICES FROM DATABASE
// ============================================
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'notices'");
if (mysqli_num_rows($table_check) > 0) {
    $sql = "SELECT * FROM notices WHERE status = 'published'";
    if ($selected_category != 'all') {
        $sql .= " AND category = '$selected_category'";
    }
    if (!empty($search_query)) {
        $sql .= " AND (title LIKE '%$search_query%' OR content LIKE '%$search_query%')";
    }
    $sql .= " ORDER BY is_important DESC, created_at DESC";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $notices[] = $row;
    }
}

// If no notices in database, use sample data (for first time)
if (empty($notices)) {
    $notices = [
        [
            'id' => 1,
            'title' => 'Mabadiliko ya Mfumo wa Fidia',
            'content' => 'Wizara imetangaza mabadiliko makubwa katika mfumo wa fidia ili kuongeza kasi ya usindikaji wa madai. Wananchi wote wanaotarajiwa kupata fidia wanatakiwa kuhakikisha wamewasilisha nyaraka zote kabla ya tarehe 30 Juni, 2025.',
            'category' => 'general',
            'created_at' => '2025-05-15 10:30:00',
            'author' => 'Wizara ya Ardhi',
            'is_important' => true
        ],
        [
            'id' => 2,
            'title' => 'Tarehe ya Mwisho Kuwasilisha Madai',
            'content' => 'Wananchi wote walioathirika na mradi wa SGR wanatakiwa kuwasilisha madai yao kabla ya tarehe 15 Juni, 2025.',
            'category' => 'deadlines',
            'created_at' => '2025-05-10 14:20:00',
            'author' => 'Mkurugenzi wa Miradi',
            'is_important' => true
        ],
        [
            'id' => 3,
            'title' => 'Malipo ya Fidia Awamu ya Kwanza',
            'content' => 'Malipo ya fidia kwa wananchi 500 wa kwanza yameanza kufanyika.',
            'category' => 'payments',
            'created_at' => '2025-05-05 09:15:00',
            'author' => 'Idara ya Fedha',
            'is_important' => false
        ]
    ];
}

// ============================================
// FETCH DYNAMIC STATISTICS FROM DATABASE
// ============================================

// 1. Total Claims
$total_claims_query = "SELECT COUNT(*) as total FROM claims";
$total_claims_result = mysqli_query($conn, $total_claims_query);
$total_claims_row = mysqli_fetch_assoc($total_claims_result);
$total_claims = $total_claims_row['total'] ?? 12400;

// 2. Total Compensation Paid (from valuations)
$total_compensation_query = "SELECT SUM(total_compensation) as total FROM valuations";
$total_compensation_result = mysqli_query($conn, $total_compensation_query);
$total_compensation_row = mysqli_fetch_assoc($total_compensation_result);
$total_compensation = $total_compensation_row['total'] ?? 0;

// Format compensation in Billions
if ($total_compensation >= 1000000000) {
    $total_compensation_display = round($total_compensation / 1000000000, 1) . 'B+';
} else {
    $total_compensation_display = number_format($total_compensation, 0, '.', ',') . ' TZS';
}

// 3. Total Beneficiaries (Users with claims)
$beneficiaries_query = "SELECT COUNT(DISTINCT claimant_id) as total FROM claims";
$beneficiaries_result = mysqli_query($conn, $beneficiaries_query);
$beneficiaries_row = mysqli_fetch_assoc($beneficiaries_result);
$total_beneficiaries = $beneficiaries_row['total'] ?? 0;
$total_beneficiaries_display = ($total_beneficiaries >= 1000) ? number_format($total_beneficiaries / 1000, 1) . 'K+' : $total_beneficiaries;

// 4. Total Paid Claims (claims with status 'paid')
$paid_claims_query = "SELECT COUNT(*) as total FROM claims WHERE status = 'paid'";
$paid_claims_result = mysqli_query($conn, $paid_claims_query);
$paid_claims_row = mysqli_fetch_assoc($paid_claims_result);
$paid_claims = $paid_claims_row['total'] ?? 0;

// 5. Active Claims (submitted, valuation, legal_review)
$active_claims_query = "SELECT COUNT(*) as total FROM claims WHERE status IN ('submitted', 'valuation', 'legal_review')";
$active_claims_result = mysqli_query($conn, $active_claims_query);
$active_claims_row = mysqli_fetch_assoc($active_claims_result);
$active_claims = $active_claims_row['total'] ?? 0;

// 6. Total Users
$total_users_query = "SELECT COUNT(*) as total FROM users WHERE role = 'claimant'";
$total_users_result = mysqli_query($conn, $total_users_query);
$total_users_row = mysqli_fetch_assoc($total_users_result);
$total_users = $total_users_row['total'] ?? 0;

// Helper function to format date
function formatNoticeDate($date) {
    $months = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Disemba'];
    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = $months[date('n', $timestamp) - 1];
    $year = date('Y', $timestamp);
    return "$day $month, $year";
}

// Get category badge color
function getCategoryBadgeClass($category) {
    $badges = [
        'general' => 'bg-blue-100 text-blue-800',
        'claims' => 'bg-purple-100 text-purple-800',
        'payments' => 'bg-green-100 text-green-800',
        'deadlines' => 'bg-red-100 text-red-800'
    ];
    return $badges[$category] ?? 'bg-gray-100 text-gray-800';
}

function getCategoryLabel($category) {
    $labels = [
        'general' => 'Maelezo Makuu',
        'claims' => 'Madai',
        'payments' => 'Malipo',
        'deadlines' => 'Tarehe za Mwisho'
    ];
    return $labels[$category] ?? $category;
}
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#006e2c"/>
<title>Taarifa na Matangazo | HCS - Mfumo wa Fidia ya Nyumba</title>

<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "surface-bright": "#f4fcef",
                    "on-tertiary-container": "#690025",
                    "primary-fixed": "#79fd92",
                    "on-background": "#161d16",
                    "on-error-container": "#93000a",
                    "on-surface-variant": "#3d4a3d",
                    "on-primary-fixed": "#002108",
                    "on-error": "#ffffff",
                    "secondary-container": "#fed000",
                    "inverse-primary": "#5be079",
                    "on-secondary-container": "#6f5900",
                    "outline-variant": "#bccab9",
                    "inverse-surface": "#2b322a",
                    "tertiary-fixed-dim": "#ffb2bd",
                    "surface": "#f4fcef",
                    "on-tertiary-fixed": "#400014",
                    "surface-tint": "#006e2c",
                    "primary-container": "#1eb050",
                    "on-secondary-fixed": "#231b00",
                    "surface-variant": "#dde5d9",
                    "secondary-fixed": "#ffe07f",
                    "primary-fixed-dim": "#5be079",
                    "background": "#f4fcef",
                    "primary": "#006e2c",
                    "surface-container": "#e8f0e4",
                    "on-primary-fixed-variant": "#005320",
                    "on-secondary": "#ffffff",
                    "on-tertiary": "#ffffff",
                    "secondary": "#725c00",
                    "on-primary": "#ffffff",
                    "inverse-on-surface": "#ebf3e7",
                    "surface-container-high": "#e3eadf",
                    "outline": "#6d7b6c",
                    "on-tertiary-fixed-variant": "#8d0f38",
                    "on-primary-container": "#003a14",
                    "error-container": "#ffdad6",
                    "error": "#ba1a1a",
                    "tertiary": "#ad2c4e",
                    "surface-container-lowest": "#ffffff",
                    "on-secondary-fixed-variant": "#564500",
                    "surface-container-low": "#eef6ea",
                    "surface-container-highest": "#dde5d9",
                    "on-surface": "#161d16",
                    "tertiary-container": "#fb6787",
                    "tertiary-fixed": "#ffd9dd",
                    "secondary-fixed-dim": "#edc200",
                    "surface-dim": "#d4dcd1"
                },
                borderRadius: {
                    DEFAULT: "0.125rem",
                    lg: "0.25rem",
                    xl: "0.5rem",
                    full: "0.75rem"
                },
                spacing: {
                    "margin-desktop": "64px",
                    "base": "8px",
                    "xl": "80px",
                    "xs": "4px",
                    "lg": "48px",
                    "sm": "12px",
                    "gutter": "24px",
                    "md": "24px",
                    "max-width": "1280px",
                    "margin-mobile": "16px"
                },
                fontFamily: {
                    "body-lg": ["Inter"],
                    "label-md": ["Inter"],
                    "headline-md": ["Inter"],
                    "headline-lg": ["Inter"],
                    "display-lg": ["Inter"],
                    "body-md": ["Inter"],
                    "headline-lg-mobile": ["Inter"],
                    "display-lg-mobile": ["Inter"],
                    "label-sm": ["Inter"]
                },
                fontSize: {
                    "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                    "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                    "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                    "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}],
                    "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                    "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                    "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                    "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                    "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}]
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
    .bg-pattern {
        background-image: radial-gradient(#006e2c 0.5px, transparent 0.5px);
        background-size: 24px 24px;
        opacity: 0.05;
    }
    .notice-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .notice-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
    }
    .important-badge {
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    body {
        padding-bottom: 65px;
    }
    @media (min-width: 768px) {
        body {
            padding-bottom: 0;
        }
    }
    .bottom-nav-item {
        transition: all 0.2s ease;
    }
    .bottom-nav-item:active {
        transform: scale(0.9);
    }
    @supports (padding-bottom: env(safe-area-inset-bottom)) {
        .bottom-nav {
            padding-bottom: env(safe-area-inset-bottom);
        }
    }
</style>
</head>
<body class="bg-surface font-body-md text-on-surface min-h-screen flex flex-col">

<!-- Top Navigation Bar -->
<header class="bg-surface border-b-2 border-secondary sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto h-16 md:h-20">
        <div class="flex items-center gap-3">
            <a href="index.php" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
                <span class="material-symbols-outlined text-primary text-headline-md">arrow_back</span>
                <span class="material-symbols-outlined text-primary text-headline-md">account_balance</span>
                <h1 class="font-headline-md text-headline-md-mobile md:text-headline-md text-primary font-bold">HCS</h1>
            </a>
        </div>
        <div class="flex items-center gap-4">
            <a href="track-claim.php" class="hidden md:inline-flex font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors">Fuatilia Dai</a>
            <a href="notices.php" class="font-label-md text-label-md text-primary font-bold border-b-2 border-primary py-1">Taarifa</a>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="w-10 h-10 rounded-full bg-primary-container flex items-center justify-center hover:scale-105 transition-transform">
                    <span class="material-symbols-outlined text-on-primary-container">account_circle</span>
                </a>
            <?php else: ?>
                <a href="auth/login.php" class="bg-primary text-on-primary px-4 py-2 rounded-lg font-label-md text-label-md hover:bg-primary-container transition-colors">Ingia</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="flex-grow relative overflow-hidden pb-16">
    <!-- Background Pattern -->
    <div class="absolute inset-0 bg-pattern pointer-events-none"></div>
    
    <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop pt-6 md:pt-8">
        
        <!-- Hero Section -->
        <div class="mb-8 md:mb-12 text-center">
            <span class="text-label-md text-primary uppercase tracking-wider mb-2 inline-block">Habari na Matangazo</span>
            <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-on-background mb-3 md:mb-4">
                Taarifa za Hivi Karibuni
            </h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-2xl mx-auto">
                Pata taarifa zote muhimu kuhusu mchakato wa fidia, tarehe za mwisho, na matangazo ya serikali.
            </p>
        </div>
        
        <!-- Search and Filter Section -->
        <div class="mb-8 md:mb-12">
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 md:p-6">
                <form method="GET" action="" class="flex flex-col md:flex-row gap-4">
                    <div class="relative flex-1">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">search</span>
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               placeholder="Tafuta taarifa..." 
                               class="w-full h-12 pl-12 pr-4 bg-surface border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="?category=all<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-4 py-2 rounded-lg font-label-md text-label-md transition-all <?php echo $selected_category == 'all' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Zote
                        </a>
                        <a href="?category=general<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-4 py-2 rounded-lg font-label-md text-label-md transition-all <?php echo $selected_category == 'general' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Maelezo Makuu
                        </a>
                        <a href="?category=claims<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-4 py-2 rounded-lg font-label-md text-label-md transition-all <?php echo $selected_category == 'claims' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Madai
                        </a>
                        <a href="?category=payments<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-4 py-2 rounded-lg font-label-md text-label-md transition-all <?php echo $selected_category == 'payments' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Malipo
                        </a>
                        <a href="?category=deadlines<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-4 py-2 rounded-lg font-label-md text-label-md transition-all <?php echo $selected_category == 'deadlines' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Tarehe za Mwisho
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Notices Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">
            <?php if (!empty($notices)): ?>
                <?php foreach ($notices as $notice): ?>
                    <div class="notice-card bg-white border border-outline-variant rounded-xl overflow-hidden hover:shadow-lg transition-all">
                        <?php if ($notice['is_important']): ?>
                            <div class="bg-red-500 text-white text-xs font-bold px-3 py-1 inline-block m-4 rounded-full important-badge">
                                <span class="material-symbols-outlined text-sm mr-1">priority_high</span>
                                MUHIMU
                            </div>
                        <?php endif; ?>
                        
                        <div class="p-6 pt-0">
                            <div class="flex items-center justify-between mb-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getCategoryBadgeClass($notice['category']); ?>">
                                    <?php echo getCategoryLabel($notice['category']); ?>
                                </span>
                                <span class="text-label-sm text-label-sm text-outline">
                                    <?php echo formatNoticeDate($notice['created_at']); ?>
                                </span>
                            </div>
                            
                            <h3 class="font-headline-md text-headline-md text-on-background mb-3 line-clamp-2">
                                <?php echo htmlspecialchars($notice['title']); ?>
                            </h3>
                            
                            <p class="font-body-md text-body-md text-on-surface-variant mb-4 line-clamp-3">
                                <?php echo htmlspecialchars(substr($notice['content'], 0, 200)) . (strlen($notice['content']) > 200 ? '...' : ''); ?>
                            </p>
                            
                            <div class="flex items-center justify-between pt-4 border-t border-outline-variant">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-outline text-sm">person_outline</span>
                                    <span class="text-label-sm text-label-sm text-on-surface-variant"><?php echo htmlspecialchars($notice['author']); ?></span>
                                </div>
                                <button onclick="showNoticeModal(<?php echo $notice['id']; ?>)" 
                                        class="text-primary font-bold text-label-md text-label-md hover:underline flex items-center gap-1">
                                    Soma Zaidi
                                    <span class="material-symbols-outlined text-sm">arrow_forward</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-span-1 md:col-span-2 text-center py-12">
                    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-8">
                        <span class="material-symbols-outlined text-outline text-6xl mb-4">notifications_off</span>
                        <h3 class="font-headline-md text-headline-md text-on-background mb-2">Hakuna Taarifa</h3>
                        <p class="font-body-md text-body-md text-on-surface-variant">
                            Hakuna taarifa zilizopatikana kwa vigezo ulivyovichagua. Tafadhali jaribu tena.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Stats Section - ALL DYNAMIC FROM DATABASE -->
        <div class="mt-12 md:mt-16 grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
                <span class="material-symbols-outlined text-primary text-3xl mb-2">description</span>
                <p class="font-headline-md text-headline-md text-primary font-bold"><?php echo number_format($total_claims); ?>+</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant">Jumla ya Madai</p>
            </div>
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
                <span class="material-symbols-outlined text-primary text-3xl mb-2">payments</span>
                <p class="font-headline-md text-headline-md text-primary font-bold"><?php echo $total_compensation_display; ?></p>
                <p class="font-label-sm text-label-sm text-on-surface-variant">Fidia Iliyolipwa</p>
            </div>
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
                <span class="material-symbols-outlined text-primary text-3xl mb-2">groups</span>
                <p class="font-headline-md text-headline-md text-primary font-bold"><?php echo $total_beneficiaries_display; ?></p>
                <p class="font-label-sm text-label-sm text-on-surface-variant">Wananchi Waliofidiwa</p>
            </div>
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
                <span class="material-symbols-outlined text-primary text-3xl mb-2">pending_actions</span>
                <p class="font-headline-md text-headline-md text-primary font-bold"><?php echo number_format($active_claims); ?></p>
                <p class="font-label-sm text-label-sm text-on-surface-variant">Madai Yanayoendelea</p>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="bg-on-background text-primary-fixed border-t border-outline-variant mt-8">
    <div class="w-full py-8 px-margin-mobile md:px-margin-desktop grid grid-cols-1 md:grid-cols-2 gap-6 max-w-max-width mx-auto">
        <div>
            <h3 class="font-black text-headline-sm text-primary-fixed mb-2">HCS</h3>
            <p class="font-label-sm text-surface-variant max-w-sm">© 2025 House Compensation System. Jamhuri ya Muungano wa Tanzania. Haki zote zimehifadhiwa.</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <h4 class="font-label-md font-bold text-secondary-fixed">Huduma</h4>
                <ul class="space-y-2">
                    <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="track-claim.php">Kufuatilia Madai</a></li>
                    <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="guide.php">Mwongozo wa Mchakato</a></li>
                </ul>
            </div>
            <div class="space-y-2">
                <h4 class="font-label-md font-bold text-secondary-fixed">Msaada</h4>
                <ul class="space-y-2">
                    <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="contact.php">Wasiliana Nasi</a></li>
                    <li><a class="font-label-sm text-surface-variant hover:text-primary-fixed transition-colors" href="privacy.php">Sera ya Faragha</a></li>
                </ul>
            </div>
        </div>
    </div>
</footer>

<!-- BOTTOM NAVIGATION BAR - Mobile Only -->
<nav class="bottom-nav fixed bottom-0 left-0 right-0 bg-surface border-t border-outline-variant flex justify-around items-center px-2 py-1 shadow-lg z-50 md:hidden" style="padding-bottom: env(safe-area-inset-bottom, 0.5rem);">
    <a href="index.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">home</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Nyumbani</span>
    </a>
    
    <a href="track-claim.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">track_changes</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Fuatilia</span>
    </a>
    
    <a href="notices.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">campaign</span>
        <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Taarifa</span>
    </a>
    
    <a href="<?php echo isset($_SESSION['user_id']) ? 'dashboard.php' : 'auth/login.php'; ?>" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">person</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Akaunti</span>
    </a>
</nav>

<script>
    // Notice Modal Function
    const noticesData = <?php echo json_encode($notices); ?>;
    
    function showNoticeModal(noticeId) {
        const notice = noticesData.find(n => n.id == noticeId);
        
        if (notice) {
            Swal.fire({
                title: notice.title,
                html: `
                    <div class="text-left">
                        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getCategoryBadgeClass(notice.category)}">
                                ${getCategoryLabel(notice.category)}
                            </span>
                            <span class="text-sm text-gray-500">${formatNoticeDate(notice.created_at)}</span>
                            ${notice.is_important ? '<span class="inline-flex items-center gap-1 text-red-600 text-xs font-bold"><span class="material-symbols-outlined text-sm">priority_high</span>MUHIMU</span>' : ''}
                        </div>
                        <p class="text-gray-700 leading-relaxed mb-4">${notice.content}</p>
                        <div class="flex items-center gap-2 pt-3 border-t border-gray-100 text-sm text-gray-500">
                            <span class="material-symbols-outlined text-sm">person_outline</span>
                            <span>Imetolewa na: ${notice.author}</span>
                        </div>
                    </div>
                `,
                icon: 'info',
                confirmButtonColor: '#006e2c',
                confirmButtonText: 'Funga',
                width: '600px'
            });
        }
    }
    
    function getCategoryBadgeClass(category) {
        const badges = {
            'general': 'bg-blue-100 text-blue-800',
            'claims': 'bg-purple-100 text-purple-800',
            'payments': 'bg-green-100 text-green-800',
            'deadlines': 'bg-red-100 text-red-800'
        };
        return badges[category] || 'bg-gray-100 text-gray-800';
    }
    
    function getCategoryLabel(category) {
        const labels = {
            'general': 'Maelezo Makuu',
            'claims': 'Madai',
            'payments': 'Malipo',
            'deadlines': 'Tarehe za Mwisho'
        };
        return labels[category] || category;
    }
    
    function formatNoticeDate(dateString) {
        const months = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Juli', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Disemba'];
        const date = new Date(dateString);
        const day = date.getDate();
        const month = months[date.getMonth()];
        const year = date.getFullYear();
        return `${day} ${month}, ${year}`;
    }
    
    // Active bottom nav highlight
    const currentPage = window.location.pathname.split('/').pop() || 'notices.php';
    const bottomNavLinks = document.querySelectorAll('.bottom-nav-item');
    
    bottomNavLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            const icon = link.querySelector('.material-symbols-outlined');
            const text = link.querySelector('span:last-child');
            if (icon) {
                icon.style.fontVariationSettings = "'FILL' 1";
                icon.classList.add('text-primary');
            }
            if (text) {
                text.classList.add('text-primary', 'font-bold');
            }
        }
    });
</script>

</body>
</html>