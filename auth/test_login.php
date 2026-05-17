<?php
// test_login.php - Test login directly
session_start();
require_once '../config/db.php';

$conn = getDB();
$message = '';

// Test login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $query = "SELECT id, email, full_name, role, password, status FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($user = mysqli_fetch_assoc($result)) {
        $verify = password_verify($password, $user['password']);
        
        $message = "<div style='padding: 20px; margin: 10px; border-radius: 8px;'>";
        $message .= "<h3>Matokeo ya Test:</h3>";
        $message .= "<p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>";
        $message .= "<p><strong>Jina:</strong> " . htmlspecialchars($user['full_name']) . "</p>";
        $message .= "<p><strong>Role:</strong> " . $user['role'] . "</p>";
        $message .= "<p><strong>Status:</strong> " . $user['status'] . "</p>";
        $message .= "<p><strong>Password Hash:</strong> <code>" . substr($user['password'], 0, 50) . "...</code></p>";
        $message .= "<p><strong>Password iliyoingizwa:</strong> " . htmlspecialchars($password) . "</p>";
        
        if ($verify) {
            $message .= "<p style='color: green; font-weight: bold;'>✅ PASSWORD SAHIHI! Unaweza kuingia.</p>";
            $message .= "<p><strong>Session ingefanya:</strong> user_id={$user['id']}, role={$user['role']}</p>";
        } else {
            $message .= "<p style='color: red; font-weight: bold;'>❌ PASSWORD SI SAHIHI!</p>";
            
            // Try to generate what the hash should be for this password
            $test_hash = password_hash($password, PASSWORD_DEFAULT);
            $message .= "<p><strong>Hash ya password iliyoingizwa:</strong> <code>" . $test_hash . "</code></p>";
        }
        $message .= "</div>";
    } else {
        $message = "<div style='padding: 20px; background: #fee; color: red; border-radius: 8px;'>";
        $message .= "❌ Email '{$email}' haipatikani kwenye database!";
        $message .= "</div>";
    }
}

// Get all users
$users_query = "SELECT id, email, full_name, role, status FROM users ORDER BY id";
$users_result = mysqli_query($conn, $users_query);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login Test - HCS</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .test-box { border: 1px solid #ccc; border-radius: 8px; padding: 20px; margin-bottom: 20px; background: #f9f9f9; }
        input { padding: 10px; margin: 5px; width: 200px; }
        button { padding: 10px 20px; background: #006e2c; color: white; border: none; border-radius: 5px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; }
        .success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    </style>
</head>
<body>
    <h1>🔧 Login Test - House Compensation System</h1>
    
    <div class="test-box">
        <h2>Jaribu Kuingia</h2>
        <form method="POST">
            <input type="email" name="email" placeholder="admin@hcs.go.tz" value="admin@hcs.go.tz" required>
            <input type="password" name="password" placeholder="Nenosiri" required>
            <button type="submit">Test Login</button>
        </form>
    </div>
    
    <?php echo $message; ?>
    
    <div class="test-box">
        <h2>Watumiaji Wote kwenye Database</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>Email</th><th>Jina</th><th>Role</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo $u['id']; ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                    <td><?php echo $u['role']; ?></td>
                    <td><?php echo $u['status']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="test-box">
        <h2>💡 Jinsi ya Kuweka Nenosiri Jipya kwa Admin</h2>
        <p>Endelea kwa kutumia SQL hii (badilisha 'YourNewPassword' na nenosiri unalotaka):</p>
        <code style="display: block; background: #333; color: #0f0; padding: 15px; border-radius: 5px; overflow-x: auto;">
        <?php
        $new_password = 'admin123';
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        echo "UPDATE users SET password = '{$new_hash}' WHERE email = 'admin@hcs.go.tz';\n";
        ?>
        </code>
        <p class="success" style="margin-top: 10px; padding: 10px;">
            ✅ Nenosiri "admin123" linakuwa hash: <?php echo password_hash('admin123', PASSWORD_DEFAULT); ?>
        </p>
    </div>
</body>
</html>