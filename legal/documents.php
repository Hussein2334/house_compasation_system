<?php
// legal/documents.php - Manage Legal Documents
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is legal officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'legal_officer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Legal Documents Management';
$page_heading = 'Usimamizi wa Nyaraka za Kisheria';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];
$is_super_admin = ($_SESSION['role'] === 'super_admin');

// Get filter parameters
$claim_filter = $_GET['claim_id'] ?? '';
$search_term = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if (!empty($claim_filter)) {
    $where_clauses[] = "d.claim_id = ?";
    $params[] = $claim_filter;
    $types .= "i";
}

if (!empty($search_term)) {
    $where_clauses[] = "(d.document_name LIKE ? OR c.claim_number LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
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
$query = "SELECT d.*, c.claim_number, c.project_name, u.full_name as claimant_name,
          c.status as claim_status
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
$claims_query = "SELECT id, claim_number, project_name FROM claims ORDER BY created_at DESC";
$claims_result = mysqli_query($conn, $claims_query);
$claims_list = [];
while ($row = mysqli_fetch_assoc($claims_result)) {
    $claims_list[] = $row;
}

// Handle file upload via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file']) && isset($_POST['claim_id'])) {
    header('Content-Type: application/json');
    
    $claim_id = intval($_POST['claim_id']);
    $document_name = mysqli_real_escape_string($conn, $_POST['document_name'] ?? '');
    
    if (empty($document_name)) {
        echo json_encode(['success' => false, 'message' => 'Tafadhali jina la hati']);
        exit();
    }
    
    // Check if claim exists
    $check_claim = "SELECT id, claim_number FROM claims WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_claim);
    mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
    mysqli_stmt_execute($check_stmt);
    $claim_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));
    
    if (!$claim_exists) {
        echo json_encode(['success' => false, 'message' => 'Dai halijapatikana']);
        exit();
    }
    
    // Handle file upload
    $upload_dir = '../uploads/documents/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file = $_FILES['document_file'];
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = time() . '_' . $claim_id . '_' . rand(1000, 9999) . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;
    
    // Allowed file types
    $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
    
    if (!in_array(strtolower($file_extension), $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Aina ya faili hairuhusiwi. Ruhusiwa: ' . implode(', ', $allowed_types)]);
        exit();
    }
    
    // Check file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Faili kubwa sana. Kiwango cha juu ni 10MB']);
        exit();
    }
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $insert_query = "INSERT INTO documents (claim_id, document_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "iss", $claim_id, $document_name, $file_path);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            $doc_id = mysqli_insert_id($conn);
            
            // Add notification for claimant
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                           SELECT c.claimant_id, 'Hati Imepakiwa', 
                           CONCAT('Hati mpya imepakiwa kwenye dai lako namba ', c.claim_number, ': ', ?),
                           'document', NOW()
                           FROM claims c WHERE c.id = ?";
            $notif_stmt = mysqli_prepare($conn, $notif_query);
            mysqli_stmt_bind_param($notif_stmt, "si", $document_name, $claim_id);
            mysqli_stmt_execute($notif_stmt);
            
            logAudit($conn, $user_id, 'UPLOAD_DOCUMENT', 'documents', $doc_id, [], ['claim_id' => $claim_id, 'document_name' => $document_name]);
            
            echo json_encode(['success' => true, 'message' => 'Hati imepakiwa kikamilifu']);
        } else {
            unlink($file_path);
            echo json_encode(['success' => false, 'message' => 'Hitilafu katika kuhifadhi taarifa za hati']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Hitilafu katika kupakia faili']);
    }
    exit();
}

// Handle document deletion
if (isset($_GET['delete_document']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $doc_id = intval($_GET['id']);
    
    // Get file path
    $select_query = "SELECT file_path, claim_id FROM documents WHERE id = ?";
    $select_stmt = mysqli_prepare($conn, $select_query);
    mysqli_stmt_bind_param($select_stmt, "i", $doc_id);
    mysqli_stmt_execute($select_stmt);
    $doc = mysqli_fetch_assoc(mysqli_stmt_get_result($select_stmt));
    
    if ($doc) {
        // Delete file from server
        if (file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }
        
        // Delete from database
        $delete_query = "DELETE FROM documents WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $doc_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            logAudit($conn, $user_id, 'DELETE_DOCUMENT', 'documents', $doc_id, [], ['claim_id' => $doc['claim_id']]);
            echo json_encode(['success' => true, 'message' => 'Hati imefutwa kikamilifu']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Hitilafu katika kufuta hati']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Hati haikupatikana']);
    }
    exit();
}

