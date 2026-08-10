<?php
// Creates/upgrades the email_queue table used by the attendance.php Bulk Email
// action and includes/send_email_queue.php, so the schema only lives in one place.

function ensureEmailQueueTable(PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'email_queue'")->rowCount() > 0;

    if ($exists) {
        $hasToEmails = $pdo->query("SHOW COLUMNS FROM email_queue LIKE 'to_emails'")->rowCount() > 0;
        $hasClaimedAt = $pdo->query("SHOW COLUMNS FROM email_queue LIKE 'claimed_at'")->rowCount() > 0;
        if (!$hasToEmails || !$hasClaimedAt) {
            // ponytail: pre-launch schema fix — table was created with the old single-recipient
            // layout before this feature had real data queued, so drop instead of migrating rows.
            $pdo->exec("DROP TABLE email_queue");
            $exists = false;
        }
    }

    if (!$exists) {
        $pdo->exec("CREATE TABLE email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            to_emails TEXT NOT NULL,
            cc_emails TEXT DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            source_type VARCHAR(30) DEFAULT NULL,
            source_id INT DEFAULT NULL,
            status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            claimed_at TIMESTAMP NULL DEFAULT NULL,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
