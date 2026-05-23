<?php
// admin/payments.php - Manage Claim Payments with Full Audit Logging
session_start();

// Include required files
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';

// ========== AJAX HANDLERS - MUST BE AT THE TOP ==========

// Handle AJAX get pending claims
if (isset($_GET['ajax_get_pending_claims'])) {
    header('Content-Type: application/json');
    $conn = getDB();
    $pending_query = "SELECT c.id, c.claim_number, c.project_name, u.full_name, 
                      COALESCE(v.total_compensation, 0) as total_compensation
                      FROM claims c
                      JOIN users u ON c.claimant_id = u.id
                      LEFT JOIN valuations v ON c.id = v.claim_id
                      WHERE c.status = 'approved'
                      ORDER BY c.created_at ASC";
    $pending_result = mysqli_query($conn, $pending_query);
    $pending = [];
    while ($row = mysqli_fetch_assoc($pending_result)) {
        $pending[] = $row;
    }
    echo json_encode(['success' => true, 'claims' => $pending]);
    exit();
}

// Handle AJAX get claim for payment
if (isset($_GET['ajax_get_claim']) && isset($_GET['claim_id'])) {
    header('Content-Type: application/json');
    $conn = getDB();
    $claim_id = intval($_GET['claim_id']);
    $query = "SELECT c.id, c.claim_number, c.project_name, 
              u.full_name as claimant_name, u.email, u.phone,
              COALESCE(v.total_compensation, 0) as total_compensation,
              p.id as payment_id, p.amount, p.payment_method, p.transaction_reference, p.payment_status, p.notes
              FROM claims c
              JOIN users u ON c.claimant_id = u.id
              LEFT JOIN valuations v ON c.id = v.claim_id
              LEFT JOIN payments p ON c.id = p.claim_id
              WHERE c.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $claim_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $claim = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $claim]);
    exit();
}

// Handle AJAX get single payment details
if (isset($_GET['ajax_get_payment']) && isset($_GET['payment_id'])) {
    header('Content-Type: application/json');
    $conn = getDB();
    $payment_id = intval($_GET['payment_id']);
    $query = "SELECT p.*, c.claim_number, c.project_name, c.status as claim_status,
              u.full_name as claimant_name,
              creator.full_name as created_by_name,
              updater.full_name as updated_by_name
              FROM payments p
              JOIN claims c ON p.claim_id = c.id
              JOIN users u ON c.claimant_id = u.id
              LEFT JOIN users creator ON p.created_by = creator.id
              LEFT JOIN users updater ON p.updated_by = updater.id
              WHERE p.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $payment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $payment = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'data' => $payment]);
    exit();
}

// Handle export payments
if (isset($_GET['export'])) {
    $conn = getDB();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Claim Number', 'Claimant Name', 'Project Name', 'Amount (TZS)',
        'Payment Method', 'Transaction Reference', 'Payment Status', 'Payment Date',
        'Created By', 'Created At', 'Notes'
    ]);
    
    $export_query = "SELECT c.claim_number, u.full_name, c.project_name, 
                     p.amount, p.payment_method, p.transaction_reference, 
                     p.payment_status, p.paid_at,
                     creator.full_name as created_by_name, p.created_at, p.notes
                     FROM payments p
                     JOIN claims c ON p.claim_id = c.id
                     JOIN users u ON c.claimant_id = u.id
                     LEFT JOIN users creator ON p.created_by = creator.id
                     ORDER BY p.paid_at DESC";
    
    $export_result = mysqli_query($conn, $export_query);
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, [
            $row['claim_number'],
            $row['full_name'],
            $row['project_name'],
            number_format($row['amount'], 2),
            $row['payment_method'],
            $row['transaction_reference'],
            $row['payment_status'],
            $row['paid_at'],
            $row['created_by_name'] ?? 'System',
            $row['created_at'],
            $row['notes']
        ]);
    }
    fclose($output);
    exit();
}

// Check if user is logged in and is admin or finance officer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'finance_officer') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page variables
$page_title = 'Manage Payments';
$page_heading = 'Usimamizi wa Malipo';

// Get database connection
$conn = getDB();
$current_user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'paid_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Validate sort column
$allowed_sort_columns = ['paid_at', 'amount', 'payment_status', 'claim_number', 'full_name', 'created_at'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'paid_at';
}
$sort_order = ($sort_order === 'ASC') ? 'ASC' : 'DESC';

