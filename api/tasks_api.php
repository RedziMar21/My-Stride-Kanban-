<?php
session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://127.0.0.1:5500"); 
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); 
    exit();
}

require 'db_connect.php'; 

if (!isset($_SESSION['user_id'])) {
    http_response_code(401); 
    echo json_encode(["error" => "User not authenticated. Please login."]);
    exit;
}
$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input_data = json_decode(file_get_contents("php://input"), true); 

switch ($method) {
    case 'GET':
        $stmt = $conn->prepare("SELECT id, text, priority, due_date, labels, column_id, sort_order, created_at, updated_at FROM tasks WHERE user_id = ? ORDER BY column_id ASC, sort_order ASC, created_at ASC");
        if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (GET): " . $conn->error]); exit; }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tasks_by_column = ["todo" => [], "inprogress" => [], "done" => []]; 
        while ($row = $result->fetch_assoc()) {
            $row['due_date'] = $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : null;
            if (array_key_exists($row['column_id'], $tasks_by_column)) {
                $tasks_by_column[$row['column_id']][] = $row;
            } else {
                error_log("Unknown column_id encountered for task ID " . $row['id'] . ": " . $row['column_id']);
                $tasks_by_column['todo'][] = $row;
            }
        }
        echo json_encode($tasks_by_column);
        $stmt->close();
        break;

    case 'POST': 
        if (empty($input_data['text'])) { http_response_code(400); echo json_encode(["error" => "Task text is required."]); exit; }
        
        $text = trim($input_data['text']);
        $priority = $input_data['priority'] ?? 'low';
        $dueDate = (!empty($input_data['dueDate']) && $input_data['dueDate'] !== 'null' && $input_data['dueDate'] !== '') ? $input_data['dueDate'] : null;
        $labels = $input_data['labels'] ?? '';
        $columnId = $input_data['columnId'] ?? 'todo';

        $sort_stmt = $conn->prepare("SELECT MAX(sort_order) as max_sort FROM tasks WHERE user_id = ? AND column_id = ?");
        if (!$sort_stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (sort_order): " . $conn->error]); exit; }
        $sort_stmt->bind_param("is", $user_id, $columnId);
        $sort_stmt->execute();
        $sort_result = $sort_stmt->get_result()->fetch_assoc();
        $sort_order = ($sort_result && isset($sort_result['max_sort']) && is_numeric($sort_result['max_sort'])) ? intval($sort_result['max_sort']) + 1 : 0;
        $sort_stmt->close();

        $stmt = $conn->prepare("INSERT INTO tasks (user_id, text, priority, due_date, labels, column_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (POST): " . $conn->error]); exit; }
        $stmt->bind_param("isssssi", $user_id, $text, $priority, $dueDate, $labels, $columnId, $sort_order);
        
        if ($stmt->execute()) {
            $new_task_id = $stmt->insert_id;
            $select_new_stmt = $conn->prepare("SELECT id, text, priority, due_date, labels, column_id, sort_order, created_at, updated_at FROM tasks WHERE id = ?");
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
                 echo json_encode([
                    "success" => true, "message" => "Task added (select back failed).", 
                    "task" => [
                        "id" => $new_task_id, "user_id" => $user_id, "text" => $text, 
                        "priority" => $priority, "due_date" => $dueDate, "labels" => $labels, 
                        "column_id" => $columnId, "sort_order" => $sort_order
                    ]
                ]);
            }
        } else { http_response_code(500); echo json_encode(["error" => "Failed to add task: " . $stmt->error]); }
        $stmt->close();
        break;

    case 'PUT': 
        if (empty($input_data['id'])) { http_response_code(400); echo json_encode(["error" => "Task ID required."]); exit; }
        $task_id = intval($input_data['id']);
        
        $fields_to_update = []; 
        $params_for_bind = []; 
        $types_for_bind = "";
        
        if (isset($input_data['text'])) { $fields_to_update[] = "text = ?"; $params_for_bind[] = $input_data['text']; $types_for_bind .= "s"; }
        if (isset($input_data['priority'])) { $fields_to_update[] = "priority = ?"; $params_for_bind[] = $input_data['priority']; $types_for_bind .= "s"; }
        if (array_key_exists('dueDate', $input_data)) { 
            $fields_to_update[] = "due_date = ?";
            $params_for_bind[] = ($input_data['dueDate'] === '' || $input_data['dueDate'] === null) ? null : $input_data['dueDate']; 
            $types_for_bind .= "s"; 
        }
        if (isset($input_data['labels'])) { $fields_to_update[] = "labels = ?"; $params_for_bind[] = $input_data['labels']; $types_for_bind .= "s"; }
        if (isset($input_data['column_id'])) { $fields_to_update[] = "column_id = ?"; $params_for_bind[] = $input_data['column_id']; $types_for_bind .= "s"; }
        else if (isset($input_data['columnId'])) { $fields_to_update[] = "column_id = ?"; $params_for_bind[] = $input_data['columnId']; $types_for_bind .= "s"; }

        if (isset($input_data['sort_order'])) { $fields_to_update[] = "sort_order = ?"; $params_for_bind[] = intval($input_data['sort_order']); $types_for_bind .= "i"; }

        if (count($fields_to_update) === 0) { http_response_code(400); echo json_encode(["error" => "No fields provided for update."]); exit; }
        
        $sql_query_string = "UPDATE tasks SET " . implode(", ", $fields_to_update) . " WHERE id = ? AND user_id = ?";
        
        $types_for_bind .= "ii";
        $params_for_bind[] = $task_id;
        $params_for_bind[] = $user_id;
        
        $stmt = $conn->prepare($sql_query_string);
        if (!$stmt) { 
            http_response_code(500); 
            error_log("DB Prepare Failed (PUT): " . $conn->error . " | SQL: " . $sql_query_string);
            echo json_encode(["error" => "DB Prepare Failed (PUT): " . $conn->error]); 
            exit; 
        }
        
        if (strlen($types_for_bind) !== count($params_for_bind)) {
            http_response_code(500);
            error_log("Type/Param mismatch in PUT. Types: $types_for_bind, Count_Params: " . count($params_for_bind) . " | SQL: " . $sql_query_string . " | Params: " . print_r($params_for_bind, true));
            echo json_encode(["error" => "Internal server error: Parameter count mismatch for DB update."]);
            exit;
        }

        $stmt->bind_param($types_for_bind, ...$params_for_bind); 

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                 echo json_encode(["success" => true, "message" => "Task updated."]);
            } else { 
                 $check_stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND user_id = ?");
                 if (!$check_stmt) { 
                    error_log("DB Check Stmt Prepare Failed (PUT no-affect): " . $conn->error);
                    echo json_encode(["success" => true, "message" => "Task data possibly unchanged (db check error)." ]); exit; 
                 }
                 $check_stmt->bind_param("ii", $task_id, $user_id);
                 $check_stmt->execute();
                 $check_stmt->store_result();
                 if($check_stmt->num_rows > 0) {
                    echo json_encode(["success" => true, "message" => "Task data unchanged (no actual differences detected or value already set)."]); 
                 } else {
                    http_response_code(404); echo json_encode(["error" => "Task not found or not owned by user (after attempting update)."]);
                 }
                 $check_stmt->close();
            }
        } else { 
            http_response_code(500); 
            error_log("DB Execute Failed (PUT): " . $stmt->error . " | SQL: " . $sql_query_string);
            echo json_encode(["error" => "Update execution failed: " . $stmt->error]); 
        }
        $stmt->close();
        break;

    case 'DELETE':
        if (empty($_GET['id'])) { http_response_code(400); echo json_encode(["error" => "Task ID required in query string."]); exit; }
        $task_id = intval($_GET['id']);
        
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        if (!$stmt) { http_response_code(500); echo json_encode(["error" => "DB Prepare Failed (DELETE): " . $conn->error]); exit; }
        $stmt->bind_param("ii", $task_id, $user_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                 echo json_encode(["success" => true, "message" => "Task deleted."]);
            } else {
                 http_response_code(404);
                 echo json_encode(["error" => "Task not found or not owned by user."]);
            }
        } else { http_response_code(500); echo json_encode(["error" => "Delete failed: " . $stmt->error]); }
        $stmt->close();
        break;

    default:
        http_response_code(405); 
        echo json_encode(["error" => "Method not allowed."]);
        break;
}
$conn->close();
?>
