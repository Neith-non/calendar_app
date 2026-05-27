<?php
session_start();

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

require_once '../functions/database.php';

// Support preview mode: if ?preview=1 (GET) return JSON with count; otherwise perform archive via POST/GET
$preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$days = isset($_REQUEST['days']) ? (int) $_REQUEST['days'] : 90;
if ($days <= 0) $days = 90;

$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

try {
    // ensure archive table exists (structure like event_logs)
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_logs_archive LIKE event_logs");

    if ($preview) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_logs WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        $count = (int)$stmt->fetchColumn();
        header('Content-Type: application/json');
        echo json_encode(['count' => $count, 'days' => $days]);
        exit;
    }

    // perform archive
    $pdo->beginTransaction();
    $stmtInsert = $pdo->prepare("INSERT INTO event_logs_archive (event_publish_id, event_id, user_id, action, details, created_at) SELECT event_publish_id, event_id, user_id, action, details, created_at FROM event_logs WHERE created_at < ?");
    $stmtInsert->execute([$cutoff]);
    $moved = $stmtInsert->rowCount();

    $stmtDel = $pdo->prepare("DELETE FROM event_logs WHERE created_at < ?");
    $stmtDel->execute([$cutoff]);
    $deleted = $stmtDel->rowCount();

    $pdo->commit();

    header("Location: admin_manage.php?msg=" . urlencode("Archived {$moved} logs older than {$days} days."));
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($preview) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    header("Location: admin_manage.php?msg=" . urlencode("Archive failed: " . $e->getMessage()));
    exit();
}

?>
