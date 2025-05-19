<?php
$servername = "localhost";
$username = "root";
$password = "";    
$dbname = "stride_kanban_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed: " . $conn->connect_error, "details" => "Check servername, username, password, and dbname."]));
}

if (!$conn->set_charset("utf8mb4")) {
  
}
?>