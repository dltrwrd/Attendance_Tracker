<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    redirect(BASE_URL);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fortune Gems - Cyber Edition</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ffd700;
            --secondary: #ff003c;
            --bg: #0a0510;
            --panel: rgba(20, 10, 30, 0.8);
            --border: rgba(255, 215, 0, 0.3);
            --text-glow: 0 0 10px rgba(255, 215, 0, 0.8);
        }

        body {
            margin: 0;
            padding: 0;
            background: radial-gradient(circle at center, #2a1540 0%, var(--bg) 100%);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            padding-bottom: 50px; /* Ensure space at the bottom for scrolling */
        }

        .container {
            text-align: center;
            max-width: 900px;
            width: 100%;
            padding: 40px 20px;
            box-sizing: border-box;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 40px; /* Prevent overlap with back button */
        }

        h1 {
            font-size: 3rem;
            text-transform: uppercase;
            background: linear-gradient(90deg, #ffcc00, #ff6600);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
            text-shadow: 0 0 20px rgba(255, 100, 0, 0.3);
            font-weight: 900;
        }

        .stats-bar {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 20px;
            font-size: 1.5rem;
            background: var(--panel);
            padding: 15px 40px;
            border-radius: 50px;
            border: 2px solid var(--border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.8), inset 0 0 20px rgba(255,215,0,0.1);
        }

        .points-display {
            font-weight: 900;
            color: var(--primary);
            text-shadow: var(--text-glow);
        }

        .machine-wrapper {
            display: flex;
            gap: 20px;
            align-items: center;
            background: linear-gradient(180deg, #1a0f2e 0%, #0d071a 100%);
            border: 4px solid var(--primary);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.9), 0 0 30px rgba(255,215,0,0.2);
            position: relative;
        }

        /* 5x3 Grid */
        .slot-grid {
            display: grid;
            grid-template-columns: repeat(5, 70px);
            grid-template-rows: repeat(3, 70px);
            gap: 8px;
            background: rgba(0,0,0,0.8);
            padding: 15px;
            border-radius: 15px;
            border: 2px inset rgba(255,255,255,0.1);
            position: relative;
        }

        /* Multiplier Reel */
        .multiplier-reel {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 235px;
            background: rgba(0,0,0,0.8);
            border: 2px inset rgba(255,255,255,0.1);
            border-radius: 15px;
            position: relative;
            overflow: hidden;
        }

        .cell {
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.02) 100%);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            box-shadow: inset 0 0 20px rgba(0,0,0,0.8);
            position: relative;
        }

        .multiplier-val {
            font-size: 3rem;
            font-weight: 900;
            color: var(--secondary);
            text-shadow: 0 0 15px var(--secondary);
        }

        .cell.spinning {
            animation: slotSpin 0.1s linear infinite;
            filter: blur(3px);
        }

        .cell.win-highlight {
            animation: pulseWin 0.5s infinite alternate;
            border-color: var(--primary);
            background: rgba(255,215,0,0.2);
            box-shadow: 0 0 20px var(--primary), inset 0 0 20px var(--primary);
            z-index: 2;
        }

        @keyframes slotSpin {
            0% { transform: translateY(-10px); opacity: 0.8; }
            50% { transform: translateY(10px); opacity: 1; }
            100% { transform: translateY(-10px); opacity: 0.8; }
        }

        @keyframes pulseWin {
            0% { transform: scale(1); box-shadow: 0 0 10px var(--primary); }
            100% { transform: scale(1.1); box-shadow: 0 0 30px var(--primary); }
        }

        /* Controls */
        .controls {
            margin-top: 30px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .bet-selector {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .bet-btn {
            background: var(--panel);
            color: #fff;
            border: 1px solid var(--border);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.2rem;
            cursor: pointer;
            transition: 0.2s;
        }

        .bet-btn:hover {
            background: rgba(255, 215, 0, 0.2);
        }

        .bet-btn.active {
            background: var(--primary);
            color: #000;
            box-shadow: 0 0 15px var(--primary);
        }

        .action-btns {
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .spin-btn {
            background: linear-gradient(45deg, #ffcc00, #ff6600);
            color: #000;
            border: none;
            padding: 15px 60px;
            font-size: 2rem;
            font-weight: 900;
            border-radius: 50px;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 0 0 30px rgba(255, 150, 0, 0.5);
            transition: 0.2s;
        }

        .spin-btn:active:not(:disabled) { transform: scale(0.95); }
        .spin-btn:hover:not(:disabled) { transform: scale(1.05); }
        .spin-btn:disabled { background: #555; color: #888; box-shadow: none; cursor: not-allowed; }

        .auto-btn {
            background: #222;
            color: #fff;
            border: 2px solid #555;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: bold;
            border-radius: 50px;
            cursor: pointer;
            text-transform: uppercase;
            transition: 0.2s;
        }

        .auto-btn.active {
            background: var(--secondary);
            border-color: var(--secondary);
            box-shadow: 0 0 20px var(--secondary);
        }

        .message-box {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.5);
            background: rgba(0, 0, 0, 0.95);
            border: 4px solid var(--primary);
            padding: 40px 60px;
            border-radius: 20px;
            box-shadow: 0 0 80px var(--primary);
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 100;
            text-align: center;
        }

        .message-box.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        .message-title {
            font-size: 4rem;
            color: var(--primary);
            margin: 0 0 10px 0;
            text-shadow: 0 0 20px var(--primary);
            text-transform: uppercase;
            font-weight: 900;
        }

        .message-text {
            font-size: 2rem;
            margin: 0;
            color: #fff;
        }

        .back-link {
            position: absolute;
            top: 20px;
            left: 20px;
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            background: var(--panel);
            padding: 10px 20px;
            border-radius: 10px;
            border: 1px solid var(--border);
            z-index: 20;
        }
        .back-link:hover { background: rgba(255,255,255,0.1); }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            h1 { font-size: 2rem; }
            .stats-bar {
                flex-direction: column;
                gap: 10px;
                padding: 10px 20px;
                font-size: 1.2rem;
            }
            .machine-wrapper {
                flex-direction: column;
                padding: 10px;
                width: 90%;
            }
            .slot-grid {
                grid-template-columns: repeat(5, 1fr);
                grid-template-rows: repeat(3, 1fr);
                width: 100%;
                gap: 5px;
                padding: 10px;
                box-sizing: border-box;
            }
            .cell {
                font-size: 1.8rem;
                aspect-ratio: 1/1;
            }
            .multiplier-reel {
                width: 100%;
                height: 60px;
            }
            .multiplier-val {
                height: 100% !important;
                width: 100% !important;
                font-size: 2rem;
            }
            .spin-btn {
                padding: 15px 40px;
                font-size: 1.5rem;
            }
            .action-btns {
                flex-direction: column;
                width: 100%;
            }
            .auto-btn, .spin-btn { width: 100%; box-sizing: border-box; }
        }
    </style>
</head>
<body>

    <a href="index.php" class="back-link">← Back</a>

    <div class="container">
        <h1>Fortune Gems</h1>
        
        <div class="stats-bar">
            <div>Points: <span class="points-display" id="pointsDisplay">--</span></div>
            <div>Win: <span style="color:var(--secondary)" id="winDisplay">0</span></div>
        </div>

        <div class="machine-wrapper">
            <div class="slot-grid" id="slotGrid">
                <!-- 25 cells -->
            </div>
            <div class="multiplier-reel">
                <div class="cell multiplier-val" id="multCell" style="width:80%; height:100px; border:none; box-shadow:none;">1x</div>
            </div>

            <div class="message-box" id="messageBox">
                <h2 class="message-title" id="msgTitle">BIG WIN!</h2>
                <p class="message-text" id="msgText">500</p>
            </div>
        </div>

        <div class="controls">
            <div class="bet-selector" id="betSelector">
                <!-- Buttons injected by JS -->
            </div>
            
            <div class="action-btns">
                <button class="auto-btn" id="autoBtn" onclick="toggleAuto()">Auto Spin</button>
                <button class="spin-btn" id="spinBtn" onclick="spin()">SPIN</button>
            </div>
        </div>
    </div>

    <script>
        const validBets = [1, 2, 5, 10, 15, 20, 30, 40, 50];
        let currentBet = 10;
        let currentPoints = 0;
        let isSpinning = false;
        let autoSpin = false;
        let autoInterval = null;

        const gridEl = document.getElementById('slotGrid');
        const multEl = document.getElementById('multCell');
        const pointsEl = document.getElementById('pointsDisplay');
        const winEl = document.getElementById('winDisplay');
        const spinBtn = document.getElementById('spinBtn');
        const autoBtn = document.getElementById('autoBtn');
        const msgBox = document.getElementById('messageBox');
        
        let cells = [];

        function initUI() {
            // Build Grid
            for (let i = 0; i < 15; i++) {
                const cell = document.createElement('div');
                cell.className = 'cell';
                cell.textContent = '💎';
                gridEl.appendChild(cell);
                cells.push(cell);
            }

            // Build Bet Buttons
            const betContainer = document.getElementById('betSelector');
            validBets.forEach(bet => {
                const btn = document.createElement('button');
                btn.className = `bet-btn ${bet === currentBet ? 'active' : ''}`;
                btn.textContent = `Bet ${bet}`;
                btn.onclick = () => setBet(bet);
                betContainer.appendChild(btn);
            });
        }

        function setBet(bet) {
            if(isSpinning) return;
            currentBet = bet;
            document.querySelectorAll('.bet-btn').forEach(b => {
                b.classList.remove('active');
                if(b.textContent === `Bet ${bet}`) b.classList.add('active');
            });
        }

        async function fetchStatus() {
            try {
                const res = await fetch('api/slot_machine.php');
                const data = await res.json();
                if (data.success) {
                    currentPoints = data.points;
                    pointsEl.textContent = currentPoints;
                    if (data.daily_granted) showMessage("RESET!", "100 Points Awarded", "var(--primary)");
                }
            } catch (err) { console.error(err); }
        }

        function showMessage(title, text, color = "var(--primary)") {
            document.getElementById('msgTitle').textContent = title;
            document.getElementById('msgTitle').style.color = color;
            document.getElementById('msgTitle').style.textShadow = `0 0 20px ${color}`;
            document.getElementById('msgText').textContent = text;
            msgBox.style.borderColor = color;
            msgBox.style.boxShadow = `0 0 80px ${color}`;
            
            msgBox.classList.add('show');
            setTimeout(() => msgBox.classList.remove('show'), 2000);
        }

        function toggleAuto() {
            autoSpin = !autoSpin;
            autoBtn.classList.toggle('active', autoSpin);
            autoBtn.textContent = autoSpin ? 'STOP AUTO' : 'AUTO SPIN';
            if (autoSpin && !isSpinning) spin();
        }

        async function spin() {
            if (isSpinning) return;
            if (currentPoints < currentBet) {
                showMessage("NO FUNDS", "Not enough points!", "var(--secondary)");
                if(autoSpin) toggleAuto();
                return;
            }

            isSpinning = true;
            spinBtn.disabled = true;
            winEl.textContent = "0";
            
            // Visual spin
            const emojis = ['🃏', '👸', '🤴', '🅰️', '💎'];
            cells.forEach(c => {
                c.classList.remove('win-highlight');
                c.classList.add('spinning');
                c.dataset.iv = setInterval(() => c.textContent = emojis[Math.floor(Math.random()*5)], 50);
            });
            multEl.classList.add('spinning');
            multEl.dataset.iv = setInterval(() => multEl.textContent = [1,2,3,5,10,15][Math.floor(Math.random()*6)]+'x', 50);

            try {
                const fd = new FormData();
                fd.append('action', 'spin');
                fd.append('bet', currentBet);
                // Unique nonce per spin to prevent double-submission on browser refresh
                fd.append('nonce', Date.now() + '_' + Math.random().toString(36).slice(2));
                
                const res = await fetch('api/slot_machine.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    // PRG fix: replace browser history state so F5 refresh never re-POSTs the spin
                    if (history.replaceState) {
                        history.replaceState(null, '', window.location.pathname);
                    }
                    setTimeout(() => stopSpinning(data), 1000);
                } else {
                    stopVisuals();
                    showMessage("ERROR", data.message, "var(--secondary)");
                    isSpinning = false;
                    spinBtn.disabled = false;
                    if(autoSpin) toggleAuto();
                }
            } catch (err) {
                stopVisuals();
                isSpinning = false;
                spinBtn.disabled = false;
                if(autoSpin) toggleAuto();
            }
        }

        function stopVisuals() {
            cells.forEach(c => { clearInterval(c.dataset.iv); c.classList.remove('spinning'); });
            clearInterval(multEl.dataset.iv);
            multEl.classList.remove('spinning');
        }

        function stopSpinning(data) {
            stopVisuals();
            
            data.grid.forEach((char, i) => cells[i].textContent = char);
            multEl.textContent = data.multiplier + 'x';
            
            currentPoints = data.points;
            pointsEl.textContent = currentPoints;
            winEl.textContent = data.winnings;

            if (data.winnings > 0) {
                data.winning_lines.forEach(wl => {
                    wl.line.forEach(idx => cells[idx].classList.add('win-highlight'));
                });
                multEl.classList.add('win-highlight');
                
                if(data.winnings >= currentBet * 10) {
                    showMessage("MEGA WIN!", `+${data.winnings}`, "var(--primary)");
                }
            }

            isSpinning = false;
            spinBtn.disabled = false;

            if (autoSpin) {
                // Wait 1.5s before next auto spin
                setTimeout(() => {
                    if (autoSpin && currentPoints >= currentBet) spin();
                    else if (autoSpin) toggleAuto();
                }, 1500);
            }
        }

        initUI();
        fetchStatus();
    </script>
</body>
</html>
