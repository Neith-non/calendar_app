<?php
require 'functions/database.php';
// 1. Start the Session (Give out the VIP wristbands)
session_start();
// 1. Tell the browser NEVER to cache this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
// 2. If they already have a wristband, send them straight to the dashboard!
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// 3. Process the form when they click "Sign In"
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CHANGE THIS LINE to match your actual database connection file!
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {

        // 1. We JOIN the users and roles tables together to get the role_name!
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.role_id, u.username, u.password, u.full_name, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.username = ? AND u.is_active = 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Check if the user exists AND the password matches
        if ($user && $password === $user['password']) {

            // 3. Success! Give them their Session Wristband with all the correct info
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['full_name'] = $user['full_name'];

            // --> THE MAGIC LINE: Grab the word 'Admin' from the database!
            $_SESSION['role_name'] = $user['role_name'];

            // Send them to the dashboard
            header("Location: index.php");
            exit;
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
        body {
            background-color: #d1e8df; 
            background-image: 
                radial-gradient(at 0% 0%, rgba(0, 71, 49, 0.15) 0px, transparent 60%),
                radial-gradient(at 100% 100%, rgba(255, 187, 0, 0.1) 0px, transparent 50%);
            background-attachment: fixed;
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
    </style>
</head>

<body class="flex items-center justify-center min-h-screen p-4 text-sjsfi-green">

    <div class="premium-card p-8 sm:p-12 rounded-[2rem] w-full max-w-md z-10">

        <div class="flex flex-col items-center justify-center mb-8 text-center">
            
            <div class="w-24 h-24 bg-white rounded-full flex items-center justify-center shadow-lg mb-4 p-1 border-2 border-green-100 relative group transition-transform duration-300 hover:scale-105">
                <img src="assets/img/sjsfi_schoologo.png" alt="SJSFI Logo" 
                     class="w-full h-full object-contain rounded-full relative z-10" 
                     onerror="this.outerHTML='<i class=\'fa-solid fa-graduation-cap text-sjsfi-green text-4xl relative z-10\'></i>'">
            </div>
            
            <h2 class="text-xl sm:text-2xl font-extrabold text-sjsfi-green tracking-tight leading-tight mb-1">
                Saint Joseph School<br>Foundation Incorporated
            </h2>
            
            <h3 class="text-lg sm:text-xl font-bold font-chinese text-sjsfi-green/80 tracking-widest mb-3">
                三寶颜忠義中學
</h3>
            
            <p class="text-sjsfi-green/70 text-xs font-bold tracking-widest uppercase">Calendar of Events</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl mb-6 text-sm font-semibold flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation text-red-500 text-lg"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            
            <div class="mb-5 group">
                <label class="block text-sjsfi-green text-xs font-bold mb-2 uppercase tracking-wide">Username</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fa-solid fa-user text-green-700/50 group-focus-within:text-sjsfi-green transition-colors duration-300"></i>
                    </div>
                    <input type="text" name="username" required
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
                    <input type="password" name="password" required
                        class="input-premium w-full pl-11 pr-4 py-3.5 rounded-xl text-sm font-medium" 
                        placeholder="Enter your password">
                </div>
            </div>

            <button type="submit" class="btn-premium w-full py-4 rounded-xl flex justify-center items-center gap-3 text-sm">
                <span>Secure Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
            
        </form>
        
        <div class="mt-8 text-center border-t border-green-200/60 pt-6">
            <p class="text-sjsfi-green/60 text-xs font-medium tracking-wide">© <?php echo date('Y'); ?> Saint Joseph School Foundation Incorporated</p>
        </div>
    </div>

</body>
</html>