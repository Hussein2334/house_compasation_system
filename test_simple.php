<?php
echo "<h1>Test Page</h1>";

// Test 1: Basic PHP
echo "<p>Test 1: PHP is working</p>";

// Test 2: Database connection
echo "<p>Test 2: Trying to connect to database...</p>";

$host = "localhost";
$user = "root";
$password = "";
$database = "house_compensation_system";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    echo "<p style='color:red'>❌ Connection failed: " . mysqli_connect_error() . "</p>";
} else {
    echo "<p style='color:green'>✅ Database connected successfully!</p>";
    
    // Test 3: Query
    $result = mysqli_query($conn, "SELECT * FROM users");
    if ($result) {
        echo "<p style='color:green'>✅ Query successful! Total users: " . mysqli_num_rows($result) . "</p>";
        
        // Display users
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Full Name</th><th>Email</th><th>Role</th></tr>";
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['full_name'] . "</td>";
            echo "<td>" . $row['email'] . "</td>";
            echo "<td>" . $row['role'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red'>❌ Query failed: " . mysqli_error($conn) . "</p>";
    }
    
    mysqli_close($conn);
}

// Test 4: Check if config/db.php works
echo "<p>Test 4: Trying to include config/db.php...</p>";
if (file_exists('config/db.php')) {
    echo "<p style='color:green'>✅ config/db.php exists</p>";
    require_once 'config/db.php';
    
    if (class_exists('Database')) {
        echo "<p style='color:green'>✅ Database class exists</p>";
        
        $db = new Database();
        $conn2 = $db->getConnection();
        
        if ($conn2) {
            echo "<p style='color:green'>✅ Database::getConnection() works!</p>";
        } else {
            echo "<p style='color:red'>❌ Database::getConnection() failed</p>";
        }
    } else {
        echo "<p style='color:red'>❌ Database class not found</p>";
    }
} else {
    echo "<p style='color:red'>❌ config/db.php not found</p>";
}
?>