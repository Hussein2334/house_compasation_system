<?php
// set_password.php - Set admin password directly
require_once 'config/db.php';

$conn = getDB();

// Nenosiri unalotaka kutumia
$password = "admin123";

// Generate hash
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<pre>";
echo "========================================\n";
echo "WEKA UPYA NENOSIRI LA ADMIN\n";
echo "========================================\n\n";
echo "Nenosiri: " . $password . "\n";
echo "Hash: " . $hash . "\n\n";

// Update admin password
$query = "UPDATE users SET password = ? WHERE email = 'admin@hcs.go.tz' AND role = 'super_admin'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $hash);

if (mysqli_stmt_execute($stmt)) {
    if (mysqli_stmt_affected_rows($stmt) > 0) {
        echo "✅ Nenosiri limebadilishwa kikamilifu!\n";
        echo "📧 Email: admin@hcs.go.tz\n";
        echo "🔑 Nenosiri: " . $password . "\n";
    } else {
        echo "❌ Admin haipatikani! Angalia email 'admin@hcs.go.tz'\n";
        
        // Insert new admin if not exists
        echo "\n🇯🇲 Kujaribu kuunda admin mpya...\n";
        $insert = "INSERT INTO users (full_name, email, phone, role, password, status, created_at) 
                   VALUES ('Admin User', 'admin@hcs.go.tz', '0712345678', 'super_admin', ?, 'active', NOW())";
        $insert_stmt = mysqli_prepare($conn, $insert);
        mysqli_stmt_bind_param($insert_stmt, "s", $hash);
        if (mysqli_stmt_execute($insert_stmt)) {
            echo "✅ Admin mpya imeundwa!\n";
        } else {
            echo "❌ Hitilafu: " . mysqli_error($conn) . "\n";
        }
    }
} else {
    echo "❌ Hitilafu: " . mysqli_error($conn) . "\n";
}

echo "\n========================================\n";
echo "SASA JARIBU KUINGIA KWA:\n";
echo "Email: admin@hcs.go.tz\n";
echo "Password: " . $password . "\n";
echo "========================================\n";
?>