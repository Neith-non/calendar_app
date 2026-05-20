<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

require_once 'functions/database.php';

// Get the event ID to delete
$publishId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($publishId <= 0) {
    http_response_code(400);
    exit('Invalid event ID');
}

try {
    // Verify the event exists, is personal, and owned by the current user (via approved_by)
    $stmt = $pdo->prepare("
        SELECT id, approved_by, is_personal 
        FROM event_publish 
        WHERE id = ? AND approved_by = ? AND is_personal = 1
    ");
    $stmt->execute([$publishId, $_SESSION['user_id']]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(403);
        exit('Unauthorized: Cannot delete this event');
    }

    // Begin transaction for safety
    $pdo->beginTransaction();

    // Delete associated event entries (from events table)
    $deleteEventsStmt = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
    $deleteEventsStmt->execute([$publishId]);

    // Delete participant schedule entries
    $deletePartsStmt = $pdo->prepare("DELETE FROM participant_schedule WHERE event_publish_id = ?");
    $deletePartsStmt->execute([$publishId]);

    // Delete the event_publish entry
    $deletePublishStmt = $pdo->prepare("DELETE FROM event_publish WHERE id = ?");
    $deletePublishStmt->execute([$publishId]);

    $pdo->commit();

    // Redirect back to calendar with success message
    header("Location: calendar.php?success=deleted");
    exit;

} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Database error in delete_event.php: " . $e->getMessage());
    http_response_code(500);
    exit('An error occurred while deleting the event');
}
