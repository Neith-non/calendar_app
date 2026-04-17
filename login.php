<?php
// 1. Start the Session (Give out the VIP wristbands)
session_start();
// 2. If they already have a wristband, send them straight to the dashboard!
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// 3. Process the form when they click "Sign In"
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // CHANGE THIS LINE to match your actual database connection file!
    require 'functions/database.php'; 

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
    <title>Login - Event Calendar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-zinc-950 flex items-center justify-center min-h-screen p-4 font-sans text-slate-300">

    <div class="bg-zinc-900/60 backdrop-blur-xl border border-zinc-800 p-8 sm:p-10 rounded-2xl shadow-2xl max-w-sm w-full relative overflow-hidden">
        
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-emerald-500 rounded-full mix-blend-screen filter blur-[60px] opacity-20 pointer-events-none"></div>

        <div class="text-center mb-8 relative z-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-zinc-800/50 rounded-full mb-4 border border-zinc-700 shadow-inner">
                <i class="fa-solid fa-calendar-days text-emerald-400 text-2xl"></i>
            </div>
            <h2 class="text-3xl font-bold text-white tracking-tight">Welcome Back</h2>
            <p class="text-zinc-400 text-sm mt-2">Sign in to access the event calendar</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 p-3 rounded-lg mb-6 text-sm font-medium flex items-center gap-3 backdrop-blur-sm">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="relative z-10">
            <div class="mb-5">
                <label class="block text-zinc-400 text-xs font-semibold uppercase tracking-wider mb-2">Username</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none transition-colors group-focus-within:text-emerald-400 text-zinc-500">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <input type="text" name="username" required 
                           class="w-full pl-10 pr-3 py-3 bg-zinc-950/50 border border-zinc-800 text-zinc-100 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all placeholder-zinc-600" 
                           placeholder="Enter username">
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-zinc-400 text-xs font-semibold uppercase tracking-wider mb-2">Password</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none transition-colors group-focus-within:text-emerald-400 text-zinc-500">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    <input type="password" name="password" required 
                           class="w-full pl-10 pr-3 py-3 bg-zinc-950/50 border border-zinc-800 text-zinc-100 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all placeholder-zinc-600" 
                           placeholder="Enter password">
                </div>
            </div>

            <button type="submit" class="w-full py-3 px-4 rounded-lg flex justify-center items-center gap-2 shadow-lg shadow-emerald-900/50 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold transition-all duration-300 hover:-translate-y-0.5 active:translate-y-0">
                <span>Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
    </div>

</body>
</html>