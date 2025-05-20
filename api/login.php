<?php
session_start(); // Should be one of the very first things

// --- CORS HEADERS ---
// Allow requests from your specific frontend development origin
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); // Ensure this matches your frontend
// Allow specific methods
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
// Allow specific headers. Crucially, Content-Type for JSON POSTs.
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Origin");
// Allow credentials (cookies, authorization headers)
header("Access-Control-Allow-Credentials: true");
// Optional: How long the results of a preflight request can be cached (in seconds)
// header("Access-Control-Max-Age: 86400");

// --- HANDLE PREFLIGHT (OPTIONS) REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // If it's an OPTIONS request, send 204 No Content and exit.
    // The headers above are all that's needed for the preflight.
    http_response_code(204);
    exit; // DO NOT process further for OPTIONS requests
}

// --- ACTUAL API LOGIC STARTS HERE ---
// This header is for the ACTUAL response, not the preflight
header("Content-Type: application/json");

// error_reporting(E_ALL); // Uncomment for debugging if needed
// ini_set('display_errors', 1); // Uncomment for debugging if needed

// Test DB connection first
require 'db_connect.php'; // Make sure this is your db_config.php and it defines $mysqli

if ($mysqli->connect_error) {
    error_log("Login.php - DB Connection Error: " . $mysqli->connect_error); // Log detailed error
    http_response_code(500);
    // Send generic error to client
    echo json_encode(["success" => false, "error" => "Database connection error. Please try again later."]);
    exit;
}
// Test point for DB connection (uncomment to test)
// echo json_encode(["success" => true, "message" => "DB connected."]);
// exit;

// Then test input parsing
$input_data = json_decode(file_get_contents("php://input"), true); // true for associative array

if (!$input_data || !isset($input_data['email']) || !isset($input_data['password'])) { // Check isset specifically
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email and password are required."]);
    exit;
}
// Test point for input parsing (uncomment to test)
// echo json_encode(["success" => true, "input_received" => $input_data]);
// exit;


$email = trim($input_data['email']);
$password = $input_data['password']; // Don't trim password, spaces can be intentional
$isAdminLoginAttempt = isset($input_data['isAdminLogin']) && $input_data['isAdminLogin'] === true;

// Use $mysqli which should be defined in db_connect.php (your db_config.php)
$stmt = $mysqli->prepare("SELECT id, email, password_hash, is_admin FROM users WHERE email = ?");
if (!$stmt) {
    error_log("Login.php - DB Prepare Failed: " . $mysqli->error);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "An internal server error occurred (DB prepare). Please try again later."]);
    exit;
}

$stmt->bind_param("s", $email);

if (!$stmt->execute()) {
    error_log("Login.php - DB Execute Failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "An internal server error occurred (DB execute). Please try again later."]);
    $stmt->close();
    $mysqli->close();
    exit;
}

$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user) {
    if (password_verify($password, $user['password_hash'])) {
        // Successful password verification

        if ($isAdminLoginAttempt && $user['is_admin'] != 1) {
            // User tried to log in as admin, but is not an admin
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'error' => 'Access denied. Not an administrator account.']);
            $mysqli->close();
            exit;
        }

        // Regenerate session ID for security upon login
        if (!session_regenerate_id(true)) {
            error_log("Login.php - Failed to regenerate session ID.");
            // Continue, but this is a potential security issue to investigate
        }


        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];

        echo json_encode([
            "success" => true,
            "message" => "Login successful.",
            "isAdmin" => $_SESSION['is_admin'],
            "userId" => $user['id']
        ]);

    } else {
        // Password does not match
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid email or password."]);
    }
} else {
    // No user found with that email
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid email or password."]);
}

$mysqli->close();
?>