// Handle document download
if (isset($_GET['download_document']) && isset($_GET['id'])) {
    $doc_id = intval($_GET['id']);
    
    $select_query = "SELECT file_path, document_name FROM documents WHERE id = ?";
    $select_stmt = mysqli_prepare($conn, $select_query);
    mysqli_stmt_bind_param($select_stmt, "i", $doc_id);
    mysqli_stmt_execute($select_stmt);
    $doc = mysqli_fetch_assoc(mysqli_stmt_get_result($select_stmt));
    
    if ($doc && file_exists($doc['file_path'])) {
        logAudit($conn, $user_id, 'DOWNLOAD_DOCUMENT', 'documents', $doc_id, [], ['document_name' => $doc['document_name']]);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $doc['document_name'] . '.' . pathinfo($doc['file_path'], PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($doc['file_path']));
        readfile($doc['file_path']);
        exit();
    } else {
        $_SESSION['error_message'] = 'Faili halijapatikana';
        header("Location: documents.php");
        exit();
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/legal-header.php';
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
    
    .document-card {
        background: white;
        border: 1px solid #e8f0e4;
        border-radius: 0.75rem;
        padding: 1rem;
        transition: all 0.2s;
    }
    .document-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    
    .btn-primary {
        background-color: #006e2c;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
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
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        text-decoration: none;
    }
    .btn-outline:hover {
        background-color: #eef6ea;
    }
    .btn-danger {
        background-color: #dc2626;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
        font-size: 0.8rem;
    }
    .btn-danger:hover {
        background-color: #b91c1c;
    }
    
    .search-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        width: 100%;
    }
    
    .pagination-btn {
        padding: 0.375rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        transition: all 0.15s ease;
        text-decoration: none;
        color: #3d4a3d;
        background: white;
    }
    .pagination-btn.active {
        background-color: #006e2c;
        color: white;
        border-color: #006e2c;
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
        border-radius: 1rem;
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
    }
    .modal-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f4fcef;
    }
    .modal-body {
        padding: 1.25rem;
    }
    .modal-footer {
        padding: 1rem 1.25rem;
        border-top: 1px solid #e8f0e4;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    .form-label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        margin-bottom: 0.25rem;
    }
    .form-input, .form-select {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
    }
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 2px rgba(0,110,44,0.1);
    }
    
    .file-info {
        font-size: 0.75rem;
        color: #6d7b6c;
        margin-top: 0.25rem;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .filter-row {
            flex-direction: column;
        }
    }
</style>

