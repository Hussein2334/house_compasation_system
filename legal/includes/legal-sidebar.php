<?php
// legal/includes/legal-sidebar.php - Legal Officer Sidebar Component (No Logout)

// Determine current page for active states
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Legal Sidebar (Desktop & Mobile Drawer) -->
<aside class="fixed inset-y-0 left-0 w-72 bg-surface-container-lowest border-r border-outline-variant z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:relative md:translate-x-0 flex flex-col h-full overflow-y-auto custom-scrollbar" id="sidebar">
    
    <!-- Sidebar Header -->
    <div class="px-md py-4 mb-2 flex items-center justify-between border-b border-outline-variant shrink-0 bg-surface-container-lowest">
        <a href="dashboard.php" class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-2xl">gavel</span>
            <span class="font-headline-md text-primary font-bold">HCS | Legal</span>
        </a>
        <button class="md:hidden p-2 text-primary rounded-lg hover:bg-surface-container-low transition-colors" onclick="toggleSidebar()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    
    <!-- Scrollable Navigation Area -->
    <div class="flex-1 px-3 py-2">
        
        <!-- Profile Section -->
        <div class="flex items-center gap-3 p-3 bg-surface-container-low rounded-xl mb-4">
            <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center text-white font-bold text-sm shadow-md">
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'L', 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-label-md text-on-surface truncate font-semibold"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Legal Officer'); ?></p>
                <p class="text-[11px] text-secondary">Afisa Kisheria (Legal)</p>
            </div>
        </div>
        
        <!-- Main Navigation Items with Dropdowns -->
        <div class="space-y-1">
            
            <!-- Dashboard (No Dropdown) -->
            <?php 
            $is_dashboard_active = ($current_page == 'dashboard.php');
            ?>
            <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 <?php echo $is_dashboard_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200">
                <span class="material-symbols-outlined text-xl">dashboard</span>
                <span>Dashibodi</span>
                <?php if($is_dashboard_active): ?>
                <span class="ml-auto w-1.5 h-1.5 bg-primary rounded-full"></span>
                <?php endif; ?>
            </a>
            
            <!-- ==================== DROPDOWN: CLAIMS MANAGEMENT ==================== -->
            <?php 
            $is_claims_active = ($current_page == 'claims.php');
            $claims_sub_active = ($is_claims_active || (isset($_GET['status']) && in_array($_GET['status'], ['legal_review', 'approved', 'rejected'])));
            ?>
            <div class="dropdown-container <?php echo $claims_sub_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $claims_sub_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">description</span>
                        <span>Usimamizi wa Madai</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="claims.php" class="py-2.5 text-sm <?php echo ($is_claims_active && !isset($_GET['status'])) ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📋 Madai Yanayohitaji Uhakiki
                    </a>
                    <a href="claims.php?status=legal_review" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'legal_review') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Yanayosubiri Uhakiki
                    </a>
                    <a href="claims.php?status=approved" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-green-500 rounded-full"></span> Yaliyoidhinishwa
                    </a>
                    <a href="claims.php?status=rejected" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-red-500 rounded-full"></span> Yaliyokataliwa
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: REPORTS ==================== -->
            <?php 
            $is_reports_active = ($current_page == 'reports.php');
            ?>
            <div class="dropdown-container <?php echo $is_reports_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_reports_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">analytics</span>
                        <span>Ripoti</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="reports.php" class="py-2.5 text-sm <?php echo $is_reports_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📊 Ripoti za Uhakiki
                    </a>
                </div>
            </div>
            
            <!-- Separator -->
            <div class="border-t border-outline-variant my-3"></div>
            
            <!-- ==================== DROPDOWN: DOCUMENTS ==================== -->
            <?php 
            $is_documents_active = ($current_page == 'documents.php');
            ?>
            <div class="dropdown-container <?php echo $is_documents_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_documents_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">folder</span>
                        <span>Nyaraka</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="documents.php" class="py-2.5 text-sm <?php echo $is_documents_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📄 Hati za Kisheria
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: ACCOUNT ==================== -->
            <?php 
            $is_profile_active = ($current_page == 'profile.php');
            ?>
            <div class="dropdown-container <?php echo $is_profile_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_profile_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">account_circle</span>
                        <span>Akaunti</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="profile.php" class="py-2.5 text-sm <?php echo $is_profile_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        👤 Maelezo
                    </a>
                    <a href="profile.php?tab=security" class="py-2.5 text-sm flex items-center gap-2 text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        🔒 Badilisha Nenosiri
                    </a>
                    <a href="profile.php?tab=notifications" class="py-2.5 text-sm flex items-center gap-2 text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        🔔 Arifa
                    </a>
                    <a href="profile.php?tab=activities" class="py-2.5 text-sm flex items-center gap-2 text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        📜 Shughuli
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: HELP ==================== -->
            <?php 
            $is_help_active = ($current_page == 'help.php');
            ?>
            <div class="dropdown-container <?php echo $is_help_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_help_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">help</span>
                        <span>Msaada</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="help.php" class="py-2.5 text-sm <?php echo $is_help_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        ❓ Maswali na Majibu
                    </a>
                    <a href="help.php#contactForm" class="py-2.5 text-sm text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        📞 Wasiliana Nasi
                    </a>
                </div>
            </div>
            
            <!-- NO LOGOUT BUTTON IN SIDEBAR - Only in header user menu -->
            
        </div>
    </div>
    
    <!-- Sidebar Footer -->
    <div class="shrink-0 p-3 border-t border-outline-variant mt-auto bg-surface-container-lowest">
        <div class="text-center">
            <p class="text-[10px] text-secondary">House Compensation System</p>
            <p class="text-[9px] text-secondary mt-0.5">v1.0.0 | © <?php echo date('Y'); ?></p>
        </div>
    </div>
    
