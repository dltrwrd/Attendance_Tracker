<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Session verification
if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
    echo "<div style='color: white; background: #0f172a; padding: 20px; font-family: sans-serif; text-align: center; height: 100vh; display: flex; align-items: center; justify-content: center;'>Unauthorized Access. Please log in first.</div>";
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT id, fullname, display_photo FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

$fullname = $currentUser['fullname'] ?? 'User';
$displayPhoto = !empty($currentUser['display_photo']) 
    ? BASE_URL . "components/profile/" . $currentUser['display_photo'] 
    : BASE_URL . "components/profile/default.jpg";
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Channel | CXI Services</title>
    <link rel="icon" href="../assets/cxiico.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/peerjs@1.5.2/dist/peerjs.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        darkBg: '#0b0f19',
                        panelBg: '#131926',
                        accentGreen: '#10b981',
                        accentRed: '#ef4444',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #0b0f19;
            user-select: none;
            overflow-x: hidden;
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #0b0f19;
        }
        ::-webkit-scrollbar-thumb {
            background: #1f2937;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #374151;
        }
        /* Speaking animation pulse - Premium Border style (no glow) */
        .speaking-glow {
            border-color: #10b981 !important;
            transform: scale(1.05);
            transition: all 0.12s ease-in-out;
        }
        .speaking-glow-local {
            border-color: #3b82f6 !important;
            transform: scale(1.05);
            transition: all 0.12s ease-in-out;
        }
        /* Default state: right container takes full width */
        #right-container {
            width: 100%;
            flex: 1 1 0%;
            display: flex;
            flex-direction: row;
            min-width: 0;
        }
        #participants-panel {
            flex: 1 1 0%;
            display: flex;
            flex-direction: column;
        }
        #chat-panel {
            display: none;
            width: 370px;
            min-width: 370px;
            flex-direction: column;
            border-left: 1px solid rgba(31, 41, 55, 0.6);
        }
        #voice-layout-container.chat-open #chat-panel {
            display: flex;
        }
        /* When screen sharing is active: right container acts as a sidebar */
        #voice-layout-container.screen-active #right-container {
            width: 410px;
            min-width: 410px;
            flex: none;
            flex-direction: column;
        }
        #voice-layout-container.screen-active #participants-panel {
            border-right: none;
            border-bottom: 1px solid rgba(31, 41, 55, 0.6);
            height: 200px;
            flex: none;
        }
        #voice-layout-container.screen-active #chat-panel {
            width: 100%;
            min-width: 100%;
            flex: 1 1 0%;
            border-left: none;
        }
    </style>
