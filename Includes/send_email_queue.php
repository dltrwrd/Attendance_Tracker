<?php
// Infraction-email queue worker.
// Run unattended via Windows Task Scheduler: php.exe C:\xampp\htdocs\Attendance_Tracker\Includes\send_email_queue.php
// Also fetched on-demand (AJAX) right after admin/attendance.php queues a batch, so sending
// starts immediately instead of waiting for the next scheduled run.

$current_dir = __DIR__;
$root_dir = dirname(__DIR__);
$is_cli = (php_sapi_name() === 'cli');

require_once $current_dir . '/config.php';
require_once $current_dir . '/functions.php';
require_once $current_dir . '/ensure_email_queue_table.php';
require_once $root_dir . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!$is_cli) {
    header('Content-Type: application/json');
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    // The browser-triggered kick is fire-and-forget (JS doesn't wait on it), so a longer
    // budget here doesn't block anyone's page — it just avoids php.ini's default 30s
    // execution limit killing the script mid-batch and stranding rows in 'sending'.
    set_time_limit(120);
}

ensureEmailQueueTable($pdo);

// Self-heal: a prior run that crashed or got killed mid-batch leaves rows stuck in
// 'sending' forever (nothing else ever picks them back up). Requeue anything stale.
$pdo->exec("UPDATE email_queue SET status = 'pending' WHERE status = 'sending' AND claimed_at < NOW() - INTERVAL 5 MINUTE");

// Tables allowed to be auto-marked email_sent=1 after a successful send. Whitelisted so
// source_type (which ends up in a raw SQL identifier) can never come from unchecked input.
$sourceTables = ['tardiness', 'absenteeism'];

// ponytail: single flock as the only concurrency guard (fine for one XAMPP server;
// move to a distributed lock if this ever runs on multiple app servers).
$lockFile = $root_dir . '/email_queue.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($is_cli) {
        echo "Another instance is already running.\n";
    } else {
        echo json_encode(['busy' => true]);
    }
    exit;
}

$batchSize = 20;
$maxAttempts = 3;
$sent = 0;
$failed = 0;

try {
    $idsStmt = $pdo->query("SELECT id FROM email_queue WHERE status = 'pending' ORDER BY id LIMIT $batchSize");
    $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE email_queue SET status = 'sending', claimed_at = NOW() WHERE id IN ($in)")->execute($ids);

        $rowsStmt = $pdo->prepare("SELECT * FROM email_queue WHERE id IN ($in)");
        $rowsStmt->execute($ids);
        $rows = $rowsStmt->fetchAll();

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cxi-slm@communixinc.com';
        $mail->Password = 'lvxi sqrd tpvq bpgh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPKeepAlive = true; // reuse one SMTP connection for the whole batch
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->Timeout = 30;
        $mail->setFrom('cxi-slm@communixinc.com', 'CXI Service Level Management');
        $mail->isHTML(true);

        foreach ($rows as $row) {
            $mail->clearAddresses();
            $mail->clearCCs();
            $mail->Subject = $row['subject'];
            $mail->Body = $row['body'];
            try {
                foreach (explode(',', $row['to_emails']) as $addr) {
                    if ($addr !== '') $mail->addAddress($addr);
                }
                foreach (explode(',', $row['cc_emails'] ?? '') as $addr) {
                    if ($addr !== '') $mail->addCC($addr);
                }
                $mail->send();
                $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$row['id']]);

                if ($row['source_type'] && $row['source_id'] && in_array($row['source_type'], $sourceTables, true)) {
                    $table = $row['source_type'];
                    $pdo->prepare("UPDATE $table SET email_sent = 1, email_sent_at = NOW() WHERE id = ?")->execute([$row['source_id']]);
                }
                $sent++;
            } catch (Exception $e) {
                $attempts = $row['attempts'] + 1;
                $newStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';
                $pdo->prepare("UPDATE email_queue SET status = ?, attempts = ?, error_message = ? WHERE id = ?")
                    ->execute([$newStatus, $attempts, $mail->ErrorInfo ?: $e->getMessage(), $row['id']]);
                $failed++;
            }
            usleep(200000); // throttle to ~5/sec so we don't get flagged/rate-limited by the SMTP provider
        }
        $mail->smtpClose();
    }

    $counts = $pdo->query("SELECT status, COUNT(*) c FROM email_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $logLine = date('Y-m-d H:i:s') . " - Processed batch: sent={$sent}, failed={$failed}, remaining_pending=" . ($counts['pending'] ?? 0) . "\n";
    file_put_contents($root_dir . '/cron_log.txt', $logLine, FILE_APPEND);

    if ($is_cli) {
        echo $logLine;
    } else {
        echo json_encode(['sent' => $sent, 'failed' => $failed, 'counts' => $counts]);
    }
} catch (Throwable $e) {
    file_put_contents($root_dir . '/cron_errors.txt', date('Y-m-d H:i:s') . " - send_email_queue: " . $e->getMessage() . "\n", FILE_APPEND);
    if ($is_cli) {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
