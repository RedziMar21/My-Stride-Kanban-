<?php
session_start();
header("Content-Type: application/json");
// Be cautious with wildcard Access-Control-Allow-Origin in production
// For development:
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); // Your frontend URL
// For production, replace with your actual frontend domain:
// header("Access-Control-Allow-Origin: https://yourdomain.com");
header("Access-Control-Allow-Methods: GET, OPTIONS"); // Typically check_session is a GET request
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"); // Added more common headers
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content for preflight
    exit(0);
}

if (isset($_SESSION['user_id']) && isset($_SESSION['email'])) { // Changed 'user_email' to 'email'
    echo json_encode([
        "loggedIn" => true,
        "userId" => $_SESSION['user_id'], // Changed key for consistency
        "email" => $_SESSION['email'],
        "isAdmin" => isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false // Return admin status
    ]);
} else {
    echo json_encode(["loggedIn" => false]);
}
?>