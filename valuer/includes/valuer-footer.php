<?php
// valuer/includes/valuer-footer.php - Valuer Footer Component
?>

    </main>
    
    <!-- Valuer Footer (Desktop) -->
    <footer class="hidden md:block bg-surface-container-high py-3 border-t border-outline-variant">
        <div class="max-w-full mx-auto px-md flex flex-col md:flex-row justify-between items-center gap-2">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-sm">copyright</span>
                <p class="text-xs text-on-surface-variant">&copy; <?php echo date('Y'); ?> House Compensation System (HCS). All rights reserved.</p>
            </div>
            <div class="flex gap-4">
                <a href="../privacy.php" class="text-xs text-on-surface-variant hover:text-primary transition-colors">Privacy Policy</a>
                <a href="../terms.php" class="text-xs text-on-surface-variant hover:text-primary transition-colors">Terms of Service</a>
                <a href="help.php" class="text-xs text-on-surface-variant hover:text-primary transition-colors">Contact Support</a>
            </div>
        </div>
    </footer>
    
    <!-- Mobile Bottom Navigation -->
    <nav class="bottom-nav fixed bottom-0 left-0 right-0 bg-white border-t border-outline-variant flex md:hidden items-center justify-around px-2 py-1 shadow-lg z-50" style="padding-bottom: env(safe-area-inset-bottom, 0.5rem);">
        <a href="dashboard.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined <?php echo ($current_page == 'dashboard.php') ? 'text-primary' : 'text-on-surface-variant'; ?> text-2xl" <?php echo ($current_page == 'dashboard.php') ? 'style="font-variation-settings: \'FILL\' 1;"' : ''; ?>>
                dashboard
            </span>
            <span class="font-label-sm text-label-sm <?php echo ($current_page == 'dashboard.php') ? 'text-primary font-bold' : 'text-on-surface-variant'; ?> text-xs">
                Mwanzo
            </span>
        </a>
        
        <a href="valuations.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">real_estate_agent</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Tathmini</span>
        </a>
        
        <div class="-mt-8">
            <a href="valuations.php?status=valuation" class="w-14 h-14 bg-primary text-white rounded-full shadow-lg flex items-center justify-center active:scale-95 transition-transform">
                <span class="material-symbols-outlined text-3xl">add</span>
            </a>
        </div>
        
        <a href="documents.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">folder</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Nyaraka</span>
        </a>
        
        <a href="profile.php" class="bottom-nav-item flex flex-col items-center justify-center py-1 px-3 rounded-lg active:bg-surface-container transition-all">
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">person</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant text-xs">Akaunti</span>
        </a>
    </nav>
</div>

<!-- Global JavaScript -->
<script>
    // Toggle sidebar on mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
        }
        if (overlay) {
            overlay.classList.toggle('hidden');
        }
    }
    
    // Toggle user menu
    function toggleUserMenu() {
        const menu = document.getElementById('userMenu');
        if (menu) {
            menu.classList.toggle('hidden');
        }
    }
    
    // Show notifications
    function showNotifications() {
        window.location.href = 'notifications.php';
    }
    
    // Close user menu when clicking outside
    document.addEventListener('click', function(event) {
        const userMenu = document.getElementById('userMenu');
        const userButton = event.target.closest('[onclick*="toggleUserMenu"]');
        const isInsideUserMenu = userMenu && userMenu.contains(event.target);
        
        if (!userButton && !isInsideUserMenu && userMenu && !userMenu.classList.contains('hidden')) {
            userMenu.classList.add('hidden');
        }
    });
    
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
            sidebar.classList.remove('-translate-x-full');
            if (overlay) overlay.classList.add('hidden');
        }
    });
    
    // Active bottom nav highlight
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        const currentFile = currentPath.split('/').pop();
        const bottomNavLinks = document.querySelectorAll('.bottom-nav-item');
        
        bottomNavLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentFile) {
                const icon = link.querySelector('.material-symbols-outlined:first-child');
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
    });
</script>
</body>
</html>