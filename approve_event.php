<?php
// approve_event.php
session_start();
require_once 'functions/database.php';
require_once 'functions/logs.php';

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

$publish_id = (int) $_GET['id'];
$action = $_GET['action'];

$previousPage = $_SERVER['HTTP_REFERER'] ?? 'index.php';

$separator = (parse_url($previousPage, PHP_URL_QUERY) == NULL) ? '?' : '&';

try {
    if ($action === 'approve') {
        // Capture related event info
        $evStmt = $pdo->prepare("SELECT event_id, title FROM events WHERE publish_id = ? LIMIT 1");
        $evStmt->execute([$publish_id]);
        $ev = $evStmt->fetch(PDO::FETCH_ASSOC);
        $event_id = $ev['event_id'] ?? null;
        $title = $ev['title'] ?? null;

        // Update the status to 'Approved'
        $stmt = $pdo->prepare("UPDATE event_publish SET status = 'Approved' WHERE id = ?");
        $stmt->execute([$publish_id]);

        // Log approval
        if (function_exists('write_event_log')) {
            $details = json_encode(['title' => $title]);
            write_event_log($pdo, $_SESSION['user_id'] ?? null, 'approve_event', $publish_id, $event_id, $details);
        }

        $msg = "Event successfully approved!";

    } elseif ($action === 'reject') {
        // If rejected, fetch events, remove them from the calendar queue (events table) 
        // and mark it as Rejected in the publish table.
        $pdo->beginTransaction();

        // Fetch related events before deletion
        $fetchEvt = $pdo->prepare("SELECT event_id, title FROM events WHERE publish_id = ?");
        $fetchEvt->execute([$publish_id]);
        $eventsToRemove = $fetchEvt->fetchAll(PDO::FETCH_ASSOC);
        $event_ids = array_column($eventsToRemove, 'event_id');
        $event_titles = array_column($eventsToRemove, 'title');

        // Remove from the calendar
        $stmt_delete = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
        $stmt_delete->execute([$publish_id]);

        // Mark as rejected
        $stmt_update = $pdo->prepare("UPDATE event_publish SET status = 'Rejected' WHERE id = ?");
        $stmt_update->execute([$publish_id]);

        $pdo->commit();

        // Log rejection
        if (function_exists('write_event_log')) {
            $details = json_encode(['event_ids' => $event_ids, 'titles' => $event_titles]);
            write_event_log($pdo, $_SESSION['user_id'] ?? null, 'reject_event', $publish_id, null, $details);
        }

        $msg = "Event request rejected and removed from the calendar.";

    } else {
        header("Location: index.php?sync_status=error&sync_msg=" . urlencode("Error: Invalid action."));
        exit();
    }

    // 3. Redirect back to index with a success message
    $previousPage = $_SERVER['HTTP_REFERER'] ?? 'index.php';

    $separator = (parse_url($previousPage, PHP_URL_QUERY) == NULL) ? '?' : '&';

    header("Location: " . $previousPage . $separator . "sync_status=success&sync_msg=" . urlencode($msg));
    exit();


} catch (PDOException $e) {
    // If something goes wrong, rollback and show error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: " . $previousPage . $separator . "sync_status=error&sync_msg=" . urlencode("Database Error: " . $e->getMessage()));
    exit();
}