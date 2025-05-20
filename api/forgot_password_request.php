<?php
require 'db_connect.php'; 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); 
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Valid email is required."]);
    exit;
}

$email = $conn->real_escape_string($data->email);


$stmt_check_user = $conn->prepare("SELECT id FROM users WHERE email = ?");
if (!$stmt_check_user) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (check user): " . $conn->error]); exit;
}
$stmt_check_user->bind_param("s", $email);
$stmt_check_user->execute();
$result_user = $stmt_check_user->get_result();

if ($result_user->num_rows === 0) {
    echo json_encode(["success" => true, "message" => "If an account with that email exists, a password reset link has been sent."]);
    $stmt_check_user->close();
    $conn->close();
    exit;
}
$stmt_check_user->close();

$token = bin2hex(random_bytes(32)); 
$expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); 

$stmt_store_token = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
if (!$stmt_store_token) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (store token): " . $conn->error]); exit;
}
$stmt_store_token->bind_param("sss", $email, $token, $expires_at);

if ($stmt_store_token->execute()) {
   
    $reset_link = "http://127.0.0.1:5500/index.html#resetPassword?token=" . $token;

    
    error_log("Password Reset Link for " . $email . ": " . $reset_link);
    

    http_response_code(200);
    echo json_encode(["success" => true, "message" => "If an account with that email exists, a password reset link has been sent (simulated)."]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Could not process password reset request. " . $stmt_store_token->error]);
}

$stmt_store_token->close();
$conn->close();
?>