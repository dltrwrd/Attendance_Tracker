<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

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
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .bg-auth {
            background-image: linear-gradient(to right, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.9)), 
                              url('https://source.unsplash.com/random/1920x1080/?abstract,dark');
            background-size: cover;
            background-position: center;
        }
        .captcha-image {
            height: 42px;
            border-radius: 0.375rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body class="bg-auth min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-gray-800/80 backdrop-blur-sm rounded-xl shadow-xl overflow-hidden border border-gray-700/50">
            <div class="p-8">
                <div class="flex justify-center mb-8">
                    <img src="assets/cxi.png" alt="CXI Services Inc.">
                </div>
                <p class="text-gray-400 text-center mb-8">Sign in to access your account</p>
                
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
                
                <form id="loginForm" method="POST" class="space-y-6">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-300 mb-2">Username</label>
                        <div class="relative">
                            <input type="text" id="username" name="username" required 
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
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
                                   class="w-full px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
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
                                       class="flex-1 px-4 py-3 bg-gray-700/50 border border-gray-600/50 rounded-lg text-gray-200 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
                                <img src="includes/captcha.php" alt="CAPTCHA" class="captcha-image" onclick="this.src='includes/captcha.php?'+Math.random()">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Click on the image to refresh</p>
                        </div>
                        <?php unset($_SESSION['show_captcha']); ?>
                    <?php endif; ?>
                    
                    <button type="submit" name="login" 
                            class="w-full py-3 px-4 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition duration-200 flex items-center justify-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                            <path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 015.25 2h5.5A2.25 2.25 0 0113 4.25v2a.75.75 0 01-1.5 0v-2a.75.75 0 00-.75-.75h-5.5a.75.75 0 00-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 00.75-.75v-2a.75.75 0 011.5 0v2A2.25 2.25 0 0110.75 18h-5.5A2.25 2.25 0 013 15.75V4.25z" clip-rule="evenodd" />
                            <path fill-rule="evenodd" d="M6 10a.75.75 0 01.75-.75h9.546l-1.048-.943a.75.75 0 111.004-1.114l2.5 2.25a.75.75 0 010 1.114l-2.5 2.25a.75.75 0 11-1.004-1.114l1.048-.943H6.75A.75.75 0 016 10z" clip-rule="evenodd" />
                        </svg>
                        Sign in
                    </button>
                </form>
            </div>
            
            <div class="px-8 py-4 bg-gray-900/50 text-center border-t border-gray-700/50">
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
        $(document).ready(function() {
            $('#loginForm').on('submit', function() {
                $('#loadingModal').removeClass('hidden');
            });
        });
    </script>
</body>
</html>