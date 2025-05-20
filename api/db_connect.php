<?php
// api/db_config.php

// --- Your Provided Connection Details ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stride_kanban_db";
// --- End of Your Details ---

// Define constants for consistency with previous examples, though direct use is also fine.
define('DB_SERVER', $servername);
define('DB_USERNAME', $username);
define('DB_PASSWORD', $password);
define('DB_NAME', $dbname);

// Attempt to connect to MySQL database
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($mysqli->connect_error) {
    // Log the detailed error for server-side debugging
    error_log("Database connection failed: " . $mysqli->connect_error . " (Server: " . DB_SERVER . ", User: " . DB_USERNAME . ", DB: " . DB_NAME . ")");

    // Send a generic error response to the client for security
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed. Please contact support if the issue persists."]);
    exit; // Terminate script execution
}

// Set character set to utf8mb4
if (!$mysqli->set_charset("utf8mb4")) {
    // Log the error
    error_log("Error loading character set utf8mb4: " . $mysqli->error);

    // Optionally, you could decide to exit here if utf8mb4 is critical for your application
    // For now, we'll just log it and continue.
    // http_response_code(500);
    // echo json_encode(["error" => "Database character set configuration error."]);
    // exit;
}

// The $mysqli object is now ready for use in other PHP scripts that include this file.
?>