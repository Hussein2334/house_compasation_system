<?php
// admin/dashboard.php - Admin Dashboard Main Page
session_start();

// Include database and functions
require_once '../config/db.php';
require_once '../includes/functions.php';

// Set page variables
$page_title = 'Admin Dashboard';
$page_heading = 'Admin Dashboard';

// Include header
require_once __DIR__ . '/includes/admin-header.php';

// Get database connection
$conn = getDB();

// Get statistics
$total_users = 0;
$total_claims = 0;
$pending_claims = 0;
$approved_claims = 0;
$total_compensation = 0;

$users_query = "SELECT COUNT(*) as total FROM users WHERE status = 'active'";
$users_result = mysqli_query($conn, $users_query);
if ($users_result) {
    $total_users = mysqli_fetch_assoc($users_result)['total'];
}

$claims_query = "SELECT COUNT(*) as total FROM claims";
$claims_result = mysqli_query($conn, $claims_query);
if ($claims_result) {
    $total_claims = mysqli_fetch_assoc($claims_result)['total'];
}

$pending_query = "SELECT COUNT(*) as total FROM claims WHERE status IN ('submitted', 'valuation', 'legal_review')";
$pending_result = mysqli_query($conn, $pending_query);
if ($pending_result) {
    $pending_claims = mysqli_fetch_assoc($pending_result)['total'];
}

$approved_query = "SELECT COUNT(*) as total FROM claims WHERE status = 'approved'";
$approved_result = mysqli_query($conn, $approved_query);
if ($approved_result) {
    $approved_claims = mysqli_fetch_assoc($approved_result)['total'];
}

$comp_query = "SELECT SUM(total_compensation) as total FROM valuations";
$comp_result = mysqli_query($conn, $comp_query);
if ($comp_result) {
    $total_compensation = mysqli_fetch_assoc($comp_result)['total'] ?? 0;
}

// Get recent claims
$recent_claims_query = "SELECT c.*, u.full_name as claimant_name 
                        FROM claims c 
                        JOIN users u ON c.claimant_id = u.id 
                        ORDER BY c.created_at DESC 
                        LIMIT 5";
$recent_claims_result = mysqli_query($conn, $recent_claims_query);
$recent_claims = [];
while ($row = mysqli_fetch_assoc($recent_claims_result)) {
    $recent_claims[] = $row;
}

// Welcome message
$welcome_message = '';
if (isset($_SESSION['login_success'])) {
    $welcome_message = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}
?>

<!-- Welcome Section -->
<section class="flex flex-col md:flex-row md:items-end justify-between gap-md">
    <div>
        <h2 class="font-headline-lg text-on-background">Karibu, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
        <p class="text-secondary">Muhtasari wa mfumo wa fidia ya makazi nchini Tanzania.</p>
    </div>
    <div class="hidden md:flex gap-sm">
        <button onclick="exportReport()" class="px-md h-12 bg-white border border-outline-variant text-on-surface font-semibold rounded-lg flex items-center gap-xs hover:bg-surface-container-low transition-colors">
            <span class="material-symbols-outlined">download</span> Pakua Ripoti
        </button>
        <button onclick="window.location.href='new-claim.php'" class="px-md h-12 bg-primary text-white font-semibold rounded-lg flex items-center gap-xs hover:opacity-90 transition-all shadow-sm">
            <span class="material-symbols-outlined">add</span> Mradi Mpya
        </button>
    </div>
</section>

