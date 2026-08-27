<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/remember_me.php';

// Silent auto-login: if this browser has a valid "keep me logged in" token, skip the login
// page entirely. NOTE: this sets a minimal session (user_id, username, role) inside
// attemptRememberMeLogin(). If your normal auth.php login sets additional $_SESSION keys that
// other pages depend on, mirror those in includes/remember_me.php too.
if (!isLoggedIn() && isset($_COOKIE['cxi_remember_me'])) {
    $autoUser = attemptRememberMeLogin($pdo);
    if ($autoUser) {
        redirect($autoUser['role'] === 'admin' ? ADMIN_URL : BASE_URL);
    }
}

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect(ADMIN_URL);
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | CXI Services Inc.</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/cxiico.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc',
                            400: '#38bdf8', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1',
                            800: '#075985', 900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            background: radial-gradient(circle at 20% 20%, #0f1b33 0%, #0a0f1e 55%, #060910 100%);
            overflow-x: hidden;
            position: relative;
        }

        /* ---------- Animated background blobs ---------- */
        .bg-blob {
            position: fixed;
            border-radius: 9999px;
            filter: blur(100px);
            opacity: .38;
            z-index: 0;
            pointer-events: none;
            animation: floatBlob 20s ease-in-out infinite;
        }
        .bg-blob.b1 { width: 520px; height: 520px; background: #0ea5e9; top: -160px; left: -140px; }
        .bg-blob.b2 { width: 460px; height: 460px; background: #7c3aed; bottom: -160px; right: -120px; animation-delay: -7s; }
        .bg-blob.b3 { width: 380px; height: 380px; background: #ec4899; top: 45%; left: 62%; animation-delay: -13s; opacity: .22; }
        @keyframes floatBlob {
            0%, 100% { transform: translate(0,0) scale(1); }
            33%      { transform: translate(50px,-40px) scale(1.08); }
            66%      { transform: translate(-40px,35px) scale(.94); }
        }
        .grain-overlay {
            position: fixed; inset: 0; z-index: 1; pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
        }

        /* ---------- Glass card ---------- */
        .glass-card {
            position: relative;
            z-index: 2;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(28px) saturate(160%);
            -webkit-backdrop-filter: blur(28px) saturate(160%);
            border: 1px solid rgba(255,255,255,.09);
            box-shadow: 0 25px 70px -20px rgba(0,0,0,.65), inset 0 1px 0 rgba(255,255,255,.06);
            animation: cardIn .6s cubic-bezier(.16,1,.3,1);
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .glass-input {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.09);
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .glass-input:focus {
            background: rgba(255,255,255,.08);
            border-color: rgba(56,189,248,.55);
            box-shadow: 0 0 0 4px rgba(14,165,233,.15);
        }

        /* ---------- Step transitions (transform + fade) ---------- */
        .step { transition: opacity .28s cubic-bezier(.16,1,.3,1), transform .28s cubic-bezier(.16,1,.3,1); }
        .step.step-hidden { display: none; }
        .step.step-out { opacity: 0; transform: scale(.95) translateY(8px); }
        .step.step-in  { opacity: 1; transform: scale(1) translateY(0); }

        /* ---------- Profile tiles (bigger, glassmorphed) ---------- */
        .profile-tile {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 14px 6px;
            border-radius: 16px;
            background: rgba(255,255,255,.02);
            border: 1px solid transparent;
            transition: background-color .18s ease, transform .18s ease, border-color .18s ease;
        }
        .profile-tile:hover {
            background: rgba(255,255,255,.07);
            border-color: rgba(255,255,255,.08);
            transform: translateY(-3px);
        }
        .profile-tile .avatar {
            width: 84px; height: 84px; border-radius: 9999px; overflow: hidden;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: #fff; font-size: 28px;
            border: 2px solid rgba(255,255,255,.15);
            box-shadow: 0 10px 28px -10px rgba(56,189,248,.55);
            transition: box-shadow .18s ease, border-color .18s ease;
        }
        .profile-tile:hover .avatar {
            box-shadow: 0 14px 36px -8px rgba(56,189,248,.75);
            border-color: rgba(56,189,248,.5);
        }
        .profile-tile .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-tile .remove-btn {
            position: absolute; top: 4px; right: 4px; width: 24px; height: 24px;
            border-radius: 9999px; background: rgba(15,23,42,.92); color: #f87171;
            border: 1px solid rgba(255,255,255,.18); font-size: 13px; line-height: 1;
            display: none; align-items: center; justify-content: center;
            transition: transform .15s ease, background-color .15s ease;
        }
        .profile-tile .remove-btn:hover { background: rgba(248,113,113,.2); transform: scale(1.1); }
        .profile-tile:hover .remove-btn { display: flex; }
        .profile-tile .name {
            font-size: 13px; color: #cbd5e1; text-align: center; max-width: 90px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .profile-tile.add-tile .avatar {
            background: rgba(255,255,255,.04);
            border: 2px dashed rgba(255,255,255,.18);
            color: #64748b; box-shadow: none;
        }
        .profile-tile.add-tile:hover .avatar { border-color: rgba(56,189,248,.5); color: #38bdf8; box-shadow: none; }

        /* ---------- Big selected avatar (password step) ---------- */
        .selected-avatar-ring {
            width: 116px; height: 116px; border-radius: 9999px; padding: 4px;
            background: linear-gradient(135deg, #38bdf8, #6366f1, #ec4899);
            background-size: 200% 200%;
            animation: ringGlow 4s ease-in-out infinite;
        }
        @keyframes ringGlow {
            0%, 100% { background-position: 0% 50%; box-shadow: 0 0 0 6px rgba(14,165,233,.12), 0 16px 40px -10px rgba(56,189,248,.55); }
            50%      { background-position: 100% 50%; box-shadow: 0 0 0 10px rgba(99,102,241,.16), 0 20px 48px -8px rgba(99,102,241,.6); }
        }
        .selected-avatar-inner {
            width: 100%; height: 100%; border-radius: 9999px; overflow: hidden;
            background: #0f172a; display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: #fff; font-size: 36px;
        }
        .selected-avatar-inner img { width: 100%; height: 100%; object-fit: cover; }

        .captcha-image { height: 42px; border-radius: .375rem; border: 1px solid rgba(255,255,255,.1); }

        input[type="checkbox"] { accent-color: #0ea5e9; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-blob b1"></div>
    <div class="bg-blob b2"></div>
    <div class="bg-blob b3"></div>
    <div class="grain-overlay"></div>

    <div class="w-full max-w-md relative z-10">
        <div class="glass-card rounded-2xl overflow-hidden">
            <div class="p-8">
                <div class="flex justify-center mb-6">
                    <img src="assets/cxi.png" alt="CXI Services Inc." class="drop-shadow-[0_4px_20px_rgba(56,189,248,.25)]">
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 rounded-lg mb-6 text-sm">
                        <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-green-500/10 border border-green-500/30 text-green-300 px-4 py-3 rounded-lg mb-6 text-sm">
                        <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <!-- STEP 0: Saved profile picker -->
                <div id="profilePicker" class="step step-hidden">
                    <p class="text-gray-300 text-center text-lg font-medium mb-6">Who's logging in?</p>
                    <div id="profileList" class="grid grid-cols-3 gap-2 mb-2"></div>
                </div>

                <!-- STEP 1: Password-only step for a selected saved profile -->
                <div id="passwordStep" class="step step-hidden">
                    <div class="flex flex-col items-center mb-7">
                        <div class="selected-avatar-ring mb-4">
                            <div class="selected-avatar-inner" id="selectedAvatarInner">
                                <img id="selectedAvatar" src="" alt="" style="display:none;">
                                <span id="selectedInitial"></span>
                            </div>
                        </div>
                        <p id="selectedName" class="text-gray-100 font-semibold text-lg"></p>
                        <button type="button" onclick="goToFreshForm()" class="text-xs text-primary-400 hover:text-primary-300 mt-1.5 transition">Not you?</button>
                    </div>
                    <form id="quickLoginForm" method="POST" class="space-y-5">
                        <input type="hidden" name="username" id="quickUsername">
                        <div>
                            <label for="quickPassword" class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                            <input type="password" id="quickPassword" name="password" required
                                   class="glass-input w-full px-4 py-3 rounded-lg text-gray-100 outline-none transition">
                        </div>

                        <?php if (isset($_SESSION['show_captcha'])): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">CAPTCHA</label>
                                <div class="flex items-center gap-3">
                                    <input type="text" name="captcha" required
                                           class="glass-input flex-1 px-4 py-3 rounded-lg text-gray-100 outline-none transition">
                                    <img src="includes/captcha.php" alt="CAPTCHA" class="captcha-image" onclick="this.src='includes/captcha.php?'+Math.random()">
                                </div>
                            </div>
                        <?php endif; ?>

                        <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer select-none">
                            <input type="checkbox" name="keep_logged_in" id="quickKeepLoggedIn" class="rounded bg-gray-700 border-gray-600 focus:ring-primary-500">
                            Keep me logged in on this browser
                        </label>
                        <input type="hidden" name="remember_browser" value="1">

                        <button type="submit" name="login"
                                class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-500 text-white font-medium rounded-lg transition duration-200 shadow-lg shadow-primary-900/40 hover:shadow-primary-700/40">
                            Sign in
                        </button>
                    </form>
                </div>

                <!-- STEP 2: Full login form -->
                <div id="freshLoginForm" class="step step-hidden">
                    <p class="text-gray-400 text-center mb-8">Sign in to access your account</p>
                    <form id="loginForm" method="POST" class="space-y-6">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                            <div class="relative">
                                <input type="text" id="username" name="username" required
                                       class="glass-input w-full px-4 py-3 rounded-lg text-gray-100 outline-none transition">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-gray-500">
                                        <path d="M10 8a3 3 0 100-6 3 3 0 000 6zM3.465 14.493a1.23 1.23 0 00.41 1.412A9.957 9.957 0 0010 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 00-13.074.003z" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Password</label>
                            <div class="relative">
                                <input type="password" id="password" name="password" required
                                       class="glass-input w-full px-4 py-3 rounded-lg text-gray-100 outline-none transition">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-gray-500">
                                        <path fill-rule="evenodd" d="M8 7a5 5 0 013.61 1.5c.131.11.248.228.35.35a5 5 0 01-1.14 7.62 1 1 0 01-.71.29H8a5 5 0 010-10zm6.24 8.12a1 1 0 01-.71.29H10a1 1 0 01-.71-.29 1 1 0 01-.29-.71V12a1 1 0 01.29-.71 1 1 0 01.71-.29h3.54a1 1 0 01.71.29 1 1 0 01.29.71v3.54a1 1 0 01-.29.71z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($_SESSION['show_captcha'])): ?>
                            <div>
                                <label for="captcha" class="block text-sm font-medium text-gray-300 mb-2">CAPTCHA</label>
                                <div class="flex items-center gap-3">
                                    <input type="text" id="captcha" name="captcha" required
                                           class="glass-input flex-1 px-4 py-3 rounded-lg text-gray-100 outline-none transition">
                                    <img src="includes/captcha.php" alt="CAPTCHA" class="captcha-image" onclick="this.src='includes/captcha.php?'+Math.random()">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Click on the image to refresh</p>
                            </div>
                            <?php unset($_SESSION['show_captcha']); ?>
                        <?php endif; ?>

                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer select-none">
                                <input type="checkbox" name="remember_browser" id="rememberBrowser" checked
                                       class="rounded bg-gray-700 border-gray-600 focus:ring-primary-500">
                                Remember me on this browser
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer select-none">
                                <input type="checkbox" name="keep_logged_in" id="keepLoggedIn"
                                       class="rounded bg-gray-700 border-gray-600 focus:ring-primary-500">
                                Keep me logged in on this browser
                            </label>
                        </div>

                        <button type="submit" name="login"
                                class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-500 text-white font-medium rounded-lg transition duration-200 flex items-center justify-center gap-2 shadow-lg shadow-primary-900/40 hover:shadow-primary-700/40">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                                <path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 015.25 2h5.5A2.25 2.25 0 0113 4.25v2a.75.75 0 01-1.5 0v-2a.75.75 0 00-.75-.75h-5.5a.75.75 0 00-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 00.75-.75v-2a.75.75 0 011.5 0v2A2.25 2.25 0 0110.75 18h-5.5A2.25 2.25 0 013 15.75V4.25z" clip-rule="evenodd" />
                                <path fill-rule="evenodd" d="M6 10a.75.75 0 01.75-.75h9.546l-1.048-.943a.75.75 0 111.004-1.114l2.5 2.25a.75.75 0 010 1.114l-2.5 2.25a.75.75 0 11-1.004-1.114l1.048-.943H6.75A.75.75 0 016 10z" clip-rule="evenodd" />
                            </svg>
                            Sign in
                        </button>
                    </form>
                </div>
            </div>

            <div class="px-8 py-4 bg-black/20 text-center border-t border-white/5">
                <p class="text-sm text-gray-400">
                    Don't have an account?
                    <a href="#" class="text-primary-400 hover:text-primary-300 font-medium transition">Contact SLT</a>
                </p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/script.js"></script>
    <script>
        const PROFILE_STORAGE_KEY = 'cxi_remembered_profiles';
        const PENDING_INTENT_KEY = 'cxi_pending_remember'; // consumed by layout.php right after a successful login

        function getProfiles() {
            try { return JSON.parse(localStorage.getItem(PROFILE_STORAGE_KEY)) || []; }
            catch (e) { return []; }
        }
        function saveProfiles(profiles) {
            localStorage.setItem(PROFILE_STORAGE_KEY, JSON.stringify(profiles));
        }
        function avatarInitial(name) {
            return (name || '?').trim().charAt(0).toUpperCase();
        }
        function avatarHTML(p) {
            if (p.display_photo) {
                return `<img src="components/profile/${p.display_photo}" alt="${p.fullname}" onerror="this.parentElement.textContent='${avatarInitial(p.fullname)}'">`;
            }
            return avatarInitial(p.fullname);
        }

        // Crossfades between two step panels with a subtle scale+lift transform.
        function switchStep(fromId, toId) {
            const from = fromId ? document.getElementById(fromId) : null;
            const to = document.getElementById(toId);
            if (from && !from.classList.contains('step-hidden')) {
                from.classList.remove('step-in');
                from.classList.add('step-out');
                setTimeout(() => {
                    from.classList.add('step-hidden');
                    from.classList.remove('step-out');
                    showStep(to);
                }, 220);
            } else {
                showStep(to);
            }
        }
        function showStep(el) {
            el.classList.remove('step-hidden');
            el.classList.add('step-out');
            // force reflow so the transition actually plays
            void el.offsetWidth;
            requestAnimationFrame(() => {
                el.classList.remove('step-out');
                el.classList.add('step-in');
            });
        }

        function renderProfilePicker() {
            const profiles = getProfiles();
            if (profiles.length === 0) {
                showStep(document.getElementById('freshLoginForm'));
                return;
            }

            const list = document.getElementById('profileList');
            list.innerHTML = profiles.map(p => `
                <div class="profile-tile" onclick='selectProfile(${JSON.stringify(p).replace(/'/g, "&#39;")})'>
                    <button type="button" class="remove-btn" title="Remove from this browser" onclick="removeProfile(event, ${p.user_id})">&times;</button>
                    <div class="avatar">${avatarHTML(p)}</div>
                    <span class="name">${p.fullname}</span>
                </div>
            `).join('') + `
                <div class="profile-tile add-tile" onclick="goToFreshForm()">
                    <div class="avatar"><i class="fas fa-plus"></i></div>
                    <span class="name">Add account</span>
                </div>
            `;
            showStep(document.getElementById('profilePicker'));
        }

        function selectProfile(profile) {
            document.getElementById('quickUsername').value = profile.username;
            document.getElementById('selectedName').textContent = profile.fullname;
            const img = document.getElementById('selectedAvatar');
            const initial = document.getElementById('selectedInitial');
            if (profile.display_photo) {
                img.src = `components/profile/${profile.display_photo}`;
                img.style.display = 'block';
                initial.style.display = 'none';
                img.onerror = () => { img.style.display = 'none'; initial.style.display = 'block'; };
            } else {
                img.style.display = 'none';
                initial.style.display = 'block';
                initial.textContent = avatarInitial(profile.fullname);
            }
            switchStep('profilePicker', 'passwordStep');
            setTimeout(() => document.getElementById('quickPassword').focus(), 260);
        }

        function removeProfile(event, userId) {
            event.stopPropagation();
            if (!confirm('Remove this saved login from this browser? This also signs it out of "keep me logged in" here.')) return;
            saveProfiles(getProfiles().filter(p => p.user_id != userId));
            fetch('components/remember_me_actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=revoke&user_id=' + encodeURIComponent(userId)
            }).catch(() => {});
            const profiles = getProfiles();
            if (profiles.length === 0) {
                switchStep('profilePicker', 'freshLoginForm');
            } else {
                renderProfilePicker();
            }
        }

        function goToFreshForm() {
            const currentStep = ['profilePicker', 'passwordStep'].find(id => !document.getElementById(id).classList.contains('step-hidden'));
            switchStep(currentStep, 'freshLoginForm');
            setTimeout(() => document.getElementById('username')?.focus(), 260);
        }

        function stashIntent(form) {
            const remember = form.querySelector('[name="remember_browser"]');
            const keep = form.querySelector('[name="keep_logged_in"]');
            sessionStorage.setItem(PENDING_INTENT_KEY, JSON.stringify({
                remember_browser: remember ? (remember.type === 'checkbox' ? remember.checked : !!remember.value) : false,
                keep_logged_in: keep ? keep.checked : false,
            }));
        }

        document.getElementById('loginForm')?.addEventListener('submit', function() {
            stashIntent(this);
            $('#loadingModal').removeClass('hidden');
        });
        document.getElementById('quickLoginForm')?.addEventListener('submit', function() {
            stashIntent(this);
            $('#loadingModal').removeClass('hidden');
        });

        document.addEventListener('DOMContentLoaded', renderProfilePicker);
    </script>
</body>
</html>