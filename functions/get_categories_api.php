<?php
// functions/get_categories_api.php
require_once 'database.php';

try {
    // Fetch ALL categories, including Holidays
    $stmt = $pdo->query("SELECT category_id, category_name FROM event_categories ORDER BY category_name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($categories);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>