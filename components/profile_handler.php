<?php
// 1. Start Output Buffering immediately. This traps any warnings,
// notices, or stray blank spaces from included files.
ob_start(); 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/config.php';
require_once '../includes/functions.php';

// 2. Create a helper function to safely send JSON
function sendJsonResponse($response) {
    ob_clean(); // Wipes out any trapped HTML/warnings so they don't break the JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized access.']);
}

$currentUserId = $_SESSION['user_id'];

// ---------------------------------------------------------
// ACTION: Fetch Profile Information (GET request)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_profile') {
    try {
        // Support fetching other users, default to self
        $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $currentUserId;
        $isSelf = ($targetUserId === $currentUserId);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $displayPhoto = (isset($user['display_photo']) && !empty($user['display_photo'])) ? $user['display_photo'] : 'default.jpg';
            
            sendJsonResponse([
                'success' => true, 
                'data' => [
                    'id' => $user['id'],
                    'fullname' => $user['fullname'] ?? 'Unknown User',
                    'role' => $user['role'] ?? 'user',
                    'is_active' => $user['is_active'] ?? 0,
                    'display_photo' => $displayPhoto,
                    'is_self' => $isSelf
                ]
            ]);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'User not found.']);
        }
    } catch (PDOException $e) {
        sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ---------------------------------------------------------
// ACTION: Fetch Multiple Users (POST request - For Online Bar)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_users_batch') {
    try {
        $userIdsJson = $_POST['user_ids'] ?? '[]';
        $ids = json_decode($userIdsJson, true);
        
        if (!is_array($ids) || empty($ids)) {
            sendJsonResponse(['success' => true, 'data' => []]);
        }
        
        // Sanitize IDs
        $ids = array_map('intval', $ids);
        
        // Remove current user ID from the batch so they don't see themselves in the online list
        $ids = array_filter($ids, function($id) use ($currentUserId) { 
            return $id !== $currentUserId; 
        });
        
        if (empty($ids)) {
             sendJsonResponse(['success' => true, 'data' => []]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Select only what we need to render the mini avatars
        $stmt = $pdo->prepare("SELECT id, fullname, display_photo FROM users WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set fallback photos
        foreach ($users as &$u) {
            $u['display_photo'] = (isset($u['display_photo']) && !empty($u['display_photo'])) ? $u['display_photo'] : 'default.jpg';
        }
        
        sendJsonResponse(['success' => true, 'data' => $users]);
        
    } catch (PDOException $e) {
        sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ---------------------------------------------------------
// ACTION: Upload Profile Photo (POST request)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $fileName = $_FILES['photo']['name'];
        $fileTmpName = $_FILES['photo']['tmp_name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($fileExt, $allowedExtensions)) {
            
            $newFileName = uniqid('user_' . $currentUserId . '_', true) . '.' . $fileExt;
            $uploadDir = 'profile/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpName, $destPath)) {
                try {
                    $oldPhoto = 'default.jpg';
                    try {
                        $stmtFetch = $pdo->prepare("SELECT display_photo FROM users WHERE id = ?");
                        $stmtFetch->execute([$currentUserId]);
                        $row = $stmtFetch->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['display_photo'])) {
                            $oldPhoto = $row['display_photo'];
                        }
                    } catch (PDOException $e) {}

                    $stmtUpdate = $pdo->prepare("UPDATE users SET display_photo = ? WHERE id = ?");
                    $stmtUpdate->execute([$newFileName, $currentUserId]);
                    
                    $_SESSION['display_photo'] = $newFileName;
                    
                    if ($oldPhoto !== 'default.jpg' && file_exists($uploadDir . $oldPhoto)) {
                        unlink($uploadDir . $oldPhoto);
                    }

                    sendJsonResponse(['success' => true, 'photo_url' => $destPath]);
                    
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        sendJsonResponse(['success' => false, 'message' => 'The "display_photo" column is missing in your Hostinger database.']);
                    } else {
                        sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                    }
                }
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to move uploaded file. Check directory permissions.']);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Invalid file format. Please upload JPG, PNG, or GIF.']);
        }
    } else {
        sendJsonResponse(['success' => false, 'message' => 'No file uploaded or an error occurred during upload.']);
    }
}

sendJsonResponse(['success' => false, 'message' => 'Invalid Request']);
?>