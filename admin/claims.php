<?php
// admin/claims.php - Manage All Claims (with fixed dropdown menu)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Claims';
$page_heading = 'Usimamizi wa Madai';

// Get database connection
$conn = getDB();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total claims count for pagination
$count_query = "SELECT COUNT(*) as total FROM claims c 
                JOIN users u ON c.claimant_id = u.id 
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_claims = mysqli_fetch_assoc($count_result)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_claims / $per_page);

// Get claims data
$query = "SELECT c.*, 
                 u.full_name as claimant_name, 
                 u.email, 
                 u.phone,
                 v.total_compensation,
                 v.property_value
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          $where_sql
          ORDER BY $sort_by $sort_order
          LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$claims = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claims[] = $row;
}

// Get status counts for dashboard
$status_counts = [];
$status_query = "SELECT status, COUNT(*) as count FROM claims GROUP BY status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $update_query = "UPDATE claims SET status = ? WHERE id IN ($placeholders)";
        $update_params = array_merge([$action], $selected_ids);
        $update_stmt = mysqli_prepare($conn, $update_query);
        
        $update_types = "s" . str_repeat("i", count($selected_ids));
        mysqli_stmt_bind_param($update_stmt, $update_types, ...$update_params);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $affected = mysqli_stmt_affected_rows($update_stmt);
            $_SESSION['success_message'] = "Madai $affected yamebadilishwa hadi " . getStatusLabel($action);
            
            // Log bulk action
            logAudit($conn, $_SESSION['user_id'], 'BULK_UPDATE_CLAIMS', 'claims', null, null, [
                'action' => $action,
                'count' => $affected,
                'ids' => $selected_ids
            ]);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kubadilisha madai.";
        }
    }
    
    header("Location: claims.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle single claim status update
if (isset($_GET['update_status']) && isset($_GET['claim_id'])) {
    $new_status = $_GET['update_status'];
    $claim_id = intval($_GET['claim_id']);
    
    $old_status_query = "SELECT status FROM claims WHERE id = ?";
    $old_stmt = mysqli_prepare($conn, $old_status_query);
    mysqli_stmt_bind_param($old_stmt, "i", $claim_id);
    mysqli_stmt_execute($old_stmt);
    $old_result = mysqli_stmt_get_result($old_stmt);
    $old_data = mysqli_fetch_assoc($old_result);
    
    $update_stmt = mysqli_prepare($conn, "UPDATE claims SET status = ? WHERE id = ?");
    mysqli_stmt_bind_param($update_stmt, "si", $new_status, $claim_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $_SESSION['success_message'] = "Dai limebadilishwa hadi " . getStatusLabel($new_status);
        logAudit($conn, $_SESSION['user_id'], 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                ['status' => $old_data['status']], 
                ['status' => $new_status]);
    } else {
        $_SESSION['error_message'] = "Hitilafu katika kubadilisha hali ya dai.";
    }
    
    header("Location: claims.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle delete claim
if (isset($_GET['delete']) && isset($_GET['claim_id'])) {
    $claim_id = intval($_GET['claim_id']);
    
    // Check if claim has payments
    $check_payment = mysqli_query($conn, "SELECT id FROM payments WHERE claim_id = $claim_id LIMIT 1");
    if (mysqli_num_rows($check_payment) > 0) {
        $_SESSION['error_message'] = "Huwezi kufuta dai hili kwa sababu tayari lina malipo yaliyofanywa.";
    } else {
        $delete_stmt = mysqli_prepare($conn, "DELETE FROM claims WHERE id = ?");
        mysqli_stmt_bind_param($delete_stmt, "i", $claim_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $_SESSION['success_message'] = "Dai limefutwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'DELETE_CLAIM', 'claims', $claim_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kufuta dai.";
        }
    }
    
    header("Location: claims.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Get success/error messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Include header
require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-badge.submitted { background: #eab30820; color: #854d0e; }
    .status-badge.valuation { background: #f9731620; color: #9a3412; }
    .status-badge.legal_review { background: #8b5cf620; color: #5b21b6; }
    .status-badge.approved { background: #22c55e20; color: #166534; }
    .status-badge.rejected { background: #ef444420; color: #991b1b; }
    .status-badge.paid { background: #10b98120; color: #065f46; }
    .filter-active {
        background-color: #006e2c;
        color: white;
    }
    .filter-active:hover {
        background-color: #005320;
        color: white;
    }
    .table-row:hover {
        background-color: #eef6ea;
    }
    .checkbox-select {
        width: 1rem;
        height: 1rem;
        border-radius: 0.25rem;
        border-color: #bccab9;
        cursor: pointer;
    }
    .checkbox-select:checked {
        background-color: #006e2c;
        border-color: #006e2c;
    }
    
    /* Dropdown Menu Styles - FIXED */
    .action-dropdown {
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 0.5rem;
        width: 220px;
        background-color: white;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.2s ease;
    }
    .action-dropdown.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .action-dropdown a {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 1rem;
        font-size: 0.875rem;
        color: #3d4a3d;
        transition: background-color 0.2s;
        text-decoration: none;
    }
    .action-dropdown a:hover {
        background-color: #eef6ea;
    }
    .action-dropdown hr {
        margin: 0.25rem 0;
        border-color: #bccab9;
    }
    .action-dropdown .text-red-600 {
        color: #ba1a1a;
    }
    .action-dropdown .text-red-600:hover {
        background-color: #ffdad6;
    }
    .action-trigger {
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 0.5rem;
        transition: background-color 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .action-trigger:hover {
        background-color: #eef6ea;
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header with Actions -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Usimamizi wa Madai</h2>
            <p class="text-secondary text-sm mt-1">Simamia, kagua na usindikie madai yote ya fidia</p>
        </div>
        <div class="flex gap-3">
            <a href="new-claim.php" class="px-4 py-2 bg-primary text-white rounded-lg flex items-center gap-2 hover:bg-primary-container transition">
                <span class="material-symbols-outlined text-sm">add</span>
                <span>Dai Jipya</span>
            </a>
            <a href="export-claims.php" class="px-4 py-2 border border-outline-variant rounded-lg flex items-center gap-2 hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">download</span>
                <span>Export</span>
            </a>
        </div>
    </div>
    
    <!-- Status Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-2">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'all' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Zote (<?php echo array_sum($status_counts); ?>)
        </a>
        <a href="?status=submitted&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'submitted' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Imewasilishwa (<?php echo $status_counts['submitted'] ?? 0; ?>)
        </a>
        <a href="?status=valuation&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'valuation' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Tathmini (<?php echo $status_counts['valuation'] ?? 0; ?>)
        </a>
        <a href="?status=legal_review&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'legal_review' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Uhakiki wa Kisheria (<?php echo $status_counts['legal_review'] ?? 0; ?>)
        </a>
        <a href="?status=approved&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'approved' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Imeidhinishwa (<?php echo $status_counts['approved'] ?? 0; ?>)
        </a>
        <a href="?status=rejected&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'rejected' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Imekataliwa (<?php echo $status_counts['rejected'] ?? 0; ?>)
        </a>
        <a href="?status=paid&search=<?php echo urlencode($search_term); ?>" 
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $status_filter === 'paid' ? 'bg-primary text-white' : 'text-secondary hover:bg-surface-container-high'; ?>">
            Imelipwa (<?php echo $status_counts['paid'] ?? 0; ?>)
        </a>
    </div>
    
    <!-- Search and Filter Bar -->
    <div class="flex flex-col md:flex-row gap-4">
        <form method="GET" action="" class="flex-1" id="searchForm">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-xl">search</span>
                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." 
                       class="w-full pl-10 pr-4 py-2 border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
            </div>
        </form>
        
        <div class="flex gap-2">
            <select id="bulk_action_select" class="px-3 py-2 border border-outline rounded-lg bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                <option value="">Bulk Action</option>
                <option value="submitted">Weka kama Imewasilishwa</option>
                <option value="valuation">Weka kama Tathmini</option>
                <option value="legal_review">Weka kama Uhakiki wa Kisheria</option>
                <option value="approved">Weka kama Imeidhinishwa</option>
                <option value="rejected">Weka kama Imekataliwa</option>
                <option value="paid">Weka kama Imelipwa</option>
            </select>
            <button onclick="applyBulkAction()" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">
                Tumia
            </button>
        </div>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <form id="bulk_form" method="POST" action="">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="w-full text-left">
                    <thead class="bg-surface-container-low">
                        <tr class="border-b border-outline-variant">
                            <th class="px-4 py-3 w-10">
                                <input type="checkbox" id="select_all" class="checkbox-select">
                            </th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=claim_number&order=<?php echo $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                    Namba ya Dai
                                    <?php if ($sort_by == 'claim_number'): ?>
                                        <span class="material-symbols-outlined text-sm align-middle"><?php echo $sort_order == 'ASC' ? 'arrow_upward' : 'arrow_downward'; ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">Mwombaji</th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">Mradi</th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=total_compensation&order=<?php echo $sort_by == 'total_compensation' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                    Kiasi (TZS)
                                </a>
                            </th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">Hali</th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase">
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=created_at&order=<?php echo $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                    Tarehe
                                </a>
                            </th>
                            <th class="px-4 py-3 text-xs font-semibold text-secondary uppercase text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant">
                        <?php if (empty($claims)): ?>
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2">inbox</span>
                                <p>Hakuna madai yanayolingana na vigezo vyako</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($claims as $claim): ?>
                        <tr class="table-row transition-colors" data-claim-id="<?php echo $claim['id']; ?>">
                            <td class="px-4 py-3">
                                <input type="checkbox" name="selected_ids[]" value="<?php echo $claim['id']; ?>" class="checkbox-select claim-checkbox">
                            </td>
                            <td class="px-4 py-3 font-mono text-sm font-semibold">
                                <?php echo htmlspecialchars($claim['claim_number']); ?>
                            </td>
                            <td class="px-4 py-3">
                                <div>
                                    <p class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></p>
                                    <p class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></p>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?>
                            </td>
                            <td class="px-4 py-3 font-medium">
                                <?php echo $claim['total_compensation'] ? number_format($claim['total_compensation'], 0) . ' TZS' : '-'; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="status-badge <?php echo $claim['status']; ?>">
                                    <?php echo getStatusLabel($claim['status']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-secondary">
                                <?php echo formatDate($claim['created_at'], 'd M Y'); ?>
                            </td>
                            <td class="px-4 py-3 text-center relative">
                                <button class="action-trigger" data-claim-id="<?php echo $claim['id']; ?>">
                                    <span class="material-symbols-outlined text-secondary">more_vert</span>
                                </button>
                                <div class="action-dropdown" id="dropdown-<?php echo $claim['id']; ?>">
                                    <a href="view-claim.php?id=<?php echo $claim['id']; ?>">
                                        <span class="material-symbols-outlined text-sm">visibility</span>
                                        Angalia
                                    </a>
                                    <a href="edit-claim.php?id=<?php echo $claim['id']; ?>">
                                        <span class="material-symbols-outlined text-sm">edit</span>
                                        Hariri
                                    </a>
                                    <hr>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'submitted')">
                                        <span class="material-symbols-outlined text-sm text-yellow-600">pending</span>
                                        Imewasilishwa
                                    </a>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'valuation')">
                                        <span class="material-symbols-outlined text-sm text-orange-600">real_estate_agent</span>
                                        Tathmini
                                    </a>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'legal_review')">
                                        <span class="material-symbols-outlined text-sm text-purple-600">gavel</span>
                                        Uhakiki wa Kisheria
                                    </a>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'approved')">
                                        <span class="material-symbols-outlined text-sm text-green-600">verified</span>
                                        Idhinisha
                                    </a>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'rejected')">
                                        <span class="material-symbols-outlined text-sm text-red-600">cancel</span>
                                        Kataa
                                    </a>
                                    <a href="javascript:void(0)" onclick="updateClaimStatus(<?php echo $claim['id']; ?>, 'paid')">
                                        <span class="material-symbols-outlined text-sm text-emerald-600">payments</span>
                                        Lipa
                                    </a>
                                    <hr>
                                    <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $claim['id']; ?>)" class="text-red-600">
                                        <span class="material-symbols-outlined text-sm">delete</span>
                                        Futa
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-outline-variant">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" 
                   class="px-3 py-1 border border-outline-variant rounded-lg hover:bg-surface-container-low transition">
                    Awali
                </a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" 
                   class="px-3 py-1 border border-outline-variant rounded-lg transition <?php echo $i == $page ? 'bg-primary text-white' : 'hover:bg-surface-container-low'; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" 
                   class="px-3 py-1 border border-outline-variant rounded-lg hover:bg-surface-container-low transition">
                    Inayofuata
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Track currently open dropdown
    let currentOpenDropdown = null;
    
    // Toggle dropdown menu - FIXED
    function toggleDropdown(claimId, event) {
        event.stopPropagation();
        
        const dropdown = document.getElementById(`dropdown-${claimId}`);
        if (!dropdown) return;
        
        // Close any open dropdown
        if (currentOpenDropdown && currentOpenDropdown !== dropdown) {
            currentOpenDropdown.classList.remove('show');
        }
        
        // Toggle current dropdown
        dropdown.classList.toggle('show');
        currentOpenDropdown = dropdown.classList.contains('show') ? dropdown : null;
    }
    
    // Close all dropdowns
    function closeAllDropdowns() {
        if (currentOpenDropdown) {
            currentOpenDropdown.classList.remove('show');
            currentOpenDropdown = null;
        }
    }
    
    // Setup action triggers
    document.querySelectorAll('.action-trigger').forEach(trigger => {
        trigger.addEventListener('click', function(event) {
            event.stopPropagation();
            const claimId = this.getAttribute('data-claim-id');
            const dropdown = document.getElementById(`dropdown-${claimId}`);
            
            if (dropdown) {
                // Close any open dropdown
                if (currentOpenDropdown && currentOpenDropdown !== dropdown) {
                    currentOpenDropdown.classList.remove('show');
                }
                
                // Toggle current dropdown
                dropdown.classList.toggle('show');
                currentOpenDropdown = dropdown.classList.contains('show') ? dropdown : null;
            }
        });
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        // Check if click is inside a dropdown or on trigger button
        const isInsideDropdown = event.target.closest('.action-dropdown');
        const isTrigger = event.target.closest('.action-trigger');
        
        if (!isInsideDropdown && !isTrigger) {
            closeAllDropdowns();
        }
    });
    
    // Update claim status with AJAX (no page reload)
    function updateClaimStatus(claimId, status) {
        // Close dropdown
        closeAllDropdowns();
        
        // Show confirmation
        Swal.fire({
            title: 'Thibitisha',
            text: `Je, una uhakika unataka kubadilisha hali ya dai hili?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Badilisha',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Inabadilisha...',
                    text: 'Tafadhali subiri',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Make AJAX request
                fetch(window.location.href + `&update_status=${status}&claim_id=${claimId}`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.text())
                .then(() => {
                    window.location.reload();
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Hitilafu',
                        text: 'Kuna tatizo katika kubadilisha hali. Jaribu tena.',
                        confirmButtonColor: '#006e2c'
                    });
                });
            }
        });
    }
    
    // Select all checkboxes
    const selectAllCheckbox = document.getElementById('select_all');
    const claimCheckboxes = document.querySelectorAll('.claim-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            claimCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }
    
    // Apply bulk action
    function applyBulkAction() {
        const selected = document.querySelectorAll('.claim-checkbox:checked');
        const action = document.getElementById('bulk_action_select').value;
        
        if (selected.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Hakuna Madai Yaliyochaguliwa',
                text: 'Tafadhali chagua angalau dai moja.',
                confirmButtonColor: '#006e2c'
            });
            return;
        }
        
        if (!action) {
            Swal.fire({
                icon: 'warning',
                title: 'Chagua Kitendo',
                text: 'Tafadhali chagua kitendo cha kufanya kwa madai yaliyochaguliwa.',
                confirmButtonColor: '#006e2c'
            });
            return;
        }
        
        Swal.fire({
            title: 'Thibitisha',
            html: `Je, una uhakika unataka kubadilisha madai <strong>${selected.length}</strong> hadi <strong>${getStatusLabelFromKey(action)}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Badilisha',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('bulk_action_value').value = action;
                document.getElementById('bulk_form').submit();
            }
        });
    }
    
    // Helper function to get status label
    function getStatusLabelFromKey(key) {
        const labels = {
            'submitted': 'Imewasilishwa',
            'valuation': 'Tathmini',
            'legal_review': 'Uhakiki wa Kisheria',
            'approved': 'Imeidhinishwa',
            'rejected': 'Imekataliwa',
            'paid': 'Imelipwa'
        };
        return labels[key] || key;
    }
    
    // Confirm delete
    function confirmDelete(claimId) {
        closeAllDropdowns();
        
        Swal.fire({
            title: 'Futa Dai?',
            text: 'Je, una uhakika unataka kufuta dai hili? Hatua hii haiwezi kutenduliwa.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana, Ghairi'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?delete=1&claim_id=${claimId}&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page; ?>`;
            }
        });
    }
    
    // Search with debounce
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('searchForm').submit();
            }, 500);
        });
    }
    
    // Show success/error messages
    <?php if (!empty($success_message)): ?>
    Swal.fire({
        icon: 'success',
        title: 'Mafanikio!',
        text: '<?php echo addslashes($success_message); ?>',
        confirmButtonColor: '#006e2c',
        timer: 3000
    });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Hitilafu!',
        text: '<?php echo addslashes($error_message); ?>',
        confirmButtonColor: '#006e2c'
    });
    <?php endif; ?>
</script>

<?php
// Include footer
require_once __DIR__ . '/includes/admin-footer.php';
?>