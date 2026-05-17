<?php
// help.php - Help and Support Page
session_start();

require_once 'config/db.php';
require_once 'includes/functions.php';
require_once 'includes/audit.php';

$conn = getDB();

// Get contact info from database (if table exists)
$contact_info = [
    'phone' => '+255 26 232 1234',
    'email' => 'info@hcs.go.tz',
    'address' => 'Dodoma, Tanzania',
    'working_hours' => 'Jumatatu - Ijumaa: 8:00 - 17:00'
];

// Get FAQ items from database
$faqs = [];

$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'faqs'");
if (mysqli_num_rows($table_check) > 0) {
    $faq_query = "SELECT * FROM faqs WHERE status = 'published' ORDER BY `order` ASC, created_at DESC";
    $faq_result = mysqli_query($conn, $faq_query);
    while ($row = mysqli_fetch_assoc($faq_result)) {
        $faqs[] = $row;
    }
}

// If no FAQs in database, use sample data
if (empty($faqs)) {
    $faqs = [
        [
            'id' => 1,
            'question' => 'Ninawezaje kuwasilisha dai la fidia?',
            'answer' => 'Unaweza kuwasilisha dai lako kwa kujiandikisha kwenye mfumo wetu, kisha bofya "Anza Maombi" na ujaze fomu ya maombi. Hakikisha umepakia nyaraka zote muhimu ikiwemo cheti cha umiliki wa ardhi, kitambulisho chako, na picha za eneo lililoathirika.',
            'category' => 'general'
        ],
        [
            'id' => 2,
            'question' => 'Nyaraka gani zinahitajika kwa ajili ya dai la fidia?',
            'answer' => 'Nyaraka zinazohitajika ni pamoja na: (1) Kitambulisho cha Taifa (NIN), (2) Hati ya umiliki wa ardhi/nyumba, (3) Picha za eneo lililoathirika, (4) Barua ya maombi, (5) Uthibitisho wa makazi. Nyaraka zote lazima ziwe halali na zimehakikishwa.',
            'category' => 'documents'
        ],
        [
            'id' => 3,
            'question' => 'Mchakato wa fidia unachukua muda gani?',
            'answer' => 'Mchakato mzima wa fidia unachukua kati ya siku 30 hadi 45 za kazi tangu siku ya kuwasilisha maombi yako. Muda huu unajumuisha ukaguzi wa nyaraka, tathmini ya mali, uhakiki wa kisheria na hatimaye malipo.',
            'category' => 'process'
        ],
        [
            'id' => 4,
            'question' => 'Ninawezaje kufuatilia hali ya dai langu?',
            'answer' => 'Unaweza kufuatilia hali ya dai lako kwa kutumia namba ya dai uliyopewa. Ingiza namba hiyo kwenye ukurasa wa "Fuatilia Dai" na utaona hatua iliyofikiwa katika mchakato wako.',
            'category' => 'tracking'
        ],
        [
            'id' => 5,
            'question' => 'Nifanye nini kama dai langu limekataliwa?',
            'answer' => 'Kama dai lako limekataliwa, utapokea barua ya maelezo ya sababu za kukataliwa. Unaweza kukata rufaa ndani ya siku 30 kwa kuwasilisha barua ya kukata rufaa kwa Mkurugenzi wa HCS pamoja na nyaraka za ziada.',
            'category' => 'appeals'
        ],
        [
            'id' => 6,
            'question' => 'Malipo ya fidia hufanywa namna gani?',
            'answer' => 'Malipo ya fidia hufanywa moja kwa moja kwenye akaunti yako ya benki. Unatakiwa kutoa maelezo sahihi ya akaunti yako wakati wa kujisajili. Malipo hufanywa ndani ya siku 14 baada ya dai lako kuidhinishwa.',
            'category' => 'payments'
        ],
        [
            'id' => 7,
            'question' => 'Ninaweza kupata msaada wa kiufundi wapi?',
            'answer' => 'Kwa msaada wa kiufundi, unaweza: (1) Kupiga simu kwa namba +255 26 232 1234, (2) Kutuma barua pepe kwa support@hcs.go.tz, (3) Kutembelea ofisi zetu zilizopo Dodoma, au (4) Kutuma ujumbe kwenye akaunti zetu za mitandao ya kijamii.',
            'category' => 'support'
        ],
        [
            'id' => 8,
            'question' => 'Kuna ada yoyote kwa ajili ya kuwasilisha dai?',
            'answer' => 'Hakuna ada yoyote inayotozwa kwa ajili ya kuwasilisha dai la fidia. Mchakato wote ni BURE kabisa. Taarifa yoyote inayoomba malipo ni ulaghai, tafadhali ripoti kwa ofisi za HCS mara moja.',
            'category' => 'general'
        ]
    ];
}

