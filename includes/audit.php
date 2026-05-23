<?php
// includes/audit.php - Audit logging functions

/**
 * Log user activity in the system
 * @param mysqli $conn Database connection
 * @param int $user_id ID of the user performing action
 * @param string $action Action performed (e.g., 'LOGIN', 'CREATE_CLAIM', 'UPDATE_STATUS')
 * @param string $table_name Name of table affected (optional)
 * @param int $record_id ID of record affected (optional)
 * @param array|null $old_data Previous data before change (optional)
 * @param array|null $new_data New data after change (optional)
 * @return bool True if log was saved successfully
 */
function logAudit($conn, $user_id, $action, $table_name = null, $record_id = null, $old_data = null, $new_data = null) {
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Build action performed string with additional info
        $action_performed = $action;
        if ($table_name) {
            $action_performed .= " ON $table_name";
        }
        if ($record_id) {
            $action_performed .= " (ID: $record_id)";
        }
        
        // Add data changes if available
        if ($old_data || $new_data) {
            $changes = [];
            if ($old_data) {
                $changes['old'] = $old_data;
            }
            if ($new_data) {
                $changes['new'] = $new_data;
            }
            $action_performed .= " - " . json_encode($changes);
        }
        
        // Insert into audit_logs table (matching your database structure)
        $query = "INSERT INTO audit_logs (user_id, action_performed, ip_address, created_at) 
                  VALUES (?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iss", $user_id, $action_performed, $ip_address);
        
        return mysqli_stmt_execute($stmt);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log user login
 */
function logLogin($conn, $user_id) {
    return logAudit($conn, $user_id, 'LOGIN');
}

/**
 * Log user logout
 */
function logLogout($conn, $user_id) {
    return logAudit($conn, $user_id, 'LOGOUT');
}

/**
 * Log claim creation
 */
function logClaimCreation($conn, $user_id, $claim_id, $claim_number) {
    return logAudit($conn, $user_id, 'CREATE_CLAIM', 'claims', $claim_id, null, [
        'claim_id' => $claim_id,
        'claim_number' => $claim_number
    ]);
}

/**
 * Log claim update
 */
function logClaimUpdate($conn, $user_id, $claim_id, $old_data, $new_data) {
    return logAudit($conn, $user_id, 'UPDATE_CLAIM', 'claims', $claim_id, $old_data, $new_data);
}

/**
 * Log claim status change
 */
function logClaimStatusChange($conn, $user_id, $claim_id, $old_status, $new_status) {
    return logAudit($conn, $user_id, 'UPDATE_CLAIM_STATUS', 'claims', $claim_id, 
                    ['status' => $old_status], ['status' => $new_status]);
}

/**
 * Log claim deletion
 */
function logClaimDeletion($conn, $user_id, $claim_id, $claim_number) {
    return logAudit($conn, $user_id, 'DELETE_CLAIM', 'claims', $claim_id, ['claim_number' => $claim_number]);
}

/**
 * Log user creation
 */
function logUserCreation($conn, $user_id, $new_user_id, $user_data) {
    return logAudit($conn, $user_id, 'CREATE_USER', 'users', $new_user_id, null, $user_data);
}

/**
 * Log user update
 */
function logUserUpdate($conn, $user_id, $target_user_id, $old_data, $new_data) {
    return logAudit($conn, $user_id, 'UPDATE_USER', 'users', $target_user_id, $old_data, $new_data);
}

/**
 * Log user deletion
 */
function logUserDeletion($conn, $user_id, $deleted_user_id, $user_email) {
    return logAudit($conn, $user_id, 'DELETE_USER', 'users', $deleted_user_id, ['email' => $user_email]);
}

/**
 * Log valuation creation
 */
function logValuationCreation($conn, $user_id, $claim_id, $valuation_data) {
    return logAudit($conn, $user_id, 'CREATE_VALUATION', 'valuations', $claim_id, null, $valuation_data);
}

/**
 * Log valuation update
 */
function logValuationUpdate($conn, $user_id, $claim_id, $old_data, $new_data) {
    return logAudit($conn, $user_id, 'UPDATE_VALUATION', 'valuations', $claim_id, $old_data, $new_data);
}

/**
 * Log payment creation
 */
function logPaymentCreation($conn, $user_id, $claim_id, $payment_data) {
    return logAudit($conn, $user_id, 'CREATE_PAYMENT', 'payments', $claim_id, null, $payment_data);
}

/**
 * Log payment update
 */
function logPaymentUpdate($conn, $user_id, $claim_id, $old_data, $new_data) {
    return logAudit($conn, $user_id, 'UPDATE_PAYMENT', 'payments', $claim_id, $old_data, $new_data);
}

/**
 * Log payment deletion
 */
function logPaymentDeletion($conn, $user_id, $payment_id) {
    return logAudit($conn, $user_id, 'DELETE_PAYMENT', 'payments', $payment_id);
}

/**
 * Log bulk action
 */
function logBulkAction($conn, $user_id, $action, $table_name, $affected_ids, $details = null) {
    $message = "BULK_$action ON $table_name";
    if ($affected_ids) {
        $message .= " - Affected IDs: " . implode(',', $affected_ids);
    }
    if ($details) {
        $message .= " - " . json_encode($details);
    }
    return logAudit($conn, $user_id, $message);
}

/**
 * Get audit logs with filters
 * @param mysqli $conn Database connection
 * @param array $filters Filters to apply (user_id, action, from_date, to_date)
 * @param int $limit Number of records to return
 * @param int $offset Pagination offset
 * @return array Array of audit logs
 */
function getAuditLogs($conn, $filters = [], $limit = 50, $offset = 0) {
    $where_clauses = [];
    $params = [];
    $types = "";
    
    if (!empty($filters['user_id'])) {
        $where_clauses[] = "user_id = ?";
        $params[] = $filters['user_id'];
        $types .= "i";
    }
    
    if (!empty($filters['action'])) {
        $where_clauses[] = "action_performed LIKE ?";
        $params[] = "%{$filters['action']}%";
        $types .= "s";
    }
    
    if (!empty($filters['from_date'])) {
        $where_clauses[] = "DATE(created_at) >= ?";
        $params[] = $filters['from_date'];
        $types .= "s";
    }
    
    if (!empty($filters['to_date'])) {
        $where_clauses[] = "DATE(created_at) <= ?";
        $params[] = $filters['to_date'];
        $types .= "s";
    }
    
    $where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);
    
    $query = "SELECT al.*, u.full_name, u.email 
              FROM audit_logs al 
              LEFT JOIN users u ON al.user_id = u.id 
              $where_sql 
              ORDER BY al.created_at DESC 
              LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $logs = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $logs[] = $row;
        }
        return $logs;
    }
    
    return [];
}