<!-- Stats Cards -->
<section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-md">
    <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
        <div class="flex justify-between items-start">
            <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">people</span>
            <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full">Watumiaji</span>
        </div>
        <div class="mt-md">
            <h3 class="text-headline-md font-bold"><?php echo number_format($total_users); ?></h3>
            <p class="text-secondary text-sm">Watumiaji Wanafanya Kazi</p>
        </div>
    </div>
    
    <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
        <div class="flex justify-between items-start">
            <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">description</span>
            <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full"><?php echo $total_claims > 0 ? round(($approved_claims / $total_claims) * 100) : 0; ?>%</span>
        </div>
        <div class="mt-md">
            <h3 class="text-headline-md font-bold"><?php echo number_format($total_claims); ?></h3>
            <p class="text-secondary text-sm">Jumla ya Madai</p>
        </div>
    </div>
    
    <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
        <div class="flex justify-between items-start">
            <span class="material-symbols-outlined p-2 bg-surface-container-low text-secondary rounded-lg">pending_actions</span>
            <span class="text-secondary text-xs font-bold bg-secondary-container/20 px-2 py-1 rounded-full">Inasubiri</span>
        </div>
        <div class="mt-md">
            <h3 class="text-headline-md font-bold"><?php echo number_format($pending_claims); ?></h3>
            <p class="text-secondary text-sm">Tathmini Inayoendelea</p>
        </div>
    </div>
    
    <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm">
        <div class="flex justify-between items-start">
            <span class="material-symbols-outlined p-2 bg-surface-container-low text-primary rounded-lg">verified</span>
            <span class="text-primary text-xs font-bold bg-primary-container/20 px-2 py-1 rounded-full">Idhinishwa</span>
        </div>
        <div class="mt-md">
            <h3 class="text-headline-md font-bold"><?php echo number_format($approved_claims); ?></h3>
            <p class="text-secondary text-sm">Madai Yaliyothibitishwa</p>
        </div>
    </div>
    
    <div class="p-md bg-secondary-container border border-secondary text-on-secondary-container rounded-xl shadow-sm relative overflow-hidden">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full"></div>
        <div class="flex justify-between items-start">
            <span class="material-symbols-outlined p-2 bg-white/20 text-on-secondary-container rounded-lg">payments</span>
        </div>
        <div class="mt-md">
            <h3 class="text-headline-md font-bold"><?php echo number_format($total_compensation / 1000000000, 1); ?>B</h3>
            <p class="text-on-secondary-container/80 text-sm">Malipo (TZS)</p>
        </div>
    </div>
</section>

<!-- Charts and Map Section -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <div class="lg:col-span-2 p-md bg-white border border-outline-variant rounded-xl shadow-sm">
        <div class="flex items-center justify-between mb-lg">
            <h4 class="font-label-md text-on-surface">Mwenendo wa Madai (Mwezi)</h4>
            <select class="text-xs border-outline-variant rounded bg-surface p-1 outline-none">
                <option>Mwaka <?php echo date('Y'); ?></option>
                <option>Mwaka <?php echo date('Y') - 1; ?></option>
            </select>
        </div>
        <div class="h-64 flex items-end justify-between gap-4 px-2 relative">
            <div class="absolute inset-0 flex flex-col justify-between py-1 opacity-10 pointer-events-none">
                <div class="border-t border-on-surface"></div>
                <div class="border-t border-on-surface"></div>
                <div class="border-t border-on-surface"></div>
                <div class="border-t border-on-surface"></div>
            </div>
            <?php
            $chart_data = [45, 65, 35, 85, 55, 75];
            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun'];
            foreach ($months as $index => $month):
            ?>
            <div class="flex-1 flex flex-col items-center gap-2 group">
                <div class="w-full bg-primary-container/20 rounded-t-lg relative flex items-end h-full">
                    <div class="w-full bg-primary rounded-t-lg transition-all duration-500 h-[<?php echo $chart_data[$index]; ?>%] group-hover:brightness-110"></div>
                </div>
                <span class="text-[10px] text-secondary"><?php echo $month; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Map Section -->
    <div class="p-md bg-white border border-outline-variant rounded-xl shadow-sm flex flex-col">
        <div class="flex items-center justify-between mb-md">
            <h4 class="font-label-md text-on-surface">Maeneo ya Miradi</h4>
            <span class="material-symbols-outlined text-secondary cursor-pointer">map</span>
        </div>
        <div class="flex-1 bg-surface-container-low rounded-lg map-grid relative overflow-hidden border border-outline-variant min-h-[200px]">
            <div class="absolute top-1/4 left-1/3 group cursor-pointer">
                <span class="material-symbols-outlined text-primary text-3xl animate-bounce">location_on</span>
                <div class="hidden group-hover:block absolute top-full left-1/2 -translate-x-1/2 bg-white p-2 rounded shadow-lg text-[10px] z-10 w-24 border border-outline">Mradi wa SGR</div>
            </div>
            <div class="absolute bottom-1/3 right-1/4 group cursor-pointer">
                <span class="material-symbols-outlined text-secondary text-3xl animate-bounce">location_on</span>
                <div class="hidden group-hover:block absolute top-full left-1/2 -translate-x-1/2 bg-white p-2 rounded shadow-lg text-[10px] z-10 w-24 border border-outline">Dar Port Expansion</div>
            </div>
            <div class="absolute top-2/3 left-1/2 group cursor-pointer">
                <span class="material-symbols-outlined text-error text-3xl animate-bounce">location_on</span>
                <div class="hidden group-hover:block absolute top-full left-1/2 -translate-x-1/2 bg-white p-2 rounded shadow-lg text-[10px] z-10 w-24 border border-outline">Dodoma Ring Road</div>
            </div>
        </div>
        <div class="mt-md text-xs text-secondary italic">Ramani inayoonyesha vituo vya miradi kote nchini.</div>
    </div>
