<?php
// index.php - Home page ya HCS (Complete with Footer)
session_start();

// Include database connection first
require_once 'config/db.php';

// Create database connection
$database = new Database();
$conn = $database->getConnection();

// Include functions
require_once 'includes/functions.php';

// Kuchukua taarifa za takwimu za mfumo
$stats = [
    'total_claims' => getTotalClaims($conn),
    'processed_claims' => getProcessedClaims($conn),
    'total_compensation' => getTotalCompensation($conn),
    'active_users' => getActiveUsers($conn)
];
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#006e2c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>HCS | Mfumo wa Fidia ya Nyumba - Tanzania</title>
    
    <!-- TailwindCSS CDN -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    
    <!-- Material Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "surface-container": "#e8f0e4",
                        "tertiary": "#ad2c4e",
                        "primary-container": "#1eb050",
                        "primary-fixed-dim": "#5be079",
                        "surface": "#f4fcef",
                        "on-tertiary": "#ffffff",
                        "on-secondary-fixed-variant": "#564500",
                        "error-container": "#ffdad6",
                        "on-tertiary-fixed-variant": "#8d0f38",
                        "on-background": "#161d16",
                        "secondary-fixed-dim": "#edc200",
                        "on-secondary": "#ffffff",
                        "outline-variant": "#bccab9",
                        "secondary": "#725c00",
                        "on-secondary-container": "#6f5900",
                        "inverse-on-surface": "#ebf3e7",
                        "on-tertiary-container": "#690025",
                        "error": "#ba1a1a",
                        "surface-container-lowest": "#ffffff",
                        "on-error-container": "#93000a",
                        "inverse-primary": "#5be079",
                        "tertiary-fixed-dim": "#ffb2bd",
                        "background": "#f4fcef",
                        "on-primary": "#ffffff",
                        "on-error": "#ffffff",
                        "primary": "#006e2c",
                        "on-surface-variant": "#3d4a3d",
                        "surface-container-highest": "#dde5d9",
                        "surface-bright": "#f4fcef",
                        "on-secondary-fixed": "#231b00",
                        "surface-variant": "#dde5d9",
                        "on-primary-fixed": "#002108",
                        "secondary-container": "#fed000",
                        "on-tertiary-fixed": "#400014",
                        "surface-dim": "#d4dcd1",
                        "secondary-fixed": "#ffe07f",
                        "on-primary-fixed-variant": "#005320",
                        "tertiary-fixed": "#ffd9dd",
                        "inverse-surface": "#2b322a",
                        "surface-container-low": "#eef6ea",
                        "on-surface": "#161d16",
                        "surface-tint": "#006e2c",
                        "surface-container-high": "#e3eadf",
                        "primary-fixed": "#79fd92",
                        "on-primary-container": "#003a14",
                        "tertiary-container": "#fb6787",
                        "outline": "#6d7b6c"
                    },
                    borderRadius: {
                        DEFAULT: "0.125rem",
                        lg: "0.25rem",
                        xl: "0.5rem",
                        full: "0.75rem"
                    },
                    spacing: {
                        base: "8px",
                        sm: "12px",
                        "margin-mobile": "16px",
                        "margin-desktop": "64px",
                        "max-width": "1280px",
                        lg: "48px",
                        xs: "4px",
                        gutter: "24px",
                        md: "24px",
                        xl: "80px"
                    },
                    fontFamily: {
                        "display-lg-mobile": ["Inter"],
                        "display-lg": ["Inter"],
                        "body-md": ["Inter"],
                        "headline-lg": ["Inter"],
                        "label-md": ["Inter"],
                        "headline-lg-mobile": ["Inter"],
                        "body-lg": ["Inter"],
                        "headline-md": ["Inter"],
                        "label-sm": ["Inter"]
                    },
                    fontSize: {
                        "display-lg-mobile": ["36px", {"lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "body-md": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                        "headline-lg": ["32px", {"lineHeight": "40px", "fontWeight": "600"}],
                        "label-md": ["14px", {"lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600"}],
                        "headline-lg-mobile": ["28px", {"lineHeight": "36px", "fontWeight": "600"}],
                        "body-lg": ["18px", {"lineHeight": "28px", "fontWeight": "400"}],
                        "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "label-sm": ["12px", {"lineHeight": "16px", "letterSpacing": "0.04em", "fontWeight": "500"}]
                    }
                }
            }
        }
    </script>
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        body { 
            font-family: 'Inter', sans-serif;
            padding-bottom: 70px; /* Space for bottom navigation */
        }
        
        @media (min-width: 768px) {
            body {
                padding-bottom: 0;
            }
        }
        
        .material-symbols-outlined { 
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; 
        }
        
        .glass-card { 
            background: rgba(255, 255, 255, 0.9); 
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .national-gradient { 
            background: linear-gradient(135deg, #006e2c 0%, #1eb050 100%); 
        }
        
        .bento-item { 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        
        .bento-item:active { 
            transform: scale(0.98);
            background-color: #e8f0e4;
        }
        
        /* Mobile touch optimizations */
        @media (max-width: 768px) {
            button, a, .cursor-pointer {
                cursor: pointer;
                min-height: 44px;
            }
            
            input, select, textarea {
                font-size: 16px !important;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .stat-card:active {
            transform: scale(0.96);
        }
        
        /* Bottom Navigation Animation */
        .bottom-nav-item {
            transition: all 0.2s ease;
        }
        
        .bottom-nav-item:active {
            transform: scale(0.9);
        }
        
        /* Mobile Menu */
        .mobile-menu-enter {
            transform: translateX(100%);
        }
        
        .mobile-menu-enter-active {
            transform: translateX(0);
            transition: transform 300ms ease;
        }
        
        /* Safe area for notches */
        @supports (padding-bottom: env(safe-area-inset-bottom)) {
            .bottom-nav {
                padding-bottom: env(safe-area-inset-bottom);
            }
        }
    </style>
</head>
<body class="bg-surface text-on-surface selection:bg-secondary-container">

    <!-- Skip to content link -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-primary text-white p-3 rounded-lg z-50">
        Ruka hadi maudhui
    </a>

    <!-- TopAppBar - Responsive -->
    <header class="bg-surface sticky top-0 z-50 border-b-2 border-secondary shadow-sm">
        <div class="flex justify-between items-center w-full px-margin-mobile md:px-margin-desktop max-w-max-width mx-auto min-h-16 md:h-20">
            <!-- Logo -->
            <a href="index.php" class="flex items-center gap-2 active:opacity-70 transition-opacity">
                <span class="material-symbols-outlined text-primary text-[28px] md:text-[32px]">account_balance</span>
                <span class="font-headline-md text-headline-md text-primary font-bold">HCS</span>
            </a>
            
            <!-- Desktop Navigation -->
            <nav class="hidden md:flex items-center gap-6">
                <a href="index.php" class="text-primary font-bold border-b-2 border-primary font-label-md text-label-md py-1">Home</a>
                <a href="track-claim.php" class="text-on-surface-variant hover:text-primary font-label-md text-label-md transition-colors">Track</a>
                <a href="notices.php" class="text-on-surface-variant hover:text-primary font-label-md text-label-md transition-colors">Notices</a>
                <a href="help.php" class="text-on-surface-variant hover:text-primary font-label-md text-label-md transition-colors">Help</a>
            </nav>
            
            <!-- Auth Buttons -->
            <div class="flex items-center gap-2">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php" class="bg-primary text-on-primary px-4 py-2 md:px-5 md:py-2.5 rounded-lg font-label-md text-label-md font-bold hover:shadow-lg transition-all active:scale-95">
                        Dashboard
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="hidden sm:inline-block text-primary font-bold px-3 py-2">
                        Ingia
                    </a>
                    <a href="auth/register.php" class="bg-primary text-on-primary px-4 py-2 md:px-5 md:py-2.5 rounded-lg font-label-md text-label-md font-bold hover:shadow-lg transition-all active:scale-95">
                        Anza
                    </a>
                <?php endif; ?>
                
                <!-- Mobile menu button -->
                <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg active:bg-surface-container transition-colors">
                    <span class="material-symbols-outlined text-on-surface text-2xl">menu</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Mobile Side Menu -->
    <div id="mobileMenu" class="fixed inset-0 z-50 hidden md:hidden">
        <div class="absolute inset-0 bg-black/50" id="mobileMenuOverlay"></div>
        <div class="absolute right-0 top-0 h-full w-80 bg-surface shadow-xl transform translate-x-full transition-transform duration-300">
            <div class="p-6">
                <div class="flex justify-between items-center mb-8">
                    <span class="font-headline-md text-headline-md text-primary">Menu</span>
                    <button id="closeMenuBtn" class="p-2 active:bg-surface-container rounded-lg">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <nav class="flex flex-col gap-4">
                    <a href="index.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container text-primary font-bold">
                        <span class="material-symbols-outlined">home</span>
                        <span>Home</span>
                    </a>
                    <a href="track-claim.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container">
                        <span class="material-symbols-outlined">query_stats</span>
                        <span>Track Claim</span>
                    </a>
                    <a href="notices.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container">
                        <span class="material-symbols-outlined">campaign</span>
                        <span>Notices</span>
                    </a>
                    <a href="help.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container">
                        <span class="material-symbols-outlined">help_outline</span>
                        <span>Help</span>
                    </a>
                    <hr class="my-4 border-outline-variant">
                    <?php if(!isset($_SESSION['user_id'])): ?>
                        <a href="auth/login.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container">
                            <span class="material-symbols-outlined">login</span>
                            <span>Login</span>
                        </a>
                        <a href="auth/register.php" class="flex items-center gap-3 p-3 rounded-lg bg-primary text-on-primary active:scale-95 transition-transform">
                            <span class="material-symbols-outlined">person_add</span>
                            <span>Register</span>
                        </a>
                    <?php else: ?>
                        <a href="logout.php" class="flex items-center gap-3 p-3 rounded-lg active:bg-surface-container text-error">
                            <span class="material-symbols-outlined">logout</span>
                            <span>Logout</span>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>

    <main id="main-content">
        <!-- Hero Section - Optimized for Mobile -->
        <section class="relative overflow-hidden pt-6 pb-10 md:pt-20 md:pb-32 bg-white">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_var(--tw-gradient-stops))] from-surface-container via-transparent to-transparent opacity-50"></div>
            <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop flex flex-col md:flex-row items-center gap-6 md:gap-xl relative z-10">
                <div class="flex-1 text-center md:text-left">
                    <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-on-background mb-2">
                        HCS | <span class="text-primary">Mfumo wa Fidia</span>
                    </h1>
                    <p class="font-body-md md:font-body-lg text-body-md md:text-body-lg text-on-surface-variant mb-5 md:mb-lg max-w-xl mx-auto md:mx-0">
                        Hakikisha haki yako ya fidia inashughulikiwa kwa haraka, uwazi, na usalama.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center md:justify-start">
                        <a href="auth/register.php" class="h-11 px-5 md:px-6 bg-primary text-on-primary font-bold rounded-lg flex items-center justify-center gap-2 hover:shadow-lg transition-all active:scale-95">
                            Anza Maombi
                            <span class="material-symbols-outlined text-base">arrow_forward</span>
                        </a>
                        <a href="#process" class="h-11 px-5 md:px-6 border-2 border-on-background text-on-background font-bold rounded-lg hover:bg-surface-container transition-colors active:bg-surface-container-highest flex items-center justify-center">
                            Jifunze Zaidi
                        </a>
                    </div>
                </div>
                <div class="flex-1 w-full relative mt-4 md:mt-0">
                    <div class="aspect-[4/3] rounded-xl overflow-hidden shadow-2xl border border-outline-variant">
                        <img alt="Government building" class="w-full h-full object-cover" 
                             src="https://lh3.googleusercontent.com/aida-public/AB6AXuD-BbiDtuMLq0UMrg3V8R8SXqvkjLrMTYE86EN13gsohFfhJoCkJy2ZfigTNJgoxD_ZbSBh4i4-Ve9Ony4GAeWFvZPFYSWGL7OrmgD0XBey6zOIiq3_n6c03FxOn57R0Dv_OnwSOf9Vgl3_sISW5NMDzogavKc_4WlsNCv0XY2Uy_mZJ-DToGBZzNdaU8-9_5oklJnmMXG2ybalDvmKTDgWNVhO1y1UVg-y8uxsQLpDGMrAAMkUhVygEES_re5yAEmkg3M_F7djN7fb"
                             loading="lazy">
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats Cards - Mobile Friendly Grid -->
        <section class="py-6 md:py-8 bg-surface-container-low">
            <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop">
                <div class="grid grid-cols-2 gap-3 md:gap-6">
                    <div class="bg-white rounded-xl p-3 text-center shadow-sm stat-card active:scale-95 transition-transform">
                        <span class="material-symbols-outlined text-primary text-2xl mb-1">description</span>
                        <p class="font-headline-md text-xl font-bold text-primary"><?php echo number_format($stats['total_claims']); ?>+</p>
                        <p class="font-label-sm text-label-sm text-on-surface-variant text-xs">Total Claims</p>
                    </div>
                    <div class="bg-white rounded-xl p-3 text-center shadow-sm stat-card active:scale-95 transition-transform">
                        <span class="material-symbols-outlined text-primary text-2xl mb-1">check_circle</span>
                        <p class="font-headline-md text-xl font-bold text-primary"><?php echo number_format($stats['processed_claims']); ?>+</p>
                        <p class="font-label-sm text-label-sm text-on-surface-variant text-xs">Processed</p>
                    </div>
                    <div class="bg-white rounded-xl p-3 text-center shadow-sm stat-card active:scale-95 transition-transform">
                        <span class="material-symbols-outlined text-primary text-2xl mb-1">payments</span>
                        <p class="font-headline-md text-xl font-bold text-primary"><?php echo number_format($stats['total_compensation']); ?>B+</p>
                        <p class="font-label-sm text-label-sm text-on-surface-variant text-xs">TZS Paid</p>
                    </div>
                    <div class="bg-white rounded-xl p-3 text-center shadow-sm stat-card active:scale-95 transition-transform">
                        <span class="material-symbols-outlined text-primary text-2xl mb-1">people</span>
                        <p class="font-headline-md text-xl font-bold text-primary"><?php echo number_format($stats['active_users']); ?>K+</p>
                        <p class="font-label-sm text-label-sm text-on-surface-variant text-xs">Beneficiaries</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Track Status Section -->
        <section class="py-6 md:py-xl bg-surface-container-low">
            <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop">
                <div class="bg-white border border-outline-variant p-4 md:p-6 rounded-xl shadow-sm">
                    <div class="text-center md:text-left md:flex md:items-center md:justify-between gap-4">
                        <div class="mb-3 md:mb-0">
                            <span class="material-symbols-outlined text-primary text-3xl md:hidden mb-1">track_changes</span>
                            <h2 class="font-headline-lg text-headline-lg text-on-background text-lg md:text-2xl">Fuatilia Dai Lako</h2>
                            <p class="font-body-md text-body-md text-on-surface-variant text-sm">Ingiza namba ya dai uliyopewa</p>
                        </div>
                        <div class="flex-1">
                            <form id="trackClaimForm" class="flex flex-col sm:flex-row gap-2" onsubmit="event.preventDefault(); trackClaim();">
                                <div class="relative flex-1">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
                                    <input id="claimNumber" type="text" 
                                           class="w-full h-11 pl-9 pr-3 bg-surface border border-outline-variant rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-all text-sm"
                                           placeholder="Mf: HCS-2024-001">
                                </div>
                                <button type="submit" id="trackBtn" 
                                        class="h-11 px-5 bg-on-background text-white font-bold rounded-lg hover:bg-opacity-90 transition-all active:scale-95 text-sm">
                                    Angalia Hali
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Services Bento Grid -->
        <section class="py-6 md:py-xl">
            <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop">
                <div class="flex items-center justify-between mb-4 md:mb-6">
                    <h2 class="font-headline-lg text-headline-lg text-on-background text-xl md:text-2xl">Huduma Zetu</h2>
                    <div class="h-1 bg-secondary w-12 md:w-16"></div>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-5">
                    <!-- Guide Card -->
                    <div class="bento-item bg-white border border-outline-variant p-4 rounded-xl flex items-start gap-3 active:bg-surface-container-low transition-all">
                        <div class="w-10 h-10 rounded bg-primary-container/10 flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl">menu_book</span>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-headline-md text-headline-md text-on-background text-base mb-1">Mwongozo wa Fidia</h3>
                            <p class="font-body-md text-body-md text-on-surface-variant text-xs">Maelezo ya kina kuhusu taratibu na haki.</p>
                            <a href="guide.php" class="text-primary font-bold text-sm flex items-center gap-1 mt-2 active:opacity-70">
                                Soma Zaidi <span class="material-symbols-outlined text-sm">arrow_forward</span>
                            </a>
                        </div>
                    </div>
                    <!-- Map Card -->
                    <div class="bento-item bg-white border border-outline-variant p-4 rounded-xl flex items-start gap-3 active:bg-surface-container-low transition-all">
                        <div class="w-10 h-10 rounded bg-primary-container/10 flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl">map</span>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-headline-md text-headline-md text-on-background text-base mb-1">Ramani ya Miradi</h3>
                            <p class="font-body-md text-body-md text-on-surface-variant text-xs">Angalia maeneo ya miradi ya kitaifa.</p>
                            <a href="map.php" class="text-primary font-bold text-sm flex items-center gap-1 mt-2 active:opacity-70">
                                Fungua Ramani <span class="material-symbols-outlined text-sm">arrow_forward</span>
                            </a>
                        </div>
                    </div>
                    <!-- FAQ Card -->
                    <div class="bento-item bg-white border border-outline-variant p-4 rounded-xl flex items-start gap-3 active:bg-surface-container-low transition-all">
                        <div class="w-10 h-10 rounded bg-primary-container/10 flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl">quiz</span>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-headline-md text-headline-md text-on-background text-base mb-1">Maswali (FAQ)</h3>
                            <p class="font-body-md text-body-md text-on-surface-variant text-xs">Majibu kwa maswali yanayoulizwa mara kwa mara.</p>
                            <a href="faq.php" class="text-primary font-bold text-sm flex items-center gap-1 mt-2 active:opacity-70">
                                Pata Majibu <span class="material-symbols-outlined text-sm">arrow_forward</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Process Steps -->
        <section id="process" class="py-8 md:py-xl bg-on-background text-white relative overflow-hidden">
            <div class="absolute top-0 right-0 w-48 h-48 md:w-64 md:h-64 bg-primary/20 blur-[120px] rounded-full"></div>
            <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop relative z-10">
                <div class="text-center mb-6 md:mb-10">
                    <h2 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-xl md:text-3xl mb-2">Mchakato wa Hatua Tatu</h2>
                    <p class="font-body-md text-body-md text-outline-variant text-sm">Tumerahisisha utoaji wa fidia kwa wananchi wote.</p>
                </div>
                <div class="flex flex-col md:flex-row gap-6 relative">
                    <!-- Step 1 -->
                    <div class="flex-1 text-center">
                        <div class="relative inline-block mb-3">
                            <div class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-primary border-4 border-on-background flex items-center justify-center mx-auto shadow-lg">
                                <span class="material-symbols-outlined text-2xl md:text-3xl">person_add</span>
                            </div>
                            <div class="absolute -top-2 -right-2 bg-secondary text-on-secondary-fixed w-6 h-6 md:w-7 md:h-7 rounded-full flex items-center justify-center font-bold text-sm border-2 border-on-background">1</div>
                        </div>
                        <h4 class="font-headline-md text-headline-md text-base md:text-lg mb-1">Jisajili</h4>
                        <p class="font-body-sm text-body-sm text-outline-variant text-xs">Unda akaunti yako rasmi.</p>
                    </div>
                    <!-- Step 2 -->
                    <div class="flex-1 text-center">
                        <div class="relative inline-block mb-3">
                            <div class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-primary border-4 border-on-background flex items-center justify-center mx-auto shadow-lg">
                                <span class="material-symbols-outlined text-2xl md:text-3xl">assignment_turned_in</span>
                            </div>
                            <div class="absolute -top-2 -right-2 bg-secondary text-on-secondary-fixed w-6 h-6 md:w-7 md:h-7 rounded-full flex items-center justify-center font-bold text-sm border-2 border-on-background">2</div>
                        </div>
                        <h4 class="font-headline-md text-headline-md text-base md:text-lg mb-1">Tathmini</h4>
                        <p class="font-body-sm text-body-sm text-outline-variant text-xs">Wataalamu watathmini mali yako.</p>
                    </div>
                    <!-- Step 3 -->
                    <div class="flex-1 text-center">
                        <div class="relative inline-block mb-3">
                            <div class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-primary border-4 border-on-background flex items-center justify-center mx-auto shadow-lg">
                                <span class="material-symbols-outlined text-2xl md:text-3xl">payments</span>
                            </div>
                            <div class="absolute -top-2 -right-2 bg-secondary text-on-secondary-fixed w-6 h-6 md:w-7 md:h-7 rounded-full flex items-center justify-center font-bold text-sm border-2 border-on-background">3</div>
                        </div>
                        <h4 class="font-headline-md text-headline-md text-base md:text-lg mb-1">Malipo</h4>
                        <p class="font-body-sm text-body-sm text-outline-variant text-xs">Malipo yataingizwa moja kwa moja.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- FOOTER - Full Footer with Links -->
    <footer class="bg-on-background text-surface-variant border-t border-outline-variant mt-auto">
        <!-- Main Footer Content -->
        <div class="max-w-max-width mx-auto px-margin-mobile md:px-margin-desktop py-8 md:py-12">
            <!-- Footer Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                
                <!-- Column 1: Brand & About -->
                <div class="text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start gap-2 mb-4">
                        <span class="material-symbols-outlined text-primary-fixed text-2xl">account_balance</span>
                        <span class="font-headline-md text-headline-md text-primary-fixed font-bold">HCS</span>
                    </div>
                    <p class="font-body-sm text-body-sm text-surface-variant mb-4">
                        Mfumo rasmi wa Jamhuri ya Muungano wa Tanzania kwa ajili ya uratibu wa malipo ya fidia kwa uwazi na ufanisi.
                    </p>
                    <div class="flex gap-3 justify-center md:justify-start">
                        <a href="#" class="w-8 h-8 rounded-full bg-surface-variant/20 flex items-center justify-center hover:bg-primary hover:text-on-primary transition-colors">
                            <span class="material-symbols-outlined text-sm">facebook</span>
                        </a>
                        <a href="#" class="w-8 h-8 rounded-full bg-surface-variant/20 flex items-center justify-center hover:bg-primary hover:text-on-primary transition-colors">
                            <span class="material-symbols-outlined text-sm">share</span>
                        </a>
                        <a href="#" class="w-8 h-8 rounded-full bg-surface-variant/20 flex items-center justify-center hover:bg-primary hover:text-on-primary transition-colors">
                            <span class="material-symbols-outlined text-sm">mail</span>
                        </a>
                        <a href="#" class="w-8 h-8 rounded-full bg-surface-variant/20 flex items-center justify-center hover:bg-primary hover:text-on-primary transition-colors">
                            <span class="material-symbols-outlined text-sm">smartphone</span>
                        </a>
                    </div>
                </div>
                
                <!-- Column 2: Quick Links -->
                <div class="text-center md:text-left">
                    <h4 class="font-label-md text-label-md text-white font-bold mb-4">Viungo Muhimu</h4>
                    <ul class="space-y-2">
                        <li><a href="index.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Nyumbani</a></li>
                        <li><a href="track-claim.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Fuatilia Dai</a></li>
                        <li><a href="notices.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Taarifa na Matangazo</a></li>
                        <li><a href="guide.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Mwongozo wa Mchakato</a></li>
                        <li><a href="faq.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Maswali na Majibu</a></li>
                    </ul>
                </div>
                
                <!-- Column 3: Support -->
                <div class="text-center md:text-left">
                    <h4 class="font-label-md text-label-md text-white font-bold mb-4">Msaada</h4>
                    <ul class="space-y-2">
                        <li><a href="contact.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Wasiliana Nasi</a></li>
                        <li><a href="privacy.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Sera ya Faragha</a></li>
                        <li><a href="terms.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Masharti ya Huduma</a></li>
                        <li><a href="help.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Msaada wa Kiufundi</a></li>
                        <li><a href="report.php" class="font-body-sm text-body-sm text-surface-variant hover:text-primary-fixed transition-colors flex items-center gap-1 justify-center md:justify-start hover:translate-x-1 transition-transform">Ripoti Tatizo</a></li>
                    </ul>
                </div>
                
                <!-- Column 4: Contact Info -->
                <div class="text-center md:text-left">
                    <h4 class="font-label-md text-label-md text-white font-bold mb-4">Wasiliana Nasi</h4>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-3 justify-center md:justify-start">
                            <span class="material-symbols-outlined text-primary-fixed text-sm">location_on</span>
                            <span class="font-body-sm text-body-sm text-surface-variant">Dodoma, Tanzania</span>
                        </li>
                        <li class="flex items-center gap-3 justify-center md:justify-start">
                            <span class="material-symbols-outlined text-primary-fixed text-sm">call</span>
                            <span class="font-body-sm text-body-sm text-surface-variant">+255 26 232 1234</span>
                        </li>
                        <li class="flex items-center gap-3 justify-center md:justify-start">
                            <span class="material-symbols-outlined text-primary-fixed text-sm">mail</span>
                            <span class="font-body-sm text-body-sm text-surface-variant">info@hcs.go.tz</span>
                        </li>
                        <li class="flex items-center gap-3 justify-center md:justify-start">
                            <span class="material-symbols-outlined text-primary-fixed text-sm">schedule</span>
                            <span class="font-body-sm text-body-sm text-surface-variant">Jumatatu - Ijumaa: 8:00 - 17:00</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Footer Bottom Bar -->
            <div class="border-t border-outline-variant/30 mt-8 pt-6 text-center">
                <div class="flex flex-col md:flex-row justify-between items-center gap-3">
                    <p class="font-label-sm text-label-sm text-surface-variant text-xs">
                        © 2025 House Compensation System (HCS). Jamhuri ya Muungano wa Tanzania.
                    </p>
                    <div class="flex gap-4">
                        <a href="privacy.php" class="font-label-sm text-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-xs">Sera ya Faragha</a>
                        <a href="terms.php" class="font-label-sm text-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-xs">Masharti</a>
                        <a href="sitemap.php" class="font-label-sm text-label-sm text-surface-variant hover:text-primary-fixed transition-colors text-xs">Ramani ya Tovuti</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- BOTTOM NAVIGATION BAR - Mobile Only -->
    <nav class="bottom-nav fixed bottom-0 left-0 right-0 bg-surface border-t border-outline-variant flex justify-around items-center px-2 py-1 shadow-lg z-50 md:hidden" style="padding-bottom: env(safe-area-inset-bottom, 0.5rem);">
        <a href="index.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1;">home</span>
            <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Nyumbani</span>
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
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">help_outline</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Msaada</span>
        </a>
        
        <?php if(isset($_SESSION['user_id'])): ?>
        <a href="dashboard.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-primary text-2xl">account_circle</span>
            <span class="font-label-sm text-label-sm text-primary text-xs font-bold">Akaunti</span>
        </a>
        <?php else: ?>
        <a href="auth/login.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">login</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Ingia</span>
        </a>
        <?php endif; ?>
    </nav>

    <script>
        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const closeMenuBtn = document.getElementById('closeMenuBtn');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        const mobileMenuPanel = mobileMenu?.querySelector('.transform');

        function openMobileMenu() {
            if (mobileMenu && mobileMenuPanel) {
                mobileMenu.classList.remove('hidden');
                setTimeout(() => {
                    mobileMenuPanel.classList.remove('translate-x-full');
                    mobileMenuPanel.classList.add('translate-x-0');
                }, 10);
                document.body.style.overflow = 'hidden';
            }
        }

        function closeMobileMenu() {
            if (mobileMenu && mobileMenuPanel) {
                mobileMenuPanel.classList.remove('translate-x-0');
                mobileMenuPanel.classList.add('translate-x-full');
                setTimeout(() => {
                    mobileMenu.classList.add('hidden');
                    document.body.style.overflow = '';
                }, 300);
            }
        }

        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openMobileMenu);
        if (closeMenuBtn) closeMenuBtn.addEventListener('click', closeMobileMenu);
        if (mobileMenuOverlay) mobileMenuOverlay.addEventListener('click', closeMobileMenu);

        // Track Claim Function
        function trackClaim() {
            const claimNumber = document.getElementById('claimNumber');
            const trackBtn = document.getElementById('trackBtn');
            
            if (!claimNumber.value.trim()) {
                claimNumber.classList.add('border-error', 'ring-1', 'ring-error');
                setTimeout(() => {
                    claimNumber.classList.remove('border-error', 'ring-1', 'ring-error');
                }, 2000);
                return;
            }
            
            const originalText = trackBtn.innerHTML;
            trackBtn.innerHTML = '<span class="material-symbols-outlined animate-spin text-base">sync</span>';
            trackBtn.disabled = true;
            
            setTimeout(() => {
                window.location.href = 'track-claim.php?claim_number=' + encodeURIComponent(claimNumber.value);
            }, 500);
        }

        // Scroll reveal animation
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-up');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.bento-item, .stat-card').forEach(el => {
            el.classList.add('opacity-0');
            observer.observe(el);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    closeMobileMenu();
                }
            });
        });

        // Active bottom nav highlight
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';
        const bottomNavLinks = document.querySelectorAll('.bottom-nav-item');
        
        bottomNavLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentPage) {
                const icon = link.querySelector('.material-symbols-outlined');
                const text = link.querySelector('span:last-child');
                if (icon) icon.style.fontVariationSettings = "'FILL' 1";
                if (text) text.classList.add('text-primary', 'font-bold');
            }
        });
    </script>
</body>
</html>