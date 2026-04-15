<?php
// functions/get_pending_count.php
// We assume $pdo is already available because database.php will be required first.
$pendingStmt = $pdo->query("SELECT COUNT(*) FROM event_publish WHERE status = 'Pending'");
$pendingCount = $pendingStmt->fetchColumn();
?>