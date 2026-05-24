<?php
// commissioner/get-claim-details.php - Get claim details for modal
session_start();
header('Content-Type: application/json');

require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = getDB();
$claim_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($claim_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
    exit();
}

$query = "SELECT c.*, u.full_name as claimant_name, u.email, u.phone, u.nin,
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          vu.full_name as valuer_name
          FROM claims c
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN users vu ON v.valuer_id = vu.id
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
?>