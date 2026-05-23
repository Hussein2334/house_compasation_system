<?php
// claimant/my-documents.php - Manage My Documents
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in and is claimant
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'claimant') {
    if ($_SESSION['role'] === 'super_admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../dashboard.php");
    }
    exit();
}

// Set page variables
$page_title = 'My Documents';
$page_heading = 'Nyaraka Zangu';

// Get database connection
$conn = getDB();
$user_id = $_SESSION['user_id'];

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
$allowed_sort_columns = ['uploaded_at', 'document_name', 'claim_number'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'uploaded_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = ["c.claimant_id = ?"];
$params = [$user_id];
$types = "i";

if ($claim_filter !== 'all') {
    $where_clauses[] = "d.claim_id = ?";
    $params[] = $claim_filter;
    $types .= "i";
}

if (!empty($search_term)) {
    $where_clauses[] = "(d.document_name LIKE ? OR c.claim_number LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Get total documents count
$count_query = "SELECT COUNT(*) as total 
                FROM documents d
                JOIN claims c ON d.claim_id = c.id
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if ($count_stmt) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_documents = mysqli_fetch_assoc($count_result)['total'];
} else {
    $total_documents = 0;
}

// Pagination - 10 per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_documents / $per_page);

// Get documents data
$query = "SELECT d.*, 
          c.claim_number, c.project_name, c.status as claim_status
          FROM documents d
          JOIN claims c ON d.claim_id = c.id
          $where_sql
          ORDER BY ";

if ($sort_by === 'claim_number') {
    $query .= "c.claim_number $sort_order";
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
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $documents = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $documents[] = $row;
    }
} else {
    $documents = [];
}

// Get claims for filter
$claims_query = "SELECT id, claim_number, project_name 
                 FROM claims 
                 WHERE claimant_id = ? 
                 ORDER BY claim_number";
$claims_stmt = mysqli_prepare($conn, $claims_query);
if ($claims_stmt) {
    mysqli_stmt_bind_param($claims_stmt, "i", $user_id);
    mysqli_stmt_execute($claims_stmt);
    $claims_result = mysqli_stmt_get_result($claims_stmt);
    $claims = [];
    while ($row = mysqli_fetch_assoc($claims_result)) {
        $claims[] = $row;
    }
} else {
    $claims = [];
}

// Get document type counts
$doc_types = ['pdf_count' => 0, 'image_count' => 0, 'word_count' => 0, 'total' => 0];
$type_query = "SELECT 
    SUM(CASE WHEN d.document_name LIKE '%.pdf' THEN 1 ELSE 0 END) as pdf_count,
    SUM(CASE WHEN d.document_name LIKE '%.jpg' OR d.document_name LIKE '%.jpeg' OR d.document_name LIKE '%.png' THEN 1 ELSE 0 END) as image_count,
    SUM(CASE WHEN d.document_name LIKE '%.doc' OR d.document_name LIKE '%.docx' THEN 1 ELSE 0 END) as word_count,
    COUNT(*) as total
    FROM documents d
    JOIN claims c ON d.claim_id = c.id
    WHERE c.claimant_id = ?";
