<?php
// api/admin_api.php
session_start();
header("Content-Type: application/json");

// --- CORS Headers ---
header("Access-Control-Allow-Origin: http://127.0.0.1:5500");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS"); // PUT is not used in this version of admin_api
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

require 'db_connect.php'; // This MUST define $mysqli

// --- Database Connection Check (after db_connect.php is required) ---
if (!$mysqli || $mysqli->connect_error) {
    http_response_code(503); // Service Unavailable
    error_log("Admin API - DB connection failed: " . ($mysqli ? $mysqli->connect_error : "mysqli object not initialized"));
    echo json_encode(["success" => false, "error" => "Database connection unavailable."]);
    exit;
}

// --- Admin Authentication Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Access denied. Administrator privileges required.']);
    $mysqli->close();
    exit;
}

$action = $_GET['action'] ?? null;
$current_admin_id = $_SESSION['user_id'];

// --- GET Users (WITH TASK COUNTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_users') {
    $sql = "SELECT u.id, u.email, u.is_admin, u.created_at,
                   COUNT(t.id) as total_tasks,
                   SUM(CASE WHEN t.is_archived = 0 THEN 1 ELSE 0 END) as active_tasks
            FROM users u
            LEFT JOIN tasks t ON u.id = t.user_id
            GROUP BY u.id, u.email, u.is_admin, u.created_at
            ORDER BY u.created_at DESC";

    $result = $mysqli->query($sql);
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['is_admin'] = (bool)$row['is_admin'];
            $row['total_tasks'] = isset($row['total_tasks']) ? (int)$row['total_tasks'] : 0;
            $row['active_tasks'] = isset($row['active_tasks']) ? (int)$row['active_tasks'] : 0;
            $users[] = $row;
        }
        echo json_encode(['success' => true, 'users' => $users]);
    } else {
        error_log("Admin API - Get Users (with counts) Error: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch users with task counts.']);
    }
}
// --- GET User's Tasks ---
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_user_tasks') {
    $target_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    if ($target_user_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid target user ID provided.']);
        $mysqli->close(); exit; // Close connection before exit
    }

    // Fetch user's email for display purposes in the frontend modal/section
    $user_email = 'Unknown User';
    $stmt_user_email = $mysqli->prepare("SELECT email FROM users WHERE id = ?");
    if ($stmt_user_email) {
        $stmt_user_email->bind_param("i", $target_user_id);
        if ($stmt_user_email->execute()) {
            $res_user_email = $stmt_user_email->get_result();
            if ($user_row = $res_user_email->fetch_assoc()) {
                $user_email = $user_row['email'];
            }
        } else {
            error_log("Admin API - Get User Email Execute Error: " . $stmt_user_email->error);
        }
        $stmt_user_email->close();
    } else {
         error_log("Admin API - Get User Email Prepare Error: " . $mysqli->error);
    }

    // Fetch tasks for the target user
    $sql_tasks = "SELECT id, text, priority, due_date, labels, column_id, sort_order, is_archived, created_at, updated_at
                  FROM tasks
                  WHERE user_id = ?
                  ORDER BY is_archived ASC, column_id ASC, sort_order ASC, created_at DESC";
    
    $stmt_tasks = $mysqli->prepare($sql_tasks);
    if (!$stmt_tasks) {
        error_log("Admin API - Get User Tasks Prepare Error: " . $mysqli->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to prepare statement for fetching user tasks.']);
        $mysqli->close(); exit;
    }
    $stmt_tasks->bind_param("i", $target_user_id);

    if (!$stmt_tasks->execute()) {
        error_log("Admin API - Get User Tasks Execute Error: " . $stmt_tasks->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to execute statement for fetching user tasks.']);
        $stmt_tasks->close(); $mysqli->close(); exit;
    }

    $result_tasks = $stmt_tasks->get_result();
    $user_tasks_list = [];
    while ($task_row = $result_tasks->fetch_assoc()) {
        $task_row['is_archived'] = (bool)$task_row['is_archived'];
        $task_row['due_date'] = $task_row['due_date'] ? date('Y-m-d', strtotime($task_row['due_date'])) : null;
        $user_tasks_list[] = $task_row;
    }
    $stmt_tasks->close();

    echo json_encode(['success' => true, 'user_email' => $user_email, 'tasks' => $user_tasks_list]);
}
// --- POST Toggle Admin Status ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_admin') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userIdToToggle = isset($input['user_id']) ? intval($input['user_id']) : 0;
    $makeAdmin = isset($input['make_admin']) ? (bool)$input['make_admin'] : false;

    if ($userIdToToggle <= 0) {
        http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid user ID.']); $mysqli->close(); exit;
    }
    if ($userIdToToggle === $current_admin_id) {
        http_response_code(400); echo json_encode(['success' => false, 'error' => 'Cannot change own admin status.']); $mysqli->close(); exit;
    }

    $stmt = $mysqli->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    if (!$stmt) {
        error_log("Admin API - Toggle Admin Prepare: " . $mysqli->error);
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'DB prepare error for toggle admin.']); $mysqli->close(); exit;
    }
    $newAdminStatus = $makeAdmin ? 1 : 0;
    $stmt->bind_param("ii", $newAdminStatus, $userIdToToggle);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'User admin status updated.']);
        } else {
             // Check if user exists to differentiate
            $check_user_stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ?");
            $check_user_stmt->bind_param("i", $userIdToToggle);
            $check_user_stmt->execute();
            $check_user_stmt->store_result();
            if ($check_user_stmt->num_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'User admin status was already set to the target value.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found for status update.']);
            }
            $check_user_stmt->close();
        }
    } else {
        error_log("Admin API - Toggle Admin Execute: " . $stmt->error);
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'Failed to update user admin status.']);
    }
    $stmt->close();
}
// --- DELETE User ---
elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $action === 'delete_user') {
    $userIdToDelete = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    if ($userIdToDelete <= 0) {
        http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid user ID for deletion.']); $mysqli->close(); exit;
    }
    if ($userIdToDelete === $current_admin_id) {
        http_response_code(400); echo json_encode(['success' => false, 'error' => 'Cannot delete own account.']); $mysqli->close(); exit;
    }

    // Transaction for deleting user and their tasks (if not using ON DELETE CASCADE)
    $mysqli->begin_transaction();
    try {
        // OPTION A: If tasks table has ON DELETE CASCADE for user_id, this step is not needed.
        // OPTION B: Manually delete tasks if no cascade or different handling needed.
        $stmt_tasks_delete = $mysqli->prepare("DELETE FROM tasks WHERE user_id = ?");
        if (!$stmt_tasks_delete) {
            throw new Exception("Prepare failed for deleting user tasks: " . $mysqli->error);
        }
        $stmt_tasks_delete->bind_param("i", $userIdToDelete);
        if (!$stmt_tasks_delete->execute()) {
            throw new Exception("Execute failed for deleting user tasks: " . $stmt_tasks_delete->error);
        }
        $stmt_tasks_delete->close();
        // Add other related data deletions here if necessary (e.g., password_resets)

        // Now delete the user
        $stmt_user_delete = $mysqli->prepare("DELETE FROM users WHERE id = ?");
        if (!$stmt_user_delete) {
            throw new Exception("Prepare failed for deleting user: " . $mysqli->error);
        }
        $stmt_user_delete->bind_param("i", $userIdToDelete);
        if (!$stmt_user_delete->execute()) {
            throw new Exception("Execute failed for deleting user: " . $stmt_user_delete->error);
        }

        if ($stmt_user_delete->affected_rows > 0) {
            $mysqli->commit();
            echo json_encode(['success' => true, 'message' => 'User and their associated data deleted successfully.']);
        } else {
            $mysqli->rollback(); // No user was deleted, rollback task deletion too
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found.']);
        }
        $stmt_user_delete->close();

    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Admin API - Delete User Transaction Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete user due to a server error. ' . $e->getMessage()]);
    }
}
// --- Invalid Action or Method ---
else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action or request method specified for admin API.']);
}

$mysqli->close();
?>