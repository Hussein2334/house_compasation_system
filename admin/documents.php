<?php
// admin/documents.php - Manage Claim Documents
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and has access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'legal_officer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Documents';
$page_heading = 'Usimamizi wa Nyaraka na Hati';

// Get database connection
$conn = getDB();

// Create upload directory if not exists
$upload_dir = '../uploads/documents/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get filter parameters
$claim_filter = $_GET['claim_id'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'uploaded_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['uploaded_at', 'document_name', 'claim_number', 'full_name'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'uploaded_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($claim_filter !== 'all') {
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

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_documents / $per_page);

// Get documents data
$query = "SELECT d.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          u.full_name as claimant_name, u.email
          FROM documents d
          JOIN claims c ON d.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          $where_sql
          ORDER BY ";
          
if ($sort_by === 'claim_number') {
    $query .= "c.claim_number $sort_order";
} elseif ($sort_by === 'full_name') {
    $query .= "u.full_name $sort_order";
} elseif ($sort_by === 'document_name') {
    $query .= "d.document_name $sort_order";
} else {
    $query .= "d.uploaded_at $sort_order";
}

$query .= " LIMIT ? OFFSET ?";

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

// Get claims for filter
$claims_query = "SELECT c.id, c.claim_number, u.full_name 
                 FROM claims c
                 JOIN users u ON c.claimant_id = u.id
                 ORDER BY c.claim_number";
$claims_result = mysqli_query($conn, $claims_query);
$claims = [];
while ($row = mysqli_fetch_assoc($claims_result)) {
    $claims[] = $row;
}

// Get document type counts
$type_counts = [];
$type_query = "SELECT 
    SUM(CASE WHEN document_name LIKE '%.pdf' THEN 1 ELSE 0 END) as pdf_count,
    SUM(CASE WHEN document_name LIKE '%.jpg' OR document_name LIKE '%.jpeg' OR document_name LIKE '%.png' THEN 1 ELSE 0 END) as image_count,
    SUM(CASE WHEN document_name LIKE '%.doc' OR document_name LIKE '%.docx' THEN 1 ELSE 0 END) as word_count,
    COUNT(*) as total
    FROM documents";
$type_result = mysqli_query($conn, $type_query);
$doc_types = mysqli_fetch_assoc($type_result);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $claim_id = intval($_POST['claim_id']);
    $document_name = trim($_POST['document_name']);
    
    $errors = [];
    
    if ($claim_id <= 0) {
        $errors[] = "Tafadhali chagua dai";
    }
    
    if (empty($document_name)) {
        $errors[] = "Tafadhali jina la hati";
    }
    
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Tafadhali chagua faili la hati";
    }
    
    if (empty($errors)) {
        $file = $_FILES['document_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        
        if (!in_array($file_ext, $allowed_extensions)) {
            $errors[] = "Aina ya faili haikubaliki. Kuruhusiwa: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX";
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = "Faili ni kubwa sana. Ukubwa unaoruhusiwa ni 10MB";
        }
        
        if (empty($errors)) {
            $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $document_name);
            $filename = time() . '_' . $safe_filename . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $insert_query = "INSERT INTO documents (claim_id, document_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, "iss", $claim_id, $document_name, $filename);
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $_SESSION['success_message'] = "Hati imepakiwa kikamilifu.";
                    logAudit($conn, $_SESSION['user_id'], 'UPLOAD_DOCUMENT', 'documents', mysqli_insert_id($conn), null, [
                        'claim_id' => $claim_id,
                        'document_name' => $document_name,
                        'filename' => $filename
                    ]);
                } else {
                    $_SESSION['error_message'] = "Hitilafu katika kuhifadhi hati: " . mysqli_error($conn);
                    unlink($filepath);
                }
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kupakia faili.";
            }
        } else {
            $_SESSION['error_message'] = implode("<br>", $errors);
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
    
    header("Location: documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle delete document
if (isset($_GET['delete']) && isset($_GET['doc_id'])) {
    $doc_id = intval($_GET['doc_id']);
    
    // Get file path before deleting
    $file_query = "SELECT file_path FROM documents WHERE id = ?";
    $file_stmt = mysqli_prepare($conn, $file_query);
    mysqli_stmt_bind_param($file_stmt, "i", $doc_id);
    mysqli_stmt_execute($file_stmt);
    $file_result = mysqli_stmt_get_result($file_stmt);
    $file_data = mysqli_fetch_assoc($file_result);
    
    if ($file_data) {
        $delete_query = "DELETE FROM documents WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $doc_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Delete physical file
            $file_path = $upload_dir . $file_data['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $_SESSION['success_message'] = "Hati imefutwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'DELETE_DOCUMENT', 'documents', $doc_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kufuta hati.";
        }
    } else {
        $_SESSION['error_message'] = "Hati haikupatikana.";
    }
    
    header("Location: documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle download document
if (isset($_GET['download']) && isset($_GET['doc_id'])) {
    $doc_id = intval($_GET['download']);
    $query = "SELECT document_name, file_path FROM documents WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $doc_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $doc = mysqli_fetch_assoc($result);
    
    if ($doc) {
        $file_path = $upload_dir . $doc['file_path'];
        if (file_exists($file_path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $doc['document_name'] . '.pdf"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
            logAudit($conn, $_SESSION['user_id'], 'DOWNLOAD_DOCUMENT', 'documents', $doc_id);
            exit();
        } else {
            $_SESSION['error_message'] = "Faili la hati halijapatikana.";
            header("Location: documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Hati haikupatikana.";
        header("Location: documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
        exit();
    }
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        // Get file paths before deleting
        $file_query = "SELECT file_path FROM documents WHERE id IN ($placeholders)";
        $file_stmt = mysqli_prepare($conn, $file_query);
        $delete_types = str_repeat("i", count($selected_ids));
        mysqli_stmt_bind_param($file_stmt, $delete_types, ...$selected_ids);
        mysqli_stmt_execute($file_stmt);
        $file_result = mysqli_stmt_get_result($file_stmt);
        
        $file_paths = [];
        while ($row = mysqli_fetch_assoc($file_result)) {
            $file_paths[] = $upload_dir . $row['file_path'];
        }
        
        // Delete records
        $delete_query = "DELETE FROM documents WHERE id IN ($placeholders)";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, $delete_types, ...$selected_ids);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Delete physical files
            foreach ($file_paths as $file_path) {
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            $affected = mysqli_stmt_affected_rows($delete_stmt);
            $_SESSION['success_message'] = "Hati $affected zimefutwa kikamilifu.";
            logAudit($conn, $_SESSION['user_id'], 'BULK_DELETE_DOCUMENTS', 'documents', null, null, ['ids' => $selected_ids]);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kufuta hati.";
        }
    }
    
    header("Location: documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .stat-card {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        transition: all 0.2s;
    }
    .stat-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transform: translateY(-2px);
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 0.75rem;
    }
    .stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e2a1e;
    }
    .stat-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #6d7b6c;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    
    /* File Type Icons */
    .file-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }
    .file-icon.pdf { background: #fee2e2; color: #991b1b; }
    .file-icon.image { background: #d1fae5; color: #065f46; }
    .file-icon.word { background: #e0e7ff; color: #4338ca; }
    .file-icon.excel { background: #d1fae5; color: #065f46; }
    .file-icon.default { background: #e8f0e4; color: #3d4a3d; }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .documents-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }
    .documents-table th {
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #3d4a3d;
        background-color: #eef6ea;
        border-bottom: 2px solid #bccab9;
    }
    .documents-table td {
        padding: 1rem;
        border-bottom: 1px solid #e8f0e4;
        vertical-align: middle;
        font-size: 0.875rem;
    }
    .documents-table tr:hover {
        background-color: #f4fcef;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 1rem;
        padding: 1.25rem;
        border: 1px solid #e8f0e4;
        margin-bottom: 1.5rem;
    }
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-select, .filter-input {
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        background: white;
        width: 100%;
    }
    .btn-filter, .btn-upload {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    .btn-filter {
        background-color: #006e2c;
        color: white;
        border: none;
    }
    .btn-filter:hover {
        background-color: #005a24;
    }
    .btn-upload {
        background-color: white;
        color: #006e2c;
        border: 1px solid #006e2c;
    }
    .btn-upload:hover {
        background-color: #eef6ea;
    }
    .button-group {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    /* Modal Styles */
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
        backdrop-filter: blur(4px);
    }
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    .modal-container {
        background: white;
        border-radius: 1.5rem;
        width: 95%;
        max-width: 550px;
        max-height: 90vh;
        overflow-y: auto;
        transform: scale(0.95);
        transition: transform 0.3s ease;
    }
    .modal-overlay.show .modal-container {
        transform: scale(1);
    }
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e8f0e4;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f4fcef;
        position: sticky;
        top: 0;
    }
    .modal-body {
        padding: 1.5rem;
    }
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e8f0e4;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        background: white;
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
    .form-label.required::after {
        content: "*";
        color: #dc2626;
        margin-left: 0.25rem;
    }
    .form-control, .form-select {
        width: 100%;
        padding: 0.625rem 0.75rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.875rem;
    }
    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    
    .pagination {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: center;
        align-items: center;
    }
    .pagination-btn {
        padding: 0.5rem 0.875rem;
        border: 1px solid #bccab9;
        border-radius: 0.5rem;
        font-size: 0.8rem;
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
    
    .action-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 0.5rem;
        color: #6d7b6c;
        transition: all 0.2s;
    }
    .action-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    .checkbox-select {
        width: 1rem;
        height: 1rem;
        accent-color: #006e2c;
        cursor: pointer;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
        .stat-value {
            font-size: 1.25rem;
        }
    }
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background text-2xl font-bold">Usimamizi wa Nyaraka na Hati</h2>
            <p class="text-secondary text-sm mt-1">Pakia, simamia na upakue hati za madai na tathmini</p>
        </div>
        <div class="button-group">
            <button onclick="openUploadModal()" class="btn-upload">
                <span class="material-symbols-outlined text-sm">upload</span> Pakia Hati
            </button>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #eef6ea; color: #006e2c;">
                <span class="material-symbols-outlined">folder</span>
            </div>
            <div class="stat-value"><?php echo number_format($doc_types['total'] ?? 0); ?></div>
            <div class="stat-label">Jumla ya Hati</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
                <span class="material-symbols-outlined">picture_as_pdf</span>
            </div>
            <div class="stat-value"><?php echo number_format($doc_types['pdf_count'] ?? 0); ?></div>
            <div class="stat-label">Hati za PDF</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #d1fae5; color: #065f46;">
                <span class="material-symbols-outlined">image</span>
            </div>
            <div class="stat-value"><?php echo number_format($doc_types['image_count'] ?? 0); ?></div>
            <div class="stat-label">Picha</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0e7ff; color: #4338ca;">
                <span class="material-symbols-outlined">description</span>
            </div>
            <div class="stat-value"><?php echo number_format($doc_types['word_count'] ?? 0); ?></div>
            <div class="stat-label">Nyaraka za Word/Excel</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Dai</label>
                <select name="claim_id" class="filter-select">
                    <option value="all">-- Madai Yote --</option>
                    <?php foreach ($claims as $claim): ?>
                        <option value="<?php echo $claim['id']; ?>" <?php echo $claim_filter == $claim['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($claim['claim_number']); ?> - <?php echo htmlspecialchars($claim['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Jina la hati, namba ya dai..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="button-group">
                <button type="submit" class="btn-filter">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
                <a href="documents.php" class="btn-upload">
                    <span class="material-symbols-outlined text-sm">refresh</span> Reset
                </a>
            </div>
        </form>
    </div>
    
    <!-- Documents Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <form id="bulk_form" method="POST">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="documents-table">
                    <thead>
                        <tr>
                            <th class="w-10"><input type="checkbox" id="select_all" class="checkbox-select"></th>
                            <th>Aina</th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'document_name', 'order' => $sort_by == 'document_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Jina la Hati</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mwombaji</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'uploaded_at', 'order' => $sort_by == 'uploaded_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe ya Kupakia</a></th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-12 text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2 block">folder</span>
                                Hakuna hati zilizopakiwa
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                        <?php
                        $file_ext = strtolower(pathinfo($doc['document_name'], PATHINFO_EXTENSION));
                        if (in_array($file_ext, ['pdf'])) {
                            $file_type = 'pdf';
                            $file_icon = 'picture_as_pdf';
                        } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                            $file_type = 'image';
                            $file_icon = 'image';
                        } elseif (in_array($file_ext, ['doc', 'docx'])) {
                            $file_type = 'word';
                            $file_icon = 'description';
                        } elseif (in_array($file_ext, ['xls', 'xlsx'])) {
                            $file_type = 'excel';
                            $file_icon = 'table_chart';
                        } else {
                            $file_type = 'default';
                            $file_icon = 'insert_drive_file';
                        }
                        ?>
                        <tr id="row-<?php echo $doc['id']; ?>">
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $doc['id']; ?>" class="checkbox-select doc-checkbox"></td>
                            <td>
                                <div class="file-icon <?php echo $file_type; ?>">
                                    <span class="material-symbols-outlined"><?php echo $file_icon; ?></span>
                                </div>
                            </td>
                            <td class="font-medium"><?php echo htmlspecialchars($doc['document_name']); ?></td>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($doc['claim_number']); ?></td>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($doc['claimant_name']); ?></div>
                                <div class="text-xs text-secondary"><?php echo htmlspecialchars($doc['email']); ?></div>
                            </td>
                            <td class="whitespace-nowrap text-sm"><?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?></td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <a href="?download=<?php echo $doc['id']; ?>" class="action-btn" title="Pakua Hati">
                                        <span class="material-symbols-outlined">download</span>
                                    </a>
                                    <a href="?delete=1&doc_id=<?php echo $doc['id']; ?>&claim_id=<?php echo $claim_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page; ?>" 
                                       class="action-btn delete-btn" title="Futa Hati" 
                                       onclick="return confirmDelete(event, '<?php echo addslashes($doc['document_name']); ?>')">
                                        <span class="material-symbols-outlined text-red-600">delete</span>
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
        <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-4 border-t border-outline-variant bg-surface-container-low gap-3">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_documents); ?> kati ya <?php echo $total_documents; ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">« Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">Inayofuata »</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Bulk Actions -->
    <div class="flex gap-2 items-center">
        <select id="bulk_action_select" class="px-3 py-2 border border-outline rounded-lg bg-white text-sm">
            <option value="">Bulk Action</option>
            <option value="delete">Futa Hati Zilizochaguliwa</option>
        </select>
        <button onclick="applyBulkAction()" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">
            Tumia
        </button>
    </div>
</div>

<!-- Upload Document Modal -->
<div id="uploadModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">upload</span>
                <h3 class="text-lg font-semibold">Pakia Hati Mpya</h3>
            </div>
            <button type="button" id="closeUploadModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="upload_document" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Chagua Dai</label>
                    <select name="claim_id" class="form-select" required>
                        <option value="">-- Chagua Dai --</option>
                        <?php foreach ($claims as $claim): ?>
                            <option value="<?php echo $claim['id']; ?>">
                                <?php echo htmlspecialchars($claim['claim_number']); ?> - <?php echo htmlspecialchars($claim['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label required">Jina la Hati</label>
                    <input type="text" name="document_name" class="form-control" required placeholder="Mfano: Hati ya Umiliki Ardhi">
                    <div class="text-xs text-secondary mt-1">Tumia jina linaloelezea maudhui ya hati</div>
                </div>
                <div class="form-group">
                    <label class="form-label required">Faili la Hati</label>
                    <input type="file" name="document_file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                    <div class="text-xs text-secondary mt-1">Aina zinazokubalika: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX. Ukubwa: hadi 10MB</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="cancelUploadBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Pakia Hati</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Select all checkbox
    const selectAll = document.getElementById('select_all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.doc-checkbox').forEach(cb => cb.checked = selectAll.checked);
        });
    }
    
    // Open upload modal
    function openUploadModal() {
        const modal = document.getElementById('uploadModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function closeUploadModal() {
        const modal = document.getElementById('uploadModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    // Apply bulk action
    function applyBulkAction() {
        const selected = document.querySelectorAll('.doc-checkbox:checked');
        const action = document.getElementById('bulk_action_select').value;
        
        if (selected.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Hakuna Hati', text: 'Chagua angalau hati moja', confirmButtonColor: '#006e2c' });
            return;
        }
        
        if (!action) {
            Swal.fire({ icon: 'warning', title: 'Chagua Kitendo', text: 'Chagua kitendo cha kufanya', confirmButtonColor: '#006e2c' });
            return;
        }
        
        Swal.fire({
            title: 'Futa Hati?',
            text: `Je, una uhakika unataka kufuta hati ${selected.length}? Hatua haiwezi kutenduliwa.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('bulk_action_value').value = action;
                document.getElementById('bulk_form').submit();
            }
        });
    }
    
    // Confirm delete single document
    function confirmDelete(event, docName) {
        event.preventDefault();
        Swal.fire({
            title: 'Futa Hati?',
            text: `Je, una uhakika unataka kufuta "${docName}"? Hatua haiwezi kutenduliwa.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ba1a1a',
            cancelButtonColor: '#006e2c',
            confirmButtonText: 'Ndiyo, Futa',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = event.currentTarget.href;
            }
        });
        return false;
    }
    
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
    
    // Event listeners
    document.getElementById('closeUploadModalBtn')?.addEventListener('click', closeUploadModal);
    document.getElementById('cancelUploadBtn')?.addEventListener('click', closeUploadModal);
    document.getElementById('uploadModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUploadModal();
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>