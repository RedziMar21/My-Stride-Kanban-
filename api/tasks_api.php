<?php
// api/tasks_api.php

// error_reporting(E_ALL); // Uncomment for debugging
// ini_set('display_errors', 1); // Uncomment for debugging

session_start(); // MUST be at the very top before any output

// --- CORS Headers ---
header("Access-Control-Allow-Origin: http://127.0.0.1:5500");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin");
header("Access-Control-Allow-Credentials: true");

// --- Handle OPTIONS preflight request ---
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content for preflight
    exit(); // Crucial: Stop script for OPTIONS
}

// --- Set Content-Type for all subsequent JSON responses ---
header("Content-Type: application/json");

// --- Database Connection ---
require 'db_connect.php'; // This file MUST define $mysqli

if (!$mysqli || $mysqli->connect_error) {
    http_response_code(503);
    error_log("tasks_api.php - DB connection failed: " . ($mysqli ? $mysqli->connect_error : "mysqli object not initialized"));
    echo json_encode(["success" => false, "error" => "Database connection unavailable. Please try again later."]);
    exit;
}

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "User not authenticated. Please login."]);
    $mysqli->close(); // Close connection before exiting
    exit;
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input_data = null;

// Read input data for methods that can have a body
if ($method === 'POST' || $method === 'PUT' || ($method === 'DELETE' && ($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json')) {
    $raw_input = file_get_contents("php://input");
    if (!empty($raw_input)) {
        $input_data = json_decode($raw_input, true);
        if ($input_data === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            error_log("tasks_api.php - Invalid JSON input: " . json_last_error_msg() . " | Raw input: " . substr($raw_input, 0, 200));
            echo json_encode(["success" => false, "error" => "Invalid JSON input provided."]);
            $mysqli->close(); exit;
        }
    }
}


// --- Main Switch for HTTP Methods ---
switch ($method) {
    case 'GET':
        $archived_filter = isset($_GET['archived']) && $_GET['archived'] === 'true';
        $is_archived_val = $archived_filter ? 1 : 0;

        $sql = "SELECT id, text, priority, due_date, labels, column_id, sort_order, is_archived, created_at, updated_at FROM tasks WHERE user_id = ? AND is_archived = ?";
        $sql .= ($archived_filter) ? " ORDER BY updated_at DESC" : " ORDER BY column_id ASC, sort_order ASC, created_at ASC";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { 
            http_response_code(500); 
            error_log("DB Prepare Failed (GET): " . $mysqli->error . " | SQL: " . $sql);
            echo json_encode(["success" => false, "error" => "Server error (DBP_GET)."]); 
            exit; 
        }
        $stmt->bind_param("ii", $user_id, $is_archived_val);
        
        if(!$stmt->execute()){
            http_response_code(500); 
            error_log("DB Execute Failed (GET): " . $stmt->error . " | SQL: " . $sql);
            echo json_encode(["success" => false, "error" => "Server error (DBE_GET)."]); 
            $stmt->close(); $mysqli->close(); exit; 
        }
        $result = $stmt->get_result();
        
        if ($archived_filter) {
            $archived_tasks = [];
            while ($row = $result->fetch_assoc()) {
                $row['due_date'] = $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : null;
                $row['is_archived'] = (bool)$row['is_archived'];
                $archived_tasks[] = $row;
            }
            echo json_encode(["success" => true, "archived" => $archived_tasks]);
        } else {
            $tasks_by_column = ["todo" => [], "inprogress" => [], "done" => []];
            while ($row = $result->fetch_assoc()) {
                $row['due_date'] = $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : null;
                $row['is_archived'] = (bool)$row['is_archived'];
                if (isset($row['column_id']) && array_key_exists($row['column_id'], $tasks_by_column)) {
                    $tasks_by_column[$row['column_id']][] = $row;
                } elseif ($row['column_id'] === null && $row['is_archived'] == 0) {
                    error_log("Task ID {$row['id']} (User ID: {$user_id}) is active but has NULL column_id. Defaulting to 'todo'.");
                    $tasks_by_column['todo'][] = $row; 
                } else if ($row['column_id'] !== null) {
                     error_log("Task ID {$row['id']} (User ID: {$user_id}) has unknown column_id '{$row['column_id']}'. Defaulting to 'todo'.");
                     $tasks_by_column['todo'][] = $row;
                }
            }
            echo json_encode($tasks_by_column);
        }
        $stmt->close();
        break;

    case 'POST': 
        if (!isset($input_data['text']) || empty(trim($input_data['text']))) {
             http_response_code(400); echo json_encode(["success" => false, "error" => "Task text is required."]); exit;
        }
        
        $text = trim($input_data['text']);
        $priority = $input_data['priority'] ?? 'low';
        $dueDate = (!empty($input_data['dueDate']) && strtolower($input_data['dueDate']) !== 'null') ? $input_data['dueDate'] : null;
        $labels = $input_data['labels'] ?? '';
        $columnId = $input_data['columnId'] ?? 'todo';
        $is_archived_val = 0; 

        $sort_order = 0;
        $sort_stmt = $mysqli->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ? AND is_archived = 0");
        if (!$sort_stmt) { 
            error_log("DB Prepare Failed (POST sort_order): " . $mysqli->error);
            http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBPS_POST)."]); exit;
        }
        $sort_stmt->bind_param("is", $user_id, $columnId);
        if(!$sort_stmt->execute()){
            error_log("DB Execute Failed (POST sort_order): " . $sort_stmt->error);
            http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBES_POST)."]); $sort_stmt->close(); $mysqli->close(); exit;
        }
        $sort_result = $sort_stmt->get_result()->fetch_assoc();
        $sort_order = ($sort_result && isset($sort_result['max_sort']) && is_numeric($sort_result['max_sort'])) ? intval($sort_result['max_sort']) + 1 : 0;
        $sort_stmt->close();

        $stmt = $mysqli->prepare("INSERT INTO tasks (user_id, text, priority, due_date, labels, column_id, sort_order, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { 
            error_log("DB Prepare Failed (POST insert): " . $mysqli->error);
            http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBPI_POST)."]); exit;
        }
        $stmt->bind_param("isssssii", $user_id, $text, $priority, $dueDate, $labels, $columnId, $sort_order, $is_archived_val);
        
        if ($stmt->execute()) {
            $new_task_id = $mysqli->insert_id;
            $select_new_stmt = $mysqli->prepare("SELECT id, text, priority, due_date, labels, column_id, sort_order, is_archived, created_at, updated_at FROM tasks WHERE id = ? AND user_id = ?");
            if($select_new_stmt){
                $select_new_stmt->bind_param("ii", $new_task_id, $user_id);
                if ($select_new_stmt->execute()) {
                    $new_task_data = $select_new_stmt->get_result()->fetch_assoc();
                    if ($new_task_data) {
                        $new_task_data['due_date'] = $new_task_data['due_date'] ? date('Y-m-d', strtotime($new_task_data['due_date'])) : null;
                        $new_task_data['is_archived'] = (bool)$new_task_data['is_archived']; 
                        echo json_encode([ "success" => true, "message" => "Task added successfully.", "task" => $new_task_data ]);
                    } else {
                         error_log("Failed to fetch newly inserted task ID: $new_task_id for user: $user_id");
                         echo json_encode(["success" => false, "error" => "Task added, but failed to retrieve its details."]); 
                    }
                } else {
                    error_log("Execute failed for selecting back new task (POST): " . $select_new_stmt->error);
                    echo json_encode(["success" => false, "error" => "Task added, but select back execute failed."]);
                }
                $select_new_stmt->close();
            } else {
                 error_log("Prepare failed for selecting back new task (POST): " . $mysqli->error);
                 echo json_encode(["success" => false, "error" => "Task added, but select back prepare failed."]);
            }
        } else { 
            error_log("DB Execute Failed (POST insert): " . $stmt->error . " (Errno: " . $mysqli->errno . ")");
             if ($mysqli->errno == 1062) { 
                 http_response_code(409); 
                 echo json_encode(["success" => false, "error" => "Duplicate task data or constraint violation."]);
             } else {
                 http_response_code(500); echo json_encode(["success" => false, "error" => "Failed to add task (DBE_POST)."]);
             }
        }
        $stmt->close();
        break;

    case 'PUT':
        if (!isset($input_data)) { // Simpler initial check for any input data
            http_response_code(400); echo json_encode(["success" => false, "error" => "No data provided for update."]); exit;
        }

        $is_batch_operation = isset($input_data['batch']) && $input_data['batch'] === true;
        $is_batch_archive_unarchive = $is_batch_operation && isset($input_data['ids']) && isset($input_data['is_archived']);
        $is_batch_reorder = $is_batch_operation && isset($input_data['tasks_order']) && is_array($input_data['tasks_order']);
        $is_single_task_update = isset($input_data['id']) && !$is_batch_operation;

        if ($is_batch_archive_unarchive) {
            $task_ids = $input_data['ids'];
            $is_archived_target_val = $input_data['is_archived'] ? 1 : 0;
            if (empty($task_ids)) { http_response_code(400); echo json_encode(["success" => false, "error" => "No task IDs for batch archive/unarchive."]); exit; }

            $mysqli->begin_transaction();
            try {
                foreach ($task_ids as $task_id_raw) {
                    $task_id = intval($task_id_raw);
                    if ($task_id <= 0) continue; 

                    if ($is_archived_target_val === 0) { 
                        $target_column_id_batch = $input_data['column_id'] ?? 'todo'; 
                        $target_sort_order_batch = 0;
                        $sort_stmt_unarchive = $mysqli->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ? AND is_archived = 0");
                        if(!$sort_stmt_unarchive){ throw new Exception("DB Prepare Failed (unarchive sort): " . $mysqli->error); }
                        $sort_stmt_unarchive->bind_param("is", $user_id, $target_column_id_batch); 
                        if(!$sort_stmt_unarchive->execute()){ throw new Exception("DB Execute Failed (unarchive sort): " . $sort_stmt_unarchive->error); }
                        $sort_result = $sort_stmt_unarchive->get_result()->fetch_assoc();
                        $target_sort_order_batch = ($sort_result && $sort_result['max_sort'] !== null) ? intval($sort_result['max_sort']) + 1 : 0;
                        $sort_stmt_unarchive->close();
                        
                        $stmt_batch = $mysqli->prepare("UPDATE tasks SET is_archived = ?, column_id = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        if(!$stmt_batch){ throw new Exception("DB Prepare Failed (batch unarchive): " . $mysqli->error); }
                        $stmt_batch->bind_param("isiii", $is_archived_target_val, $target_column_id_batch, $target_sort_order_batch, $task_id, $user_id);
                    } else { 
                        $stmt_batch = $mysqli->prepare("UPDATE tasks SET is_archived = ?, column_id = NULL, sort_order = NULL, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        if(!$stmt_batch){ throw new Exception("DB Prepare Failed (batch archive): " . $mysqli->error); }
                        $stmt_batch->bind_param("iii", $is_archived_target_val, $task_id, $user_id);
                    }
                    
                    if (!$stmt_batch->execute()) { throw new Exception("DB Execute Failed (batch for task $task_id): " . $stmt_batch->error); }
                    $stmt_batch->close();
                }
                $mysqli->commit();
                echo json_encode(["success" => true, "message" => count($task_ids) . " task(s) archive status updated."]);
            } catch (Exception $e) {
                $mysqli->rollback();
                http_response_code(500);
                error_log("Batch PUT (Archive/Unarchive) Error: " . $e->getMessage());
                echo json_encode(["success" => false, "error" => "Batch update failed: " . $e->getMessage()]);
            }

        } elseif ($is_batch_reorder) {
            $mysqli->begin_transaction();
            try {
                $affected_count = 0;
                foreach($input_data['tasks_order'] as $task_update_data) {
                    if (!isset($task_update_data['id'], $task_update_data['column_id']) || !array_key_exists('sort_order', $task_update_data)) {
                        error_log("Invalid data in tasks_order array: " . print_r($task_update_data, true));
                        continue; 
                    }
                    $task_id_reorder = intval($task_update_data['id']);
                    $new_column_id = $task_update_data['column_id'];
                    $new_sort_order = intval($task_update_data['sort_order']);

                    if ($task_id_reorder <= 0) continue;

                    $stmt_reorder = $mysqli->prepare("UPDATE tasks SET column_id = ?, sort_order = ?, is_archived = 0, updated_at = NOW() WHERE id = ? AND user_id = ?");
                    if(!$stmt_reorder) { throw new Exception("Reorder prepare failed: " . $mysqli->error); }
                    $stmt_reorder->bind_param("siii", $new_column_id, $new_sort_order, $task_id_reorder, $user_id);
                    if(!$stmt_reorder->execute()) { throw new Exception("Reorder execute failed for task $task_id_reorder: " . $stmt_reorder->error); }
                    $affected_count += $stmt_reorder->affected_rows;
                    $stmt_reorder->close();
                }
                $mysqli->commit();
                echo json_encode(["success" => true, "message" => "Tasks reordered successfully. Affected: " . $affected_count]);
            } catch (Exception $e) {
                $mysqli->rollback();
                http_response_code(500); error_log("Task Reorder Error: " . $e->getMessage());
                echo json_encode(["success" => false, "error" => "Task reorder failed: " . $e->getMessage()]);
            }

        } elseif ($is_single_task_update) {
            $task_id = intval($input_data['id']);
            if ($task_id <= 0) { http_response_code(400); echo json_encode(["success" => false, "error" => "Invalid Task ID for update."]); exit; }

            $fields_to_update_sql = [];
            $bind_types = "";
            $bind_params_array = []; 

            if (array_key_exists('text', $input_data)) { $fields_to_update_sql[] = "text = ?"; $bind_types .= "s"; $bind_params_array[] = $input_data['text']; }
            if (array_key_exists('priority', $input_data)) { $fields_to_update_sql[] = "priority = ?"; $bind_types .= "s"; $bind_params_array[] = $input_data['priority']; }
            if (array_key_exists('dueDate', $input_data)) { 
                $fields_to_update_sql[] = "due_date = ?"; $bind_types .= "s";
                $bind_params_array[] = (empty($input_data['dueDate']) || strtolower($input_data['dueDate']) === 'null') ? null : $input_data['dueDate'];
            }
            if (array_key_exists('labels', $input_data)) { $fields_to_update_sql[] = "labels = ?"; $bind_types .= "s"; $bind_params_array[] = $input_data['labels']; }
            
            $target_column_for_unarchive = $input_data['column_id'] ?? 'todo';

            if (array_key_exists('column_id', $input_data)) { 
                $fields_to_update_sql[] = "column_id = ?"; $bind_types .= "s"; $bind_params_array[] = $input_data['column_id']; 
                $target_column_for_unarchive = $input_data['column_id']; 
            }
            if (array_key_exists('sort_order', $input_data)) { $fields_to_update_sql[] = "sort_order = ?"; $bind_types .= "i"; $bind_params_array[] = intval($input_data['sort_order']); }
            
            if (array_key_exists('is_archived', $input_data)) {
                $is_archiving_val_single = (bool)$input_data['is_archived'];
                $fields_to_update_sql[] = "is_archived = ?"; $bind_types .= "i"; $bind_params_array[] = $is_archiving_val_single ? 1 : 0;
                if ($is_archiving_val_single) { 
                    $fields_to_update_sql[] = "column_id = NULL";
                    $fields_to_update_sql[] = "sort_order = NULL";
                } else { 
                    if (!in_array("column_id = ?", array_map(function($f){ return explode(" ", $f)[0]." = ?"; }, $fields_to_update_sql)) && !array_key_exists('column_id', $input_data) ) {
                        $fields_to_update_sql[] = "column_id = ?"; $bind_types .= "s"; $bind_params_array[] = $target_column_for_unarchive;
                    }
                    if (!in_array("sort_order = ?", array_map(function($f){ return explode(" ", $f)[0]." = ?"; }, $fields_to_update_sql)) && !array_key_exists('sort_order', $input_data) ) {
                        $sort_stmt_unarchive_single = $mysqli->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ? AND is_archived = 0");
                        if($sort_stmt_unarchive_single){
                             $sort_stmt_unarchive_single->bind_param("is", $user_id, $target_column_for_unarchive);
                             $sort_stmt_unarchive_single->execute();
                             $res_sort_unarc = $sort_stmt_unarchive_single->get_result()->fetch_assoc();
                             $new_sort_unarc = ($res_sort_unarc && $res_sort_unarc['max_sort'] !== null) ? $res_sort_unarc['max_sort'] + 1 : 0;
                             $sort_stmt_unarchive_single->close();
                             $fields_to_update_sql[] = "sort_order = ?"; $bind_types .= "i"; $bind_params_array[] = $new_sort_unarc;
                        } else {
                            error_log("DB Prepare Failed (PUT single unarchive sort): " . $mysqli->error);
                        }
                    }
                }
            }

            if (empty($fields_to_update_sql)) { http_response_code(400); echo json_encode(["success" => false, "error" => "No valid fields provided for single task update."]); exit; }

            $fields_to_update_sql[] = "updated_at = NOW()";
            $sql_update_single = "UPDATE tasks SET " . implode(", ", $fields_to_update_sql) . " WHERE id = ? AND user_id = ?";
            $bind_types .= "ii"; 
            $bind_params_array[] = $task_id;
            $bind_params_array[] = $user_id;

            $stmt_update_single = $mysqli->prepare($sql_update_single);
             if (!$stmt_update_single) {
                error_log("DB Prepare Failed (PUT single): " . $mysqli->error . " SQL: " . $sql_update_single);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBPU_S_P)."]); exit;
            }
            
            if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
                $stmt_update_single->bind_param($bind_types, ...$bind_params_array);
            } else {
                $ref_params_single = [$bind_types]; // Initialize with types string
                foreach($bind_params_array as $key_ref => &$value_ref){ $ref_params_single[] = $value_ref; } // Pass by reference
                unset($value_ref); // Break reference with the last element
                call_user_func_array([$stmt_update_single, 'bind_param'], $ref_params_single);
            }

            if ($stmt_update_single->execute()) {
                if ($stmt_update_single->affected_rows > 0) {
                    echo json_encode(["success" => true, "message" => "Task updated successfully."]);
                } else {
                    $check_stmt = $mysqli->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ?");
                    $check_stmt->bind_param("ii", $task_id, $user_id); $check_stmt->execute(); $check_stmt->store_result();
                    if($check_stmt->num_rows > 0) {
                        echo json_encode(["success" => true, "message" => "Task data was already up to date."]);
                    } else {
                         http_response_code(404); echo json_encode(["success" => false, "error" => "Task not found or not owned by user."]);
                    }
                    $check_stmt->close();
                }
            } else {
                error_log("DB Execute Failed (PUT single): " . $stmt_update_single->error);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Failed to update task (DBPU_S_E)."]);
            }
            $stmt_update_single->close();

        } else {
             http_response_code(400); echo json_encode(["success" => false, "error" => "Invalid PUT request data structure."]);
        }
        break;

    case 'DELETE':
        $is_batch_delete_attempt = false;
        if (isset($input_data['batch']) && $input_data['batch'] === true && isset($input_data['ids'])) {
            $is_batch_delete_attempt = true;
        }
        
        if ($is_batch_delete_attempt && is_array($input_data['ids'])) {
            $task_ids = $input_data['ids'];
            if (empty($task_ids)) { 
                http_response_code(400); 
                echo json_encode(["success" => false, "error" => "No task IDs provided for batch delete."]); 
                exit; 
            }
            
            $sanitized_task_ids_del = array_map('intval', $task_ids);
            $sanitized_task_ids_del = array_filter($sanitized_task_ids_del, function($id){ return $id > 0; });
            if(empty($sanitized_task_ids_del)){ http_response_code(400); echo json_encode(["success" => false, "error" => "Invalid task IDs for batch delete."]); exit; }

            $placeholders = rtrim(str_repeat('?,', count($sanitized_task_ids_del)), ','); 
            $types = str_repeat('i', count($sanitized_task_ids_del)) . 'i'; 
            $params_for_delete = $sanitized_task_ids_del;
            $params_for_delete[] = $user_id; 

            $stmt_batch_del = $mysqli->prepare("DELETE FROM tasks WHERE id IN ($placeholders) AND user_id = ?");
            if (!$stmt_batch_del) { 
                error_log("DB Prepare Failed (batch DELETE): " . $mysqli->error);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBPD_B_P)."]); exit;
            }
            
            if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
                $stmt_batch_del->bind_param($types, ...$params_for_delete);
            } else {
                $ref_params_del_batch = [$types]; 
                foreach($params_for_delete as $key_ref => &$value_ref){ $ref_params_del_batch[] = $value_ref; }
                unset($value_ref);
                call_user_func_array([$stmt_batch_del, 'bind_param'], $ref_params_del_batch);
            }
            
            if ($stmt_batch_del->execute()) {
                echo json_encode(["success" => true, "message" => $stmt_batch_del->affected_rows . " task(s) deleted."]);
            } else {
                error_log("DB Execute Failed (batch DELETE): " . $stmt_batch_del->error);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Batch delete failed (DBPD_B_E)."]);
            }
            $stmt_batch_del->close();
        } else { 
            if (empty($_GET['id'])) { http_response_code(400); echo json_encode(["success" => false, "error" => "Task ID required for single delete (or invalid batch payload)."]); exit; }
            $task_id_del = intval($_GET['id']);
             if ($task_id_del <= 0) { http_response_code(400); echo json_encode(["success" => false, "error" => "Invalid Task ID for single delete."]); exit; }

            $stmt_del_single = $mysqli->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
            if (!$stmt_del_single) { 
                error_log("DB Prepare Failed (single DELETE): " . $mysqli->error);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Server error (DBPD_S_P)."]); exit;
            }
            $stmt_del_single->bind_param("ii", $task_id_del, $user_id);
            if ($stmt_del_single->execute()) { 
                if ($stmt_del_single->affected_rows > 0) { 
                    echo json_encode(["success" => true, "message" => "Task deleted successfully."]); 
                } else { 
                    http_response_code(404); echo json_encode(["success" => false, "error" => "Task not found or not owned by user."]); 
                }
            } else { 
                error_log("DB Execute Failed (single DELETE): " . $stmt_del_single->error);
                http_response_code(500); echo json_encode(["success" => false, "error" => "Delete failed (DBPD_S_E)."]); 
            }
            $stmt_del_single->close();
        }
        break;

    default:
        http_response_code(405); 
        echo json_encode(["success" => false, "error" => "Method not allowed on this endpoint."]);
        break;
}

$mysqli->close();
?>