<?php
// api/register.php (MySQLi Version - Updated for Auto-Login and Robustness)

session_start(); // MUST be at the very top

// --- CORS Headers ---
header("Access-Control-Allow-Origin: http://127.0.0.1:5500");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit();
}

header("Content-Type: application/json");

// error_reporting(E_ALL); // Uncomment for debugging if needed
// ini_set('display_errors', 1); // Uncomment for debugging

require_once 'db_connect.php'; // This file MUST define $mysqli

// Check if $mysqli was successfully initialized
if (!isset($mysqli) || $mysqli->connect_error) {
    error_log("Register.php - DB Connection Error: " . ($mysqli ? $mysqli->connect_error : "mysqli object not initialized or db_connect.php failed"));
    http_response_code(503);
    echo json_encode(["success" => false, "error" => "Database service unavailable. Please try again later."]);
    exit;
}

$input_data = json_decode(file_get_contents("php://input"), true);

if (!$input_data || !isset($input_data['email']) || !isset($input_data['password'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email and password are required."]);
    exit;
}

$email = trim($input_data['email']);
$password = $input_data['password']; // No trim on password

// Basic validation
if (empty($email)) {
    http_response_code(400); echo json_encode(["success" => false, "error" => "Email cannot be empty."]); exit;
}
if (empty($password)) {
    http_response_code(400); echo json_encode(["success" => false, "error" => "Password cannot be empty."]); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(["success" => false, "error" => "Invalid email format."]); exit;
}
if (strlen($password) < 6) {
    http_response_code(400); echo json_encode(["success" => false, "error" => "Password must be at least 6 characters."]); exit;
}

// Check if email already exists
$stmt_check = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
if (!$stmt_check) {
    error_log("Register.php - Check Email Prepare Failed: " . $mysqli->error);
    http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (R_CP)."]); exit;
}
$stmt_check->bind_param("s", $email);
if(!$stmt_check->execute()){
    error_log("Register.php - Check Email Execute Failed: " . $stmt_check->error);
    http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (R_CE)."]); $stmt_check->close(); $mysqli->close(); exit;
}
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    http_response_code(409); // Conflict
    echo json_encode(["success" => false, "error" => "This email address is already registered."]);
    $stmt_check->close(); $mysqli->close(); exit;
}
$stmt_check->close();

// Hash the password
$password_hash = password_hash($password, PASSWORD_DEFAULT);
if ($password_hash === false) {
    error_log("Register.php - Password hashing failed for email: " . $email);
    http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (R_PH)."]); exit;
}

// Insert new user
// Assumes your 'users' table has 'email', 'password_hash', and 'is_admin' (with DEFAULT 0)
$default_is_admin = 0; // New users are not admins

$stmt_insert = $mysqli->prepare("INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)");
if (!$stmt_insert) {
    error_log("Register.php - Insert User Prepare Failed: " . $mysqli->error);
    http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (R_IP)."]); exit;
}
// Types: s (email), s (password_hash), i (is_admin)
$stmt_insert->bind_param("ssi", $email, $password_hash, $default_is_admin);

if ($stmt_insert->execute()) {
    $new_user_id = $mysqli->insert_id;

    // ---- AUTOMATICALLY LOG IN THE USER ----
    if (session_status() == PHP_SESSION_ACTIVE) { // Ensure session is still active before regenerating
         if (!session_regenerate_id(true)) { // Regenerate session ID for security
            error_log("Register.php - session_regenerate_id failed.");
            // Decide if this is a critical failure. For now, we'll log and continue.
         }
    } else {
        error_log("Register.php - Session was not active before attempting to regenerate ID. This is unexpected after session_start().");
        // Attempt to start it again if somehow lost, though this indicates a deeper issue.
        // session_start(); 
    }


    $_SESSION['user_id'] = $new_user_id;
    $_SESSION['email'] = $email;
    $_SESSION['is_admin'] = false; // New users are explicitly not admins in the session

    // Log session data for debugging
    error_log("REGISTER.PHP - Session set after registration: user_id=" . ($new_user_id ?? 'NULL') . ", email=" . ($email ?? 'NULL') . ", is_admin=" . (isset($_SESSION['is_admin']) ? ($_SESSION['is_admin'] ? 'true' : 'false') : 'NOT_SET'));

    session_write_close(); // IMPORTANT: Explicitly write and close the session BEFORE sending output

    echo json_encode([
        "success" => true,
        "message" => "Registration successful. You are now logged in.",
        "userId" => $new_user_id, // Send user ID to frontend
        "isAdmin" => false        // Send admin status to frontend
    ]);
} else {
    error_log("Register.php - Insert User Execute Failed: " . $stmt_insert->error . " for email: " . $email);
    if ($mysqli->errno == 1062) { // Duplicate entry
         http_response_code(409);
         echo json_encode(["success" => false, "error" => "This email address is already registered (concurrent attempt)."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Registration failed. Please try again (R_IE)."]);
    }
}

$stmt_insert->close();
$mysqli->close();
?>