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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid email format."]);
    exit;
}
if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["error" => "Password must be at least 6 characters."]);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
if (!$stmt) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (select user): " . $conn->error]); exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    http_response_code(409); 
    echo json_encode(["error" => "User with this email already exists."]);
} else {
    $password_hash = password_hash($password, PASSWORD_BCRYPT); 
    $insert_stmt = $conn->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
    if (!$insert_stmt) {
        http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (insert user): " . $conn->error]); exit;
    }
    $insert_stmt->bind_param("ss", $email, $password_hash);
    
    if ($insert_stmt->execute()) {
        $_SESSION['user_id'] = $insert_stmt->insert_id;
        $_SESSION['user_email'] = $email;
        echo json_encode(["success" => true, "message" => "Registration successful. Logged in.", "user" => ["id" => $_SESSION['user_id'], "email" => $email]]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Registration failed: " . $insert_stmt->error]);
    }
    $insert_stmt->close();
}
$stmt->close();
$conn->close();
?>
