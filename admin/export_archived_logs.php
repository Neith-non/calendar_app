<?php
session_start();

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

require_once '../functions/database.php';

$filename = 'event_logs_archive_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['id','event_publish_id','event_id','user_id','action','details','created_at','username']);

$stmt = $pdo->query("SELECT ea.*, u.username FROM event_logs_archive ea LEFT JOIN users u ON ea.user_id = u.user_id ORDER BY ea.created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $row['id'],
        $row['event_publish_id'],
        $row['event_id'],
        $row['user_id'],
        $row['action'],
        $row['details'],
        $row['created_at'],
        $row['username'] ?? ''
    ]);
}

fclose($out);
exit();

?>
