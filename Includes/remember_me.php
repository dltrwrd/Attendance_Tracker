<?php
// includes/remember_me.php
// "Keep me logged in on this browser" — passwordless auto-login.
//
// Uses the standard selector/validator cookie pattern:
//   - the cookie holds "selector:validator"
//   - only SHA-256(validator) is ever stored in the DB, never the raw validator
//   - the selector is looked up first (indexed, safe to leak on its own), then the validator
//     is checked with hash_equals() to prevent timing attacks
//   - every successful use ROTATES the token (old row deleted, new one issued) so a stolen
//     cookie value can't be replayed after the legitimate user's next visit
//
// Requires $pdo (PDO) to already be available — include after includes/config.php.

const REMEMBER_ME_COOKIE = 'cxi_remember_me';
const REMEMBER_ME_DAYS   = 30;

function issueRememberMeCookie(PDO $pdo, int $userId): void {
    $selector      = bin2hex(random_bytes(9));   // 18 hex chars — public identifier
    $validator     = bin2hex(random_bytes(32));  // 64 hex chars — secret, only ever lives in the cookie
    $validatorHash = hash('sha256', $validator);
    $expiresAt     = date('Y-m-d H:i:s', time() + REMEMBER_ME_DAYS * 86400);

    $stmt = $pdo->prepare(
        "INSERT INTO remembered_logins (user_id, selector, validator_hash, user_agent, ip_address, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)"
    );
    $stmt->execute([
        $userId,
        $selector,
        $validatorHash,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $expiresAt,
    ]);

    setcookie(REMEMBER_ME_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + REMEMBER_ME_DAYS * 86400,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Attempts a silent login from the remember-me cookie, if present and valid.
// On success: sets the session, rotates the token, and returns the user row.
// On failure/absence: returns null and clears any stale cookie.
//
// NOTE: this sets a minimal $_SESSION (user_id, username, role). If your real login handler
// (in auth.php) sets additional session keys other pages rely on, add them here too.
function attemptRememberMeLogin(PDO $pdo): ?array {
    if (empty($_COOKIE[REMEMBER_ME_COOKIE])) return null;

    $parts = explode(':', $_COOKIE[REMEMBER_ME_COOKIE], 2);
    if (count($parts) !== 2) { forgetDeviceCookie(); return null; }
    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare("SELECT * FROM remembered_logins WHERE selector = ? AND expires_at > NOW()");
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if (!$row) { forgetDeviceCookie(); return null; }

    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        // Validator mismatch on a valid selector = possible stolen/replayed cookie.
        // Revoke this token outright rather than silently failing.
        $pdo->prepare("DELETE FROM remembered_logins WHERE id = ?")->execute([$row['id']]);
        forgetDeviceCookie();
        return null;
    }

    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $userStmt->execute([$row['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        $pdo->prepare("DELETE FROM remembered_logins WHERE id = ?")->execute([$row['id']]);
        forgetDeviceCookie();
        return null;
    }

    // Rotate: this exact cookie value can never be used again after this point.
    $pdo->prepare("DELETE FROM remembered_logins WHERE id = ?")->execute([$row['id']]);
    issueRememberMeCookie($pdo, (int)$user['id']);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    return $user;
}

function forgetDeviceCookie(): void {
    setcookie(REMEMBER_ME_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Revokes the remember-me token tied to THIS browser's cookie for a given user
// (used when the person removes a saved profile from the login screen).
function revokeRememberMeForUser(PDO $pdo, int $userId): void {
    if (!empty($_COOKIE[REMEMBER_ME_COOKIE])) {
        $parts = explode(':', $_COOKIE[REMEMBER_ME_COOKIE], 2);
        if (count($parts) === 2) {
            $pdo->prepare("DELETE FROM remembered_logins WHERE user_id = ? AND selector = ?")
                ->execute([$userId, $parts[0]]);
        }
    }
    forgetDeviceCookie();
}