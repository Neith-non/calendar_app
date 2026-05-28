<?php
require 'functions/database.php';
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.role_id, u.username, u.password, u.full_name, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.username = ? AND u.is_active = 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $stored = $user['password'];
            $password_ok = false;

            if (!empty($stored) && password_verify($password, $stored)) {
                $password_ok = true;
                if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $upd->execute([$newHash, $user['user_id']]);
                }
            } elseif ($password === $stored) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $upd->execute([$newHash, $user['user_id']]);
                $password_ok = true;
            }

            if ($password_ok) {
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['role_id']   = $user['role_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role_name'] = $user['role_name'];
                header("Location: index.php");
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SJSFI - Calendar of Events</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                        chinese: ['Noto Sans TC', 'sans-serif'],
                    },
                    colors: {
                        sjsfi: {
                            green: '#004731',
                            greenHover: '#003323',
                            greenTint: '#e6f2ee',
                            yellow: '#ffbb00'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #d1e8df;
            background-image: 
                radial-gradient(at 0% 0%, rgba(0, 71, 49, 0.15) 0px, transparent 60%),
                radial-gradient(at 100% 100%, rgba(255, 187, 0, 0.1) 0px, transparent 50%);
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .content-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .premium-card {
            background: linear-gradient(145deg, #ffffff 0%, #f0fdf4 100%);
            box-shadow: 0 25px 50px -12px rgba(0, 71, 49, 0.15), 0 0 0 1px rgba(0, 71, 49, 0.05);
            position: relative;
            overflow: hidden;
        }

        .premium-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(to right, #05a170, #03583c, #d49d06);
        }

        .input-premium {
            background-color: #ffffff;
            border: 1px solid #bce3d4;
            color: #004731;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-premium:focus {
            background-color: #ffffff;
            border-color: #004731;
            box-shadow: 0 0 0 4px rgba(0, 71, 49, 0.15);
            outline: none;
            transform: translateY(-1px);
        }

        .input-premium::placeholder {
            color: #8dafa1;
        }

        .btn-premium {
            background-color: #004731;
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 71, 49, 0.25);
        }

        .btn-premium:hover {
            background-color: #003323;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 71, 49, 0.35);
        }

        /* Sticky footer styles */
        .sticky-footer {
            background: linear-gradient(135deg, rgba(0, 71, 49, 0.04), rgba(0, 71, 49, 0.08));
            border-top: 1px solid rgba(0, 71, 49, 0.1);
            padding: 1rem 2rem;
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 3rem;
        }

        .footer-wmsu-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }

        .footer-developers-section {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .dev-chip {
            background: rgba(0, 71, 49, 0.07);
            border: 1px solid rgba(0, 71, 49, 0.15);
            transition: all 0.2s ease;
        }

        .dev-chip:hover {
            background: rgba(0, 71, 49, 0.13);
            transform: translateY(-1px);
        }

        .wmsu-logo-ring {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fff;
            border: 1.5px solid rgba(155, 28, 28, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            flex-shrink: 0;
        }

        .wmsu-logo-ring:hover {
            transform: scale(1.07);
            box-shadow: 0 4px 14px rgba(155, 28, 28, 0.15);
        }

        .wmsu-logo-ring img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }

        .wmsu-text-section {
            text-align: left;
        }

        /* Password toggle button */
        .pw-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: rgba(0, 71, 49, 0.4);
            font-size: 13px;
            padding: 2px 4px;
            transition: color 0.2s;
        }
        .pw-toggle:hover { color: #004731; }

        @media (max-width: 768px) {
            .sticky-footer {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 1rem;
            }

            .footer-wmsu-section {
                flex-direction: column;
            }

            .wmsu-text-section {
                text-align: center;
            }

            .footer-developers-section {
                justify-content: center;
            }
        }
    </style>
</head>

<body class="text-sjsfi-green">

    <div class="content-wrapper p-4">
        <div class="premium-card p-8 sm:p-10 rounded-[2rem] w-full max-w-md z-10">

            <!-- ── TOP: SJSFI Logo + School Name ── -->
            <div class="flex flex-col items-center mb-7 text-center">

                <div class="w-24 h-24 bg-white rounded-full flex items-center justify-center shadow-lg mb-4 p-1 border-2 border-green-100 transition-transform duration-300 hover:scale-105">
                    <img src="assets/img/sjsfi_schoologo.png" alt="SJSFI Logo"
                         class="w-full h-full object-contain rounded-full"
                         onerror="this.outerHTML='<i class=\'fa-solid fa-graduation-cap text-sjsfi-green text-4xl\'></i>'">
                </div>

                <h2 class="text-xl sm:text-2xl font-extrabold text-sjsfi-green tracking-tight leading-tight mb-1">
                    Saint Joseph School<br>Foundation Incorporated
                </h2>

                <h3 class="text-lg sm:text-xl font-bold font-chinese text-sjsfi-green/80 tracking-widest mb-3">
                    三寶颜忠義中學
                </h3>

                <div class="flex items-center gap-2">
                    <div class="h-px w-8 bg-green-200"></div>
                    <p class="text-sjsfi-green/60 text-[10px] font-bold tracking-widest uppercase">Calendar of Events</p>
                    <div class="h-px w-8 bg-green-200"></div>
                </div>
            </div>

            <!-- ── Error ── -->
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl mb-6 text-sm font-semibold flex items-center gap-3">
                    <i class="fa-solid fa-circle-exclamation text-red-500 text-lg"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- ── Form ── -->
            <form method="POST" action="">

                <div class="mb-5 group">
                    <label class="block text-sjsfi-green text-xs font-bold mb-2 uppercase tracking-wide">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fa-solid fa-user text-green-700/50 group-focus-within:text-sjsfi-green transition-colors duration-300"></i>
                        </div>
                        <input type="text" name="username" required autocomplete="username"
                            class="input-premium w-full pl-11 pr-4 py-3.5 rounded-xl text-sm font-medium"
                            placeholder="Enter your username">
                    </div>
                </div>

                <div class="mb-8 group">
                    <label class="block text-sjsfi-green text-xs font-bold mb-2 uppercase tracking-wide">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fa-solid fa-lock text-green-700/50 group-focus-within:text-sjsfi-green transition-colors duration-300"></i>
                        </div>
                        <input type="password" id="passwordInput" name="password" required autocomplete="current-password"
                            class="input-premium w-full pl-11 pr-11 py-3.5 rounded-xl text-sm font-medium"
                            placeholder="Enter your password">
                        <button type="button" class="pw-toggle" id="togglePw" aria-label="Show password">
                            <i class="fa-regular fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-premium w-full py-4 rounded-xl flex justify-center items-center gap-3 text-sm">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>

            </form>

            <!-- ── Copyright ── -->
            <div class="mt-5 text-center">
                <p class="text-sjsfi-green/50 text-[10px] font-medium tracking-wide">
                    &copy; <?php echo date('Y'); ?> Saint Joseph School Foundation Incorporated
                </p>
            </div>

        </div>
    </div>

    <!-- ── Sticky Footer with WMSU + Developers ── -->
    <footer class="sticky-footer">
        <!-- WMSU Branding (Left) -->
        <div class="footer-wmsu-section">
            <div class="wmsu-logo-ring">
                <img src="assets/img/wmsulogo.jpg" alt="WMSU Logo"
                     onerror="this.outerHTML='<i class=\'fa-solid fa-university\' style=\'font-size:20px;color:#9b1c1c\'></i>'">
            </div>
            <div class="wmsu-text-section">
                <p class="text-[10px] font-bold text-sjsfi-green/50 uppercase tracking-widest leading-tight">In Partial Fulfillment of</p>
                <p class="text-[11px] font-extrabold text-sjsfi-green/70 leading-tight">Western Mindanao State University</p>
                <p class="text-[10px] font-semibold text-sjsfi-green/45 leading-tight">Internship Program</p>
            </div>
        </div>

        <!-- Developers (Right) -->
        <div class="footer-developers-section">
            <span class="text-sjsfi-green/60 text-[10px] font-bold tracking-widest uppercase mr-2">Developed by:</span>
            <span class="dev-chip rounded-full px-3 py-1 text-[10px] font-semibold text-sjsfi-green/70">
                <i class="fa-solid fa-user-code mr-1 text-[9px]"></i><a href="https://johanbuenaventuraportfolio.netlify.app/" target="_blank" >Johan C. Buenaventura</a>
            </span>
            <span class="dev-chip rounded-full px-3 py-1 text-[10px] font-semibold text-sjsfi-green/70">
                <i class="fa-solid fa-user-code mr-1 text-[9px]"></i><a href="https://nitanportfolio.netlify.app/" target="_blank" >Neithan Deniel B. Gula</a>
            </span>
            <span class="dev-chip rounded-full px-3 py-1 text-[10px] font-semibold text-sjsfi-green/70">
                <i class="fa-solid fa-user-code mr-1 text-[9px]"></i><a href="https://mathewpayopelin.netlify.app/" target="_blank" >Mathew JG S. Payopelin</a>
            </span>
            <span class="dev-chip rounded-full px-3 py-1 text-[10px] font-semibold text-sjsfi-green/70">
                <i class="fa-solid fa-user-code mr-1 text-[9px]"></i><a href="https://.netlify.app/" target="_blank" >Paolo S. Garcia</a>
            </span>
            <span class="dev-chip rounded-full px-3 py-1 text-[10px] font-semibold text-sjsfi-green/70">
                <i class="fa-solid fa-user-code mr-1 text-[9px]"></i><a href="https://.netlify.app/" target="_blank" >Aljon V. Reyes</a>
            </span>
            </span>
        </div>
    </footer>

    <script>
        const togglePw   = document.getElementById('togglePw');
        const pwInput    = document.getElementById('passwordInput');
        const eyeIcon    = document.getElementById('eyeIcon');

        togglePw.addEventListener('click', function () {
            const hidden = pwInput.type === 'password';
            pwInput.type = hidden ? 'text' : 'password';
            eyeIcon.classList.toggle('fa-eye',       !hidden);
            eyeIcon.classList.toggle('fa-eye-slash',  hidden);
            togglePw.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        });
    </script>

</body>
</html>