</aside>

<style>
    /* Custom Scrollbar for Sidebar */
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: #e8f0e4;
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #bccab9;
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #6d7b6c;
    }
    
    /* Dropdown Styles */
    .dropdown-container { 
        position: relative;
    }
    .dropdown-content { 
        max-height: 0; 
        overflow: hidden; 
        transition: max-height 0.3s ease-out; 
    }
    .dropdown-open .dropdown-content { 
        max-height: 350px; 
    }
    .dropdown-open .arrow-icon { 
        transform: rotate(180deg); 
    }
    
    /* Active state styles */
    .bg-secondary-container {
        background-color: #e8f0e4 !important;
        color: #006e2c !important;
    }
    .text-on-secondary-container {
        color: #006e2c !important;
    }
    
    /* Hover effects */
    .dropdown-content a:hover {
        background-color: rgba(0, 110, 44, 0.05);
    }
    
    /* Sidebar transition for mobile */
    @media (max-width: 768px) {
        #sidebar {
            transition: transform 0.3s ease-in-out;
        }
        #sidebar.-translate-x-full {
            transform: translateX(-100%);
        }
        #sidebar {
            transform: translateX(0);
        }
    }
</style>

<script>
    // Toggle dropdown menus in sidebar
    function toggleDropdown(button) {
        if (event) {
            event.stopPropagation();
        }
        const container = button.closest('.dropdown-container');
        if (container) {
            container.classList.toggle('dropdown-open');
        }
    }
    
    // Toggle sidebar on mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            const isVisible = sidebar.style.transform === 'translateX(0px)' || !sidebar.classList.contains('-translate-x-full');
            if (isVisible) {
                sidebar.style.transform = 'translateX(-100%)';
                sidebar.classList.add('-translate-x-full');
            } else {
                sidebar.style.transform = 'translateX(0)';
                sidebar.classList.remove('-translate-x-full');
            }
        }
        const overlay = document.getElementById('sidebar-overlay');
        if (overlay) {
            overlay.classList.toggle('hidden');
        }
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const toggleBtn = event.target.closest('[onclick*="toggleSidebar"]');
        const isInsideSidebar = sidebar && sidebar.contains(event.target);
        
        if (window.innerWidth <= 768 && !isInsideSidebar && !toggleBtn && overlay && !overlay.classList.contains('hidden')) {
            toggleSidebar();
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (window.innerWidth > 768 && sidebar) {
            sidebar.style.transform = '';
            sidebar.classList.remove('-translate-x-full');
            if (overlay) overlay.classList.add('hidden');
        }
    });
    
    // Prevent dropdown from closing when clicking inside dropdown content
    document.querySelectorAll('.dropdown-content').forEach(content => {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
</script>