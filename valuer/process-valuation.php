<?php
// valuer/process-valuation.php - Process valuation form submission
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is valuer or super_admin
if ($_SESSION['role'] !== 'valuer' && $_SESSION['role'] !== 'super_admin') {
    header("Location: ../dashboard.php");
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Debug log
error_log("Process Valuation - User ID: $user_id, Role: $user_role");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: claims.php");
    exit();
}

// Get form data
$claim_id = isset($_POST['claim_id']) ? intval($_POST['claim_id']) : 0;
$property_value = isset($_POST['property_value']) ? floatval($_POST['property_value']) : 0;
$disturbance_allowance = isset($_POST['disturbance_allowance']) ? floatval($_POST['disturbance_allowance']) : 0;
$transport_allowance = isset($_POST['transport_allowance']) ? floatval($_POST['transport_allowance']) : 0;
$total_compensation = isset($_POST['total_compensation']) ? floatval($_POST['total_compensation']) : 0;
$valuation_report = isset($_POST['valuation_report']) ? trim($_POST['valuation_report']) : '';

// Debug log
error_log("Claim ID: $claim_id, Property Value: $property_value, Total: $total_compensation");

// Validate data
$errors = [];

if ($claim_id <= 0) {
    $errors[] = "Claim ID is invalid";
}

if ($property_value <= 0) {
    $errors[] = "Property value is required and must be greater than 0";
}

// Verify claim exists and is in valuation status
$claim_query = "SELECT c.id, c.claim_number, c.status, c.claimant_id,
                u.full_name as claimant_name, u.email
                FROM claims c
                JOIN users u ON c.claimant_id = u.id
                WHERE c.id = ?";
$claim_stmt = mysqli_prepare($conn, $claim_query);
mysqli_stmt_bind_param($claim_stmt, "i", $claim_id);
mysqli_stmt_execute($claim_stmt);
$claim_result = mysqli_stmt_get_result($claim_stmt);
$claim = mysqli_fetch_assoc($claim_result);

if (!$claim) {
    $errors[] = "Claim not found";
} elseif ($claim['status'] !== 'valuation' && $_SESSION['role'] !== 'super_admin') {
    $errors[] = "This claim is not in valuation status. Current status: " . $claim['status'];
}

if (empty($errors)) {
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Check if valuation already exists for this claim
        $check_query = "SELECT id FROM valuations WHERE claim_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            // Update existing valuation
            $update_query = "UPDATE valuations SET 
                             property_value = ?, 
                             disturbance_allowance = ?, 
                             transport_allowance = ?, 
                             total_compensation = ?, 
                             valuation_report = ?,
                             valuer_id = ?
                             WHERE claim_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ddddssi", 
                $property_value, 
                $disturbance_allowance, 
                $transport_allowance, 
                $total_compensation, 
                $valuation_report, 
                $user_id, 
                $claim_id);
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception("Failed to update valuation: " . mysqli_error($conn));
            }
            
            // Update claim status
            $status_update = "UPDATE claims SET status = 'legal_review', updated_at = NOW() WHERE id = ?";
            $status_stmt = mysqli_prepare($conn, $status_update);
            mysqli_stmt_bind_param($status_stmt, "i", $claim_id);
            mysqli_stmt_execute($status_stmt);
            
            $_SESSION['success_message'] = "Valuation updated successfully for claim " . $claim['claim_number'];
            logAudit($conn, $user_id, 'UPDATE_VALUATION', 'valuations', $claim_id);
            
        } else {
            // Insert new valuation
            $insert_query = "INSERT INTO valuations (claim_id, valuer_id, property_value, 
                             disturbance_allowance, transport_allowance, total_compensation, valuation_report, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "iidddds", 
                $claim_id, 
                $user_id, 
                $property_value, 
                $disturbance_allowance, 
                $transport_allowance, 
                $total_compensation, 
                $valuation_report);
            
            if (!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception("Failed to insert valuation: " . mysqli_error($conn));
            }
            
            $valuation_id = mysqli_insert_id($conn);
            
            // Update claim status to legal_review
            $status_update = "UPDATE claims SET status = 'legal_review', updated_at = NOW() WHERE id = ?";
            $status_stmt = mysqli_prepare($conn, $status_update);
            mysqli_stmt_bind_param($status_stmt, "i", $claim_id);
            mysqli_stmt_execute($status_stmt);
            
            // Create notification for admin
            $admin_query = "SELECT id FROM users WHERE role IN ('super_admin', 'commissioner') LIMIT 1";
            $admin_result = mysqli_query($conn, $admin_query);
            $admin = mysqli_fetch_assoc($admin_result);
            
            if ($admin) {
                $notif_title = "Tathmini Imekamilika";
                $notif_message = "Tathmini ya dai " . $claim['claim_number'] . " imekamilika. Fidia: TZS " . number_format($total_compensation, 0);
                $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, 'valuation', NOW())";
                $notif_stmt = mysqli_prepare($conn, $notif_query);
                mysqli_stmt_bind_param($notif_stmt, "iss", $admin['id'], $notif_title, $notif_message);
                mysqli_stmt_execute($notif_stmt);
            }
            
            // Create notification for claimant
            $claimant_notif_title = "Tathmini ya Mali Yako Imekamilika";
            $claimant_notif_message = "Tathmini ya mali yako kwa dai " . $claim['claim_number'] . " imekamilika. Fidia iliyopendekezwa ni TZS " . number_format($total_compensation, 0);
            $claimant_notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                                    VALUES (?, ?, ?, 'valuation', NOW())";
            $claimant_notif_stmt = mysqli_prepare($conn, $claimant_notif_query);
            mysqli_stmt_bind_param($claimant_notif_stmt, "iss", $claim['claimant_id'], $claimant_notif_title, $claimant_notif_message);
            mysqli_stmt_execute($claimant_notif_stmt);
            
            $_SESSION['success_message'] = "Valuation submitted successfully for claim " . $claim['claim_number'];
            logAudit($conn, $user_id, 'CREATE_VALUATION', 'valuations', $valuation_id);
        }
        
        mysqli_commit($conn);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error_message'] = $e->getMessage();
        error_log("Valuation Error: " . $e->getMessage());
    }
} else {
    $_SESSION['error_message'] = implode("<br>", $errors);
}

// Redirect back
header("Location: valuations.php");
exit();
?>