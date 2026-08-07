<?php
require_once 'functions.php';

// Handle login
if (isset($_POST['login'])) {
    $username = sanitizeInput($_POST['username']);
    $password = sanitizeInput($_POST['password']);
    
    try {
        // Check if account is temporarily locked
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Check if account is locked
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remaining_time = strtotime($user['locked_until']) - time();
                $_SESSION['error'] = "Account temporarily locked. Please try again in " . ceil($remaining_time/60) . " minutes.";
                redirect(BASE_URL);
            }
            
            // Check if we should show CAPTCHA (after 3 failed attempts)
            if ($user['login_attempts'] >= 3) {
                if (!isset($_POST['captcha']) || $_POST['captcha'] != $_SESSION['captcha_code']) {
                    $_SESSION['show_captcha'] = true;
                    $_SESSION['error'] = "Please enter the CAPTCHA code correctly.";
                    redirect(BASE_URL);
                }
            }
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Reset login attempts on successful login
                $stmt = $pdo->prepare("UPDATE users SET login_attempts = 0, last_failed_login = NULL, locked_until = NULL WHERE id = ?");
                $stmt->execute([$user['id']]);
                $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nickname'] = $user['sub_name'];
                $_SESSION['slt_email'] = $user['slt_email'];
                $_SESSION['display_photo'] = $user['display_photo'] ?? '';
                $_SESSION['last_activity'] = time();
                
                if (isAdmin()) {
                    redirect(ADMIN_URL);
                } else {
                    redirect(BASE_URL);
                }
            } else {
                // Increment failed login attempts
                $login_attempts = $user['login_attempts'] + 1;
                $locked_until = null;
                
                // Lock account after 5 failed attempts for 30 minutes
                if ($login_attempts >= 5) {
                    $locked_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $_SESSION['error'] = "Too many failed attempts. Account locked for 30 minutes!";
                } else {
                    $_SESSION['error'] = "Incorrect username or password!";
                    if ($login_attempts >= 3) {
                        $_SESSION['show_captcha'] = true;
                    }
                }
                
                $stmt = $pdo->prepare("UPDATE users SET login_attempts = ?, last_failed_login = NOW(), locked_until = ? WHERE id = ?");
                $stmt->execute([$login_attempts, $locked_until, $user['id']]);
                
                // Add delay for failed attempts (1 second per attempt)
                sleep(min($login_attempts, 5));
                
                redirect(BASE_URL);
            }
        } else {
            // Username doesn't exist, but don't reveal that
            sleep(3); // Delay to prevent username enumeration
            $_SESSION['error'] = "Incorrect username or password!";
            redirect(BASE_URL);
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        redirect(BASE_URL);
    }
}

// Handle registration remains the same...