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
            --primary: #FFD700;
            --primary-dark: #b8860b;
            --secondary: #00ffcc;
            --bg: #0d1a12;
            --panel: rgba(20, 40, 20, 0.9);
            --border: #4d5c48;
            --gold-glow: 0 0 15px rgba(255, 215, 0, 0.8);
            --stone-shadow: inset 0 0 20px #000, 0 10px 20px rgba(0,0,0,0.8);
        }

        /* ---- Video Background ---- */
        #bg-video {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            z-index: -2;
        }
        .video-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.55);
            z-index: -1;
            pointer-events: none;
        }

        /* ---- Cascading Reel Animations ---- */
        @keyframes shatter {
            0%   { transform: scale(1) rotate(0deg); opacity: 1; }
            40%  { transform: scale(1.25) rotate(12deg); opacity: 0.7; }
            100% { transform: scale(0) rotate(-25deg); opacity: 0; }
        }
        @keyframes dropIn {
            0%   { transform: translateY(-70px) scale(0.4); opacity: 0; }
            70%  { transform: translateY(6px) scale(1.08); opacity: 1; }
            100% { transform: translateY(0) scale(1); opacity: 1; }
        }
        .cell.shattering {
            animation: shatter 0.38s ease-in forwards;
            pointer-events: none;
        }
        .cell.dropping {
            animation: dropIn 0.42s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            padding-bottom: 50px;
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
            margin-top: 40px;
            position: relative;
        }

        h1 {
            font-size: 3.5rem;
            text-transform: uppercase;
            background: linear-gradient(180deg, #FFD700 0%, #b8860b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
            text-shadow: 0 4px 6px rgba(0, 0, 0, 0.9), var(--gold-glow);
            font-weight: 900;
            letter-spacing: 3px;
        }

        .stats-bar {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 20px;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #2b3327 0%, #171d15 100%);
            padding: 15px 40px;
            border-radius: 50px;
            border: 3px solid #5d6b58;
            box-shadow: 0 10px 30px rgba(0,0,0,0.9), inset 0 0 15px rgba(0,0,0,0.5);
            color: #ccc;
        }

        .points-display {
            font-weight: 900;
            color: var(--primary);
            text-shadow: var(--gold-glow);
        }

        .machine-wrapper {
            display: flex;
            gap: 20px;
            align-items: center;
            background: linear-gradient(180deg, #2c3529 0%, #151a14 100%);
            border: 6px solid #b8860b;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--stone-shadow), 0 0 40px rgba(0,0,0,0.6);
            position: relative;
        }
        
        .machine-wrapper::before, .machine-wrapper::after {
            content: '♦';
            position: absolute;
            color: #FFD700;
            font-size: 2.5rem;
            text-shadow: var(--gold-glow);
        }
        .machine-wrapper::before { top: -20px; left: -10px; }
        .machine-wrapper::after { bottom: -20px; right: -10px; }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(6, 75px);
            grid-template-rows: repeat(4, 75px);
            gap: 10px;
            background: #111511;
            padding: 15px;
            border-radius: 10px;
            border: 3px inset #4d5c48;
            position: relative;
        }

        .multiplier-reel {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 85px;
            height: 255px;
            background: #111511;
            border: 3px inset #4d5c48;
            border-radius: 10px;
            position: relative;
            overflow: hidden;
        }

        .cell {
            background: linear-gradient(135deg, #3a3028 0%, #1e1710 100%);
            border: 2px solid #6b5a3a;
            border-bottom: 4px solid #1a1410;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.5), 0 4px 6px rgba(0,0,0,0.8);
            position: relative;
            overflow: hidden;
            padding: 3px;
        }
        .cell img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 5px;
            display: block;
            pointer-events: none;
            user-select: none;
        }

        .multiplier-val {
            font-size: 3rem;
            font-weight: 900;
            color: var(--secondary);
            text-shadow: 0 0 15px var(--secondary);
            border: none !important;
            box-shadow: none !important;
            background: transparent !important;
        }

        .cell.spinning {
            animation: slotSpin 0.1s linear infinite;
            filter: blur(3px);
        }

        .cell.win-highlight {
            animation: pulseWin 0.5s infinite alternate;
            border-color: var(--primary);
            background: linear-gradient(135deg, #b8860b 0%, #5c4305 100%);
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
            100% { transform: scale(1.15); box-shadow: 0 0 30px var(--primary); }
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
            background: linear-gradient(180deg, #3a4235 0%, #1d221a 100%);
            color: #ccc;
            border: 2px solid #5d6b58;
            border-bottom: 4px solid #1a1e18;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1.2rem;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
        }

        .bet-btn:hover {
            background: linear-gradient(180deg, #4d5c48 0%, #2b3327 100%);
            color: #fff;
        }

        .bet-btn.active {
            background: linear-gradient(180deg, #1d221a 0%, #0d1a12 100%);
            border-color: var(--secondary);
            color: var(--secondary);
            box-shadow: 0 0 15px rgba(0, 255, 204, 0.4), inset 0 0 10px rgba(0,0,0,0.8);
            border-bottom: 2px solid var(--secondary);
            transform: translateY(2px);
        }

        .action-btns {
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .spin-btn {
            background: linear-gradient(180deg, #FFD700 0%, #b8860b 100%);
            color: #222;
            border: 2px solid #fff;
            padding: 15px 60px;
            font-size: 2.2rem;
            font-weight: 900;
            border-radius: 8px;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 0 6px 0 #5c4305, 0 10px 20px rgba(0,0,0,0.8);
            text-shadow: 1px 1px 0 rgba(255,255,255,0.5);
            transition: all 0.1s;
            letter-spacing: 2px;
        }

        .spin-btn:active:not(:disabled) { 
            transform: translateY(6px); 
            box-shadow: 0 0 0 #5c4305, 0 2px 5px rgba(0,0,0,0.8); 
        }
        .spin-btn:hover:not(:disabled) { filter: brightness(1.1); }
        .spin-btn:disabled { background: #555; color: #333; box-shadow: none; border-color:#444; cursor: not-allowed; text-shadow:none; }

        .auto-btn {
            background: linear-gradient(180deg, #3a4235 0%, #1d221a 100%);
            color: #ccc;
            border: 2px solid #5d6b58;
            border-bottom: 4px solid #1a1e18;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            text-transform: uppercase;
            transition: 0.2s;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
        }

        .auto-btn.active {
            background: linear-gradient(180deg, #1d221a 0%, #0d1a12 100%);
            border-color: var(--secondary);
            color: var(--secondary);
            box-shadow: 0 0 15px rgba(0, 255, 204, 0.4), inset 0 0 10px rgba(0,0,0,0.8);
            border-bottom: 2px solid var(--secondary);
            transform: translateY(2px);
        }

        .message-box {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.5);
            background: rgba(10, 15, 10, 0.95);
            border: 4px solid var(--primary);
            padding: 40px 60px;
            border-radius: 15px;
            box-shadow: 0 0 80px var(--primary), inset 0 0 30px rgba(0,0,0,0.8);
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
            letter-spacing: 2px;
        }

        .message-text {
            font-size: 2.5rem;
            margin: 0;
            color: #fff;
            font-weight: bold;
        }

        .back-link {
            position: absolute;
            top: 20px;
            left: 20px;
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            background: rgba(20, 40, 20, 0.9);
            padding: 10px 20px;
            border-radius: 8px;
            border: 2px solid #5d6b58;
            z-index: 20;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
            transition: 0.2s;
        }
        .back-link:hover { background: #3a4235; border-color: #fff; }

        /* Mobile Responsiveness — Tablet (≤ 768px) */
        @media (max-width: 768px) {
            h1 { font-size: 2rem; letter-spacing: 1px; }

            .container {
                padding: 15px 10px;
                margin-top: 60px; /* space for back-link */
                width: 100%;
            }

            .stats-bar {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 12px;
                padding: 10px 16px;
                font-size: 1.1rem;
                border-radius: 20px;
                width: 100%;
                box-sizing: border-box;
            }

            .machine-wrapper {
                flex-direction: column;
                padding: 12px;
                width: 100%;
                box-sizing: border-box;
                margin: 0;
                border-width: 4px;
            }
            /* Hide decorative corner diamonds on mobile — they overflow */
            .machine-wrapper::before,
            .machine-wrapper::after { display: none; }

            .slot-grid {
                grid-template-columns: repeat(6, 1fr);
                grid-template-rows: repeat(4, 1fr);
                width: 100%;
                gap: 5px;
                padding: 8px;
                box-sizing: border-box;
            }

            .cell {
                font-size: 1.6rem;
                aspect-ratio: 1/1;
                min-height: 0;
                border-radius: 6px;
            }

            .multiplier-reel {
                width: 100%;
                height: 55px;
                flex-direction: row;
                border-radius: 8px;
            }

            .multiplier-val {
                height: 100% !important;
                width: 100% !important;
                font-size: 1.8rem;
            }

            .controls { gap: 12px; }

            .bet-selector { gap: 7px; }

            .bet-btn {
                padding: 8px 14px;
                font-size: 1rem;
            }

            .action-btns {
                flex-direction: column;
                width: 100%;
                gap: 10px;
            }

            .auto-btn, .spin-btn {
                width: 100%;
                box-sizing: border-box;
                font-size: 1.5rem;
                padding: 14px 20px;
            }

            #winBreakdown {
                font-size: 1rem;
                word-break: break-word;
                padding: 0 5px;
            }

            .back-link {
                position: fixed;
                top: 10px;
                left: 10px;
                padding: 8px 14px;
                font-size: 0.9rem;
                z-index: 100;
            }

            .message-box {
                padding: 25px 20px;
                width: 80%;
            }

            .message-title { font-size: 2.5rem; }
            .message-text  { font-size: 1.6rem; }
        }

        /* Small phones (≤ 480px) */
        @media (max-width: 480px) {
            h1 { font-size: 1.6rem; }

            .stats-bar {
                font-size: 1rem;
                padding: 8px 12px;
                gap: 8px;
            }

            .cell { font-size: 1.3rem; }

            .multiplier-val { font-size: 1.5rem; }

            .spin-btn { font-size: 1.3rem; padding: 12px 16px; }
            .auto-btn { font-size: 1rem; padding: 12px 16px; }

            .bet-btn { padding: 7px 10px; font-size: 0.9rem; }

            .message-box { width: 90%; padding: 20px 15px; }
            .message-title { font-size: 2rem; }
            .message-text  { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

    <!-- Video Background -->
    <video id="bg-video" autoplay muted loop playsinline>
        <source src="assets/Temple_run_vid.mp4" type="video/mp4">
    </video>
    <div class="video-overlay"></div>

    <a href="index.php" class="back-link">← Back</a>

    <div class="container">
        <h1>Fortune Gems</h1>
        
        <div class="stats-bar">
            <div>Points: <span class="points-display" id="pointsDisplay">--</span></div>
            <div>Win: <span style="color:var(--secondary)" id="winDisplay">0</span></div>
        </div>
        <div id="winBreakdown" style="margin-bottom: 20px; font-size: 1.2rem; color: #ffcc00; text-align: center; min-height: 25px; text-shadow: 0 0 10px rgba(255, 215, 0, 0.5);"></div>

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

        const gridEl   = document.getElementById('slotGrid');
        const multEl   = document.getElementById('multCell');
        const pointsEl = document.getElementById('pointsDisplay');
        const winEl    = document.getElementById('winDisplay');
        const spinBtn  = document.getElementById('spinBtn');
        const autoBtn  = document.getElementById('autoBtn');
        const msgBox   = document.getElementById('messageBox');
        const breakdown = document.getElementById('winBreakdown');

        let cells = [];

        // All card image paths
        const cardImgs = [
            'assets/joker-removebg-preview.png',
            'assets/queen-removebg-preview.png',
            'assets/queen_heart-removebg-preview.png',
            'assets/king_spade-removebg-preview.png',
            'assets/king_heart-removebg-preview.png',
            'assets/ace_clove-removebg-preview.png',
            'assets/ace_diamond-removebg-preview.png',
            'assets/ace_heart-removebg-preview.png',
            'assets/ace_spade-removebg-preview.png',
            'assets/coins-removebg-preview.png'
        ];

        function setCardImg(cell, src) {
            if (!src) { cell.innerHTML = ''; return; }
            cell.innerHTML = `<img src="${src}" alt="card">`;
        }

        function initUI() {
            for (let i = 0; i < 24; i++) {
                const cell = document.createElement('div');
                cell.className = 'cell';
                setCardImg(cell, 'assets/coins-removebg-preview.png');
                gridEl.appendChild(cell);
                cells.push(cell);
            }
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
            if (isSpinning) return;
            currentBet = bet;
            document.querySelectorAll('.bet-btn').forEach(b => {
                b.classList.remove('active');
                if (b.textContent === `Bet ${bet}`) b.classList.add('active');
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
            setTimeout(() => msgBox.classList.remove('show'), 2500);
        }

        function toggleAuto() {
            autoSpin = !autoSpin;
            autoBtn.classList.toggle('active', autoSpin);
            autoBtn.textContent = autoSpin ? 'STOP AUTO' : 'AUTO SPIN';
            if (autoSpin && !isSpinning) spin();
        }

        // Utility: wait ms milliseconds
        const wait = ms => new Promise(r => setTimeout(r, ms));

        // Animate class: add class, wait for its duration, remove it
        function animateCell(cell, cls, durationMs) {
            return new Promise(resolve => {
                cell.classList.add(cls);
                setTimeout(() => { cell.classList.remove(cls); resolve(); }, durationMs);
            });
        }

        async function spin() {
            if (isSpinning) return;
            if (currentPoints < currentBet) {
                showMessage("NO FUNDS", "Not enough points!", "var(--secondary)");
                if (autoSpin) toggleAuto();
                return;
            }

            isSpinning = true;
            spinBtn.disabled = true;
            winEl.textContent = "0";
            breakdown.innerHTML = "";

            // Reset cell states
            cells.forEach(c => {
                c.classList.remove('win-highlight', 'shattering', 'dropping');
            });
            multEl.classList.remove('win-highlight');

            // Visual spin — cycle card images rapidly
            cells.forEach(c => {
                c.classList.add('spinning');
                c.dataset.iv = setInterval(() => setCardImg(c, cardImgs[Math.floor(Math.random() * cardImgs.length)]), 80);
            });
            multEl.classList.add('spinning');
            multEl.dataset.iv = setInterval(() => multEl.textContent = [1,2,3,5,10,15][Math.floor(Math.random()*6)]+'x', 80);

            try {
                const fd = new FormData();
                fd.append('action', 'spin');
                fd.append('bet', currentBet);
                fd.append('nonce', Date.now() + '_' + Math.random().toString(36).slice(2));

                const res = await fetch('api/slot_machine.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    if (history.replaceState) history.replaceState(null, '', window.location.pathname);
                    await wait(900);
                    await playCascades(data);
                } else {
                    stopVisuals();
                    showMessage("ERROR", data.message, "var(--secondary)");
                    isSpinning = false;
                    spinBtn.disabled = false;
                    if (autoSpin) toggleAuto();
                }
            } catch (err) {
                stopVisuals();
                isSpinning = false;
                spinBtn.disabled = false;
                if (autoSpin) toggleAuto();
            }
        }

        function stopVisuals() {
            cells.forEach(c => { clearInterval(c.dataset.iv); c.classList.remove('spinning'); });
            clearInterval(multEl.dataset.iv);
            multEl.classList.remove('spinning');
        }

        async function playCascades(data) {
            stopVisuals();

            // Show multiplier reel result
            multEl.textContent = data.multiplier + 'x';

            let runningBaseWin = 0;

            for (let ci = 0; ci < data.cascades.length; ci++) {
                const cascade = data.cascades[ci];

                // Render this cascade's grid
                cascade.grid.forEach((src, i) => setCardImg(cells[i], src));

                // If no winning lines, this is the final idle grid — stop here
                if (!cascade.winning_lines || cascade.winning_lines.length === 0) break;

                // Highlight winners
                const winIdxSet = new Set();
                cascade.winning_lines.forEach(wl => wl.line.forEach(idx => winIdxSet.add(idx)));
                winIdxSet.forEach(idx => cells[idx].classList.add('win-highlight'));

                // Accumulate base win and update breakdown
                runningBaseWin += cascade.base_win;
                let bHtml = '';
                cascade.winning_lines.forEach(wl => {
                    bHtml += `<div style="animation:dropIn .3s ease; display:flex; align-items:center; gap:8px;"><img src="${wl.char}" style="width:28px;height:38px;object-fit:contain;border-radius:3px;"> ${wl.match_count}× &nbsp;base: <b>+${wl.amount}</b></div>`;
                });
                breakdown.innerHTML += bHtml;

                await wait(700); // let player see the highlights

                // Shatter winning cells
                const shatterPs = [...winIdxSet].map(idx => animateCell(cells[idx], 'shattering', 380));
                await Promise.all(shatterPs);

                // Clear win-highlight so they look empty
                winIdxSet.forEach(idx => {
                    cells[idx].classList.remove('win-highlight');
                    cells[idx].innerHTML = '';
                });

                await wait(120);

                // If there's a next cascade, drop new symbols into the winning spots
                const nextCascade = data.cascades[ci + 1];
                if (nextCascade) {
                    const dropPs = [...winIdxSet].map(idx => {
                        setCardImg(cells[idx], nextCascade.grid[idx]);
                        return animateCell(cells[idx], 'dropping', 420);
                    });
                    await Promise.all(dropPs);
                    await wait(200);
                }
            }

            // Final state
            const lastCascade = data.cascades[data.cascades.length - 1];
            lastCascade.grid.forEach((src, i) => setCardImg(cells[i], src));

            // Update stats
            currentPoints = data.points;
            pointsEl.textContent = currentPoints;
            winEl.textContent = data.winnings;

            if (data.winnings > 0) {
                multEl.classList.add('win-highlight');
                // Show final math summary
                breakdown.innerHTML += `
                    <div style="margin-top:8px; padding-top:6px; border-top:1px solid rgba(255,215,0,0.3); color:#fff;">
                        Base Total: <b>${data.base_win}</b>  ×  ${data.multiplier}x  =  
                        <span style="color:var(--secondary); font-size:1.4em; font-weight:900;">+${data.winnings}</span>
                    </div>`;

                if (data.winnings >= currentBet * 10) {
                    showMessage("MEGA WIN!", `+${data.winnings}`, "var(--primary)");
                }
            }

            isSpinning = false;
            spinBtn.disabled = false;

            if (autoSpin) {
                await wait(1500);
                if (autoSpin && currentPoints >= currentBet) spin();
                else if (autoSpin) toggleAuto();
            }
        }

        initUI();
        fetchStatus();
    </script>
</body>
</html>

