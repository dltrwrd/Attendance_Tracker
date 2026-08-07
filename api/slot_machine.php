<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$today = date('Y-m-d');
$validBets = [1, 2, 5, 10, 15, 20, 30, 40, 50];
$symbols = [
    'J' => ['char' => '🃏', 'payout' => 1],
    'Q' => ['char' => '👸', 'payout' => 2],
    'K' => ['char' => '🤴', 'payout' => 3],
    'A' => ['char' => '🅰️', 'payout' => 4],
    'WILD' => ['char' => '💎', 'payout' => 5] // Wildcard
];

try {
    // 1. Fetch current points and handle daily reset
    $stmt = $pdo->prepare("SELECT points, last_reset_date FROM games_points WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userPoints = $stmt->fetch(PDO::FETCH_ASSOC);

    $justReset = false;
    if (!$userPoints) {
        $stmt = $pdo->prepare("INSERT INTO games_points (user_id, points, last_reset_date) VALUES (?, 100, ?)");
        $stmt->execute([$userId, $today]);
        $points = 100;
        $justReset = true;
    } else {
        $points = (int)$userPoints['points'];
        $lastResetDate = $userPoints['last_reset_date'];

        // Only reset if points are EXACTLY 0 and last_reset_date is not today
        if ($lastResetDate !== $today && $points === 0) {
            $points = 100; // Give 100 points
            $stmt = $pdo->prepare("UPDATE games_points SET points = ?, last_reset_date = ? WHERE user_id = ?");
            $stmt->execute([$points, $today, $userId]);
            $justReset = true;
        } elseif ($lastResetDate !== $today) {
            // Update the date but don't give points
            $stmt = $pdo->prepare("UPDATE games_points SET last_reset_date = ? WHERE user_id = ?");
            $stmt->execute([$today, $userId]);
        }
    }

    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'status');

    if ($action === 'status') {
        echo json_encode([
            'success' => true,
            'points' => $points,
            'daily_granted' => $justReset
        ]);
        exit();
    }

    if ($action === 'spin') {
        $betSize = isset($_POST['bet']) ? (int)$_POST['bet'] : 0;
        $nonce    = $_POST['nonce'] ?? null;
        
        // Prevent double-spin resubmission: track the last nonce in session
        if ($nonce && isset($_SESSION['last_spin_nonce']) && $_SESSION['last_spin_nonce'] === $nonce) {
            echo json_encode(['success' => false, 'message' => 'Duplicate spin detected. Please spin again.']);
            exit();
        }
        if ($nonce) $_SESSION['last_spin_nonce'] = $nonce;
        
        if (!in_array($betSize, $validBets)) {
            echo json_encode(['success' => false, 'message' => 'Invalid bet size!']);
            exit();
        }

        if ($points < $betSize) {
            echo json_encode(['success' => false, 'message' => 'Not enough points!']);
            exit();
        }

        // Deduct cost
        $points -= $betSize;

        // Determine win or loss strictly (80% loss / 20% win)
        $isWinSpin = (rand(1, 100) <= 20);
        
        $gridKeys = array_keys($symbols);
        $grid = [];
        
        if ($isWinSpin) {
            // Force a win: exactly 8 matching (mix of target and wild)
            $normalSymbols = ['J', 'Q', 'K', 'A'];
            $target = $normalSymbols[array_rand($normalSymbols)];
            $wildCount = rand(0, 3);
            $targetCount = 8 - $wildCount;
            
            for ($i = 0; $i < $targetCount; $i++) $grid[] = $target;
            for ($i = 0; $i < $wildCount; $i++) $grid[] = 'WILD';
            
            // Fill remaining 7 spots with other symbols to ensure no other symbol wins
            $otherSymbols = array_diff($normalSymbols, [$target]);
            // Re-index array so array_rand works properly
            $otherSymbols = array_values($otherSymbols); 
            for ($i = 0; $i < 7; $i++) {
                $grid[] = $otherSymbols[array_rand($otherSymbols)];
            }
            shuffle($grid);
        } else {
            // Force a loss (80% probability): ensure no symbol + wild >= 8
            do {
                $grid = [];
                $counts = ['J'=>0, 'Q'=>0, 'K'=>0, 'A'=>0, 'WILD'=>0];
                for ($i = 0; $i < 15; $i++) {
                    $s = $gridKeys[array_rand($gridKeys)];
                    $grid[] = $s;
                    $counts[$s]++;
                }
                
                $isLoss = true;
                foreach (['J', 'Q', 'K', 'A'] as $s) {
                    if ($counts[$s] + $counts['WILD'] >= 8) {
                        $isLoss = false;
                        break;
                    }
                }
                if ($counts['WILD'] >= 8) $isLoss = false;
                
            } while (!$isLoss);
        }
        
        // Pick a multiplier based on specific probabilities
        // 1x = 67%, 2x = 15%, 3x = 10%, 5x = 5%, 10x = 2%, 15x = 1%
        $randMult = rand(1, 100);
        if ($randMult <= 67) {
            $multiplier = 1;
        } elseif ($randMult <= 82) { // 67 + 15
            $multiplier = 2;
        } elseif ($randMult <= 92) { // 82 + 10
            $multiplier = 3;
        } elseif ($randMult <= 97) { // 92 + 5
            $multiplier = 5;
        } elseif ($randMult <= 99) { // 97 + 2
            $multiplier = 10;
        } else {
            $multiplier = 15;
        }
        
        // Count occurrences of each symbol
        $counts = [];
        $wildCount = 0;
        foreach ($grid as $s) {
            if ($s === 'WILD') {
                $wildCount++;
            } else {
                $counts[$s] = ($counts[$s] ?? 0) + 1;
            }
        }
        
        $totalWinnings = 0;
        $winningLines = []; // We'll store winning cell indexes here for the frontend to highlight
        
        // Scatter win logic: 8 or more of the same symbol (including WILDs) wins
        foreach ($counts as $symbol => $count) {
            if ($count + $wildCount >= 8) {
                // Determine which cells contributed to this win
                $winIndices = [];
                foreach ($grid as $idx => $s) {
                    if ($s === $symbol || $s === 'WILD') {
                        $winIndices[] = $idx;
                    }
                }
                
                // Calculate payout based on base payout and bet
                // Extra symbols beyond 8 add a small multiplier
                $extraMatch = ($count + $wildCount) - 8; 
                $payoutMult = $symbols[$symbol]['payout'] * (1 + ($extraMatch * 0.5)); 
                $winAmount = $betSize * $payoutMult * $multiplier;
                
                $totalWinnings += $winAmount;
                $winningLines[] = [
                    'line' => $winIndices, // Frontend expects an array of indexes to highlight
                    'symbol' => $symbol,
                    'amount' => $winAmount
                ];
            }
        }
        
        // Also check if they hit 8+ WILDs purely on their own
        if ($wildCount >= 8) {
            $winIndices = [];
            foreach ($grid as $idx => $s) {
                if ($s === 'WILD') $winIndices[] = $idx;
            }
            $extraMatch = $wildCount - 8;
            $payoutMult = $symbols['WILD']['payout'] * (1 + ($extraMatch * 1)); 
            $winAmount = $betSize * $payoutMult * $multiplier;
            $totalWinnings += $winAmount;
            $winningLines[] = [
                'line' => $winIndices,
                'symbol' => 'WILD',
                'amount' => $winAmount
            ];
        }
        
        $points += $totalWinnings;

        // Convert grid to chars for frontend
        $gridChars = array_map(function($key) use ($symbols) {
            return $symbols[$key]['char'];
        }, $grid);

        // Update database
        $stmt = $pdo->prepare("UPDATE games_points SET points = ? WHERE user_id = ?");
        $stmt->execute([$points, $userId]);

        echo json_encode([
            'success' => true,
            'points' => $points,
            'grid' => $gridChars,
            'multiplier' => $multiplier,
            'winning_lines' => $winningLines,
            'winnings' => $totalWinnings
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
}
