<?php
// admin/includes/admin-sidebar.php - Admin Sidebar Component
// This file contains the sidebar navigation for admin pages
?>

<!-- Admin Sidebar (Desktop & Mobile Drawer) -->
<aside class="fixed inset-y-0 left-0 w-72 bg-surface-container-lowest border-r border-outline-variant z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:relative md:translate-x-0 flex flex-col h-full py-md" id="sidebar">
    <div class="px-md mb-lg flex items-center justify-between">
        <a href="dashboard.php" class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-2xl">account_balance</span>
            <span class="font-headline-md text-primary font-bold">HCS | Admin</span>
        </a>
        <button class="md:hidden p-2 text-primary" onclick="toggleSidebar()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    
    <div class="flex flex-col gap-1 px-sm overflow-y-auto flex-1">
        <!-- Profile Section -->
        <div class="flex items-center gap-sm p-sm bg-surface-container-low rounded-lg mb-md">
            <div class="w-12 h-12 bg-primary rounded-full flex items-center justify-center text-white font-bold text-xl">
                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
            </div>
            <div>
                <p class="font-label-md text-on-surface"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                <p class="text-[12px] text-secondary">Super Administrator</p>
            </div>
        </div>
        
        <!-- Main Navigation Items -->
        <a href="dashboard.php" class="flex items-center gap-md <?php echo ($current_page == 'dashboard.php') ? 'bg-secondary-container text-on-secondary-container' : 'text-secondary hover:bg-surface-container-high'; ?> font-semibold rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">dashboard</span>
            <span>Dashibodi</span>
        </a>
        
        <!-- Dropdown Menu: Claims -->
        <div class="dropdown-container">
            <button class="w-full flex items-center justify-between text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors" onclick="toggleDropdown(this)">
                <div class="flex items-center gap-md">
                    <span class="material-symbols-outlined">description</span>
                    <span>Madai</span>
                </div>
                <span class="material-symbols-outlined arrow-icon transition-transform">expand_more</span>
            </button>
            <div class="dropdown-content pl-xl flex flex-col gap-1 mt-1">
                <a class="py-2 text-sm text-secondary hover:text-primary" href="claims.php">Madai Yote</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="claims.php?status=pending">Madai Yanayosubiri</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="claims.php?status=approved">Madai Yaliyoidhinishwa</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="claims.php?status=rejected">Madai Yaliyokataliwa</a>
            </div>
        </div>
        
        <a href="new-claim.php" class="flex items-center gap-md text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">add_box</span>
            <span>Wasilisha Dai</span>
        </a>
        
        <!-- Dropdown Menu: Users -->
        <div class="dropdown-container">
            <button class="w-full flex items-center justify-between text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors" onclick="toggleDropdown(this)">
                <div class="flex items-center gap-md">
                    <span class="material-symbols-outlined">person_search</span>
                    <span>Watumiaji</span>
                </div>
                <span class="material-symbols-outlined arrow-icon transition-transform">expand_more</span>
            </button>
            <div class="dropdown-content pl-xl flex flex-col gap-1 mt-1">
                <a class="py-2 text-sm text-secondary hover:text-primary" href="users.php">Orodha ya Watumiaji</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="users.php?role=claimant">Wadai</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="users.php?role=valuer">Wakaguzi</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="users.php?role=admin">Administrators</a>
            </div>
        </div>
        
        <!-- Dropdown Menu: Valuations -->
        <div class="dropdown-container">
            <button class="w-full flex items-center justify-between text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors" onclick="toggleDropdown(this)">
                <div class="flex items-center gap-md">
                    <span class="material-symbols-outlined">real_estate_agent</span>
                    <span>Tathmini</span>
                </div>
                <span class="material-symbols-outlined arrow-icon transition-transform">expand_more</span>
            </button>
            <div class="dropdown-content pl-xl flex flex-col gap-1 mt-1">
                <a class="py-2 text-sm text-secondary hover:text-primary" href="valuations.php">Tathmini Zote</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="valuations.php?status=pending">Tathmini Inayoendelea</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="valuations-reports.php">Ripoti za Tathmini</a>
            </div>
        </div>
        
        <!-- Dropdown Menu: Payments -->
        <div class="dropdown-container">
            <button class="w-full flex items-center justify-between text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors" onclick="toggleDropdown(this)">
                <div class="flex items-center gap-md">
                    <span class="material-symbols-outlined">payments</span>
                    <span>Malipo</span>
                </div>
                <span class="material-symbols-outlined arrow-icon transition-transform">expand_more</span>
            </button>
            <div class="dropdown-content pl-xl flex flex-col gap-1 mt-1">
                <a class="py-2 text-sm text-secondary hover:text-primary" href="payments.php">Malipo Yote</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="payments.php?status=pending">Malipo Yanayosubiri</a>
                <a class="py-2 text-sm text-secondary hover:text-primary" href="payments.php?status=completed">Malipo Yaliyofanywa</a>
            </div>
        </div>
        
        <a href="reports.php" class="flex items-center gap-md text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">assessment</span>
            <span>Ripoti</span>
        </a>
        
        <a href="settings.php" class="flex items-center gap-md text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">settings</span>
            <span>Mipangilio</span>
        </a>
        
        <a href="help.php" class="flex items-center gap-md text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">help</span>
            <span>Msaada</span>
        </a>
        
        <!-- Audit Logs -->
        <a href="audit-logs.php" class="flex items-center gap-md text-secondary hover:bg-surface-container-high rounded-lg px-md py-sm transition-colors">
            <span class="material-symbols-outlined">history</span>
            <span>Rekodi za Shughuli</span>
        </a>
    </div>
    
    <!-- System Status -->
    <!-- <div class="mt-auto px-md">
        <div class="p-sm bg-primary-container text-on-primary-container rounded-lg flex items-center gap-sm">
            <span class="material-symbols-outlined">verified_user</span>
            <div>
                <span class="text-xs font-semibold">Mfumo Umeimarishwa</span>
                <p class="text-[10px] opacity-75">AES-256 Encryption Active</p>
            </div>
        </div>
    </div> -->
</aside>

<style>
    .dropdown-content { 
        max-height: 0; 
        overflow: hidden; 
        transition: max-height 0.3s ease-out; 
    }
    .dropdown-open .dropdown-content { 
        max-height: 300px; 
    }
    .dropdown-open .arrow-icon { 
        transform: rotate(180deg); 
    }
</style>