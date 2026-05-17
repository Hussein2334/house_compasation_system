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
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $old_data_json = $old_data ? json_encode($old_data) : null;
        $new_data_json = $new_data ? json_encode($new_data) : null;
        
        $query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ississss", 
            $user_id, 
            $action, 
            $table_name, 
            $record_id, 
            $old_data_json, 
            $new_data_json, 
            $ip_address, 
            $user_agent
        );
        
        return mysqli_stmt_execute($stmt);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
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
        $where_clauses[] = "action = ?";
        $params[] = $filters['action'];
        $types .= "s";
    }
    
    if (!empty($filters['table_name'])) {
        $where_clauses[] = "table_name = ?";
        $params[] = $filters['table_name'];
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
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Decode JSON data
        if ($row['old_data']) {
            $row['old_data'] = json_decode($row['old_data'], true);
        }
        if ($row['new_data']) {
            $row['new_data'] = json_decode($row['new_data'], true);
        }
        $logs[] = $row;
    }
    
    return $logs;
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