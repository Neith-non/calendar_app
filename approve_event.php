<?php
// approve_event.php
session_start();
require_once 'functions/database.php';

// 1. Check Permissions (Only Admin and Head Scheduler allowed)
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    // Kick unauthorized users back to the index with an error message
    header("Location: index.php?sync_status=error&sync_msg=" . urlencode("Unauthorized: You do not have permission to approve events."));
    exit();
}

// 2. Validate URL Parameters
if (!isset($_GET['id']) || !isset($_GET['action'])) {
    header("Location: index.php?sync_status=error&sync_msg=" . urlencode("Error: Missing event ID or action."));
    exit();
}

$publish_id = (int)$_GET['id'];
$action = $_GET['action'];

try {
    if ($action === 'approve') {
        // Update the status to 'Approved'
        $stmt = $pdo->prepare("UPDATE event_publish SET status = 'Approved' WHERE id = ?");
        $stmt->execute([$publish_id]);
        
        $msg = "Event successfully approved!";
        
    } elseif ($action === 'reject') {
        // If rejected, we must remove it from the calendar queue (events table) 
        // and mark it as Rejected in the publish table.
        $pdo->beginTransaction();
        
        // Remove from the calendar
        $stmt_delete = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
        $stmt_delete->execute([$publish_id]);
        
        // Mark as rejected
        $stmt_update = $pdo->prepare("UPDATE event_publish SET status = 'Rejected' WHERE id = ?");
        $stmt_update->execute([$publish_id]);
        
        $pdo->commit();
        
        $msg = "Event request rejected and removed from the calendar.";
        
    } else {
        header("Location: index.php?sync_status=error&sync_msg=" . urlencode("Error: Invalid action."));
        exit();
    }

    // 3. Redirect back to index with a success message
    header("Location: index.php?sync_status=success&sync_msg=" . urlencode($msg));
    exit();

} catch (PDOException $e) {
    // If something goes wrong, rollback and show error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: index.php?sync_status=error&sync_msg=" . urlencode("Database Error: " . $e->getMessage()));
    exit();
}