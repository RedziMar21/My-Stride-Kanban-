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

if (!isset($data->token) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(["error" => "Token and new password are required."]);
    exit;
}

if (strlen($data->password) < 6) {
    http_response_code(400);
    echo json_encode(["error" => "Password must be at least 6 characters."]);
    exit;
}

$token = $conn->real_escape_string($data->token);
$new_password = $data->password;

// Verify token and get email
$stmt_verify = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
if (!$stmt_verify) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (verify token): " . $conn->error]); exit;
}
$stmt_verify->bind_param("s", $token);
$stmt_verify->execute();
$result_verify = $stmt_verify->get_result();

if ($result_verify->num_rows === 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid or expired reset token."]);
    $stmt_verify->close();
    $conn->close();
    exit;
}

$row = $result_verify->fetch_assoc();
$email = $row['email'];
$stmt_verify->close();


$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);


$stmt_update_pass = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
if (!$stmt_update_pass) {
    http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (update pass): " . $conn->error]); exit;
}
$stmt_update_pass->bind_param("ss", $hashed_password, $email);

if ($stmt_update_pass->execute()) {
   
    $stmt_delete_token = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    if ($stmt_delete_token) {
        $stmt_delete_token->bind_param("s", $token);
        $stmt_delete_token->execute();
        $stmt_delete_token->close();
    } else {
        error_log("Failed to prepare statement to delete token: " . $conn->error);
    }

    http_response_code(200);
    echo json_encode(["success" => true, "message" => "Password reset successfully."]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Could not reset password. " . $stmt_update_pass->error]);
}

$stmt_update_pass->close();
$conn->close();
?>