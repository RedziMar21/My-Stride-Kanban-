<?php
// api/admin_api.php
session_start();
header("Content-Type: application/json");
// Adjust Access-Control-Allow-Origin for production
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); // Your frontend URL
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

require 'db_connect.php'; // Or your db_config.php

// --- Admin Authentication Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Access denied. Administrator privileges required.']);
    $mysqli->close(); // Ensure DB connection is closed
    exit;
}

$action = $_GET['action'] ?? null;
$current_admin_id = $_SESSION['user_id']; // Get the ID of the currently logged-in admin

// --- GET Users ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_users') {
    $sql = "SELECT id, email, is_admin, created_at FROM users ORDER BY created_at DESC";
    $result = $mysqli->query($sql);
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['is_admin'] = (bool)$row['is_admin']; // Ensure boolean type
            $users[] = $row;
        }
        echo json_encode(['success' => true, 'users' => $users]);
    } else {
        error_log("Admin API - Get Users Error: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch users. Please try again later.']);
    }
}
// --- POST Toggle Admin Status ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_admin') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userIdToToggle = isset($input['user_id']) ? intval($input['user_id']) : 0;
    $makeAdmin = isset($input['make_admin']) ? (bool)$input['make_admin'] : false;

    if ($userIdToToggle <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user ID provided.']);
        $mysqli->close();
        exit;
    }

    // Prevent admin from changing their own status via this endpoint
    if ($userIdToToggle === $current_admin_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Administrators cannot change their own admin status using this function.']);
        $mysqli->close();
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    if (!$stmt) {
        error_log("Admin API - Toggle Admin Prepare Error: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to prepare user update. Please try again later.']);
        $mysqli->close();
        exit;
    }

    $newAdminStatus = $makeAdmin ? 1 : 0;
    $stmt->bind_param("ii", $newAdminStatus, $userIdToToggle);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'User admin status updated successfully.']);
        } else {
            // This could happen if the user ID doesn't exist or status is already set to the target value
            echo json_encode(['success' => true, 'message' => 'No change in user admin status (user not found or status already set).']);
        }
    } else {
        error_log("Admin API - Toggle Admin Execute Error: " . $stmt->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update user admin status. Please try again later.']);
    }
    $stmt->close();
}
// --- DELETE User ---
elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $action === 'delete_user') {
    $userIdToDelete = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    if ($userIdToDelete <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user ID provided for deletion.']);
        $mysqli->close();
        exit;
    }

    // Prevent admin from deleting their own account via this endpoint
    if ($userIdToDelete === $current_admin_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Administrators cannot delete their own account using this function.']);
        $mysqli->close();
        exit;
    }

    // Before deleting a user, you might want to handle their tasks:
    // Option 1: Delete tasks (cascading delete if DB supports it, or manual)
    // Option 2: Reassign tasks to a default admin or mark as unassigned
    // Option 3: Archive tasks
    // For simplicity, this example just deletes the user.
    // Add task handling logic here if needed. Example for deleting tasks:
    /*
    $stmt_tasks = $mysqli->prepare("DELETE FROM tasks WHERE user_id = ?");
    if ($stmt_tasks) {
        $stmt_tasks->bind_param("i", $userIdToDelete);
        $stmt_tasks->execute();
        $stmt_tasks->close();
    } else {
        error_log("Admin API - Delete User Tasks Prepare Error for user ID $userIdToDelete: " . $mysqli->error);
        // Decide if this is a fatal error for the user deletion process
    }
    */


    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("Admin API - Delete User Prepare Error: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to prepare user deletion. Please try again later.']);
        $mysqli->close();
        exit;
    }
    $stmt->bind_param("i", $userIdToDelete);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
        } else {
            // User ID might not exist
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'error' => 'User not found or already deleted.']);
        }
    } else {
        error_log("Admin API - Delete User Execute Error: " . $stmt->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete user. Please try again later.']);
    }
    $stmt->close();
}
// --- Invalid Action or Method ---
else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action or request method specified for admin API.']);
}

$mysqli->close();
?>