// Build query
$where_clauses = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where_clauses[] = "p.payment_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(c.claim_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR p.transaction_reference LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Get total payments count
$count_query = "SELECT COUNT(*) as total 
                FROM payments p 
                JOIN claims c ON p.claim_id = c.id 
                JOIN users u ON c.claimant_id = u.id 
                $where_sql";
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_payments = mysqli_fetch_assoc($count_result)['total'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total_pages = ceil($total_payments / $per_page);

// Get payments data with audit info
$query = "SELECT p.*, 
          c.claim_number, c.project_name, c.status as claim_status,
          u.full_name as claimant_name, u.email, u.phone, u.nin,
          COALESCE(v.total_compensation, 0) as total_compensation,
          creator.full_name as created_by_name,
          updater.full_name as updated_by_name
          FROM payments p
          JOIN claims c ON p.claim_id = c.id
          JOIN users u ON c.claimant_id = u.id
          LEFT JOIN valuations v ON c.id = v.claim_id
          LEFT JOIN users creator ON p.created_by = creator.id
          LEFT JOIN users updater ON p.updated_by = updater.id
          $where_sql
          ORDER BY ";

if ($sort_by === 'claim_number') {
    $query .= "c.claim_number $sort_order";
} elseif ($sort_by === 'full_name') {
    $query .= "u.full_name $sort_order";
} elseif ($sort_by === 'amount') {
    $query .= "p.amount $sort_order";
} elseif ($sort_by === 'payment_status') {
    $query .= "p.payment_status $sort_order";
} elseif ($sort_by === 'created_at') {
    $query .= "p.created_at $sort_order";
} else {
    $query .= "p.paid_at $sort_order";
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
    $payments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $payments[] = $row;
    }
} else {
    $payments = [];
}

// Get status counts
$status_counts = [];
$status_query = "SELECT payment_status, COUNT(*) as count FROM payments GROUP BY payment_status";
$status_result = mysqli_query($conn, $status_query);
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['payment_status']] = $row['count'];
}

// Get claims pending payment
$pending_claims_query = "SELECT c.id, c.claim_number, c.project_name, u.full_name, 
                         COALESCE(v.total_compensation, 0) as total_compensation
                         FROM claims c
                         JOIN users u ON c.claimant_id = u.id
                         LEFT JOIN valuations v ON c.id = v.claim_id
                         LEFT JOIN payments p ON c.id = p.claim_id
                         WHERE c.status = 'approved' AND (p.id IS NULL OR p.payment_status != 'completed')
                         ORDER BY c.created_at ASC";
$pending_claims_result = mysqli_query($conn, $pending_claims_query);
$pending_claims = [];
while ($row = mysqli_fetch_assoc($pending_claims_result)) {
    $pending_claims[] = $row;
}

// Payment methods
$payment_methods = [
    'bank_transfer' => 'Bank Transfer (Benki)',
    'mobile_money' => 'Mobile Money (M-Pesa, Tigo Pesa, Airtel Money)',
    'cash' => 'Cash (Fedha Taslimu)',
    'cheque' => 'Cheque (Hundi)'
];

