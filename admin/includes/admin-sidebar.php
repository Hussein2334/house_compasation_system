<?php
// admin/includes/admin-sidebar.php - Admin Sidebar Component with Dropdown Menus

// Determine current page for active states
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Admin Sidebar (Desktop & Mobile Drawer) -->
<aside class="fixed inset-y-0 left-0 w-72 bg-surface-container-lowest border-r border-outline-variant z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:relative md:translate-x-0 flex flex-col h-full overflow-y-auto custom-scrollbar" id="sidebar">
    
    <!-- Sidebar Header -->
    <div class="px-md py-4 mb-2 flex items-center justify-between border-b border-outline-variant shrink-0 bg-surface-container-lowest">
        <a href="dashboard.php" class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-2xl">account_balance</span>
            <span class="font-headline-md text-primary font-bold">HCS | Admin</span>
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
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-label-md text-on-surface truncate font-semibold"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?></p>
                <p class="text-[11px] text-secondary"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? 'Super Admin')); ?></p>
            </div>
        </div>
        
        <!-- Main Navigation Items with Dropdowns -->
        <div class="space-y-1">
            
            <!-- Dashboard (No Dropdown) -->
            <?php 
            $is_dashboard_active = ($current_page == 'dashboard.php');
            ?>
            <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 <?php echo $is_dashboard_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200">
                <span class="material-symbols-outlined text-xl <?php echo $is_dashboard_active ? 'text-on-secondary-container' : ''; ?>">dashboard</span>
                <span>Dashibodi</span>
                <?php if($is_dashboard_active): ?>
                <span class="ml-auto w-1.5 h-1.5 bg-primary rounded-full"></span>
                <?php endif; ?>
            </a>
            
            <!-- ==================== DROPDOWN: CLAIMS MANAGEMENT ==================== -->
            <?php 
            $is_claims_active = ($current_page == 'claims.php');
            $claims_sub_active = isset($_GET['status']) && in_array($_GET['status'], ['submitted', 'valuation', 'legal_review', 'approved', 'rejected', 'paid']);
            ?>
            <div class="dropdown-container <?php echo ($is_claims_active || $claims_sub_active) ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo ($is_claims_active || $claims_sub_active) ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">description</span>
                        <span>Usimamizi wa Madai</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="claims.php" class="py-2.5 text-sm <?php echo ($is_claims_active && !isset($_GET['status'])) ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📋 Madai Yote
                    </a>
                    <a href="claims.php?status=submitted" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'submitted') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-yellow-500 rounded-full"></span> Imewasilishwa
                    </a>
                    <a href="claims.php?status=valuation" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'valuation') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-orange-500 rounded-full"></span> Tathmini
                    </a>
                    <a href="claims.php?status=legal_review" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'legal_review') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Uhakiki
                    </a>
                    <a href="claims.php?status=approved" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'approved') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-green-500 rounded-full"></span> Imeidhinishwa
                    </a>
                    <a href="claims.php?status=rejected" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'rejected') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-red-500 rounded-full"></span> Imekataliwa
                    </a>
                    <a href="claims.php?status=paid" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'paid') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-emerald-500 rounded-full"></span> Imelipwa
                    </a>
                </div>
            </div>
            
            <!-- New Claim (Separate item) -->
            <?php 
            $is_newclaim_active = ($current_page == 'new-claim.php');
            ?>
            <a href="new-claim.php" class="flex items-center gap-3 px-3 py-2.5 <?php echo $is_newclaim_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200 mt-1">
                <span class="material-symbols-outlined text-xl">add_box</span>
                <span>Wasilisha Dai Jipya</span>
            </a>
            
            <!-- ==================== DROPDOWN: VALUATIONS MANAGEMENT ==================== -->
            <?php 
            $is_valuations_active = ($current_page == 'valuations.php');
            $is_valuations_reports_active = ($current_page == 'valuations-reports.php');
            $valuations_sub_active = ($is_valuations_active || $is_valuations_reports_active || (isset($_GET['status']) && in_array($_GET['status'], ['valuation', 'legal_review'])));
            ?>
            <div class="dropdown-container <?php echo $valuations_sub_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $valuations_sub_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">real_estate_agent</span>
                        <span>Usimamizi wa Tathmini</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="valuations.php" class="py-2.5 text-sm <?php echo ($is_valuations_active && !isset($_GET['status'])) ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📊 Tathmini Zote
                    </a>
                    <a href="valuations.php?status=valuation" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'valuation') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-orange-500 rounded-full"></span> Zinazohitaji Tathmini
                    </a>
                    <a href="valuations.php?status=legal_review" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'legal_review') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Tathmini Zimekamilika
                    </a>
                    <a href="valuations-reports.php" class="py-2.5 text-sm <?php echo $is_valuations_reports_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📈 Ripoti za Tathmini
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: PAYMENTS MANAGEMENT ==================== -->
            <?php 
            $is_payments_active = ($current_page == 'payments.php');
            $is_add_payment_active = ($current_page == 'add-payment.php');
            $payments_sub_active = ($is_payments_active || $is_add_payment_active || (isset($_GET['status']) && in_array($_GET['status'], ['pending', 'processed', 'completed'])));
            ?>
            <div class="dropdown-container <?php echo $payments_sub_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $payments_sub_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">payments</span>
                        <span>Usimamizi wa Malipo</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="payments.php" class="py-2.5 text-sm <?php echo ($is_payments_active && !isset($_GET['status'])) ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        💰 Malipo Yote
                    </a>
                    <a href="add-payment.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_add_payment_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        ➕ Ingiza Malipo
                    </a>
                    <a href="payments.php?status=pending" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-yellow-500 rounded-full"></span> Yanatarajiwa
                    </a>
                    <a href="payments.php?status=processed" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'processed') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-blue-500 rounded-full"></span> Yanachakatwa
                    </a>
                    <a href="payments.php?status=completed" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['status']) && $_GET['status'] == 'completed') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-green-500 rounded-full"></span> Yamekamilika
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: USER MANAGEMENT ==================== -->
            <?php 
            $is_users_active = ($current_page == 'users.php');
            $users_sub_active = isset($_GET['role']) && in_array($_GET['role'], ['claimant', 'valuer', 'legal_officer', 'finance_officer', 'commissioner', 'super_admin']);
            ?>
            <div class="dropdown-container <?php echo ($is_users_active || $users_sub_active) ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo ($is_users_active || $users_sub_active) ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">people</span>
                        <span>Usimamizi wa Watumiaji</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="users.php" class="py-2.5 text-sm <?php echo ($is_users_active && !isset($_GET['role'])) ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        👥 Watumiaji Wote
                    </a>
                    <a href="users.php?role=claimant" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'claimant') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-blue-500 rounded-full"></span> Wadai (Claimants)
                    </a>
                    <a href="users.php?role=valuer" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'valuer') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Wakaguzi (Valuers)
                    </a>
                    <a href="users.php?role=legal_officer" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'legal_officer') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-indigo-500 rounded-full"></span> Wanasheria (Legal Officers)
                    </a>
                    <a href="users.php?role=finance_officer" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'finance_officer') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-emerald-500 rounded-full"></span> Wahasibu (Finance Officers)
                    </a>
                    <a href="users.php?role=commissioner" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'commissioner') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-rose-500 rounded-full"></span> Makamishna (Commissioners)
                    </a>
                    <a href="users.php?role=super_admin" class="py-2.5 text-sm flex items-center gap-2 <?php echo (isset($_GET['role']) && $_GET['role'] == 'super_admin') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="w-2 h-2 bg-red-500 rounded-full"></span> Wasimamizi Wakuu (Super Admins)
                    </a>
                </div>
            </div>
            
            <!-- Add New User (Separate item) -->
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 text-secondary hover:bg-surface-container-high rounded-xl transition-all duration-200 mt-1" onclick="openAddUserModal(); return false;">
                <span class="material-symbols-outlined text-xl">person_add</span>
                <span>Ongeza Mtumiaji</span>
            </a>
            
            <!-- ==================== DROPDOWN: REPORTS ==================== -->
            <?php 
            $is_reports_active = ($current_page == 'reports.php');
            ?>
            <div class="dropdown-container <?php echo $is_reports_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_reports_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">analytics</span>
                        <span>Ripoti na Uchambuzi</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="reports.php?report_type=dashboard" class="py-2.5 text-sm <?php echo ($is_reports_active && isset($_GET['report_type']) && $_GET['report_type'] == 'dashboard') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📊 Dashboard Report
                    </a>
                    <a href="reports.php?report_type=claims_analysis" class="py-2.5 text-sm <?php echo ($is_reports_active && isset($_GET['report_type']) && $_GET['report_type'] == 'claims_analysis') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📋 Uchambuzi wa Madai
                    </a>
                    <a href="reports.php?report_type=financial" class="py-2.5 text-sm <?php echo ($is_reports_active && isset($_GET['report_type']) && $_GET['report_type'] == 'financial') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        💰 Ripoti za Kifedha
                    </a>
                    <a href="reports.php?report_type=performance" class="py-2.5 text-sm <?php echo ($is_reports_active && isset($_GET['report_type']) && $_GET['report_type'] == 'performance') ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        📈 Utendaji na Mwelekeo
                    </a>
                </div>
            </div>
            
            <!-- ==================== DROPDOWN: SYSTEM ADMINISTRATION ==================== -->
            <?php 
            $is_audit_active = ($current_page == 'audit-logs.php');
            $is_help_active = ($current_page == 'help-requests.php');
            $is_documents_active = ($current_page == 'documents.php');
            $is_settings_active = ($current_page == 'settings.php');
            $system_sub_active = ($is_audit_active || $is_help_active || $is_documents_active || $is_settings_active);
            ?>
            <div class="dropdown-container <?php echo $system_sub_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $system_sub_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">admin_panel_settings</span>
                        <span>Usimamizi wa Mfumo</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="audit-logs.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_audit_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">history</span> Rekodi za Shughuli
                    </a>
                    <a href="help-requests.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_help_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">support_agent</span> Maombi ya Msaada
                    </a>
                    <a href="documents.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_documents_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">folder</span> Nyaraka na Hati
                    </a>
                    <a href="settings.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_settings_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">settings</span> Mipangilio ya Mfumo
                    </a>
                </div>
            </div>
            
            <!-- Separator -->
            <div class="border-t border-outline-variant my-3"></div>
            
            <!-- ==================== DROPDOWN: ACCOUNT ==================== -->
            <?php 
            $is_profile_active = ($current_page == 'profile.php');
            ?>
            <div class="dropdown-container <?php echo $is_profile_active ? 'dropdown-open' : ''; ?>">
                <button class="w-full flex items-center justify-between px-3 py-2.5 <?php echo $is_profile_active ? 'bg-secondary-container text-on-secondary-container font-semibold' : 'text-secondary hover:bg-surface-container-high'; ?> rounded-xl transition-all duration-200" onclick="toggleDropdown(this)">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">account_circle</span>
                        <span>Akaunti Yangu</span>
                    </div>
                    <span class="material-symbols-outlined arrow-icon transition-transform duration-200 text-xl">expand_more</span>
                </button>
                <div class="dropdown-content pl-11 flex flex-col gap-1 mt-1 ml-2 border-l-2 border-outline-variant">
                    <a href="profile.php" class="py-2.5 text-sm flex items-center gap-2 <?php echo $is_profile_active ? 'text-primary font-semibold bg-primary-container/15 rounded-lg px-3' : 'text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3'; ?> transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">badge</span> Maelezo ya Akaunti
                    </a>
                    <a href="profile.php?tab=security" class="py-2.5 text-sm flex items-center gap-2 text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">lock</span> Badilisha Nenosiri
                    </a>
                    <a href="profile.php?tab=notifications" class="py-2.5 text-sm flex items-center gap-2 text-secondary hover:text-primary hover:bg-primary-container/10 rounded-lg px-3 transition-all duration-200">
                        <span class="material-symbols-outlined text-sm">notifications</span> Arifa
                    </a>
                </div>
            </div>
            
            <!-- Logout Button -->
            <!-- <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 text-red-600 hover:bg-red-50 rounded-xl transition-all duration-200 mt-2">
                <span class="material-symbols-outlined text-xl">logout</span>
                <span>Ondoka</span>
            </a> -->
            
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
        max-height: 600px; 
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
    .dropdown-content a:hover,
    a:not(.dropdown-container):hover {
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
    
    // Function to open add user modal
    function openAddUserModal() {
        window.location.href = 'users.php?action=add';
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
    
    // Ensure dropdowns stay open when clicking inside
    document.querySelectorAll('.dropdown-content').forEach(content => {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
</script>