<?php
// components/remember_me_actions.php
// Small AJAX endpoint used by the login screen / dashboard to issue or revoke the
// "keep me logged in on this browser" token. Requires an active session (i.e. the person is
// already authenticated) — this never itself verifies a password.

session_start();
require_once '../includes/config.php';
require_once '../includes/remember_me.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$action = $_POST['action'] ?? '';

if ($action === 'issue') {
    issueRememberMeCookie($pdo, (int)$_SESSION['user_id']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'revoke') {
    // Revoke the "keep me logged in" token for a given user on THIS browser. Used when the
    // person removes a saved profile card from the login screen — no session is required for
    // this path since it may be revoking a profile that isn't the currently logged-in one.
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        revokeRememberMeForUser($pdo, $userId);
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);