/**
 * Get total count of audit logs with filters
 */
function getAuditLogsCount($conn, $filters = []) {
    $where_clauses = [];
    $params = [];
    $types = "";
    
    if (!empty($filters['user_id'])) {
        $where_clauses[] = "user_id = ?";
        $params[] = $filters['user_id'];
        $types .= "i";
    }
    
    if (!empty($filters['action'])) {
        $where_clauses[] = "action_performed LIKE ?";
        $params[] = "%{$filters['action']}%";
        $types .= "s";
    }
    
    if (!empty($filters['from_date'])) {
        $where_clauses[] = "DATE(created_at) >= ?";
        $params[] = $filters['from_date'];
        $types .= "s";
    }
    
    if (!empty($filters['to_date'])) {
        $where_clauses[] = "DATE(created_at) <= ?";
        $params[] = $filters['to_date'];
        $types .= "s";
    }
    
    $where_sql = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);
    
    $query = "SELECT COUNT(*) as total FROM audit_logs al $where_sql";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        return $row['total'];
    } elseif ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        return $row['total'];
    }
    
    return 0;
}

/**
 * Get user activity summary
 * @param mysqli $conn Database connection
 * @param int $user_id User ID (optional, if empty returns all users)
 * @return array Activity summary
 */
function getUserActivitySummary($conn, $user_id = null) {
    $query = "SELECT u.full_name, u.email, 
                     COUNT(al.id) as total_actions,
                     COUNT(DISTINCT DATE(al.created_at)) as active_days,
                     MAX(al.created_at) as last_activity
              FROM audit_logs al
              JOIN users u ON al.user_id = u.id";
    
    if ($user_id) {
        $query .= " WHERE al.user_id = $user_id";
    }
    
    $query .= " GROUP BY al.user_id ORDER BY total_actions DESC";
    
    $result = mysqli_query($conn, $query);
    
    $summary = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $summary[] = $row;
    }
    
    return $summary;
}

/**
 * Clean old audit logs (older than specified days)
 * @param mysqli $conn Database connection
 * @param int $days Number of days to keep (default: 90 days)
 * @return int Number of records deleted
 */
function cleanOldAuditLogs($conn, $days = 90) {
    $query = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $days);
    mysqli_stmt_execute($stmt);
    
    return mysqli_stmt_affected_rows($stmt);
}
?>