</section>

<!-- Recent Claims Table -->
<section class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
    <div class="p-md border-b border-outline-variant flex items-center justify-between">
        <h4 class="font-label-md">Madai ya Hivi Karibuni</h4>
        <a href="claims.php" class="text-primary text-xs font-bold hover:underline">Angalia Yote</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-surface-container-low text-secondary text-xs uppercase">
                <tr>
                    <th class="px-md py-sm">Mhusika</th>
                    <th class="px-md py-sm">Namba ya Dai</th>
                    <th class="px-md py-sm">Mradi</th>
                    <th class="px-md py-sm">Hali</th>
                    <th class="px-md py-sm">Tarehe</th>
                    <th class="px-md py-sm">Hatua</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant text-sm">
                <?php if (empty($recent_claims)): ?>
                <tr>
                    <td colspan="6" class="px-md py-md text-center text-secondary">Hakuna madai bado</td>
                </tr>
                <?php else: ?>
                <?php foreach ($recent_claims as $claim): ?>
                <tr class="hover:bg-surface-container-low transition-colors">
                    <td class="px-md py-md font-semibold"><?php echo htmlspecialchars($claim['claimant_name']); ?></td>
                    <td class="px-md py-md"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                    <td class="px-md py-md"><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                    <td class="px-md py-md">
                        <span class="px-2 py-1 <?php echo getStatusBadgeClass($claim['status']); ?> text-[10px] font-bold rounded">
                            <?php echo getStatusLabel($claim['status']); ?>
                        </span>
                    </td>
                    <td class="px-md py-md"><?php echo formatDateSw($claim['created_at']); ?></td>
                    <td class="px-md py-md">
                        <a href="view-claim.php?id=<?php echo $claim['id']; ?>" class="material-symbols-outlined text-secondary cursor-pointer hover:text-primary">visibility</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Export Report Script -->
<script>
    function exportReport() {
        Swal.fire({
            title: 'Pakua Ripoti',
            text: 'Je, unataka kupakua ripoti ya takwimu zote?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Ripoti Inapakuliwa',
                    text: 'Ripoti yako itaanza kupakua hivi karibuni.',
                    confirmButtonColor: '#006e2c',
                    timer: 2000
                });
            }
        });
    }
    
    // Welcome message with SweetAlert
    <?php if (!empty($welcome_message)): ?>
    Swal.fire({
        icon: 'success',
        title: 'Karibu!',
        text: '<?php echo addslashes($welcome_message); ?>',
        confirmButtonColor: '#006e2c',
        timer: 3000,
        showConfirmButton: true
    });
    <?php endif; ?>
</script>

<style>
    .map-grid { 
        background-image: radial-gradient(#bccab9 1px, transparent 1px); 
        background-size: 20px 20px; 
    }
    .chart-bar {
        transition: height 0.5s ease;
    }
</style>

<?php
// Include footer
require_once __DIR__ . '/includes/admin-footer.php';
?>