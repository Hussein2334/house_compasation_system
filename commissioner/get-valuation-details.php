<?php
// commissioner/get-valuation-details.php - Get valuation details for modal
session_start();
header('Content-Type: application/json');

error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/db.php';

function sendJsonResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit();
}

// Check login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendJsonResponse(false, 'Unauthorized');
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    sendJsonResponse(false, 'Unauthorized');
}

$valuation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($valuation_id <= 0) {
    sendJsonResponse(false, 'Invalid valuation ID');
}

$conn = getDB();

$query = "SELECT v.*, 
          c.claim_number, c.project_name, c.district, c.property_type, c.property_size, c.description, c.status as claim_status, c.decision_date,
          u.full_name as claimant_name, u.email, u.phone,
          vu.full_name as valuer_name
          FROM valuations v
          JOIN claims c ON v.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN users vu ON v.valuer_id = vu.id
          WHERE v.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $valuation_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$valuation = mysqli_fetch_assoc($result);

if ($valuation) {
    sendJsonResponse(true, 'Success', $valuation);
} else {
    sendJsonResponse(false, 'Valuation not found');
}
?>