</head>
<body class="text-gray-100 flex flex-col h-screen antialiased">

    <!-- Top Premium Header -->
    <header class="flex items-center justify-between px-6 py-4 border-b border-gray-800 bg-panelBg/80 backdrop-blur-md sticky top-0 z-50">
        <div class="flex items-center space-x-3">
            <div class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
            </div>
            <div>
                <h1 class="text-sm font-bold tracking-wider text-emerald-400 uppercase">CXI VOICE ROOM</h1>
                <p class="text-xs text-gray-400" id="connection-status">Connecting to network...</p>
            </div>
        </div>
        <div class="text-xs text-gray-500 font-mono bg-darkBg/60 px-2 py-1 rounded border border-gray-800" id="peer-id-badge">
            PEER ID: --
        </div>
    </header>

    <!-- Split Layout Container (Adapts when screen sharing is active) -->
    <div id="voice-layout-container" class="flex-1 flex overflow-hidden">
        <!-- Left Side: Screen Share Video (Hidden by default, shown when someone shares screen) -->
        <div id="screen-share-container" class="hidden flex-1 bg-black border-r border-gray-800 flex flex-col relative justify-center items-center">
            <video id="screen-share-video" class="w-full h-full object-contain" autoplay playsinline></video>
            <div id="screen-share-info-overlay" class="absolute bottom-4 left-4 bg-black/60 backdrop-blur-md px-3 py-1.5 rounded-lg border border-gray-800 text-xs text-gray-300 flex items-center space-x-2 shadow-lg">
                <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                <span id="screen-share-username" class="font-semibold text-white">Someone</span><span>is sharing screen</span>
            </div>
        </div>

        <!-- Right Side: Channel Participants List & Chat -->
        <div id="right-container" class="flex-1 flex overflow-hidden min-w-0">
            <!-- Channel Participants Panel -->
            <main id="participants-panel" class="flex flex-col overflow-y-auto px-6 py-6 space-y-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-gray-400 tracking-wider uppercase">Channel Participants</span>
                    <span class="text-xs font-semibold text-gray-500 bg-gray-800/40 px-2 py-0.5 rounded-full" id="participants-count">0 connected</span>
                </div>

                <!-- Participants List -->
                <div id="participants-list" class="grid grid-cols-1 gap-3">
                    <!-- Dynamically populated via JS -->
                    <div class="flex items-center justify-center py-12 text-gray-500 text-sm space-x-2">
                        <i class="fas fa-spinner animate-spin"></i>
                        <span>Setting up media devices...</span>
                    </div>
                </div>
            </main>

            <!-- Chat Box Panel -->
            <div id="chat-panel" class="bg-panelBg/30 flex flex-col h-full">
                <!-- Chat Header -->
                <div class="p-4 border-b border-gray-800 flex items-center justify-between bg-panelBg/60 backdrop-blur-sm">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-comments text-emerald-400"></i>
                        <span class="text-xs font-semibold text-gray-300 uppercase tracking-wider">Live Chat</span>
                    </div>
                </div>

                <!-- Chat Messages Area -->
                <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
                    <div class="text-center py-8 text-gray-500 text-xs italic">
                        Messages are secure and peer-to-peer.
                    </div>
                </div>

                <!-- Chat Input Area -->
                <div class="p-4 border-t border-gray-800 bg-panelBg/40">
                    <form id="chat-form" class="flex items-center space-x-2">
                        <input type="text" id="chat-input" placeholder="Type a message..." autocomplete="off" class="flex-1 bg-darkBg/60 border border-gray-700/80 rounded-xl px-4 py-2.5 text-sm text-gray-200 focus:outline-none focus:border-emerald-500 placeholder-gray-500 transition">
                        <button type="submit" class="w-10 h-10 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white flex items-center justify-center transition active:scale-95">
                            <i class="fas fa-paper-plane text-sm"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls Panel -->
    <footer class="bg-panelBg border-t border-gray-800 p-6 space-y-4 w-full z-10">
        <!-- Volume Controller -->
        <div class="flex items-center space-x-3 bg-darkBg/50 p-3 rounded-xl border border-gray-800/60">
            <button id="footer-vol-icon" class="text-gray-400 hover:text-white transition">
                <i class="fas fa-volume-up"></i>
            </button>
            <input type="range" id="volume-slider" min="0" max="1" step="0.01" value="1.0" class="flex-1 accent-emerald-500 bg-gray-800 h-1.5 rounded-lg appearance-none cursor-pointer">
            <span class="text-xs font-mono text-gray-400 w-8 text-right" id="volume-label">100%</span>
        </div>

        <!-- Main Button Actions -->
        <div class="flex items-center justify-center space-x-3">
            <!-- Mute mic -->
            <button id="btn-mute" class="w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300" title="Mute Microphone">
                <i class="fas fa-microphone text-lg"></i>
            </button>

            <!-- Deafen audio -->
            <button id="btn-deafen" class="w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300" title="Deafen Audio">
                <i class="fas fa-headphones text-lg"></i>
            </button>

            <!-- Share Screen -->
            <button id="btn-share-screen" class="w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300" title="Share Screen">
                <i class="fas fa-desktop text-lg"></i>
            </button>

            <!-- Toggle Chat -->
            <button id="btn-chat" class="w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300 relative" title="Toggle Chat">
                <i class="fas fa-comments text-lg"></i>
                <span id="chat-unread-badge" class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-emerald-500 border border-slate-900 hidden animate-pulse"></span>
            </button>

            <!-- Red Disconnect Button -->
            <button id="btn-disconnect" class="px-6 h-12 rounded-xl bg-red-600 hover:bg-red-500 active:scale-95 text-white font-semibold transition-all flex items-center justify-center space-x-2" title="Leave Call">
                <i class="fas fa-phone-slash"></i>
                <span>Disconnect</span>
            </button>
        </div>
    </footer>

    <!-- Audio Elements -->
    <audio id="joinSound" src="../assets/join.mp3" preload="auto"></audio>
    <audio id="disconnectSound" src="../assets/disconnect.mp3" preload="auto"></audio>

    <script>
        // Global variables for call state
        const MY_USER_ID = <?= json_encode($userId) ?>;
        const MY_FULLNAME = <?= json_encode($fullname) ?>;
        const MY_PHOTO = <?= json_encode($displayPhoto) ?>;

        let localStream = null;
        let peer = null;
        let myPeerId = null;
        let activeCalls = {};
        let knownPeers = [];
        
        let isMuted = false;
        let isDeafened = false;
        let currentVolume = 1.0;
        let dbParticipantsList = [];

        // Screen share state variables
        let localScreenStream = null;
        let activeScreenCalls = {}; // peer_id -> screen call
        let isSharingScreen = false;
        let remoteScreenStreams = {}; // peer_id -> mediaStream
        let activeStagePeerId = null; // peer_id of current stream on stage

        // Audio analysers for showing speaking status
        let localAnalyserCleanup = null;
        let peerAnalysers = {}; // maps peer_id -> cleanup function

        // Chat state variables
        let isChatOpen = false;
        let activeConnections = {}; // peer_id -> dataConnection

        // Set up Broadcast Channel for multi-tab sync
        const channel = new BroadcastChannel('cxi_voice_channel');

        // Elements
        const participantsListContainer = document.getElementById('participants-list');
        const participantsCountEl = document.getElementById('participants-count');
        const muteBtn = document.getElementById('btn-mute');
        const deafenBtn = document.getElementById('btn-deafen');
        const screenShareBtn = document.getElementById('btn-share-screen');
        const chatBtn = document.getElementById('btn-chat');
        const screenShareContainer = document.getElementById('screen-share-container');
        const screenShareVideo = document.getElementById('screen-share-video');
        const screenShareUsername = document.getElementById('screen-share-username');
        const volumeSlider = document.getElementById('volume-slider');
        const volumeLabel = document.getElementById('volume-label');
        const connectionStatus = document.getElementById('connection-status');
        const peerIdBadge = document.getElementById('peer-id-badge');
        const chatPanel = document.getElementById('chat-panel');
        const chatForm = document.getElementById('chat-form');
        const chatInput = document.getElementById('chat-input');
        const chatMessages = document.getElementById('chat-messages');
        const chatUnreadBadge = document.getElementById('chat-unread-badge');

        // Play Sound utilities
        function playSound(type) {
            try {
                const el = document.getElementById(type + 'Sound');
                if (el) {
                    el.currentTime = 0;
                    el.play().catch(err => console.log('Audio autoplay blocked:', err));
                }
            } catch(e) {
                console.warn(e);
            }
        }

        // Send updates back to all other tabs
        function broadcastState() {
            const state = {
                type: 'status_sync',
                isConnectedToVoice: true,
                isMuted: isMuted,
                isDeafened: isDeafened,
                currentVolume: currentVolume,
                myPeerId: myPeerId,
                participants: dbParticipantsList.map(p => ({
                    fullname: p.fullname,
                    display_photo: p.display_photo,
                    peer_id: p.peer_id,
                    is_me: p.peer_id === myPeerId
                }))
            };

            // Send to other tabs
            channel.postMessage(state);

            // Persist state in localStorage
            try {
                localStorage.setItem('cxi_voice_state', JSON.stringify(state));
            } catch (e) {
                console.warn("Failed to save state to localStorage:", e);
            }
        }

        // Listen for actions sent from main tabs
        channel.onmessage = (event) => {
            const data = event.data;
            if (!data) return;

            switch(data.type) {
                case 'query_status':
                    broadcastState();
                    break;
                case 'toggle_mute':
                    toggleMute();
                    break;
                case 'toggle_deafen':
                    toggleDeafen();
                    break;
                case 'change_volume':
                    changeVolume(data.value);
                    break;
                case 'leave':
                    leaveCallAndClose();
                    break;
            }
        };

        // Handle Mute
        function toggleMute() {
            if (!localStream) return;
            isMuted = !isMuted;

            // Deafen locks mute to active
            if (!isMuted && isDeafened) {
                isMuted = true;
                return;
            }

            localStream.getAudioTracks()[0].enabled = !isMuted;
            updateMuteButtonUI();
            broadcastState();
            updateParticipantsUI();
        }

        function updateMuteButtonUI() {
            if (isMuted) {
                muteBtn.innerHTML = '<i class="fas fa-microphone-slash text-lg"></i>';
                muteBtn.className = "w-12 h-12 rounded-xl bg-red-500/10 border border-red-500/30 text-red-500 flex items-center justify-center transition-all";
                muteBtn.setAttribute('title', 'Unmute Microphone');
            } else {
                muteBtn.innerHTML = '<i class="fas fa-microphone text-lg"></i>';
                muteBtn.className = "w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300";
                muteBtn.setAttribute('title', 'Mute Microphone');
            }
        }

        // Handle Deafen
        function toggleDeafen() {
            isDeafened = !isDeafened;
            
            if (isDeafened && !isMuted) {
                toggleMute();
            }
 
            document.querySelectorAll('.remote-audio').forEach(audio => {
                audio.muted = isDeafened;
            });
 
            updateDeafenButtonUI();
            broadcastState();
            updateParticipantsUI();
        }
 
        function updateDeafenButtonUI() {
            if (isDeafened) {
                deafenBtn.innerHTML = '<i class="fas fa-headphones text-lg"></i>';
                deafenBtn.className = "w-12 h-12 rounded-xl bg-red-500/10 border border-red-500/30 text-red-500 flex items-center justify-center transition-all";
                deafenBtn.setAttribute('title', 'Undeafen Audio');
            } else {
                deafenBtn.innerHTML = '<i class="fas fa-headphones text-lg"></i>';
                deafenBtn.className = "w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300";
                deafenBtn.setAttribute('title', 'Deafen Audio');
            }
        }

        // Handle Volume
        function changeVolume(val) {
            currentVolume = parseFloat(val);
            volumeSlider.value = currentVolume;
            volumeLabel.innerText = Math.round(currentVolume * 100) + '%';
            
            document.querySelectorAll('.remote-audio').forEach(audio => {
                audio.volume = currentVolume;
            });
            
            const volIcon = document.getElementById('footer-vol-icon').querySelector('i');
            if (currentVolume === 0) {
                volIcon.className = "fas fa-volume-mute text-gray-500";
            } else if (currentVolume < 0.5) {
                volIcon.className = "fas fa-volume-down text-gray-400";
            } else {
                volIcon.className = "fas fa-volume-up text-gray-400";
            }

            broadcastState();
        }

        // Audio Stream Analyser logic to show speaker glow
        function monitorStreamVolume(stream, callback) {
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const source = audioContext.createMediaStreamSource(stream);
                const analyser = audioContext.createAnalyser();
                analyser.fftSize = 256;
                source.connect(analyser);
                const dataArray = new Uint8Array(analyser.frequencyBinCount);
                let active = true;

                function checkVolume() {
                    if (!active) return;
                    analyser.getByteFrequencyData(dataArray);
                    let sum = 0;
                    for (let i = 0; i < dataArray.length; i++) {
                        sum += dataArray[i];
                    }
                    const average = sum / dataArray.length;
                    callback(average);
                    setTimeout(() => {
                        requestAnimationFrame(checkVolume);
                    }, 50); // check 20 times per second
                }
                checkVolume();
                
                return () => {
                    active = false;
                    try {
                        source.disconnect();
                        analyser.disconnect();
                        audioContext.close();
                    } catch(err) {
                        console.warn(err);
                    }
                };
            } catch(e) {
                console.error("Failed to start audio analyzer:", e);
                return () => {};
            }
        }

        // Setup the peer connection and join voice
        async function initVoiceCall() {
            try {
                connectionStatus.innerText = "Requesting microphone access...";
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                connectionStatus.innerText = "Connecting to Peer Server...";
                
                peer = new Peer({
                    config: {
                        'iceServers': [
                            { urls: 'stun:stun.l.google.com:19302' },
                            { urls: 'stun:stun1.l.google.com:19302' },
                            { urls: 'stun:global.stun.twilio.com:3478' }
                        ]
                    },
                    debug: 1
                });

                // Monitor local mic for speaking indicator
                if (localStream) {
                    localAnalyserCleanup = monitorStreamVolume(localStream, (vol) => {
                        const avatarEl = document.getElementById('avatar-' + myPeerId);
                        if (avatarEl) {
                            // If user is muted, don't show speaking indicators
                            if (isMuted) {
                                avatarEl.classList.remove('speaking-glow-local');
                                return;
                            }
                            if (vol > 8) { // threshold
                                avatarEl.classList.add('speaking-glow-local');
                            } else {
                                avatarEl.classList.remove('speaking-glow-local');
                            }
                        }
                    });
                }

                peer.on('open', async (id) => {
                    myPeerId = id;
                    peerIdBadge.innerText = "PEER ID: " + id;
                    connectionStatus.innerText = "Connected to room";
                    playSound('join');

                    // Register with DB
                    const formData = new FormData();
                    formData.append('action', 'join');
                    formData.append('peer_id', myPeerId);
                    await fetch('../components/voice_status.php', { method: 'POST', body: formData });
                    
                    fetchVoiceParticipants();
                    // Sync with any active tabs
                    broadcastState();
                });

                peer.on('call', (call) => {
                    console.log("Receiving call from", call.peer);
                    if (call.metadata && call.metadata.type === 'screen') {
                        call.answer(); // Answer without sending any stream
                        handleScreenShareCall(call);
                    } else {
                        call.answer(localStream);
                        handleActiveCall(call);
                    }
                });

                peer.on('connection', (conn) => {
                    console.log("Incoming data connection from:", conn.peer);
                    handleDataConnection(conn);
                });

                peer.on('disconnected', () => {
                    console.log("Peer disconnected from signaling server. Reconnecting...");
                    connectionStatus.innerText = "Reconnecting...";
                    setTimeout(() => {
                        if (peer && !peer.destroyed && peer.disconnected) {
                            peer.reconnect();
                        }
                    }, 2000);
                });

                peer.on('error', (err) => {
                    console.error("PeerJS error:", err);
                    connectionStatus.innerText = "Connection error: " + err.type;
                    
                    // Re-initialize or reconnect on socket/network/server errors
                    if (err.type === 'network' || err.type === 'server-error' || err.type === 'socket-error') {
                        connectionStatus.innerText = "Retrying connection...";
                        setTimeout(() => {
                            if (!peer || peer.destroyed) {
                                initVoiceCall();
                            } else if (peer.disconnected) {
                                peer.reconnect();
                            }
                        }, 3000);
                    }
                });
                
            } catch(e) {
                console.error("Microphone or PeerJS initialization error:", e);
                connectionStatus.innerText = "Access Denied";
                alert("Microphone access is required to join the voice channel.");
                window.close();
            }
        }

        // Manage incoming or outgoing peer calls
        function handleActiveCall(call) {
            activeCalls[call.peer] = call;
            
            call.on('stream', (remoteStream) => {
                let audioId = 'audio-' + call.peer;
                let audioEl = document.getElementById(audioId);
                
                if (!audioEl) {
                    audioEl = document.createElement('audio');
                    audioEl.id = audioId;
                    audioEl.className = 'remote-audio hidden';
                    audioEl.autoplay = true;
                    document.body.appendChild(audioEl);
                }
                
                audioEl.srcObject = remoteStream;
                audioEl.volume = currentVolume;
                audioEl.muted = isDeafened;
                
                audioEl.onloadedmetadata = () => {
                    audioEl.play().catch(err => console.error("Audio playback blocked:", err));
                };

                // Track peer's speaking volume
                if (peerAnalysers[call.peer]) {
                    peerAnalysers[call.peer]();
                }
                peerAnalysers[call.peer] = monitorStreamVolume(remoteStream, (vol) => {
                    const avatarEl = document.getElementById('avatar-' + call.peer);
                    if (avatarEl) {
                        // If they are speaking above threshold
                        if (vol > 8) {
                            avatarEl.classList.add('speaking-glow');
                        } else {
                            avatarEl.classList.remove('speaking-glow');
                        }
                    }
                });
            });
            
            call.on('close', () => {
                cleanupCallInstance(call.peer);
            });

            call.on('error', (err) => {
                console.error("Call stream error with peer " + call.peer + ":", err);
                cleanupCallInstance(call.peer);
            });
        }

        // Manage screen sharing call instance (Incoming)
        function handleScreenShareCall(call) {
            activeScreenCalls[call.peer] = call;
            
            call.on('stream', (remoteScreenStream) => {
                remoteScreenStreams[call.peer] = remoteScreenStream;
                
                // If there is no active stage, take it
                if (activeStagePeerId === null) {
                    switchStage(call.peer);
                } else {
                    // Just update list UI to show the new "LIVE" badge
                    updateParticipantsUI();
                }
            });
            
            call.on('close', () => {
                cleanupScreenCallInstance(call.peer);
            });

            call.on('error', (err) => {
                console.error("Screen call error:", err);
                cleanupScreenCallInstance(call.peer);
            });
        }

        function cleanupScreenCallInstance(peerId) {
            if (activeScreenCalls[peerId]) {
                activeScreenCalls[peerId].close();
                delete activeScreenCalls[peerId];
            }
            if (remoteScreenStreams[peerId]) {
                delete remoteScreenStreams[peerId];
            }
            
            // If the closed stream was on the stage, we must reassign
            if (activeStagePeerId === peerId) {
                activeStagePeerId = null;
                
                // Fall back to another active screen share
                const remainingPeers = Object.keys(remoteScreenStreams);
                if (remainingPeers.length > 0) {
                    switchStage(remainingPeers[0]);
                } else if (isSharingScreen && localScreenStream) {
                    switchStage(myPeerId);
                } else {
                    // No one is sharing anymore
                    screenShareVideo.srcObject = null;
                    screenShareContainer.classList.add('hidden');
                    document.getElementById('voice-layout-container').classList.remove('screen-active');
                    window.resizeTo(isChatOpen ? 780 : 410, 600);
                }
            }

            updateParticipantsUI();
        }

        // Switch which screen share stream is displayed on the main stage
        function switchStage(peerId) {
            const isMe = peerId === myPeerId;
            const hasStream = isMe ? (isSharingScreen && localScreenStream) : !!remoteScreenStreams[peerId];
            if (!hasStream) {
                console.warn("Cannot switch stage: Stream not found for peer " + peerId);
                return;
            }

            activeStagePeerId = peerId;

            // Set video source
            screenShareVideo.srcObject = isMe ? localScreenStream : remoteScreenStreams[peerId];
            
            // Update overlay username
            let name = "Someone";
            if (isMe) {
                name = MY_FULLNAME + " (You)";
            } else {
                const participant = dbParticipantsList.find(p => p.peer_id === peerId);
                if (participant) name = participant.fullname;
            }
            screenShareUsername.innerText = name;

            // Show container and expand window
            screenShareContainer.classList.remove('hidden');
            document.getElementById('voice-layout-container').classList.add('screen-active');
            window.resizeTo(isChatOpen ? 1200 : 1000, 650);

            // Refresh UI to highlight the active stream
            updateParticipantsUI();
        }

        async function toggleScreenShare() {
            if (isSharingScreen) {
                stopScreenShare();
            } else {
                await startScreenShare();
            }
        }
        async function startScreenShare() {
            try {
                localScreenStream = await navigator.mediaDevices.getDisplayMedia({ 
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        frameRate: { ideal: 30, max: 30 }
                    }, 
                    audio: false 
                });
                isSharingScreen = true;
                
                // Style UI for active screen sharing
                screenShareBtn.className = "w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center transition-all";
                screenShareBtn.setAttribute('title', 'Stop Sharing Screen');

                // Call all active peers and send screen stream
                dbParticipantsList.forEach(p => {
                    if (p.peer_id && p.peer_id !== myPeerId) {
                        console.log("Sending screen share to:", p.peer_id);
                        const screenCall = peer.call(p.peer_id, localScreenStream, { metadata: { type: 'screen' } });
                        activeScreenCalls[p.peer_id] = screenCall;
                    }
                });

                // Listen for browser native "Stop Sharing" click
                localScreenStream.getVideoTracks()[0].onended = () => {
                    stopScreenShare();
                };

                // Take the stage automatically
                switchStage(myPeerId);

            } catch (e) {
                console.error("Screen sharing failed:", e);
                isSharingScreen = false;
            }
        }

        function stopScreenShare() {
            if (!isSharingScreen) return;
            isSharingScreen = false;

            // Restore UI button style
            screenShareBtn.className = "w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300";
            screenShareBtn.setAttribute('title', 'Share Screen');

            // Stop screen sharing tracks
            if (localScreenStream) {
                localScreenStream.getTracks().forEach(track => track.stop());
                localScreenStream = null;
            }

            // Close all outgoing screen calls
            Object.values(activeScreenCalls).forEach(call => {
                call.close();
            });
            activeScreenCalls = {};

            // If we were on stage, reassign
            if (activeStagePeerId === myPeerId) {
                activeStagePeerId = null;
                const remainingPeers = Object.keys(remoteScreenStreams);
                if (remainingPeers.length > 0) {
                    switchStage(remainingPeers[0]);
                } else {
                    screenShareVideo.srcObject = null;
                    screenShareContainer.classList.add('hidden');
                    document.getElementById('voice-layout-container').classList.remove('screen-active');
                    window.resizeTo(isChatOpen ? 780 : 410, 600);
                }
            }

            updateParticipantsUI();
        }

        function cleanupCallInstance(peerId) {
            let audioEl = document.getElementById('audio-' + peerId);
            if (audioEl) audioEl.remove();
            
            if (peerAnalysers[peerId]) {
                peerAnalysers[peerId]();
                delete peerAnalysers[peerId];
            }
            
            delete activeCalls[peerId];
            updateParticipantsUI();
        }

        // Fetch updates from database
        async function fetchVoiceParticipants() {
            try {
                const response = await fetch('../components/voice_status.php?action=get_participants&t=' + new Date().getTime(), { cache: 'no-store' });
                const data = await response.json();
                
                if (data.success) {
                    dbParticipantsList = data.participants;
                    participantsCountEl.innerText = dbParticipantsList.length + " connected";

                    let currentPeers = [];
                    let newPeerJoined = false;

                    dbParticipantsList.forEach(p => {
                        if (p.peer_id && p.peer_id !== myPeerId) {
                            currentPeers.push(p.peer_id);
                            
                            // Call newly joined peers
                            if (!knownPeers.includes(p.peer_id)) {
                                console.log("Calling peer:", p.peer_id);
                                const call = peer.call(p.peer_id, localStream);
                                handleActiveCall(call);
                                
                                console.log("Connecting data to peer:", p.peer_id);
                                const conn = peer.connect(p.peer_id);
                                handleDataConnection(conn);
                                
                                // If we are sharing screen, also send screen stream to the newly joined peer
                                if (isSharingScreen && localScreenStream) {
                                    console.log("Sending screen share to newly joined peer:", p.peer_id);
                                    const screenCall = peer.call(p.peer_id, localScreenStream, { metadata: { type: 'screen' } });
                                    activeScreenCalls[p.peer_id] = screenCall;
                                }
                                newPeerJoined = true;
                            } else {
                                // Self-healing: Re-establish lost data connection if the peer is still active but data link dropped
                                if (!activeConnections[p.peer_id] || !activeConnections[p.peer_id].open) {
                                    console.log("Self-healing: Re-connecting data to peer:", p.peer_id);
                                    if (activeConnections[p.peer_id]) {
                                        try { activeConnections[p.peer_id].close(); } catch(e) {}
                                    }
                                    const conn = peer.connect(p.peer_id);
                                    handleDataConnection(conn);
                                }
                            }
                        }
                    });

                    // Check if anyone left the call
                    let peerLeft = false;
                    knownPeers.forEach(oldPeer => {
                        if (!currentPeers.includes(oldPeer)) {
                            peerLeft = true;
                            cleanupCallInstance(oldPeer);
                        }
                    });

                    if (newPeerJoined) playSound('join');
                    if (peerLeft) playSound('disconnect');

                    knownPeers = currentPeers;
                    updateParticipantsUI();
                    broadcastState();
                }
            } catch(e) {
                console.error("Error fetching participants:", e);
            }
        }

        // Update popup list rendering
        function updateParticipantsUI() {
            if (dbParticipantsList.length === 0) {
                participantsListContainer.innerHTML = `
                    <div class="text-center py-8 text-gray-500 text-sm">
                        No other participants in this call.
                    </div>
                `;
                return;
            }

            participantsListContainer.innerHTML = dbParticipantsList.map(p => {
                const photoSrc = p.display_photo && p.display_photo !== '' 
                    ? "<?= BASE_URL ?>components/profile/" + p.display_photo 
                    : "<?= BASE_URL ?>components/profile/default.jpg";
                
                const isMe = p.peer_id === myPeerId;
                const borderClass = isMe ? 'border-blue-500' : 'border-gray-800';
                
                // Check if this participant is currently sharing screen
                const isSharing = isMe ? isSharingScreen : !!remoteScreenStreams[p.peer_id];
                let liveBadge = '';
                if (isSharing) {
                    const isCurrentStage = activeStagePeerId === p.peer_id;
                    const btnClass = isCurrentStage 
                        ? 'bg-emerald-500 text-white border-emerald-500/20 hover:bg-emerald-600' 
                        : 'bg-red-500 text-white border-red-500/20 hover:bg-red-600 animate-pulse';
                    const btnText = isCurrentStage ? 'Watching' : 'Watch';
                    liveBadge = `
                        <button onclick="switchStage('${p.peer_id}')" class="px-2.5 py-1 text-[10px] font-bold rounded-lg border shadow-sm ${btnClass} transition-all duration-150 focus:outline-none flex items-center space-x-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-white animate-ping"></span>
                            <span>${btnText}</span>
                        </button>
                    `;
                }

                return `
                    <div class="flex items-center justify-between p-3 rounded-xl bg-darkBg/60 border border-gray-800/80 hover:border-gray-700 transition">
                        <div class="flex items-center space-x-3">
                            <div class="relative">
                                <div id="avatar-${p.peer_id}" class="w-10 h-10 rounded-full border-2 ${borderClass} overflow-hidden bg-gray-900 transition-all duration-150">
                                    <img src="${photoSrc}" alt="${p.fullname}" class="w-full h-full object-cover">
                                </div>
                                <span class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-500 border-2 border-slate-900"></span>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold ${isMe ? 'text-blue-400' : 'text-gray-100'}">${p.fullname} ${isMe ? '<span class="text-xs text-gray-500 font-normal">(You)</span>' : ''}</h3>
                                <p class="text-xs text-gray-500 font-mono uppercase">${p.username || ''}</p>
                            </div>
                        </div>
                        
                        <!-- Status tags (mute / deafen / live screen share) -->
                        <div class="flex items-center space-x-2 text-gray-400">
                            ${liveBadge}
                            ${isMe && isMuted ? '<span class="w-6 h-6 flex items-center justify-center bg-red-500/10 rounded-lg text-red-500 text-xs border border-red-500/20"><i class="fas fa-microphone-slash text-[10px]"></i></span>' : ''}
                            ${isMe && isDeafened ? '<span class="w-6 h-6 flex items-center justify-center bg-red-500/10 rounded-lg text-red-500 text-xs border border-red-500/20"><i class="fas fa-headphones text-[10px]"></i></span>' : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Leaving the call cleanup
        async function leaveCallAndClose() {
            connectionStatus.innerText = "Disconnecting...";
            
            // Clear state in localStorage
            try {
                localStorage.removeItem('cxi_voice_state');
            } catch (e) {}

            // Broadcast leave state so main tabs clean up UI instantly
            channel.postMessage({
                type: 'status_sync',
                isConnectedToVoice: false,
                isMuted: false,
                isDeafened: false,
                currentVolume: 1.0,
                myPeerId: null,
                participants: []
            });

            // Stop screen sharing if active
            if (localScreenStream) {
                localScreenStream.getTracks().forEach(track => track.stop());
                localScreenStream = null;
            }
            Object.values(activeScreenCalls).forEach(call => call.close());
            activeScreenCalls = {};

            // Stop local tracks
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
                localStream = null;
            }
            if (localAnalyserCleanup) {
                localAnalyserCleanup();
                localAnalyserCleanup = null;
            }

            // Close all connections
            Object.values(activeCalls).forEach(call => call.close());
            activeCalls = {};

            Object.values(peerAnalysers).forEach(cleanup => cleanup());
            peerAnalysers = {};

            if (peer) {
                peer.destroy();
                peer = null;
            }

            // Notify DB
            const formData = new FormData();
            formData.append('action', 'leave');
            try {
                await fetch('../components/voice_status.php', { method: 'POST', body: formData });
            } catch (e) {
                console.warn("Failed to notify server of leaving:", e);
            }

            // Close popup
            window.close();
        }

        // --- CHAT SYSTEM LOGIC ---
        function toggleChat() {
            isChatOpen = !isChatOpen;
            const voiceLayout = document.getElementById('voice-layout-container');
            
            if (isChatOpen) {
                voiceLayout.classList.add('chat-open');
                chatBtn.className = "w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center transition-all relative";
                if (chatUnreadBadge) chatUnreadBadge.classList.add('hidden');
                
                // Resize window based on screen sharing status
                if (voiceLayout.classList.contains('screen-active')) {
                    window.resizeTo(1200, 700);
                } else {
                    window.resizeTo(780, 600);
                }
                
                // Scroll to bottom of chat
                setTimeout(() => {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }, 100);
            } else {
                voiceLayout.classList.remove('chat-open');
                chatBtn.className = "w-12 h-12 rounded-xl bg-gray-800 border border-gray-700/60 hover:bg-gray-700/80 hover:text-white transition-all flex items-center justify-center text-gray-300 relative";
                
                // Resize window back
                if (voiceLayout.classList.contains('screen-active')) {
                    window.resizeTo(1000, 650);
                } else {
                    window.resizeTo(410, 600);
                }
            }
        }

        function handleDataConnection(conn) {
            if (activeConnections[conn.peer]) {
                return;
            }

            activeConnections[conn.peer] = conn;

            conn.on('open', () => {
                console.log("Data connection opened with:", conn.peer);
            });

            conn.on('data', (data) => {
                if (data && data.type === 'chat') {
                    displayChatMessage(data);
                }
            });

            conn.on('close', () => {
                console.log("Data connection closed with:", conn.peer);
                if (activeConnections[conn.peer] === conn) {
                    delete activeConnections[conn.peer];
                }
            });

            conn.on('error', (err) => {
                console.error("Data connection error with peer:", conn.peer, err);
                if (activeConnections[conn.peer] === conn) {
                    delete activeConnections[conn.peer];
                }
            });
        }

        function displayChatMessage(msg) {
            const isMe = msg.isMe || msg.senderId === myPeerId;
            const alignClass = isMe ? 'justify-end' : 'justify-start';
            const bubbleBg = isMe ? 'bg-emerald-600/80 text-white' : 'bg-gray-800 text-gray-200';
            const borderRound = isMe ? 'rounded-l-2xl rounded-tr-2xl' : 'rounded-r-2xl rounded-tl-2xl';
            const showSender = isMe ? 'hidden' : 'block text-[10px] text-gray-400 font-semibold mb-1 ml-1';
            
            const messageHtml = `
                <div class="flex ${alignClass} w-full message-bubble">
                    <div class="max-w-[80%]">
                        <span class="${showSender}">${msg.senderName}</span>
                        <div class="flex items-end space-x-2">
                            ${!isMe ? `
                            <div class="w-6 h-6 rounded-full overflow-hidden bg-gray-700 flex-shrink-0 mb-1 border border-gray-800">
                                <img src="${msg.senderPhoto}" alt="${msg.senderName}" class="w-full h-full object-cover">
                            </div>
                            ` : ''}
                            <div class="${bubbleBg} ${borderRound} px-3.5 py-2.5 shadow-sm text-sm break-words">
                                <p class="leading-relaxed select-text">${escapeHtml(msg.text)}</p>
                                <span class="block text-[9px] text-gray-400 text-right mt-1.5 font-mono">${msg.timestamp}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Check if user is scrolled near the bottom before appending
            const isAtBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 100;
            
            chatMessages.insertAdjacentHTML('beforeend', messageHtml);
            
            // Limit message history to prevent DOM performance degradation
            const maxMessages = 200;
            const bubbles = chatMessages.getElementsByClassName('message-bubble');
            if (bubbles.length > maxMessages) {
                bubbles[0].remove();
            }

            if (isAtBottom || isMe) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            if (!isMe && !isChatOpen) {
                if (chatUnreadBadge) chatUnreadBadge.classList.remove('hidden');
                playMessageSound();
            }
        }

        function playMessageSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
                osc.frequency.setValueAtTime(880, ctx.currentTime + 0.08); // A5
                gain.gain.setValueAtTime(0.05, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.25);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.25);
            } catch(e) {}
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Wire Event Listeners
        muteBtn.addEventListener('click', toggleMute);
        deafenBtn.addEventListener('click', toggleDeafen);
        screenShareBtn.addEventListener('click', toggleScreenShare);
        chatBtn.addEventListener('click', toggleChat);
        volumeSlider.addEventListener('input', (e) => changeVolume(e.target.value));
        document.getElementById('btn-disconnect').addEventListener('click', leaveCallAndClose);

        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const msg = chatInput.value.trim();
            if (!msg) return;

            const messagePayload = {
                type: 'chat',
                senderId: myPeerId,
                senderName: MY_FULLNAME,
                senderPhoto: MY_PHOTO,
                text: msg,
                timestamp: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };

            // Send to all peers
            Object.values(activeConnections).forEach(conn => {
                if (conn.open) {
                    conn.send(messagePayload);
                }
            });

            // Display locally
            displayChatMessage({ ...messagePayload, isMe: true });
            chatInput.value = '';
        });

        // Beforeunload clean up in case they close window directly via X button
        window.addEventListener('beforeunload', () => {
            // Stop screen sharing
            if (localScreenStream) {
                localScreenStream.getTracks().forEach(track => track.stop());
                localScreenStream = null;
            }
            Object.values(activeScreenCalls).forEach(call => call.close());
            activeScreenCalls = {};

            // Clear state in localStorage
            try {
                localStorage.removeItem('cxi_voice_state');
            } catch (e) {}

            // Send leave request synchronously to guarantee DB update
            const formData = new FormData();
            formData.append('action', 'leave');
            navigator.sendBeacon('../components/voice_status.php', formData);
            
            channel.postMessage({
                type: 'status_sync',
                isConnectedToVoice: false,
                isMuted: false,
                isDeafened: false,
                currentVolume: 1.0,
                myPeerId: null,
                participants: []
            });
        });

        // Initialize Call
        initVoiceCall();
        setInterval(fetchVoiceParticipants, 3000);

    </script>
</body>
</html>
