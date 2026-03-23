<?php
// approve_event.php
require_once 'functions/database.php';

if (isset($_GET['id']) && isset($_GET['action'])) {
    $publish_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    // For now, we'll assume Admin User ID 1 is doing the approving
    $admin_id = 1; 

    try {
        if ($action === 'approve') {
            // Update status to Approved
            $stmt = $pdo->prepare("UPDATE event_publish SET status = 'Approved', approved_by = ?, approved_date = NOW() WHERE id = ?");
            $stmt->execute([$admin_id, $publish_id]);
            
            $msg = "✅ Event successfully approved!";
            $status = "success";
            
        } elseif ($action === 'reject') {
            // Update status to Rejected
            $stmt = $pdo->prepare("UPDATE event_publish SET status = 'Rejected', approved_by = ?, approved_date = NOW() WHERE id = ?");
            $stmt->execute([$admin_id, $publish_id]);
            
            // Delete the actual calendar block so it disappears from the schedule
            $stmt_del = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
            $stmt_del->execute([$publish_id]);
            
            $msg = "❌ Event rejected and removed from the calendar.";
            $status = "success"; // Still a 'success' because the system did what we asked
        } else {
            $msg = "Invalid action.";
            $status = "error";
        }
    } catch (PDOException $e) {
        $msg = "Database Error: " . $e->getMessage();
        $status = "error";
    }
    
    // Send them back to the dashboard with the message
    header("Location: index.php?sync_status=$status&sync_msg=" . urlencode($msg));
    exit();
}

header("Location: index.php");
exit();
?>