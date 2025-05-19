<?php
session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); 
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

if (isset($_SESSION['user_id']) && isset($_SESSION['user_email'])) {
    echo json_encode(["loggedIn" => true, "user" => ["id" => $_SESSION['user_id'], "email" => $_SESSION['user_email']]]);
} else {
    echo json_encode(["loggedIn" => false]);
}
?>