<?php
session_start(); 
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); 
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

require 'db_connect.php';

$data = json_decode(file_get_contents("php://input"));

if (!$data || empty($data->email) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(["error" => "Email and password are required."]);
    exit;
}

$email = trim($data->email);
$password = trim($data->password);

$stmt = $conn->prepare("SELECT id, email, password_hash FROM users WHERE email = ?");
if (!$stmt) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed: " . $conn->error]); exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    if (password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        echo json_encode(["success" => true, "message" => "Login successful.", "user" => ["id" => $user['id'], "email" => $user['email']]]);
    } else {
        http_response_code(401); 
        echo json_encode(["error" => "Invalid email or password."]);
    }
} else {
    http_response_code(401); 
    echo json_encode(["error" => "Invalid email or password."]);
}
$stmt->close();
$conn->close();
?>