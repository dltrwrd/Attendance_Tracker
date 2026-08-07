<?php
// Include your system's main config and functions files
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Use your system's built-in login check
if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

try {
    // Handle Joining the call
    if ($action === 'join') {
        // Capture the PeerJS ID sent from the frontend
        $peerId = $_POST['peer_id'] ?? null;
        
        // Save BOTH the active status AND their unique Peer ID
        $stmt = $pdo->prepare("UPDATE users SET is_in_voice = 1, peer_id = ? WHERE id = ?");
        $stmt->execute([$peerId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Handle Leaving the call
    if ($action === 'leave') {
        // Clear BOTH the active status AND their Peer ID
        $stmt = $pdo->prepare("UPDATE users SET is_in_voice = 0, peer_id = NULL WHERE id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Fetch all participants currently in the call
    if ($action === 'get_participants') {
        // Select peer_id and username (Employee ID)
        $stmt = $pdo->query("SELECT id, username, fullname, display_photo, peer_id FROM users WHERE is_in_voice = 1");
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'participants' => $participants]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);

} catch (PDOException $e) {
    // Catch database errors properly and return them as JSON
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}