<?php
// Start error reporting for debugging (remove or comment out for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- CORS Headers - Sent for all responses, including OPTIONS preflight ---
// It's crucial these are sent before any output.
header("Access-Control-Allow-Origin: http://127.0.0.1:5500");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"); // Keep it simple and correct
header("Access-Control-Allow-Credentials: true");

// --- Handle OPTIONS preflight request ---
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // 204 No Content is often preferred for OPTIONS
    // Headers above are already set globally, but being explicit here can sometimes help ensure they are part of THIS response.
    // Some servers/configurations might clear headers before script execution for OPTIONS if not handled this way.
    exit();
}

// --- Actual API Logic (POST, GET, PUT, DELETE) ---

// Set Content-Type for actual data responses AFTER preflight is handled
// and only if not an error that might output HTML.
// We will set it specifically before each json_encode.

require 'db_connect.php'; // Ensure this file has NO whitespace before <?php and NO output/errors

if ($conn->connect_error) {
    // If db_connect.php fails, it might have already outputted an error,
    // preventing subsequent headers. We try to send a JSON error here.
    http_response_code(503); // Service Unavailable
    // Try to set JSON content type, though it might fail if db_connect.php already outputted something
    if (!headers_sent()) {
        header("Content-Type: application/json");
    }
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit;
}


if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    if (!headers_sent()) { header("Content-Type: application/json"); }
    echo json_encode(["error" => "User not authenticated. Please login."]);
    exit;
}
$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input_data = json_decode(file_get_contents("php://input"), true);

// --- Set Content-Type for all successful data responses ---
// Do this once here if all subsequent valid paths echo JSON.
// If some paths might output different content types, set it individually.
if (!headers_sent()) {
    header("Content-Type: application/json");
}