<div class="space-y-4">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Usimamizi wa Nyaraka za Kisheria</h2>
            <p class="text-secondary text-xs">Pakia, tazama na usimamie hati za kisheria za madai</p>
        </div>
        <button type="button" class="btn-primary" onclick="openUploadModal()">
            <span class="material-symbols-outlined text-sm">upload</span>
            Pakia Hati Mpya
        </button>
    </div>
    
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
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_documents; ?></div>
            <div class="stat-label">Jumla ya Hati</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($claims_list); ?></div>
            <div class="stat-label">Madai Yaliyopo</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo date('d/m/Y'); ?></div>
            <div class="stat-label">Tarehe ya Leo</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="bg-white border rounded-lg p-3">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-2 filter-row">
            <div class="flex-1">
                <select name="claim_id" class="form-input">
                    <option value="">-- Dai Zote --</option>
                    <?php foreach ($claims_list as $claim): ?>
                    <option value="<?php echo $claim['id']; ?>" <?php echo $claim_filter == $claim['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($claim['claim_number'] . ' - ' . $claim['project_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <input type="text" name="search" class="search-input" placeholder="Tafuta kwa jina la hati, namba ya dai au jina la mwombaji..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Tafuta</button>
                <a href="documents.php" class="btn-outline">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Documents Grid -->
    <?php if (empty($documents)): ?>
    <div class="bg-white border rounded-lg p-12 text-center">
        <span class="material-symbols-outlined text-6xl text-secondary mb-3">folder_open</span>
        <p class="text-secondary">Hakuna hati zilizopatikana</p>
        <button onclick="openUploadModal()" class="btn-primary mt-3">Pakia Hati Mpya</button>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($documents as $doc): ?>
        <div class="document-card" id="doc-<?php echo $doc['id']; ?>">
            <div class="flex items-start justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">description</span>
                    <div>
                        <h4 class="font-semibold text-sm"><?php echo htmlspecialchars($doc['document_name']); ?></h4>
                        <p class="text-xs text-secondary"><?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?></p>
                    </div>
                </div>
                <div class="dropdown relative">
                    <button class="action-btn" onclick="toggleDocMenu(<?php echo $doc['id']; ?>)">
                        <span class="material-symbols-outlined">more_vert</span>
                    </button>
                    <div id="doc-menu-<?php echo $doc['id']; ?>" class="hidden absolute right-0 mt-1 w-36 bg-white rounded-lg shadow-lg border z-10">
                        <a href="?download_document=1&id=<?php echo $doc['id']; ?>" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                            <span class="material-symbols-outlined text-sm">download</span> Pakua
                        </a>
                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full">
                            <span class="material-symbols-outlined text-sm">delete</span> Futa
                        </button>
                    </div>
                </div>
            </div>
            <div class="mt-2 pt-2 border-t border-gray-100">
                <div class="flex justify-between text-xs">
                    <span class="text-secondary">Dai:</span>
                    <span class="font-mono"><?php echo htmlspecialchars($doc['claim_number']); ?></span>
                </div>
                <div class="flex justify-between text-xs mt-1">
                    <span class="text-secondary">Mwombaji:</span>
                    <span><?php echo htmlspecialchars($doc['claimant_name']); ?></span>
                </div>
                <div class="flex justify-between text-xs mt-1">
                    <span class="text-secondary">Mradi:</span>
                    <span><?php echo htmlspecialchars($doc['project_name']); ?></span>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <a href="?download_document=1&id=<?php echo $doc['id']; ?>" class="flex-1 btn-outline text-center text-xs py-1.5">Pakua</a>
                <button onclick="viewClaim(<?php echo $doc['claim_id']; ?>)" class="flex-1 btn-outline text-center text-xs py-1.5">Angalia Dai</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between px-4 py-3 gap-2">
        <div class="text-sm text-secondary">
            Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_documents); ?> kati ya <?php echo $total_documents; ?>
        </div>
        <div class="flex gap-1">
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
    
    <!-- Instructions -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-800 text-sm">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-sm">info</span>
            <div>
                <p class="font-semibold text-sm">Maelekezo</p>
                <p class="text-xs mt-1">Hapa unaweza kusimamia hati zote za kisheria zinazohusiana na madai. Aina za faili zinazoruhusiwa: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX. Ukubwa wa faili hauzidi 10MB.</p>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div id="uploadModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="text-lg font-semibold">Pakia Hati Mpya</h3>
            <button onclick="closeUploadModal()" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="uploadForm" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Chagua Dai *</label>
                    <select name="claim_id" id="upload_claim_id" class="form-select" required>
                        <option value="">-- Chagua Dai --</option>
                        <?php foreach ($claims_list as $claim): ?>
                        <option value="<?php echo $claim['id']; ?>">
                            <?php echo htmlspecialchars($claim['claim_number'] . ' - ' . $claim['project_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Jina la Hati *</label>
                    <input type="text" name="document_name" id="upload_document_name" class="form-input" placeholder="Mfano: Hati ya Umiliki Ardhi" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Faili *</label>
                    <input type="file" name="document_file" id="upload_document_file" class="form-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx" required>
                    <div class="file-info">Aina zinazoruhusiwa: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX. Ukubwa hadi 10MB.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeUploadModal()" class="btn-outline">Ghairi</button>
                <button type="submit" class="btn-primary">Pakia Hati</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentDocMenu = null;
    
    function closeUploadModal() {
        document.getElementById('uploadModal').classList.remove('show');
        document.body.style.overflow = '';
    }
    
    function openUploadModal() {
        document.getElementById('uploadModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function toggleDocMenu(docId) {
        const menu = document.getElementById(`doc-menu-${docId}`);
        if (currentDocMenu && currentDocMenu !== menu) {
            currentDocMenu.classList.add('hidden');
        }
        menu.classList.toggle('hidden');
        currentDocMenu = menu.classList.contains('hidden') ? null : menu;
    }
    
    function viewClaim(claimId) {
        window.location.href = `view-claim.php?id=${claimId}`;
    }
    
    async function deleteDocument(docId) {
        const result = await Swal.fire({
            title: 'Thibitisha Kufuta',
            text: 'Je, una uhakika unataka kufuta hati hii? Kitendo hiki hakiwezi kutenduliwa.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6d7b6c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana'
        });
        
        if (result.isConfirmed) {
            Swal.fire({ title: 'Inachakata...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            try {
                const response = await fetch(`?delete_document=1&id=${docId}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: data.message, confirmButtonColor: '#006e2c', timer: 1500 });
                    setTimeout(() => { window.location.reload(); }, 1500);
                } else {
                    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
            }
        }
    }
    
    // Handle upload form submission
    document.getElementById('uploadForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const claimId = document.getElementById('upload_claim_id').value;
        const documentName = document.getElementById('upload_document_name').value;
        const fileInput = document.getElementById('upload_document_file');
        const file = fileInput.files[0];
        
        if (!claimId || !documentName || !file) {
            Swal.fire({ icon: 'warning', title: 'Taarifa Zimekosekana', text: 'Tafadhali jaza sehemu zote', confirmButtonColor: '#006e2c' });
            return;
        }
        
        const formData = new FormData();
        formData.append('claim_id', claimId);
        formData.append('document_name', documentName);
        formData.append('document_file', file);
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Imefanikiwa!', text: data.message, confirmButtonColor: '#006e2c', timer: 1500 });
                setTimeout(() => { window.location.reload(); }, 1500);
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu!', text: data.message, confirmButtonColor: '#006e2c' });
            }
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Hitilafu!', text: 'Tatizo la mtandao', confirmButtonColor: '#006e2c' });
        }
    });
    
    // Close modal when clicking outside
    document.getElementById('uploadModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUploadModal();
    });
    
    // Close document menu when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown') && currentDocMenu) {
            currentDocMenu.classList.add('hidden');
            currentDocMenu = null;
        }
    });
    
    // Search with debounce
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
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/legal-footer.php'; ?>