// Handle process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $claim_id = intval($_POST['claim_id']);
    $amount = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    $transaction_reference = trim($_POST['transaction_reference']);
    $payment_status = 'completed';
    $paid_at = date('Y-m-d H:i:s');
    $notes = trim($_POST['notes'] ?? '');
    
    $errors = [];
    
    if ($claim_id <= 0) {
        $errors[] = "Dai halijachaguliwa";
    }
    
    if ($amount <= 0) {
        $errors[] = "Kiasi cha malipo kinahitajika";
    }
    
    if (empty($payment_method)) {
        $errors[] = "Tafadhali chagua njia ya malipo";
    }
    
    if (empty($errors)) {
        $check_query = "SELECT id, payment_status, amount, payment_method, transaction_reference FROM payments WHERE claim_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $claim_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            mysqli_stmt_bind_result($check_stmt, $old_id, $old_status, $old_amount, $old_method, $old_reference);
            mysqli_stmt_fetch($check_stmt);
            
            $old_data = [
                'amount' => $old_amount,
                'payment_method' => $old_method,
                'transaction_reference' => $old_reference,
                'payment_status' => $old_status
            ];
            
            $update_query = "UPDATE payments SET 
                             amount = ?, 
                             payment_method = ?, 
                             transaction_reference = ?, 
                             payment_status = ?, 
                             paid_at = ?,
                             updated_by = ?,
                             notes = ?,
                             updated_at = NOW()
                             WHERE claim_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "dssssisi", 
                $amount, $payment_method, $transaction_reference, $payment_status, $paid_at, 
                $current_user_id, $notes, $claim_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                mysqli_query($conn, "UPDATE claims SET status = 'paid', updated_at = NOW() WHERE id = $claim_id");
                
                $new_data = [
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'transaction_reference' => $transaction_reference,
                    'payment_status' => $payment_status
                ];
                
                $_SESSION['success_message'] = "Malipo yamesasishwa kikamilifu.";
                logAudit($conn, $current_user_id, 'UPDATE_PAYMENT', 'payments', $claim_id, $old_data, $new_data);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kusasisha malipo: " . mysqli_error($conn);
            }
        } else {
            $insert_query = "INSERT INTO payments (claim_id, amount, payment_method, transaction_reference, payment_status, paid_at, created_by, notes, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "idssssis", 
                $claim_id, $amount, $payment_method, $transaction_reference, $payment_status, $paid_at, 
                $current_user_id, $notes);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                mysqli_query($conn, "UPDATE claims SET status = 'paid', updated_at = NOW() WHERE id = $claim_id");
                
                $payment_data = [
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'transaction_reference' => $transaction_reference,
                    'claim_id' => $claim_id
                ];
                
                $_SESSION['success_message'] = "Malipo yamefanywa kikamilifu.";
                logAudit($conn, $current_user_id, 'CREATE_PAYMENT', 'payments', $claim_id, null, $payment_data);
                
                $claim_query = "SELECT claimant_id FROM claims WHERE id = ?";
                $claim_stmt = mysqli_prepare($conn, $claim_query);
                mysqli_stmt_bind_param($claim_stmt, "i", $claim_id);
                mysqli_stmt_execute($claim_stmt);
                $claim_result = mysqli_stmt_get_result($claim_stmt);
                $claim_data = mysqli_fetch_assoc($claim_result);
                
                if ($claim_data) {
                    $notif_title = "Malipo Yamefanywa";
                    $notif_message = "Malipo yako ya TZS " . number_format($amount, 0) . " kwa dai lako yamefanywa kikamilifu.";
                    $notif_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                                   VALUES (?, ?, ?, 'payment', NOW())";
                    $notif_stmt = mysqli_prepare($conn, $notif_query);
                    mysqli_stmt_bind_param($notif_stmt, "iss", $claim_data['claimant_id'], $notif_title, $notif_message);
                    mysqli_stmt_execute($notif_stmt);
                }
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kufanya malipo: " . mysqli_error($conn);
            }
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
    
    header("Location: payments.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!empty($selected_ids) && is_array($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        
        if ($action === 'mark_completed') {
            $old_data_query = "SELECT id, payment_status FROM payments WHERE id IN ($placeholders)";
            $old_stmt = mysqli_prepare($conn, $old_data_query);
            $old_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($old_stmt, $old_types, ...$selected_ids);
            mysqli_stmt_execute($old_stmt);
            $old_result = mysqli_stmt_get_result($old_stmt);
            $old_statuses = [];
            while ($row = mysqli_fetch_assoc($old_result)) {
                $old_statuses[$row['id']] = $row['payment_status'];
            }
            
            $update_query = "UPDATE payments SET payment_status = 'completed', updated_by = ?, updated_at = NOW() WHERE id IN ($placeholders)";
            $update_stmt = mysqli_prepare($conn, $update_query);
            $update_types = "i" . str_repeat("i", count($selected_ids));
            $params_array = array_merge([$current_user_id], $selected_ids);
            mysqli_stmt_bind_param($update_stmt, $update_types, ...$params_array);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $affected = mysqli_stmt_affected_rows($update_stmt);
                $_SESSION['success_message'] = "Malipo $affected yamewekwa kama yamekamilika.";
                logAudit($conn, $current_user_id, 'BULK_UPDATE_PAYMENTS', 'payments', null, ['statuses' => $old_statuses], ['status' => 'completed']);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kubadilisha malipo.";
            }
        } elseif ($action === 'delete') {
            $details_query = "SELECT id, amount, claim_id FROM payments WHERE id IN ($placeholders)";
            $details_stmt = mysqli_prepare($conn, $details_query);
            $details_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($details_stmt, $details_types, ...$selected_ids);
            mysqli_stmt_execute($details_stmt);
            $details_result = mysqli_stmt_get_result($details_stmt);
            $deleted_payments = [];
            while ($row = mysqli_fetch_assoc($details_result)) {
                $deleted_payments[] = $row;
            }
            
            $delete_query = "DELETE FROM payments WHERE id IN ($placeholders)";
            $delete_stmt = mysqli_prepare($conn, $delete_query);
            $delete_types = str_repeat("i", count($selected_ids));
            mysqli_stmt_bind_param($delete_stmt, $delete_types, ...$selected_ids);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                $affected = mysqli_stmt_affected_rows($delete_stmt);
                $_SESSION['success_message'] = "Malipo $affected yamefutwa kikamilifu.";
                logAudit($conn, $current_user_id, 'BULK_DELETE_PAYMENTS', 'payments', null, ['deleted' => $deleted_payments], null);
            } else {
                $_SESSION['error_message'] = "Hitilafu katika kufuta malipo.";
            }
        }
    }
    
    header("Location: payments.php?status=$status_filter&search=" . urlencode($search_term) . "&page=$page");
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        gap: 0.25rem;
    }
    .status-badge.completed { background: #d1fae5; color: #065f46; }
    .status-badge.processed { background: #fef3c7; color: #92400e; }
    .status-badge.pending { background: #fed7aa; color: #9a3412; }
    
    .claim-status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.65rem;
        font-weight: 600;
    }
    .claim-status-badge.approved { background: #d1fae5; color: #065f46; }
    .claim-status-badge.paid { background: #a7f3d0; color: #064e3b; }
    
    .payments-table { width: 100%; border-collapse: collapse; }
    .payments-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; background-color: #eef6ea; border-bottom: 1px solid #bccab9; }
    .payments-table td { padding: 0.875rem 1rem; border-bottom: 1px solid #e8f0e4; vertical-align: middle; }
    .payments-table tr:hover { background-color: #eef6ea; }
    
    .action-btn { background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 0.5rem; color: #6d7b6c; transition: all 0.2s; }
    .action-btn:hover { background-color: #e8f0e4; color: #006e2c; }
    
    .filter-tab { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 500; transition: all 0.2s ease; text-decoration: none; }
    .filter-tab.active { background-color: #006e2c; color: white; }
    .filter-tab:not(.active):hover { background-color: #e8f0e4; color: #1e2a1e; }
    
    .checkbox-select { width: 1rem; height: 1rem; accent-color: #006e2c; cursor: pointer; }
    .pagination-btn { padding: 0.375rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.75rem; transition: all 0.15s ease; text-decoration: none; color: #3d4a3d; }
    .pagination-btn:hover:not(.active) { background-color: #eef6ea; }
    .pagination-btn.active { background-color: #006e2c; color: white; border-color: #006e2c; }
    
    .payment-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .payment-modal-overlay.show { opacity: 1; visibility: visible; }
    .payment-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 650px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .payment-modal-overlay.show .payment-modal-container { transform: scale(1); }
    .payment-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .payment-modal-body { padding: 1.5rem; }
    .payment-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e8f0e4; display: flex; justify-content: flex-end; gap: 0.75rem; background: white; }
    
    .view-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.3s ease; backdrop-filter: blur(4px); }
    .view-modal-overlay.show { opacity: 1; visibility: visible; }
    .view-modal-container { background: white; border-radius: 1.5rem; width: 95%; max-width: 600px; max-height: 90vh; overflow-y: auto; transform: scale(0.95); transition: transform 0.3s ease; }
    .view-modal-overlay.show .view-modal-container { transform: scale(1); }
    .view-modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e8f0e4; display: flex; justify-content: space-between; align-items: center; background: #f4fcef; position: sticky; top: 0; }
    .view-modal-body { padding: 1.5rem; }
    
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #3d4a3d; margin-bottom: 0.25rem; }
    .form-label.required::after { content: "*"; color: #dc2626; margin-left: 0.25rem; }
    .form-control, .form-select, .form-textarea { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #bccab9; border-radius: 0.5rem; font-size: 0.875rem; }
    .form-control:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #006e2c; box-shadow: 0 0 0 3px rgba(0,110,44,0.1); }
    
    .info-row { display: flex; padding: 0.5rem 0; border-bottom: 1px solid #e8f0e4; }
    .info-label { width: 35%; font-weight: 600; color: #3d4a3d; }
    .info-value { width: 65%; color: #1e2a1e; }
    
    .amount-positive { color: #006e2c; font-weight: 700; }
    
    .pending-claims-list { max-height: 400px; overflow-y: auto; }
    .pending-claim-item { padding: 0.75rem; border-bottom: 1px solid #e8f0e4; cursor: pointer; transition: background 0.15s; text-align: left; }
    .pending-claim-item:hover { background-color: #eef6ea; }
    
    @media (max-width: 640px) {
        .info-row { flex-direction: column; }
        .info-label { width: 100%; margin-bottom: 0.25rem; }
        .info-value { width: 100%; }
    }
</style>

<!-- Page Content -->
<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="font-headline-lg text-on-background">Usimamizi wa Malipo</h2>
            <p class="text-secondary text-sm mt-1">Simamia, kagua na usindikie malipo ya fidia kwa wadai</p>
        </div>
        <div class="flex gap-3">
            <button onclick="openPaymentModal()" class="px-4 py-2 bg-primary text-white rounded-lg flex items-center gap-2 hover:bg-primary-container transition shadow-sm">
                <span class="material-symbols-outlined text-sm">payments</span> Fanya Malipo
            </button>
            <button onclick="exportPayments()" class="px-4 py-2 border border-outline-variant rounded-lg flex items-center gap-2 hover:bg-surface-container-low transition">
                <span class="material-symbols-outlined text-sm">download</span> Export
            </button>
        </div>
    </div>
    
    <!-- Status Filter Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-outline-variant pb-3">
        <a href="?status=all&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : 'text-secondary'; ?>">Zote (<?php echo array_sum($status_counts); ?>)</a>
        <a href="?status=completed&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : 'text-secondary'; ?>">Yamekamilika (<?php echo $status_counts['completed'] ?? 0; ?>)</a>
        <a href="?status=processed&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'processed' ? 'active' : 'text-secondary'; ?>">Yanachakatwa (<?php echo $status_counts['processed'] ?? 0; ?>)</a>
        <a href="?status=pending&search=<?php echo urlencode($search_term); ?>" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : 'text-secondary'; ?>">Yanatarajiwa (<?php echo $status_counts['pending'] ?? 0; ?>)</a>
    </div>
    
    <!-- Search and Bulk -->
    <div class="flex flex-col md:flex-row gap-4">
        <form method="GET" action="" class="flex-1" id="searchForm">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-xl">search</span>
                <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="Tafuta kwa namba ya dai, jina la mwombaji, barua pepe au namba ya marejeleo..." 
                       class="w-full pl-10 pr-4 py-2.5 border border-outline rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
            </div>
        </form>
        
        <div class="flex gap-2">
            <select id="bulk_action_select" class="px-3 py-2.5 border border-outline rounded-lg bg-white text-sm">
                <option value="">Bulk Action</option>
                <option value="mark_completed">Weka Yamekamilika</option>
                <option value="delete">Futa Malipo</option>
            </select>
            <button onclick="applyBulkAction()" class="px-4 py-2.5 bg-primary text-white rounded-lg hover:bg-primary-container transition shadow-sm">
                Tumia
            </button>
        </div>
    </div>
    
    <!-- Payments Table -->
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <form id="bulk_form" method="POST">
                <input type="hidden" name="bulk_action" id="bulk_action_value">
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th class="w-10"><input type="checkbox" id="select_all" class="checkbox-select"></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'claim_number', 'order' => $sort_by == 'claim_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Namba ya Dai</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'full_name', 'order' => $sort_by == 'full_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Mwombaji</a></th>
                            <th>Mradi</th>
                            <th class="text-right"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'amount', 'order' => $sort_by == 'amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Kiasi (TZS)</a></th>
                            <th>Njia ya Malipo</th>
                            <th>Namba ya Marejeleo</th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'payment_status', 'order' => $sort_by == 'payment_status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Hali ya Malipo</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'paid_at', 'order' => $sort_by == 'paid_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">Tarehe ya Malipo</a></th>
                            <th class="text-center">Hatua</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-12 text-secondary">
                                <span class="material-symbols-outlined text-5xl mb-2 block">payments</span>
                                Hakuna malipo yanayoendana na vigezo vyako
                                <div class="mt-2 text-sm">Bonyeza "Fanya Malipo" kuongeza malipo mapya</div>
                             </td>
                         </tr>
                        <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr id="row-<?php echo $payment['id']; ?>">
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo $payment['id']; ?>" class="checkbox-select payment-checkbox"></td>
                            <td class="font-mono text-sm font-semibold"><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                            <td>
                                <div class="font-medium"><?php echo htmlspecialchars($payment['claimant_name']); ?></div>
                                <div class="text-xs text-secondary"><?php echo htmlspecialchars($payment['email']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($payment['project_name'] ?? '-'); ?></td>
                            <td class="text-right amount-positive">TZS <?php echo number_format($payment['amount'] ?? 0, 0, '.', ','); ?></td>
                            <td><?php echo str_replace('_', ' ', ucfirst($payment['payment_method'] ?? '-')); ?></td>
                            <td class="font-mono text-xs"><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                            <td>
                                <span class="status-badge <?php echo $payment['payment_status']; ?>">
                                    <?php 
                                    $status_icons = [
                                        'completed' => '✅',
                                        'processed' => '🔄',
                                        'pending' => '⏳'
                                    ];
                                    echo ($status_icons[$payment['payment_status']] ?? '') . ' ' . ucfirst($payment['payment_status']);
                                    ?>
                                </span>
                            </td>
                            <td class="text-sm text-secondary"><?php echo $payment['paid_at'] ? formatDate($payment['paid_at'], 'd M Y') : '-'; ?></td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <button type="button" class="action-btn" onclick="viewPayment(<?php echo $payment['id']; ?>)" title="Angalia Maelezo">
                                        <span class="material-symbols-outlined">visibility</span>
                                    </button>
                                    <?php if ($payment['payment_status'] !== 'completed'): ?>
                                    <button type="button" class="action-btn" onclick="editPayment(<?php echo $payment['claim_id']; ?>)" title="Hariri Malipo">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <?php endif; ?>
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
        <div class="flex items-center justify-between px-4 py-3 border-t border-outline-variant bg-surface-container-low">
            <div class="text-sm text-secondary">
                Inaonyesha <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_payments); ?> kati ya <?php echo $total_payments; ?>
            </div>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Awali</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>&page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_term); ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Inayofuata</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="payment-modal-overlay">
    <div class="payment-modal-container">
        <div class="payment-modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">payments</span>
                <h3 class="text-lg font-semibold">Fanya Malipo</h3>
            </div>
            <button type="button" id="closePaymentModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <form id="paymentForm" method="POST" action="">
            <input type="hidden" name="process_payment" value="1">
            <input type="hidden" id="payment_claim_id" name="claim_id">
            <div class="payment-modal-body">
                <div class="bg-surface-container-low p-3 rounded-lg mb-4">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div><span class="text-secondary">Namba ya Dai:</span><br><span id="view_claim_number" class="font-semibold">-</span></div>
                        <div><span class="text-secondary">Mwombaji:</span><br><span id="view_claimant_name" class="font-semibold">-</span></div>
                        <div><span class="text-secondary">Mradi:</span><br><span id="view_project_name">-</span></div>
                        <div><span class="text-secondary">Fidia Iliyokubaliwa:</span><br><span id="view_total_compensation" class="amount-positive">-</span></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Kiasi cha Malipo (TZS)</label>
                    <input type="number" name="amount" id="payment_amount" class="form-control" step="1000" required placeholder="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Njia ya Malipo</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="">-- Chagua Njia --</option>
                        <?php foreach ($payment_methods as $value => $label): ?>
                            <option value="<?php echo $value; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Namba ya Marejeleo (Transaction Reference)</label>
                    <input type="text" name="transaction_reference" id="transaction_reference" class="form-control" placeholder="Mfano: TRX-2024-001">
                    <div class="form-hint">Kwa bank transfer au mobile money, ingiza namba ya marejeleo</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maelezo / Notes (Hiari)</label>
                    <textarea name="notes" id="payment_notes" class="form-textarea" rows="2" placeholder="Maelezo ya ziada kuhusu malipo..."></textarea>
                </div>
            </div>
            <div class="payment-modal-footer">
                <button type="button" id="cancelPaymentBtn" class="px-4 py-2 border border-outline-variant rounded-lg hover:bg-surface-container-low">Ghairi</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary-container transition">Wasilisha Malipo</button>
            </div>
        </form>
    </div>
</div>

<!-- View Payment Modal -->
<div id="viewModal" class="view-modal-overlay">
    <div class="view-modal-container">
        <div class="view-modal-header">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">receipt</span>
                <h3 class="text-lg font-semibold">Maelezo ya Malipo</h3>
            </div>
            <button type="button" id="closeViewModalBtn" class="p-1 hover:bg-surface-container-low rounded-lg">
                <span class="material-symbols-outlined text-secondary">close</span>
            </button>
        </div>
        <div class="view-modal-body" id="viewModalBody"></div>
    </div>
</div>

<script>
    let currentClaimId = null;
    
    function openPaymentModal() {
        Swal.fire({
            title: 'Chagua Dai la Kulipa',
            html: '<div class="pending-claims-list" id="pendingClaimsList"><div class="text-center py-4"><span class="material-symbols-outlined animate-spin">progress_activity</span> Inapakia...</div></div>',
            width: '600px',
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Funga',
            cancelButtonColor: '#ba1a1a',
            didOpen: async () => {
                try {
                    const response = await fetch('?ajax_get_pending_claims=1');
                    const data = await response.json();
                    const container = document.getElementById('pendingClaimsList');
                    
                    if (data.success && data.claims.length > 0) {
                        let html = '<div class="space-y-2">';
                        data.claims.forEach(claim => {
                            html += `<div class="pending-claim-item" onclick="selectClaimForPayment(${claim.id}, '${claim.claim_number}', '${claim.full_name}', ${claim.total_compensation || 0})">
                                        <div class="font-semibold">${claim.claim_number}</div>
                                        <div class="text-sm">${claim.full_name}</div>
                                        <div class="text-sm text-secondary">${claim.project_name || '-'}</div>
                                        <div class="text-primary font-bold mt-1">TZS ${(claim.total_compensation || 0).toLocaleString()}</div>
                                    </div>`;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="text-center py-4 text-secondary">Hakuna dai lililoidhinishwa lenye malipo yanayosubiri</div>';
                    }
                } catch (error) {
                    document.getElementById('pendingClaimsList').innerHTML = '<div class="text-center py-4 text-red-600">Hitilafu katika kupakia data</div>';
                }
            }
        });
    }
    
    function selectClaimForPayment(claimId, claimNumber, claimantName, totalCompensation) {
        Swal.close();
        currentClaimId = claimId;
        const modal = document.getElementById('paymentModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        document.getElementById('payment_claim_id').value = claimId;
        document.getElementById('view_claim_number').innerHTML = claimNumber;
        document.getElementById('view_claimant_name').innerHTML = claimantName;
        document.getElementById('view_total_compensation').innerHTML = 'TZS ' + (totalCompensation || 0).toLocaleString();
        document.getElementById('payment_amount').value = totalCompensation || 0;
        document.getElementById('payment_method').value = '';
        document.getElementById('transaction_reference').value = '';
        document.getElementById('payment_notes').value = '';
        
        fetchClaimDetails(claimId);
    }
    
    async function fetchClaimDetails(claimId) {
        try {
            const response = await fetch(`?ajax_get_claim=1&claim_id=${claimId}`);
            const data = await response.json();
            if (data.success) {
                document.getElementById('view_project_name').innerHTML = data.data.project_name || '-';
            }
        } catch (error) {
            console.error('Error fetching claim details:', error);
        }
    }
    
    function editPayment(claimId) {
        currentClaimId = claimId;
        const modal = document.getElementById('paymentModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        fetch(`?ajax_get_claim=1&claim_id=${claimId}`)
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const claim = data.data;
                    document.getElementById('payment_claim_id').value = claim.id;
                    document.getElementById('view_claim_number').innerHTML = claim.claim_number;
                    document.getElementById('view_claimant_name').innerHTML = claim.claimant_name;
                    document.getElementById('view_project_name').innerHTML = claim.project_name || '-';
                    document.getElementById('view_total_compensation').innerHTML = 'TZS ' + (claim.total_compensation || 0).toLocaleString();
                    document.getElementById('payment_amount').value = claim.amount || claim.total_compensation || 0;
                    document.getElementById('payment_method').value = claim.payment_method || '';
                    document.getElementById('transaction_reference').value = claim.transaction_reference || '';
                    document.getElementById('payment_notes').value = claim.notes || '';
                } else {
                    Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa' });
                    closePaymentModal();
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao' });
                closePaymentModal();
            });
    }
    
    function closePaymentModal() {
        const modal = document.getElementById('paymentModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    async function viewPayment(paymentId) {
        const modal = document.getElementById('viewModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        Swal.fire({ title: 'Inapakia...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`?ajax_get_payment=1&payment_id=${paymentId}`);
            const data = await response.json();
            Swal.close();
            
            if (data.success) {
                const payment = data.data;
                let html = `
                    <div class="space-y-3">
                        <div class="info-row"><div class="info-label">Namba ya Dai:</div><div class="info-value font-mono">${payment.claim_number}</div></div>
                        <div class="info-row"><div class="info-label">Mwombaji:</div><div class="info-value">${payment.claimant_name}</div></div>
                        <div class="info-row"><div class="info-label">Mradi:</div><div class="info-value">${payment.project_name || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Kiasi cha Malipo:</div><div class="info-value amount-positive">TZS ${(payment.amount || 0).toLocaleString()}</div></div>
                        <div class="info-row"><div class="info-label">Njia ya Malipo:</div><div class="info-value">${payment.payment_method ? payment.payment_method.replace('_', ' ') : '-'}</div></div>
                        <div class="info-row"><div class="info-label">Namba ya Marejeleo:</div><div class="info-value font-mono">${payment.transaction_reference || '-'}</div></div>
                        <div class="info-row"><div class="info-label">Hali ya Malipo:</div><div class="info-value"><span class="status-badge ${payment.payment_status}">${payment.payment_status}</span></div></div>
                        <div class="info-row"><div class="info-label">Tarehe ya Malipo:</div><div class="info-value">${payment.paid_at ? new Date(payment.paid_at).toLocaleDateString('sw-TZ') : '-'}</div></div>
                        <div class="info-row"><div class="info-label">Imeundwa na:</div><div class="info-value">${payment.created_by_name || 'System'}</div></div>
                        <div class="info-row"><div class="info-label">Imeundwa tarehe:</div><div class="info-value">${payment.created_at ? new Date(payment.created_at).toLocaleString('sw-TZ') : '-'}</div></div>
                        ${payment.updated_by_name ? `<div class="info-row"><div class="info-label">Imehaririwa na:</div><div class="info-value">${payment.updated_by_name}</div></div>` : ''}
                        ${payment.notes ? `<div class="info-row"><div class="info-label">Maelezo:</div><div class="info-value">${payment.notes}</div></div>` : ''}
                        <div class="info-row"><div class="info-label">Hali ya Dai:</div><div class="info-value"><span class="claim-status-badge ${payment.claim_status}">${payment.claim_status}</span></div></div>
                    </div>
                `;
                document.getElementById('viewModalBody').innerHTML = html;
            } else {
                Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Haikuweza kupata taarifa' });
                closeViewModal();
            }
        } catch (error) {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Hitilafu', text: 'Tatizo la mtandao' });
            closeViewModal();
        }
    }
    
    function closeViewModal() {
        const modal = document.getElementById('viewModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    const selectAll = document.getElementById('select_all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.payment-checkbox').forEach(cb => cb.checked = selectAll.checked);
        });
    }
    
    function applyBulkAction() {
        const selected = document.querySelectorAll('.payment-checkbox:checked');
        const action = document.getElementById('bulk_action_select').value;
        
        if (selected.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Hakuna Malipo', text: 'Chagua angalau malipo moja', confirmButtonColor: '#006e2c' });
            return;
        }
        
        if (!action) {
            Swal.fire({ icon: 'warning', title: 'Chagua Kitendo', text: 'Chagua kitendo cha kufanya', confirmButtonColor: '#006e2c' });
            return;
        }
        
        let title = '', text = '', confirmText = '';
        if (action === 'mark_completed') {
            title = 'Weka Malipo Yamekamilika';
            text = `Je, una uhakika unataka kuweka malipo ${selected.length} kama yamekamilika?`;
            confirmText = 'Ndiyo, Weka';
        } else if (action === 'delete') {
            title = 'Futa Malipo?';
            text = `Je, una uhakika unataka kufuta malipo ${selected.length}? Hatua haiwezi kutenduliwa.`;
            confirmText = 'Ndiyo, Futa';
        }
        
        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: confirmText,
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('bulk_action_value').value = action;
                document.getElementById('bulk_form').submit();
            }
        });
    }
    
    function exportPayments() {
        Swal.fire({
            title: 'Export Malipo',
            text: 'Je, unataka kupakua ripoti ya malipo?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#006e2c',
            cancelButtonColor: '#ba1a1a',
            confirmButtonText: 'Ndiyo, Pakua',
            cancelButtonText: 'Hapana'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?export=1';
            }
        });
    }
    
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
        });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('closePaymentModalBtn').addEventListener('click', closePaymentModal);
        document.getElementById('cancelPaymentBtn').addEventListener('click', closePaymentModal);
        document.getElementById('closeViewModalBtn').addEventListener('click', closeViewModal);
        
        document.getElementById('paymentModal').addEventListener('click', function(e) {
            if (e.target === this) closePaymentModal();
        });
        
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });
    });
    
    <?php if (!empty($success_message)): ?>
    Swal.fire({ icon: 'success', title: 'Mafanikio!', text: '<?php echo addslashes($success_message); ?>', confirmButtonColor: '#006e2c', timer: 3000 });
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
    Swal.fire({ icon: 'error', title: 'Hitilafu!', text: '<?php echo addslashes($error_message); ?>', confirmButtonColor: '#006e2c' });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>