switch ($method) {
    case 'GET':
        $archived_filter = isset($_GET['archived']) && $_GET['archived'] === 'true';
        $is_archived_val = $archived_filter ? 1 : 0;

        $sql = "SELECT id, text, priority, due_date, labels, column_id, sort_order, is_archived, created_at, updated_at FROM tasks WHERE user_id = ? AND is_archived = ?";
        $sql .= ($archived_filter) ? " ORDER BY updated_at DESC" : " ORDER BY column_id ASC, sort_order ASC, created_at ASC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) { 
            http_response_code(500); 
            error_log("DB Prepare Failed (GET): " . $conn->error . " | SQL: " . $sql);
            echo json_encode(["error" => "DB Prepare Failed (GET): " . $conn->error]); 
            exit; 
        }
        $stmt->bind_param("ii", $user_id, $is_archived_val);
        
        if(!$stmt->execute()){
            http_response_code(500); 
            error_log("DB Execute Failed (GET): " . $stmt->error . " | SQL: " . $sql);
            echo json_encode(["error" => "DB Execute Failed (GET): " . $stmt->error]); 
            $stmt->close(); exit; 
        }
        $result = $stmt->get_result();
        
        if ($archived_filter) {
            $archived_tasks = [];
            while ($row = $result->fetch_assoc()) {
                $row['due_date'] = $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : null;
                $archived_tasks[] = $row;
            }
            echo json_encode(["archived" => $archived_tasks]);
        } else {
            $tasks_by_column = ["todo" => [], "inprogress" => [], "done" => []];
            while ($row = $result->fetch_assoc()) {
                $row['due_date'] = $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : null;
                if ($row['is_archived'] == 0) { 
                    if (array_key_exists($row['column_id'], $tasks_by_column)) {
                        $tasks_by_column[$row['column_id']][] = $row;
                    } else {
                        $tasks_by_column['todo'][] = $row; 
                    }
                }
            }
            echo json_encode($tasks_by_column);
        }
        $stmt->close();
        break;

    case 'POST': 
        if (empty($input_data['text'])) { http_response_code(400); echo json_encode(["error" => "Task text is required."]); exit; }
        
        $text = trim($input_data['text']);
        $priority = $input_data['priority'] ?? 'low';
        $dueDate = (!empty($input_data['dueDate']) && $input_data['dueDate'] !== 'null' && $input_data['dueDate'] !== '') ? $input_data['dueDate'] : null;
        $labels = $input_data['labels'] ?? '';
        $columnId = $input_data['columnId'] ?? 'todo';
        $is_archived_val = 0; 

        $sort_stmt = $conn->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ? AND is_archived = 0");
        if (!$sort_stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (sort_order): " . $conn->error]); exit; }
        $sort_stmt->bind_param("is", $user_id, $columnId);
        $sort_stmt->execute();
        $sort_result = $sort_stmt->get_result()->fetch_assoc();
        $sort_order = ($sort_result && isset($sort_result['max_sort']) && is_numeric($sort_result['max_sort'])) ? intval($sort_result['max_sort']) + 1 : 0;
        $sort_stmt->close();

        $stmt = $conn->prepare("INSERT INTO tasks (user_id, text, priority, due_date, labels, column_id, sort_order, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (POST): " . $conn->error]); exit; }
        $stmt->bind_param("isssssii", $user_id, $text, $priority, $dueDate, $labels, $columnId, $sort_order, $is_archived_val);
        
        if ($stmt->execute()) {
            $new_task_id = $stmt->insert_id;
            $select_new_stmt = $conn->prepare("SELECT id, text, priority, due_date, labels, column_id, sort_order, is_archived, created_at, updated_at FROM tasks WHERE id = ?");
            if($select_new_stmt){
                $select_new_stmt->bind_param("i", $new_task_id);
                $select_new_stmt->execute();
                $new_task_data = $select_new_stmt->get_result()->fetch_assoc();
                if ($new_task_data && $new_task_data['due_date']) {
                    $new_task_data['due_date'] = date('Y-m-d', strtotime($new_task_data['due_date']));
                }
                echo json_encode([ "success" => true, "message" => "Task added.", "task" => $new_task_data ]);
                $select_new_stmt->close();
            } else {
                 echo json_encode(["success" => true, "message" => "Task added (select back failed).", "task" => [ "id" => $new_task_id, "text" => $text, "priority" => $priority, "due_date" => $dueDate, "labels" => $labels, "column_id" => $columnId, "sort_order" => $sort_order, "is_archived" => $is_archived_val ]]);
            }
        } else { http_response_code(500); echo json_encode(["error" => "Failed to add task: " . $stmt->error]); }
        $stmt->close();
        break;

    case 'PUT':
        if (isset($input_data['batch']) && $input_data['batch'] === true && isset($input_data['ids']) && is_array($input_data['ids'])) {
            if (isset($input_data['is_archived'])) { 
                $task_ids = $input_data['ids'];
                $is_archived_val = $input_data['is_archived'] ? 1 : 0;
                $conn->begin_transaction();
                try {
                    foreach ($task_ids as $task_id_raw) {
                        $task_id = intval($task_id_raw);
                        $update_fields = ["is_archived = ?", "updated_at = NOW()"];
                        $update_types = "i";
                        $update_params = [$is_archived_val];

                        if ($is_archived_val === 0) { 
                            $update_fields[] = "column_id = 'todo'";
                            $sort_stmt = $conn->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = 'todo' AND is_archived = 0");
                            if ($sort_stmt) {
                                $sort_stmt->bind_param("i", $user_id);
                                $sort_stmt->execute();
                                $sort_result = $sort_stmt->get_result()->fetch_assoc();
                                $new_sort_order = ($sort_result && isset($sort_result['max_sort'])) ? intval($sort_result['max_sort']) + 1 : 0;
                                $sort_stmt->close();
                                $update_fields[] = "sort_order = ?";
                                $update_params[] = $new_sort_order;
                                $update_types .= "i";
                            }
                        }
                        
                        $update_params[] = $task_id;
                        $update_params[] = $user_id;
                        $update_types .= "ii";

                        $stmt_batch = $conn->prepare("UPDATE tasks SET " . implode(", ", $update_fields) . " WHERE id = ? AND user_id = ?");
                        if (!$stmt_batch) { throw new Exception("DB Prepare Failed (batch PUT): " . $conn->error); }
                        $stmt_batch->bind_param($update_types, ...$update_params);
                        if (!$stmt_batch->execute()) { throw new Exception("DB Execute Failed (batch PUT for task $task_id): " . $stmt_batch->error); }
                        $stmt_batch->close();
                    }
                    $conn->commit();
                    echo json_encode(["success" => true, "message" => count($task_ids) . " tasks updated."]);
                } catch (Exception $e) {
                    $conn->rollback();
                    http_response_code(500);
                    error_log("Batch PUT Error: " . $e->getMessage());
                    echo json_encode(["error" => "Batch update failed: " . $e->getMessage()]);
                }
            } else {
                http_response_code(400); echo json_encode(["error" => "Batch update type not specified or invalid."]);
            }
        } else { 
            if (empty($input_data['id'])) { http_response_code(400); echo json_encode(["error" => "Task ID required."]); exit; }
            $task_id = intval($input_data['id']);
            $fields_to_update = []; $params_for_bind = []; $types_for_bind = "";
            if (isset($input_data['text'])) { $fields_to_update[] = "text = ?"; $params_for_bind[] = $input_data['text']; $types_for_bind .= "s"; }
            if (isset($input_data['priority'])) { $fields_to_update[] = "priority = ?"; $params_for_bind[] = $input_data['priority']; $types_for_bind .= "s"; }
            if (array_key_exists('dueDate', $input_data)) { $fields_to_update[] = "due_date = ?"; $params_for_bind[] = ($input_data['dueDate'] === '' || $input_data['dueDate'] === null) ? null : $input_data['dueDate']; $types_for_bind .= "s"; }
            if (isset($input_data['labels'])) { $fields_to_update[] = "labels = ?"; $params_for_bind[] = $input_data['labels']; $types_for_bind .= "s"; }
            $columnIdKey = isset($input_data['column_id']) ? 'column_id' : (isset($input_data['columnId']) ? 'columnId' : null);
            if ($columnIdKey !== null && isset($input_data[$columnIdKey])) { $fields_to_update[] = "column_id = ?"; $params_for_bind[] = $input_data[$columnIdKey]; $types_for_bind .= "s"; }
            if (isset($input_data['sort_order'])) { $fields_to_update[] = "sort_order = ?"; $params_for_bind[] = intval($input_data['sort_order']); $types_for_bind .= "i"; }
            if (isset($input_data['is_archived'])) {
                $fields_to_update[] = "is_archived = ?"; $params_for_bind[] = $input_data['is_archived'] ? 1 : 0; $types_for_bind .= "i";
                if ($input_data['is_archived'] === false) {
                    $unarchive_target_column = 'todo';
                    if ($columnIdKey !== null && isset($input_data[$columnIdKey])) { $unarchive_target_column = $input_data[$columnIdKey]; }
                    else { $colFieldFound = false; foreach($fields_to_update as $f) if(strpos($f, "column_id = ?") !== false) $colFieldFound = true; if(!$colFieldFound){ $fields_to_update[] = "column_id = ?"; $params_for_bind[] = $unarchive_target_column; $types_for_bind .= "s"; }}
                     $sort_stmt = $conn->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ? AND is_archived = 0");
                     if ($sort_stmt) {
                        $sort_stmt->bind_param("is", $user_id, $unarchive_target_column); $sort_stmt->execute(); $sort_result = $sort_stmt->get_result()->fetch_assoc();
                        $new_sort_order = ($sort_result && isset($sort_result['max_sort'])) ? intval($sort_result['max_sort']) + 1 : 0; $sort_stmt->close();
                        $sortOrderFieldExists = false; $sortOrderIndex = -1; for($i=0; $i < count($fields_to_update); $i++) if(strpos($fields_to_update[$i], "sort_order = ?") !== false) {$sortOrderFieldExists = true; $sortOrderIndex = $i; break;}
                        if (!$sortOrderFieldExists) { $fields_to_update[] = "sort_order = ?"; $params_for_bind[] = $new_sort_order; $types_for_bind .= "i"; }
                        else if ($sortOrderIndex !== -1) { $params_for_bind[$sortOrderIndex] = $new_sort_order; }
                     }
                }
            }
            if (count($fields_to_update) === 0) { http_response_code(400); echo json_encode(["error" => "No fields provided for update."]); exit; }
            $fields_to_update[] = "updated_at = NOW()"; $sql_query_string = "UPDATE tasks SET " . implode(", ", $fields_to_update) . " WHERE id = ? AND user_id = ?";
            $types_for_bind .= "ii"; $params_for_bind[] = $task_id; $params_for_bind[] = $user_id;
            $stmt = $conn->prepare($sql_query_string);
            if (!$stmt) { http_response_code(500); error_log("DB Prepare Failed (PUT single): " . $conn->error); echo json_encode(["error" => "DB Prepare Failed (PUT single): " . $conn->error]); exit; }
            if (count($params_for_bind) > 0 && strlen($types_for_bind) !== count($params_for_bind)) { http_response_code(500); echo json_encode(["error" => "Internal server error (param mismatch)."]); exit; }
            if (count($params_for_bind) > 0) { $stmt->bind_param($types_for_bind, ...$params_for_bind); }
            if ($stmt->execute()) { if ($stmt->affected_rows > 0) { echo json_encode(["success" => true, "message" => "Task updated."]); } else { $check_stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ?"); if (!$check_stmt) { echo json_encode(["success" => false, "message" => "Task data possibly unchanged (db check error)." ,"error_detail"=>"DB check failed"]); exit; } $check_stmt->bind_param("ii", $task_id, $user_id); $check_stmt->execute(); $check_stmt->store_result(); if($check_stmt->num_rows > 0) { echo json_encode(["success" => true, "message" => "Task data unchanged."]); } else { http_response_code(404); echo json_encode(["error" => "Task not found or not owned by user."]); } $check_stmt->close(); }
            } else { http_response_code(500); error_log("DB Execute Failed (PUT single): " . $stmt->error); echo json_encode(["error" => "Update execution failed: " . $stmt->error]); }
            $stmt->close();
        }
        break;

    case 'DELETE':
        if (isset($input_data['batch']) && $input_data['batch'] === true && isset($input_data['ids']) && is_array($input_data['ids'])) {
            $task_ids = $input_data['ids'];
            if (empty($task_ids)) { http_response_code(400); echo json_encode(["error" => "No task IDs provided for batch delete."]); exit;}
            
            $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
            $types = str_repeat('i', count($task_ids)) . 'i'; 
            $params = $task_ids;
            $params[] = $user_id;

            $stmt = $conn->prepare("DELETE FROM tasks WHERE id IN ($placeholders) AND user_id = ?");
            if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (batch DELETE): " . $conn->error]); exit; }
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => $stmt->affected_rows . " task(s) deleted."]);
            } else {
                http_response_code(500); echo json_encode(["error" => "Batch delete failed: " . $stmt->error]);
            }
            $stmt->close();
        } else { 
            if (empty($_GET['id'])) { http_response_code(400); echo json_encode(["error" => "Task ID required in query string for single delete."]); exit; }
            $task_id = intval($_GET['id']);
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
            if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (DELETE): " . $conn->error]); exit; }
            $stmt->bind_param("ii", $task_id, $user_id);
            if ($stmt->execute()) { if ($stmt->affected_rows > 0) { echo json_encode(["success" => true, "message" => "Task deleted."]); } else { http_response_code(404); echo json_encode(["error" => "Task not found or not owned by user."]); }
            } else { http_response_code(500); echo json_encode(["error" => "Delete failed: " . $stmt->error]); }
            $stmt->close();
        }
        break;

    default:
        http_response_code(405); 
        echo json_encode(["error" => "Method not allowed."]);
        break;
}
$conn->close();
?>