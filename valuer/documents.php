<?php
// valuer/claims.php - View Claims for Valuation
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// ========== AJAX HANDLER FOR GETTING CLAIM DETAILS ==========
// MUST BE AT THE VERY TOP BEFORE ANY OUTPUT
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $conn = getDB();
    $claim_id = intval($_GET['claim_id']);
    
    $query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin
              FROM claims c 
              JOIN users u ON c.claimant_id = u.id 
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claim = mysqli_fetch_assoc($result);
    
    if ($claim) {
        echo json_encode(['success' => true, 'data' => $claim]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Claim not found']);
    }
    exit();
}

// Check if user is logged in and is valuer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Claims for Valuation';
$page_heading = 'Madai Yanayohitaji Tathmini';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query - Show claims with status 'valuation' that have NO valuation yet
$where_clauses = ["c.status = 'valuation'"];
$params = [];
$types = "";

// Exclude claims that already have valuations
$where_clauses[] = "NOT EXISTS (SELECT 1 FROM valuations v WHERE v.claim_id = c.id)";

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR c.project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Get total claims count
$count_query = "SELECT COUNT(*) as total 
                FROM claims c 
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
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = $total_claims > 0 ? ceil($total_claims / $per_page) : 1;

// Get claims data
$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
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

// Get statistics
$total_pending = $total_claims;
$my_valuations_count = 0;
$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) as count FROM valuations WHERE valuer_id = ?");
mysqli_stmt_bind_param($count_stmt, "i", $user_id);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$my_valuations_count = mysqli_fetch_assoc($count_result)['count'];

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/valuer-header.php';
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid #e8f0e4;
        text-align: center;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #006e2c;
    }
    .stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    
    .claims-table {
        width: 100%;
        border-collapse: collapse;
    }
    .claims-table th {
        padding: 0.6rem 0.75rem;
        text-align: left;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .claims-table td {
        padding: 0.6rem 0.75rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.8rem;
    }
    .claims-table tr:hover {
        background-color: #f4fcef;
    }
    
    .action-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.3rem;
        border-radius: 0.5rem;
        color: #6d7b6c;
        transition: all 0.2s;
    }
    .action-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    .filter-bar {
        background: white;
        border-radius: 0.75rem;
        padding: 0.75rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1rem;
    }
    .search-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        width: 100%;
    }
    .btn-search {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
    }
    .btn-outline {
        background-color: white;
        color: #3d4a3d;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    
    .pagination-btn {
        padding: 0.3rem 0.6rem;
        border: 1px solid #bccab9;
        border-radius: 0.4rem;
        font-size: 0.7rem;
        text-decoration: none;
        color: #3d4a3d;
        background: white;
    }
    .pagination-btn.active {
        background-color: #006e2c;
        color: white;
        border-color: #006e2c;
    }
    .pagination-btn:hover:not(.active) {
        background-color: #eef6ea;
    }
    
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    .modal-container {
        background: white;
        border-radius: 0.75rem;
        width: 90%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
    }
    .modal-header {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f4fcef;
        position: sticky;
        top: 0;
    }
    .modal-body {
        padding: 1rem;
    }
    .modal-footer {
        padding: 0.75rem 1rem;
        border-top: 1px solid #e8f0e4;
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        background: white;
    }
    
    .form-group {
        margin-bottom: 0.75rem;
    }
    .form-label {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.2rem;
    }
    .form-control, .form-select, .form-textarea {
        width: 100%;
        padding: 0.5rem 0.7rem;
        border: 1px solid #bccab9;
        border-radius: 0.4rem;
        font-size: 0.8rem;
    }
    .form-control:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 2px rgba(0,110,44,0.1);
    }
    
    .total-box {
        background: #f4fcef;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        padding: 0.5rem;
        margin-top: 0.75rem;
        text-align: center;
    }
    .total-box .amount {
        font-size: 1.2rem;
        font-weight: 700;
        color: #006e2c;
    }
    
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .info-box {
        background: #f4fcef;
        padding: 0.75rem;
        border-radius: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .info-row {
        display: flex;
        padding: 0.25rem 0;
        font-size: 0.75rem;
    }
    .info-label {
        width: 35%;
        font-weight: 600;
        color: #3d4a3d;
    }
    .info-value {
        width: 65%;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .grid-2 {
            grid-template-columns: 1fr;
        }
        .claims-table {
            min-width: 600px;
        }
        .table-container {
            overflow-x: auto;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-4">
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-green-800 text-sm">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">check_circle</span>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-red-800 text-sm">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">error</span>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Madai Yanayohitaji Tathmini</h2>
            <p class="text-secondary text-xs">Kagua na ufanye tathmini kwa madai yaliyowasilishwa</p>
        </div>
        <div>
            <a href="valuations.php" class="btn-outline inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">real_estate_agent</span>
                Tathmini Zangu
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($total_pending); ?></div>
            <div class="stat-label">Madai Yanayosubiri</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($my_valuations_count); ?></div>
            <div class="stat-label">Tathmini Zangu</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($total_claims); ?></div>
            <div class="stat-label">Zinazohitaji Ukaguzi</div>
        </div>
    </div>
    
    <!-- Search Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-2">
            <div class="flex-1">
                <input type="text" name="search" class="search-input" placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-search">Tafuta</button>
                <a href="claims.php" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Claims Table -->
    <div class="bg-white border border-outline-variant rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="claims-table">
                <thead>
                    <tr>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Aina ya Mali</th>
                        <th>Wilaya</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-6 text-secondary">
                            <span class="material-symbols-outlined text-4xl mb-1 block">check_circle</span>
                            Hakuna madai yanayohitaji tathmini kwa sasa
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($claims as $claim): ?>
                    <tr>
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($claim['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($claim['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($claim['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($claim['project_name'] ?? '-'); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $claim['property_type'] ?? '-')); ?></td>
                        <td><?php echo htmlspecialchars($claim['district'] ?? '-'); ?></td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y', strtotime($claim['created_at'])); ?></td>
                        <td class="text-center">
                            <button type="button" class="action-btn" onclick="startValuation(<?php echo $claim['id']; ?>)" title="Fanya Tathmini">
                                <span class="material-symbols-outlined text-primary">real_estate_agent</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-3 py-2 border-t gap-2">
            <div class="text-xs text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_claims); ?> kati ya <?php echo $total_claims; ?>
            </div>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_term); ?>" class="pagination-btn">«</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_term); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_term); ?>" class="pagination-btn">»</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <div>
                <p class="font-semibold text-sm">Maelekezo ya Tathmini</p>
                <ul class="text-xs mt-1 space-y-1 list-disc list-inside">
                    <li>Kagua taarifa zote za mwombaji na nyaraka za mali</li>
                    <li>Thamini mali kwa kutumia thamani ya soko na kanuni za serikali</li>
                    <li>Jaza thamani ya mali, posho ya usumbufu, na posho ya usafiri</li>
                    <li>Toa maelezo ya kina katika ripoti ya tathmini</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Valuation Modal -->
<div id="valuationModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="font-semibold">Fanya Tathmini ya Mali</h3>
            <button onclick="closeValuationModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="valuationForm" method="POST" action="process-valuation.php">
            <input type="hidden" id="valuation_claim_id" name="claim_id">
            <div class="modal-body">
                <!-- Claim Information Summary -->
                <div class="info-box">
                    <div class="info-row">
                        <div class="info-label">Namba ya Dai:</div>
                        <div class="info-value font-mono" id="view_claim_number">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Mwombaji:</div>
                        <div class="info-value" id="view_claimant_name">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Mradi:</div>
                        <div class="info-value" id="view_project_name">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Aina ya Mali:</div>
                        <div class="info-value" id="view_property_type">-</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Wilaya:</div>
                        <div class="info-value" id="view_district">-</div>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label required">Thamani ya Mali (TZS)</label>
                        <input type="number" name="property_value" id="property_value" class="form-control" step="1000" value="0" oninput="calculateTotal()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usumbufu (TZS)</label>
                        <input type="number" name="disturbance_allowance" id="disturbance_allowance" class="form-control" step="1000" value="0" oninput="calculateTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Posho ya Usafiri (TZS)</label>
                        <input type="number" name="transport_allowance" id="transport_allowance" class="form-control" step="1000" value="0" oninput="calculateTotal()">
                    </div>
                </div>
                
                <div class="total-box">
                    <div class="label">Jumla ya Fidia</div>
                    <div class="amount" id="total_display">TZS 0</div>
                    <input type="hidden" name="total_compensation" id="total_value" value="0">
                </div>
                
                <div class="form-group mt-3">
                    <label class="form-label">Ripoti ya Tathmini</label>
                    <textarea name="valuation_report" id="valuation_report" rows="3" class="form-textarea" placeholder="Eleza mbinu ya tathmini, vigezo vilivyotumika..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeValuationModal()" class="btn-outline">Ghairi</button>
                <button type="submit" class="btn-search">Wasilisha</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentClaimId = null;
    
    function calculateTotal() {
        let property = parseFloat(document.getElementById('property_value').value) || 0;
        let disturbance = parseFloat(document.getElementById('disturbance_allowance').value) || 0;
        let transport = parseFloat(document.getElementById('transport_allowance').value) || 0;
        let total = property + disturbance + transport;
        document.getElementById('total_value').value = total;
        document.getElementById('total_display').innerHTML = 'TZS ' + total.toLocaleString();
    }
    
    async function startValuation(claimId) {
        currentClaimId = claimId;
        const modal = document.getElementById('valuationModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const claim = data.data;
                document.getElementById('valuation_claim_id').value = claim.id;
                document.getElementById('view_claim_number').innerHTML = claim.claim_number;
                document.getElementById('view_claimant_name').innerHTML = claim.claimant_name;
                document.getElementById('view_project_name').innerHTML = claim.project_name || '-';
                document.getElementById('view_property_type').innerHTML = claim.property_type || '-';
                document.getElementById('view_district').innerHTML = claim.district || '-';
                
                document.getElementById('property_value').value = 0;
                document.getElementById('disturbance_allowance').value = 0;
                document.getElementById('transport_allowance').value = 0;
                document.getElementById('valuation_report').value = '';
                calculateTotal();
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: data.message || 'Haikuweza kupata taarifa' });
                closeValuationModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao. Tafadhali jaribu tena.' });
            closeValuationModal();
        }
    }
    
    function closeValuationModal() {
        const modal = document.getElementById('valuationModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Form validation
    const valuationForm = document.getElementById('valuationForm');
    if (valuationForm) {
        valuationForm.addEventListener('submit', function(e) {
            const propertyValue = document.getElementById('property_value').value;
            if (!propertyValue || parseFloat(propertyValue) <= 0) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tafadhali ingiza thamani ya mali' });
                return false;
            }
            e.preventDefault();
            Swal.fire({
                title: 'Thibitisha',
                html: `Je, una uhakika unataka kuwasilisha tathmini hii?<br><strong>Jumla: TZS ${parseFloat(document.getElementById('total_value').value || 0).toLocaleString()}</strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ndiyo',
                cancelButtonText: 'Hapana'
            }).then((result) => {
                if (result.isConfirmed) valuationForm.submit();
            });
            return false;
        });
    }
    
    // Search debounce
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = searchInput.closest('form');
                if (form) form.submit();
            }, 500);
        });
    }
    
    document.getElementById('valuationModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeValuationModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/valuer-footer.php'; ?>