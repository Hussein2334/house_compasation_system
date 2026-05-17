<?php
// config/db.php
class Database {
    private $host = "localhost";
    private $db_name = "house_compensation_system";
    private $username = "root";
    private $password = "";
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->db_name);
            
            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8mb4");
            
        } catch(Exception $e) {
            error_log("Database Error: " . $e->getMessage());
            die("System error. Please try again later.");
        }
        
        return $this->conn;
    }
}

// Global function to get database connection
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $database = new Database();
        $conn = $database->getConnection();
    }
    return $conn;
}
?>