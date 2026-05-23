<?php
// claimant/get-claim-details.php - Get claim details for modal
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Debug - log the session
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Session logged_in: " . ($_SESSION['logged_in'] ?? 'not set'));
error_log("Session role: " . ($_SESSION['role'] ?? 'not set'));

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login', 'session' => $_SESSION]);
    exit();
}

// Check if user is claimant
if ($_SESSION['role'] !== 'claimant') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid role: ' . ($_SESSION['role'] ?? 'none')]);
    exit();
}

// Check if claim ID is provided
if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Claim ID is required']);
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$claim_id = intval($_GET['id']);

// Verify database connection
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get claim details with valuations and payments
$query = "SELECT c.*, 
          v.id as valuation_id, v.property_value, v.disturbance_allowance, 
          v.transport_allowance, v.total_compensation, v.valuation_report,
          p.id as payment_id, p.amount as paid_amount, p.payment_status, p.paid_at
          FROM claims c
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN payments p ON c.id = p.claim_id
          WHERE c.id = ? AND c.claimant_id = ?";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit();
}

mysqli_stmt_bind_param($stmt, "ii", $claim_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$claim = mysqli_fetch_assoc($result);

if ($claim) {
    // Format numbers
    $claim['claim_amount'] = $claim['claim_amount'] ? floatval($claim['claim_amount']) : 0;
    $claim['property_value'] = $claim['property_value'] ? floatval($claim['property_value']) : 0;
    $claim['disturbance_allowance'] = $claim['disturbance_allowance'] ? floatval($claim['disturbance_allowance']) : 0;
    $claim['transport_allowance'] = $claim['transport_allowance'] ? floatval($claim['transport_allowance']) : 0;
    $claim['total_compensation'] = $claim['total_compensation'] ? floatval($claim['total_compensation']) : 0;
    $claim['paid_amount'] = $claim['paid_amount'] ? floatval($claim['paid_amount']) : 0;
    
    echo json_encode(['success' => true, 'data' => $claim]);
} else {
    echo json_encode(['success' => false, 'message' => 'Claim not found or you do not have permission to view it']);
}
?>