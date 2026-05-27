<?php
// functions/logs.php
// Lightweight event logging helper. Creates table if missing.
function write_event_log($pdo, $user_id, $action, $event_publish_id = null, $event_id = null, $details = null, $level = 'info')
{
    try {
        // Ensure table exists (idempotent). New installs will include `level`.
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_publish_id INT NULL,
            event_id INT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {
        // If creation fails, continue and try inserts (don't break main flow)
    }

    // Detect/ensure `level` column exists for legacy tables. Try to add if missing.
    $hasLevel = false;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM event_logs LIKE 'level'");
        if ($res && $res->rowCount() > 0) {
            $hasLevel = true;
        } else {
            // Attempt to add column (may fail if lacking privileges) — ignore failures
            try {
                $pdo->exec("ALTER TABLE event_logs ADD COLUMN level VARCHAR(16) NOT NULL DEFAULT 'info'");
                $hasLevel = true;
            } catch (PDOException $e) {
                $hasLevel = false;
            }
        }
    } catch (PDOException $e) {
        $hasLevel = false;
    }

    try {
        if ($hasLevel) {
            $stmt = $pdo->prepare('INSERT INTO event_logs (event_publish_id, event_id, user_id, action, details, level) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $event_publish_id,
                $event_id,
                $user_id,
                $action,
                $details,
                $level
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO event_logs (event_publish_id, event_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $event_publish_id,
                $event_id,
                $user_id,
                $action,
                $details
            ]);
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

?>
