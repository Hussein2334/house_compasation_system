<?php
// commissioner/download-document.php - Download document
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Get document ID
$doc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($doc_id <= 0) {
    $_SESSION['error_message'] = 'Document ID is invalid';
    header("Location: documents.php");
    exit();
}

// Get database connection
$conn = getDB();

// Get document details with claim info for audit
$query = "SELECT d.*, c.claim_number 
          FROM documents d 
          JOIN claims c ON d.claim_id = c.id 
          WHERE d.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $doc_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$doc = mysqli_fetch_assoc($result);

if (!$doc) {
    $_SESSION['error_message'] = 'Document not found in database';
    header("Location: documents.php");
    exit();
}

// Build the correct file path - Try multiple possible paths
$possible_paths = [
    '../uploads/documents/' . $doc['file_path'],  // Relative from commissioner folder
    __DIR__ . '/../uploads/documents/' . $doc['file_path'],  // Absolute path using __DIR__
    $_SERVER['DOCUMENT_ROOT'] . '/hcs/uploads/documents/' . $doc['file_path'],  // Document root path
    $_SERVER['DOCUMENT_ROOT'] . '/house_compensation_system/uploads/documents/' . $doc['file_path'],  // Alternative project name
];

$file_found = false;
$actual_file_path = '';

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $file_found = true;
        $actual_file_path = $path;
        break;
    }
}

if (!$file_found) {
    $_SESSION['error_message'] = 'File not found on server. Path: ' . $doc['file_path'];
    header("Location: documents.php");
    exit();
}

// Log the download action
logAudit($conn, $_SESSION['user_id'], 'DOWNLOAD_DOCUMENT', 'documents', $doc_id, [
    'document_name' => $doc['document_name'],
    'claim_number' => $doc['claim_number']
]);

// Get file extension and set appropriate content type
$file_extension = strtolower(pathinfo($doc['document_name'], PATHINFO_EXTENSION));
$content_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt' => 'text/plain',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed',
];

$content_type = $content_types[$file_extension] ?? 'application/octet-stream';

// Clear any output buffers to prevent corruption
if (ob_get_level()) {
    ob_end_clean();
}

// Set headers for download
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['document_name']) . '"');
header('Content-Length: ' . filesize($actual_file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Output the file
readfile($actual_file_path);
exit();
?>