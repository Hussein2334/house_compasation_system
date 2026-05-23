<?php
// claimant/includes/claimant-header.php - Claimant Header Component

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    header("Location: ../../dashboard.php");
    exit();
}

// Get current page name for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Get database connection for notifications
$conn = getDB();

// Get unread notifications count
$unread_count = 0;
if (isset($_SESSION['user_id'])) {
    $unread_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $unread_stmt = mysqli_prepare($conn, $unread_query);
    mysqli_stmt_bind_param($unread_stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($unread_stmt);
    $unread_result = mysqli_stmt_get_result($unread_stmt);
    $unread_count = mysqli_fetch_assoc($unread_result)['count'] ?? 0;
}
?>
<!DOCTYPE html>
<html class="light" lang="sw">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#006e2c"/>
<title><?php echo $page_title ?? 'Claimant Dashboard'; ?> | HCS - House Compensation System</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script id="tailwind-config">
        tailwind.config = {
          darkMode: "class",
          theme: {
            extend: {
              "colors": {
                      "error-container": "#ffdad6",
                      "surface-container-low": "#eef6ea",
                      "on-tertiary-fixed-variant": "#8d0f38",
                      "surface-bright": "#f4fcef",
                      "on-tertiary-container": "#690025",
                      "on-error-container": "#93000a",
                      "tertiary": "#ad2c4e",
                      "on-primary": "#ffffff",
                      "tertiary-fixed-dim": "#ffb2bd",
                      "surface": "#f4fcef",
                      "on-surface-variant": "#3d4a3d",
                      "tertiary-container": "#fb6787",
                      "surface-container": "#e8f0e4",
                      "surface-tint": "#006e2c",
                      "primary-fixed": "#79fd92",
                      "surface-variant": "#dde5d9",
                      "primary-container": "#1eb050",
                      "on-secondary": "#ffffff",
                      "secondary-fixed": "#ffe07f",
                      "on-primary-fixed-variant": "#005320",
                      "on-background": "#161d16",
                      "on-error": "#ffffff",
                      "inverse-primary": "#5be079",
                      "primary-fixed-dim": "#5be079",
                      "on-secondary-container": "#6f5900",
                      "secondary": "#725c00",
                      "on-secondary-fixed-variant": "#564500",
                      "surface-container-high": "#e3eadf",
                      "primary": "#006e2c",
                      "secondary-fixed-dim": "#edc200",
                      "error": "#ba1a1a",
                      "surface-container-highest": "#dde5d9",
                      "on-primary-fixed": "#002108",
                      "surface-dim": "#d4dcd1",
                      "on-secondary-fixed": "#231b00",
                      "on-tertiary-fixed": "#400014",
                      "secondary-container": "#fed000",
                      "on-primary-container": "#003a14",
                      "tertiary-fixed": "#ffd9dd",
                      "outline": "#6d7b6c",
                      "background": "#f4fcef",
                      "inverse-surface": "#2b322a",
                      "outline-variant": "#bccab9",
                      "on-surface": "#161d16",
                      "surface-container-lowest": "#ffffff"
              },
              "borderRadius": {
                      "DEFAULT": "0.125rem",
                      "lg": "0.25rem",
                      "xl": "0.5rem",
                      "full": "0.75rem"
              },
              "spacing": {
                      "margin-mobile": "16px",
                      "margin-desktop": "64px",
                      "sm": "12px",
                      "xs": "4px",
                      "xl": "80px",
                      "gutter": "24px",
                      "md": "24px",
                      "max-width": "1280px",
                      "lg": "48px",
                      "base": "8px"
              },
              "fontFamily": {
                      "headline-lg": ["Inter"],
                      "body-md": ["Inter"],
                      "body-lg": ["Inter"],
                      "label-sm": ["Inter"],
                      "display-lg-mobile": ["Inter"],
                      "headline-lg-mobile": ["Inter"],
                      "display-lg": ["Inter"],
                      "headline-md": ["Inter"],
                      "label-md": ["Inter"]
              }
            },
          },
        }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #bccab9; border-radius: 10px; }
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
<body class="bg-surface font-body-md text-on-surface flex flex-col md:flex-row h-screen overflow-hidden">

<!-- Mobile Sidebar Overlay -->
<div class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<?php
// Include claimant sidebar
require_once __DIR__ . '/claimant-sidebar.php';
?>

<!-- Main Content Area -->
<div class="flex-1 flex flex-col h-full overflow-hidden">
    
    <!-- Claimant Header (Top Navigation) -->
    <header class="flex items-center justify-between px-md md:px-lg h-16 bg-surface-container-lowest border-b border-outline-variant shrink-0 z-30">
        <div class="flex items-center gap-md">
            <button class="material-symbols-outlined cursor-pointer md:hidden text-primary" onclick="toggleSidebar()">menu</button>
            <h1 class="font-headline-md text-primary font-bold truncate"><?php echo $page_heading ?? 'Claimant Dashboard'; ?></h1>
        </div>
        
        <div class="flex items-center gap-md">
            <!-- Notifications -->
            <div class="relative cursor-pointer hover:bg-surface-container-low p-2 rounded-full hidden sm:block" onclick="showNotifications()">
                <span class="material-symbols-outlined text-primary">notifications</span>
                <?php if ($unread_count > 0): ?>
                <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-error rounded-full border-2 border-white animate-pulse"></span>
                <?php endif; ?>
            </div>
            
            <div class="h-8 w-[1px] bg-outline-variant mx-sm hidden sm:block"></div>
            
            <!-- User Menu -->
            <div class="relative">
                <button class="flex items-center gap-2 hover:bg-surface-container-low px-3 py-2 rounded-lg transition-colors" onclick="toggleUserMenu()">
                    <div class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white text-sm font-bold">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <span class="hidden md:inline text-sm font-medium"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    <span class="material-symbols-outlined text-sm hidden md:inline">expand_more</span>
                </button>
                <div id="userMenu" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-outline-variant hidden z-50">
                    <a href="profile.php" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-sm">account_circle</span>
                        Profile
                    </a>
                    <a href="my-claims.php" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-sm">description</span>
                        Madai Yangu
                    </a>
                    <a href="my-payments.php" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-sm">payments</span>
                        Malipo Yangu
                    </a>
                    <hr class="my-1">
                    <a href="../auth/logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                        <span class="material-symbols-outlined text-sm">logout</span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Main Content Starts Here -->
    <main class="flex-1 overflow-y-auto p-md md:p-lg space-y-lg pb-24 md:pb-lg">