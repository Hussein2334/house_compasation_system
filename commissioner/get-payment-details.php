<?php
// commissioner/get-payment-details.php - Get payment details for modal
session_start();
header('Content-Type: application/json');

// Disable error display
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/db.php';

// Helper function for JSON response
function sendJsonResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendJsonResponse(false, 'Unauthorized - Please login');
}

if ($_SESSION['role'] !== 'commissioner' && $_SESSION['role'] !== 'super_admin') {
    sendJsonResponse(false, 'Unauthorized - Insufficient permissions');
}

// Get payment ID from GET or POST
$payment_id = 0;
if (isset($_GET['id'])) {
    $payment_id = intval($_GET['id']);
} elseif (isset($_POST['id'])) {
    $payment_id = intval($_POST['id']);
}

if ($payment_id <= 0) {
    sendJsonResponse(false, 'Invalid payment ID. No valid ID provided.');
}

// Get database connection
$conn = getDB();

// Query to get payment details
$query = "SELECT 
            p.*, 
            c.claim_number, 
            c.project_name, 
            u.full_name as claimant_name, 
            u.email, 
            u.phone
          FROM payments p
          JOIN claims c ON p.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          WHERE p.id = ?";

$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    sendJsonResponse(false, 'Database error: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $payment_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$payment = mysqli_fetch_assoc($result);

if ($payment) {
    sendJsonResponse(true, 'Success', $payment);
} else {
    sendJsonResponse(false, 'Payment not found for ID: ' . $payment_id);
}
?>