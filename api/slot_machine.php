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
    'JOKER'     => ['char' => 'assets/joker-removebg-preview.png',       'payout' => 1],
    'Q_SPADE'   => ['char' => 'assets/queen-removebg-preview.png',       'payout' => 2],
    'Q_HEART'   => ['char' => 'assets/queen_heart-removebg-preview.png', 'payout' => 3],
    'K_SPADE'   => ['char' => 'assets/king_spade-removebg-preview.png',  'payout' => 4],
    'K_HEART'   => ['char' => 'assets/king_heart-removebg-preview.png',  'payout' => 5],
    'A_CLOVE'   => ['char' => 'assets/ace_clove-removebg-preview.png',   'payout' => 6],
    'A_DIAMOND' => ['char' => 'assets/ace_diamond-removebg-preview.png', 'payout' => 7],
    'A_HEART'   => ['char' => 'assets/ace_heart-removebg-preview.png',   'payout' => 8],
    'A_SPADE'   => ['char' => 'assets/ace_spade-removebg-preview.png',   'payout' => 9],
    'WILD'      => ['char' => 'assets/coins-removebg-preview.png',       'payout' => 10]
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

        // Pick multiplier (applied once at the end to sum of all cascade wins)
        // 1x = 67%, 2x = 15%, 3x = 10%, 5x = 5%, 10x = 2%, 15x = 1%
        $randMult = rand(1, 100000);
        
        if ($randMult <= 60000)      $multiplier = 1;   // 60.0000%
        elseif ($randMult <= 74500)  $multiplier = 2;   // 14.5000%
        elseif ($randMult <= 77200)  $multiplier = 3;   // 2.7000%
        elseif ($randMult <= 77470)  $multiplier = 5;   // 0.2700%
        elseif ($randMult <= 77520)  $multiplier = 10;  // 0.0500%
        elseif ($randMult <= 77550)  $multiplier = 15;  // 0.0300%
        else                         $multiplier = 0;   // 22.4500% loss

        // --- Helper: evaluate wins on a given grid ---
        $evalWins = function(array $grid) use ($symbols, $betSize) {
            $counts = [];
            $wildCount = 0;
            foreach ($grid as $s) {
                if ($s === 'WILD') $wildCount++;
                else $counts[$s] = ($counts[$s] ?? 0) + 1;
            }

            $winningLines = [];
            $baseWin = 0;

            foreach ($counts as $symbol => $count) {
                if ($count + $wildCount >= 8) {
                    $winIndices = [];
                    foreach ($grid as $idx => $s) {
                        if ($s === $symbol || $s === 'WILD') $winIndices[] = $idx;
                    }
                    $extraMatch = ($count + $wildCount) - 8;
                    $payoutMult = $symbols[$symbol]['payout'] * (1 + ($extraMatch * 0.5));
                    $amount = $betSize * $payoutMult;
                    $baseWin += $amount;
                    $winningLines[] = [
                        'line'        => $winIndices,
                        'symbol'      => $symbol,
                        'char'        => $symbols[$symbol]['char'],
                        'match_count' => count($winIndices),
                        'payout_mult' => $payoutMult,
                        'amount'      => $amount,
                    ];
                }
            }
            // Pure WILD win
            if ($wildCount >= 8) {
                $winIndices = [];
                foreach ($grid as $idx => $s) if ($s === 'WILD') $winIndices[] = $idx;
                $extraMatch = $wildCount - 8;
                $payoutMult = $symbols['WILD']['payout'] * (1 + ($extraMatch * 1));
                $amount = $betSize * $payoutMult;
                $baseWin += $amount;
                $winningLines[] = [
                    'line'        => $winIndices,
                    'symbol'      => 'WILD',
                    'char'        => $symbols['WILD']['char'],
                    'match_count' => count($winIndices),
                    'payout_mult' => $payoutMult,
                    'amount'      => $amount,
                ];
            }
            return [$winningLines, $baseWin];
        };

        // --- Helper: grid keys to char array ---
        $toChars = function(array $grid) use ($symbols) {
            return array_map(fn($k) => $symbols[$k]['char'], $grid);
        };

        // --- Generate initial grid (forced win 20% / loss 80%) ---
        $isWinSpin = (rand(1, 100) <= 9.5);
        $gridKeys  = array_keys($symbols);
        $grid = [];

        if ($isWinSpin) {
            $normalSymbols = array_values(array_filter($gridKeys, fn($k) => $k !== 'WILD'));
            $target = $normalSymbols[array_rand($normalSymbols)];
            $wildCount = rand(0, 3);
            $targetCount = 8 - $wildCount;
            for ($i = 0; $i < $targetCount; $i++) $grid[] = $target;
            for ($i = 0; $i < $wildCount; $i++)   $grid[] = 'WILD';
            $otherSymbols = array_values(array_diff($normalSymbols, [$target]));
            for ($i = 0; $i < 16; $i++) $grid[] = $otherSymbols[array_rand($otherSymbols)];
            shuffle($grid);
        } else {
            $normalSymbols = array_values(array_filter($gridKeys, fn($k) => $k !== 'WILD'));
            do {
                $grid = [];
                $cnts = array_fill_keys($gridKeys, 0);
                for ($i = 0; $i < 24; $i++) { $s = $gridKeys[array_rand($gridKeys)]; $grid[] = $s; $cnts[$s]++; }
                $isLoss = true;
                foreach ($normalSymbols as $s) if ($cnts[$s] + $cnts['WILD'] >= 8) { $isLoss = false; break; }
                if ($cnts['WILD'] >= 8) $isLoss = false;
            } while (!$isLoss);
        }

        // --- Cascade loop ---
        $cascades       = [];   // Each entry: { grid, winning_lines, base_win }
        $totalBaseWin   = 0;
        $maxCascades    = 10;   // Safety cap

        for ($c = 0; $c < $maxCascades; $c++) {
            [$winningLines, $baseWin] = $evalWins($grid);

            if (empty($winningLines)) {
                // No win — store final grid with no wins and stop
                $cascades[] = [
                    'grid'          => $toChars($grid),
                    'winning_lines' => [],
                    'base_win'      => 0,
                ];
                break;
            }

            // Record this cascade step
            $totalBaseWin += $baseWin;
            $cascades[] = [
                'grid'          => $toChars($grid),
                'winning_lines' => $winningLines,
                'base_win'      => $baseWin,
            ];

            // Collect all winning indices (flatten, dedupe)
            $allWinIdx = [];
            foreach ($winningLines as $wl) {
                foreach ($wl['line'] as $idx) $allWinIdx[$idx] = true;
            }

            // Replace winning positions with new random symbols
            foreach (array_keys($allWinIdx) as $idx) {
                $grid[$idx] = $gridKeys[array_rand($gridKeys)];
            }
        }

        // Apply multiplier to total base win
        $totalWinnings = $totalBaseWin * $multiplier;
        $points += $totalWinnings;

        // Update database
        $stmt = $pdo->prepare("UPDATE games_points SET points = ? WHERE user_id = ?");
        $stmt->execute([$points, $userId]);

        echo json_encode([
            'success'    => true,
            'points'     => $points,
            'multiplier' => $multiplier,
            'cascades'   => $cascades,
            'winnings'   => $totalWinnings,
            'base_win'   => $totalBaseWin,
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
}