// Get category filter
$selected_category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : 'all';
$search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Filter FAQs
$filtered_faqs = $faqs;
if ($selected_category != 'all') {
    $filtered_faqs = array_filter($faqs, function($faq) use ($selected_category) {
        return $faq['category'] == $selected_category;
    });
}
if (!empty($search_query)) {
    $filtered_faqs = array_filter($faqs, function($faq) use ($search_query) {
        return stripos($faq['question'], $search_query) !== false || stripos($faq['answer'], $search_query) !== false;
    });
}
$filtered_faqs = array_values($filtered_faqs);

// Get category label
function getCategoryLabel($category) {
    $labels = [
        'general' => 'Maelezo Makuu',
        'documents' => 'Nyaraka',
        'process' => 'Mchakato',
        'tracking' => 'Ufuatiliaji',
        'appeals' => 'Kukata Rufaa',
        'payments' => 'Malipo',
        'support' => 'Msaada'
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
<title>Msaada | HCS - Mfumo wa Fidia ya Nyumba | Tanzania</title>

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
    .faq-card {
        transition: all 0.3s ease;
    }
    .faq-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
    }
    .contact-card {
        transition: all 0.2s ease;
    }
    .contact-card:active {
        transform: scale(0.98);
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
            <a href="notices.php" class="hidden md:inline-flex font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors">Taarifa</a>
            <a href="help.php" class="font-label-md text-label-md text-primary font-bold border-b-2 border-primary py-1">Msaada</a>
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
            <span class="text-label-md text-primary uppercase tracking-wider mb-2 inline-block">Msaada na Usaidizi</span>
            <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-on-background mb-3 md:mb-4">
                Tunakusaidia
            </h1>
            <p class="font-body-md text-body-md text-on-surface-variant max-w-2xl mx-auto">
                Pata majibu ya maswali yako, mwongozo wa mchakato, na maelezo ya kuwasiliana nasi kwa msaada zaidi.
            </p>
        </div>
        
        <!-- Contact Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-6 mb-8 md:mb-12">
            <div class="contact-card bg-white border border-outline-variant rounded-xl p-4 text-center hover:shadow-md transition-all cursor-pointer" onclick="window.location.href='tel:+255262321234'">
                <div class="w-12 h-12 bg-primary-container/10 rounded-full flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-primary text-2xl">call</span>
                </div>
                <h4 class="font-label-md text-label-md text-on-background mb-1">Simu</h4>
                <p class="font-body-sm text-body-sm text-primary font-bold">+255 26 232 1234</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant text-xs mt-1">Jumatatu - Ijumaa</p>
            </div>
            
            <div class="contact-card bg-white border border-outline-variant rounded-xl p-4 text-center hover:shadow-md transition-all cursor-pointer" onclick="window.location.href='mailto:info@hcs.go.tz'">
                <div class="w-12 h-12 bg-primary-container/10 rounded-full flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-primary text-2xl">mail</span>
                </div>
                <h4 class="font-label-md text-label-md text-on-background mb-1">Barua Pepe</h4>
                <p class="font-body-sm text-body-sm text-primary font-bold">info@hcs.go.tz</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant text-xs mt-1">24/7 Support</p>
            </div>
            
            <div class="contact-card bg-white border border-outline-variant rounded-xl p-4 text-center hover:shadow-md transition-all cursor-pointer" onclick="window.location.href='https://maps.google.com/?q=Dodoma,Tanzania'">
                <div class="w-12 h-12 bg-primary-container/10 rounded-full flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-primary text-2xl">location_on</span>
                </div>
                <h4 class="font-label-md text-label-md text-on-background mb-1">Ofisi Zetu</h4>
                <p class="font-body-sm text-body-sm text-primary font-bold">Dodoma, Tanzania</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant text-xs mt-1">Jengo la HCS</p>
            </div>
            
            <div class="contact-card bg-white border border-outline-variant rounded-xl p-4 text-center hover:shadow-md transition-all cursor-pointer" onclick="window.location.href='https://wa.me/255262321234'">
                <div class="w-12 h-12 bg-primary-container/10 rounded-full flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-primary text-2xl">chat</span>
                </div>
                <h4 class="font-label-md text-label-md text-on-background mb-1">WhatsApp</h4>
                <p class="font-body-sm text-body-sm text-primary font-bold">+255 26 232 1234</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant text-xs mt-1">Jibu la haraka</p>
            </div>
        </div>
        
        <!-- Search and Filter Section -->
        <div class="mb-8 md:mb-10">
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 md:p-6">
                <form method="GET" action="" class="flex flex-col md:flex-row gap-4">
                    <div class="relative flex-1">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">search</span>
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               placeholder="Tafuta swali..." 
                               class="w-full h-12 pl-12 pr-4 bg-surface border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="?category=all<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-3 py-2 rounded-lg font-label-sm text-label-sm transition-all <?php echo $selected_category == 'all' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Zote
                        </a>
                        <a href="?category=general<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-3 py-2 rounded-lg font-label-sm text-label-sm transition-all <?php echo $selected_category == 'general' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Maelezo Makuu
                        </a>
                        <a href="?category=documents<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-3 py-2 rounded-lg font-label-sm text-label-sm transition-all <?php echo $selected_category == 'documents' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Nyaraka
                        </a>
                        <a href="?category=process<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-3 py-2 rounded-lg font-label-sm text-label-sm transition-all <?php echo $selected_category == 'process' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Mchakato
                        </a>
                        <a href="?category=payments<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                           class="px-3 py-2 rounded-lg font-label-sm text-label-sm transition-all <?php echo $selected_category == 'payments' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-higher'; ?>">
                            Malipo
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- FAQ Section -->
        <div class="mb-8 md:mb-12">
            <div class="flex items-center justify-between mb-4 md:mb-6">
                <h2 class="font-headline-md text-headline-md text-on-background">Maswali Yanayoulizwa Mara kwa Mara</h2>
                <div class="h-1 bg-secondary w-12 md:w-16"></div>
            </div>
            
            <div class="grid grid-cols-1 gap-4">
                <?php if (!empty($filtered_faqs)): ?>
                    <?php foreach ($filtered_faqs as $index => $faq): ?>
                        <div class="faq-card bg-white border border-outline-variant rounded-xl overflow-hidden">
                            <button onclick="toggleFaq(<?php echo $index; ?>)" 
                                    class="w-full text-left p-4 md:p-5 flex items-center justify-between hover:bg-surface-container-low transition-colors">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-primary">help</span>
                                    <h3 class="font-headline-md text-headline-md text-on-background text-base md:text-lg">
                                        <?php echo htmlspecialchars($faq['question']); ?>
                                    </h3>
                                </div>
                                <span id="faq-icon-<?php echo $index; ?>" class="material-symbols-outlined text-outline transition-transform">expand_more</span>
                            </button>
                            <div id="faq-answer-<?php echo $index; ?>" class="hidden px-4 md:px-5 pb-4 md:pb-5 border-t border-outline-variant">
                                <div class="pt-3 md:pt-4 flex gap-3">
                                    <span class="material-symbols-outlined text-outline text-sm">description</span>
                                    <p class="font-body-md text-body-md text-on-surface-variant flex-1">
                                        <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                                    </p>
                                </div>
                                <div class="mt-3 pt-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <?php echo getCategoryLabel($faq['category']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-12">
                        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-8">
                            <span class="material-symbols-outlined text-outline text-6xl mb-4">help_off</span>
                            <h3 class="font-headline-md text-headline-md text-on-background mb-2">Hakuna Matokeo</h3>
                            <p class="font-body-md text-body-md text-on-surface-variant">
                                Hakuna maswali yaliyopatikana kwa vigezo ulivyovichagua. Tafadhali jaribu tena.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Still Need Help Section -->
        <div class="bg-primary-container/10 border border-primary-container rounded-xl p-6 md:p-8 text-center">
            <span class="material-symbols-outlined text-primary text-4xl mb-3">support_agent</span>
            <h3 class="font-headline-md text-headline-md text-on-background mb-2">Bado Unahitaji Msaada?</h3>
            <p class="font-body-md text-body-md text-on-surface-variant mb-4 max-w-xl mx-auto">
                Kama hujapata jibu la swali lako, tafadhali wasiliana nasi moja kwa moja. Timu yetu iko tayari kukusaidia.
            </p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <button onclick="window.location.href='tel:+255262321234'" 
                        class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-lg font-bold hover:bg-primary-container transition-all">
                    <span class="material-symbols-outlined">call</span>
                    Piga Simu Sasa
                </button>
                <button onclick="window.location.href='mailto:info@hcs.go.tz'" 
                        class="inline-flex items-center justify-center gap-2 px-6 py-3 border border-primary text-primary rounded-lg font-bold hover:bg-primary hover:text-on-primary transition-all">
                    <span class="material-symbols-outlined">mail</span>
                    Tuma Barua Pepe
                </button>
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
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">campaign</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Taarifa</span>
    </a>
    
    <a href="help.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">help</span>
        <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Msaada</span>
    </a>
    
    <a href="<?php echo isset($_SESSION['user_id']) ? 'dashboard.php' : 'auth/login.php'; ?>" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
        <span class="material-symbols-outlined text-on-surface-variant text-2xl">person</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Akaunti</span>
    </a>
</nav>

<script>
    // Toggle FAQ answers
    function toggleFaq(index) {
        const answerDiv = document.getElementById(`faq-answer-${index}`);
        const iconSpan = document.getElementById(`faq-icon-${index}`);
        
        if (answerDiv.classList.contains('hidden')) {
            answerDiv.classList.remove('hidden');
            iconSpan.style.transform = 'rotate(180deg)';
        } else {
            answerDiv.classList.add('hidden');
            iconSpan.style.transform = 'rotate(0deg)';
        }
    }
    
    // Active bottom nav highlight
    const currentPage = window.location.pathname.split('/').pop() || 'help.php';
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
    
    // Open first FAQ by default if search exists
    <?php if (!empty($search_query) && !empty($filtered_faqs)): ?>
    toggleFaq(0);
    <?php endif; ?>
</script>

</body>
</html>