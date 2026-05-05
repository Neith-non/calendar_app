<?php
session_start();

// 1. Check Permissions (Only Admin and Head Scheduler can save notes)
$allowed_roles = ['Head Scheduler', 'Admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once 'functions/database.php';

// 2. Read the JSON payload from the fetch request
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['id']) && isset($data['note'])) {
    $publish_id = (int)$data['id'];
    $note = trim($data['note']);

    try {
        // 3. Update the database
        $stmt = $pdo->prepare("UPDATE event_publish SET sticky_note = ? WHERE id = ?");
        $stmt->execute([$note, $publish_id]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>