$type_stmt = mysqli_prepare($conn, $type_query);
if ($type_stmt) {
    mysqli_stmt_bind_param($type_stmt, "i", $user_id);
    mysqli_stmt_execute($type_stmt);
    $type_result = mysqli_stmt_get_result($type_stmt);
    $doc_types = mysqli_fetch_assoc($type_result);
    if (!$doc_types) {
        $doc_types = ['pdf_count' => 0, 'image_count' => 0, 'word_count' => 0, 'total' => 0];
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $claim_id = intval($_POST['claim_id']);
    $document_name = trim($_POST['document_name']);
    
    $errors = [];
    
    if ($claim_id <= 0) {
        $errors[] = "Tafadhali chagua dai";
    }
    
    // Verify claim belongs to user
    $claim_check = "SELECT id FROM claims WHERE id = ? AND claimant_id = ?";
    $claim_stmt = mysqli_prepare($conn, $claim_check);
    mysqli_stmt_bind_param($claim_stmt, "ii", $claim_id, $user_id);
    mysqli_stmt_execute($claim_stmt);
    mysqli_stmt_store_result($claim_stmt);
    
    if (mysqli_stmt_num_rows($claim_stmt) == 0) {
        $errors[] = "Dai hili si lako au halipo";
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
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        
        if (!in_array($file_ext, $allowed_extensions)) {
            $errors[] = "Aina ya faili haikubaliki. Kuruhusiwa: PDF, JPG, PNG, DOC, DOCX";
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = "Faili ni kubwa sana. Ukubwa unaoruhusiwa ni 10MB";
        }
        
        if (empty($errors)) {
            $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $document_name);
            $filename = time() . '_' . $user_id . '_' . $safe_filename . '.' . $file_ext;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $insert_query = "INSERT INTO documents (claim_id, document_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, "iss", $claim_id, $document_name, $filename);
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $doc_id = mysqli_insert_id($conn);
                    
                    // Create notification
                    $notif_title = "Hati Imepakiwa";
                    $notif_message = "Umepakia hati: $document_name";
                    $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                                   VALUES (?, ?, ?, 'system', NOW())";
                    $notif_stmt = mysqli_prepare($conn, $notif_query);
                    mysqli_stmt_bind_param($notif_stmt, "iss", $user_id, $notif_title, $notif_message);
                    mysqli_stmt_execute($notif_stmt);
                    
                    $_SESSION['success_message'] = "Hati imepakiwa kikamilifu.";
                    logAudit($conn, $user_id, 'UPLOAD_DOCUMENT', 'documents', $doc_id);
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
    
    header("Location: my-documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// ========== FIXED DELETE FUNCTION ==========
// Handle delete document - MOVED TO TOP and FIXED
if (isset($_GET['delete']) && isset($_GET['doc_id'])) {
    $doc_id = intval($_GET['delete']);
    
    // Verify document belongs to user's claim
    $check_query = "SELECT d.file_path, d.document_name, d.claim_id
                    FROM documents d
                    JOIN claims c ON d.claim_id = c.id
                    WHERE d.id = ? AND c.claimant_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $doc_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $file_data = mysqli_fetch_assoc($check_result);
    
    if ($file_data) {
        // Delete from database
        $delete_query = "DELETE FROM documents WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $doc_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Delete physical file
            $file_path = $upload_dir . $file_data['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $_SESSION['success_message'] = "Hati '" . htmlspecialchars($file_data['document_name']) . "' imefutwa kikamilifu.";
            logAudit($conn, $user_id, 'DELETE_DOCUMENT', 'documents', $doc_id);
        } else {
            $_SESSION['error_message'] = "Hitilafu katika kufuta hati: " . mysqli_error($conn);
        }
    } else {
        $_SESSION['error_message'] = "Hati haikupatikana au huna ruhusa ya kuifuta.";
    }
    
    // Redirect back to the same page with all parameters
    $redirect_url = "my-documents.php";
    $params = [];
    if ($claim_filter !== 'all') $params['claim_id'] = $claim_filter;
    if (!empty($search_term)) $params['search'] = $search_term;
    if ($page > 1) $params['page'] = $page;
    if ($sort_by !== 'uploaded_at') $params['sort'] = $sort_by;
    if ($sort_order !== 'DESC') $params['order'] = $sort_order;
    
    if (!empty($params)) {
        $redirect_url .= "?" . http_build_query($params);
    }
    
    header("Location: " . $redirect_url);
    exit();
}

// Handle download document
if (isset($_GET['download']) && isset($_GET['doc_id'])) {
    $doc_id = intval($_GET['download']);
    
    // Verify document belongs to user
    $check_query = "SELECT d.file_path, d.document_name 
                    FROM documents d
                    JOIN claims c ON d.claim_id = c.id
                    WHERE d.id = ? AND c.claimant_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $doc_id, $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $doc = mysqli_fetch_assoc($check_result);
    
    if ($doc) {
        $file_path = $upload_dir . $doc['file_path'];
        if (file_exists($file_path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $doc['document_name'] . '"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
            logAudit($conn, $user_id, 'DOWNLOAD_DOCUMENT', 'documents', $doc_id);
            exit();
        } else {
            $_SESSION['error_message'] = "Faili la hati halijapatikana.";
        }
    } else {
        $_SESSION['error_message'] = "Hati haikupatikana au huna ruhusa ya kuipakua.";
    }
    
    header("Location: my-documents.php?claim_id=$claim_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/claimant-header.php';
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
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
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
        font-size: 1.5rem;
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
    .file-icon.default { background: #e8f0e4; color: #3d4a3d; }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .documents-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
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
        padding: 1rem;
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
    .filter-select:focus, .filter-input:focus {
        outline: none;
        border-color: #006e2c;
        box-shadow: 0 0 0 3px rgba(0,110,44,0.1);
    }
    .btn-filter {
        background-color: #006e2c;
        color: white;
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .btn-filter:hover {
        background-color: #005a24;
    }
    .btn-upload {
        background-color: white;
        color: #006e2c;
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-weight: 600;
        border: 1px solid #006e2c;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-upload:hover {
        background-color: #eef6ea;
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
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }
    .action-btn:hover {
        background-color: #e8f0e4;
        color: #006e2c;
    }
    
    /* Upload Modal */
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
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.submitted { background: #fef3c7; color: #92400e; }
    .status-badge.valuation { background: #fed7aa; color: #9a3412; }
    .status-badge.legal_review { background: #e9d5ff; color: #6b21a5; }
    .status-badge.approved { background: #d1fae5; color: #065f46; }
    .status-badge.rejected { background: #fee2e2; color: #991b1b; }
    .status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .filter-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="p-2 hover:bg-surface-container-low rounded-lg transition">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h2 class="font-headline-lg text-on-background text-2xl font-bold">Nyaraka Zangu</h2>
                <p class="text-secondary text-sm mt-1">Pakia, simamia na upakue hati za madai yako</p>
            </div>
        </div>
        <button onclick="openUploadModal()" class="btn-upload">
            <span class="material-symbols-outlined text-sm">upload</span> Pakia Hati
        </button>
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
            <div class="stat-label">Nyaraka za Word</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="" class="filter-grid">
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Dai</label>
                <select name="claim_id" class="filter-select">
                    <option value="all" <?php echo $claim_filter === 'all' ? 'selected' : ''; ?>>-- Madai Yote --</option>
                    <?php foreach ($claims as $claim): ?>
                        <option value="<?php echo $claim['id']; ?>" <?php echo $claim_filter == $claim['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($claim['claim_number']); ?> - <?php echo htmlspecialchars($claim['project_name'] ?? 'No Project'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-secondary block mb-1">Tafuta</label>
                <input type="text" name="search" class="filter-input" placeholder="Jina la hati au namba ya dai..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div>
                <button type="submit" class="btn-filter w-full">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-center gap-2 text-green-800">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo $success_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-center gap-2 text-red-800">
            <span class="material-symbols-outlined">error</span>
            <span><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Documents Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="table-container">
            <table class="documents-table">
                <thead>
                    <tr>
                        <th>Aina</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'document_name', 'order' => $sort_by == 'document_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Jina la Hati</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Namba ya Dai</a></th>
                        <th>Mradi</th>
                        <th>Hali ya Dai</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'uploaded_at', 'order' => $sort_by == 'uploaded_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" class="hover:text-primary">Tarehe ya Kupakia</a></th>
                        <th class="text-center">Hatua</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-12 text-secondary">
                            <span class="material-symbols-outlined text-5xl mb-2 block">folder</span>
                            Hakuna hati zilizopakiwa
                            <div class="mt-2">
                                <button onclick="openUploadModal()" class="text-primary hover:underline">Pakia Hati Yako ya Kwanza</button>
                            </div>
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
                    } else {
                        $file_type = 'default';
                        $file_icon = 'insert_drive_file';
                    }
                    ?>
                    <tr id="row-<?php echo $doc['id']; ?>">
                        <td>
                            <div class="file-icon <?php echo $file_type; ?>">
                                <span class="material-symbols-outlined"><?php echo $file_icon; ?></span>
                            </div>
                        </td>
                        <td class="font-medium"><?php echo htmlspecialchars($doc['document_name']); ?></td>
                        <td class="font-mono text-sm"><?php echo htmlspecialchars($doc['claim_number']); ?></td>
                        <td><?php echo htmlspecialchars($doc['project_name'] ?? '-'); ?></td>
                        <td>
                            <span class="status-badge <?php echo $doc['claim_status']; ?>">
                                <?php echo getStatusLabel($doc['claim_status']); ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary"><?php echo date('d/m/Y H:i', strtotime($doc['uploaded_at'])); ?></td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <a href="?download=1&doc_id=<?php echo $doc['id']; ?>&claim_id=<?php echo $claim_filter; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $page; ?>" class="action-btn" title="Pakua Hati">
                                    <span class="material-symbols-outlined">download</span>
                                </a>
                                <a href="#" onclick="confirmDelete(<?php echo $doc['id']; ?>, '<?php echo addslashes($doc['document_name']); ?>')" class="action-btn" title="Futa Hati">
                                    <span class="material-symbols-outlined text-red-600">delete</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
    
    <!-- Info Note -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">info</span>
            <div>
                <p class="text-sm font-semibold text-blue-800">Taarifa za Nyaraka</p>
                <p class="text-sm text-blue-700 mt-1">Hati zinazokubalika: PDF, JPG, PNG, DOC, DOCX. Ukubwa: hadi 10MB kwa faili. Hakikisha unapakia hati zinazothibitisha umiliki wako wa mali.</p>
            </div>
        </div>
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
                                <?php echo htmlspecialchars($claim['claim_number']); ?> - <?php echo htmlspecialchars($claim['project_name'] ?? 'No Project'); ?>
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
                    <input type="file" name="document_file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <div class="text-xs text-secondary mt-1">Aina zinazokubalika: PDF, JPG, PNG, DOC, DOCX. Ukubwa: hadi 10MB</div>
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
    
    // Confirm delete document - FIXED
    function confirmDelete(docId, docName) {
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
                // Get current filter parameters
                const claimFilter = '<?php echo $claim_filter; ?>';
                const searchTerm = '<?php echo urlencode($search_term); ?>';
                const currentPage = <?php echo $page; ?>;
                const sortBy = '<?php echo $sort_by; ?>';
                const sortOrder = '<?php echo $sort_order; ?>';
                
                let url = `?delete=1&doc_id=${docId}`;
                if (claimFilter !== 'all') url += `&claim_id=${claimFilter}`;
                if (searchTerm) url += `&search=${searchTerm}`;
                if (currentPage > 1) url += `&page=${currentPage}`;
                if (sortBy !== 'uploaded_at') url += `&sort=${sortBy}`;
                if (sortOrder !== 'DESC') url += `&order=${sortOrder}`;
                
                window.location.href = url;
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
</script>

<?php require_once __DIR__ . '/includes/claimant-footer.php'; ?>