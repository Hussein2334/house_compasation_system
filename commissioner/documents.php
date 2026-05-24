<?php
// commissioner/documents.php - Commissioner Documents Overview (FIXED)
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is commissioner
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Documents Overview';
$page_heading = 'Usimamizi wa Nyaraka';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$claim_filter = $_GET['claim_id'] ?? '';
$search_term = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$where_clauses = [];
$params = [];
$types = "";

if (!empty($claim_filter)) {
    $where_clauses[] = "d.claim_id = ?";
    $params[] = $claim_filter;
    $types .= "i";
}

if (!empty($date_from) && !empty($date_to)) {
    $where_clauses[] = "DATE(d.uploaded_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

if (!empty($search_term)) {
    $where_clauses[] = "(d.document_name LIKE ? OR c.claim_number LIKE ? OR u.full_name LIKE ? OR c.project_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total documents count
$count_query = "SELECT COUNT(*) as total 
                FROM documents d
                JOIN claims c ON d.claim_id = c.id
                JOIN users u ON c.claimant_id = u.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_documents = mysqli_fetch_assoc($count_result)['total'];

// Pagination - 15 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_documents / $per_page);

// Get documents data
$query = "SELECT d.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          u.full_name as claimant_name, u.email, u.phone
          FROM documents d
          JOIN claims c ON d.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          $where_sql
          ORDER BY d.uploaded_at DESC
          LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$documents = [];
while ($row = mysqli_fetch_assoc($result)) {
    $documents[] = $row;
}

// Get all claims for filter dropdown
$claims_query = "SELECT id, claim_number, project_name FROM claims ORDER BY created_at DESC LIMIT 100";
$claims_result = mysqli_query($conn, $claims_query);
$claims_list = [];
while ($row = mysqli_fetch_assoc($claims_result)) {
    $claims_list[] = $row;
}

// Get summary statistics
$summary_query = "SELECT 
    COUNT(*) as total_documents,
    COUNT(DISTINCT d.claim_id) as total_claims_with_docs
    FROM documents d";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

require_once __DIR__ . '/includes/commissioner-header.php';
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
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e2a1e;
    }
    .stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #6d7b6c;
        font-weight: 600;
        margin-top: 0.25rem;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .status-submitted { background: #e9d5ff; color: #6b21a5; }
    .status-valuation { background: #fed7aa; color: #9a3412; }
    .status-legal_review { background: #cffafe; color: #0891b2; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .status-paid { background: #d1fae5; color: #006e2c; }
    
    .filter-section {
        background: white;
        border-radius: 0.75rem;
        border: 1px solid #e8f0e4;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .documents-table {
        width: 100%;
        border-collapse: collapse;
    }
    .documents-table th {
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 1px solid #bccab9;
    }
    .documents-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .documents-table tr:hover {
        background-color: #f4fcef;
    }
    
    .search-input, .form-input, .form-select {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        width: 100%;
    }
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 2px rgba(0,110,44,0.1);
    }
    
    .btn-primary {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
    }
    .btn-primary:hover {
        background-color: #005a24;
    }
    .btn-outline {
        background-color: white;
        color: #3d4a3d;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #bccab9;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        text-decoration: none;
    }
    .btn-outline:hover {
        background-color: #eef6ea;
    }
    
    .pagination {
        display: flex;
        gap: 0.25rem;
        justify-content: center;
        margin-top: 1rem;
    }
    .pagination-btn {
        padding: 0.375rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        text-decoration: none;
        color: #3d4a3d;
        background: white;
    }
    .pagination-btn.active {
        background-color: #006e2c;
        color: white;
        border-color: #006e2c;
    }
    
    .action-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 0.5rem;
        color: #6d7b6c;
    }
    .action-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .documents-table {
            min-width: 700px;
        }
        .table-container {
            overflow-x: auto;
        }
        .filter-row {
            flex-direction: column;
        }
        .date-range {
            flex-direction: column;
        }
    }
</style>

<div class="space-y-4">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Usimamizi wa Nyaraka</h2>
            <p class="text-secondary text-xs">Angalia nyaraka zote za madai</p>
        </div>
        <div class="flex gap-2">
            <a href="reports.php?type=claims" class="btn-outline">
                <span class="material-symbols-outlined text-sm">analytics</span>
                Ripoti za Kina
            </a>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($summary['total_documents'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Nyaraka</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($summary['total_claims_with_docs'] ?? 0); ?></div>
            <div class="stat-label">Madai Yenye Nyaraka</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($total_documents); ?></div>
            <div class="stat-label">Kwenye Ukurasa Huu</div>
        </div>
    </div>
    
    <div class="filter-section">
        <form method="GET" action="" class="space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 filter-row">
                <div>
                    <select name="claim_id" class="form-select">
                        <option value="">-- Dai Zote --</option>
                        <?php foreach ($claims_list as $claim): ?>
                        <option value="<?php echo $claim['id']; ?>" <?php echo $claim_filter == $claim['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($claim['claim_number'] . ' - ' . $claim['project_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <input type="text" name="search" class="search-input" placeholder="Tafuta kwa jina la hati, namba ya dai, jina la mwombaji au mradi..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary flex-1">Tafuta</button>
                    <a href="documents.php" class="btn-outline">Reset</a>
                </div>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 date-range">
                <div>
                    <label class="text-xs text-secondary">Kuanzia Tarehe</label>
                    <input type="date" name="date_from" class="form-input" value="<?php echo $date_from; ?>">
                </div>
                <div>
                    <label class="text-xs text-secondary">Mpaka Tarehe</label>
                    <input type="date" name="date_to" class="form-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">Chuja kwa Tarehe</button>
                </div>
            </div>
        </form>
    </div>
    
    <div class="bg-white border rounded-lg overflow-hidden">
        <div class="table-container overflow-x-auto">
            <table class="documents-table">
                <thead>
                    <tr>
                        <th>Jina la Hati</th>
                        <th>Namba ya Dai</th>
                        <th>Mwombaji</th>
                        <th>Mradi</th>
                        <th>Hali ya Dai</th>
                        <th>Tarehe ya Kupakia</th>
                        <th class="text-center">Pakua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">folder_open</span>
                            Hakuna nyaraka zinazoendana na vigezo vyako
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary">description</span>
                                <span class="font-medium"><?php echo htmlspecialchars($doc['document_name']); ?></span>
                            </div>
                        </td>
                        <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($doc['claim_number']); ?></td>
                        <td>
                            <div class="font-medium"><?php echo htmlspecialchars($doc['claimant_name']); ?></div>
                            <div class="text-xs text-secondary"><?php echo htmlspecialchars($doc['email']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($doc['project_name'] ?? '-'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $doc['claim_status']; ?>">
                                <?php 
                                $status_labels = [
                                    'submitted' => 'Yaliyowasilishwa',
                                    'valuation' => 'Tathmini',
                                    'legal_review' => 'Uhakiki',
                                    'approved' => 'Imeidhinishwa',
                                    'rejected' => 'Imekataliwa',
                                    'paid' => 'Imelipwa'
                                ];
                                echo $status_labels[$doc['claim_status']] ?? ucfirst($doc['claim_status']);
                                ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?></td>
                        <td class="text-center">
                            <a href="download-document.php?id=<?php echo $doc['id']; ?>" class="action-btn" title="Pakua Hati">
                                <span class="material-symbols-outlined text-primary">download</span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between px-4 py-3 border-t gap-2">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_documents); ?> kati ya <?php echo $total_documents; ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">«</a>
                <?php endif; ?>
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">»</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <div>
                <p class="font-semibold text-sm">Maelekezo</p>
                <p class="text-xs mt-1">Unaweza kuona nyaraka zote za mfumo, kuchuja kwa dai, tarehe, na kutafuta. Bonyeza ikoni ya kupakua ili kupakua hati.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/commissioner-footer.php'; ?>