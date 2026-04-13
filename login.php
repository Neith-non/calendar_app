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
    <title>Login - Event Calendar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Frontend Change: Added Google Font for a more modern typeface -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Frontend Change: Linked our new global stylesheet -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* Frontend Change: Component-specific styles for the primary login button */
        .btn-primary-glass {
            background-color: #ffbb00; /* var(--yellow) */
            color: #004731; /* var(--dark-green) */
            font-weight: 700;
            transition: background-color 0.3s, transform 0.2s;
        }
        .btn-primary-glass:hover {
            background-color: #ffca2a; /* A slightly lighter yellow for hover */
            transform: translateY(-2px);
        }
    </style>
</head>
<!-- Frontend Change: Added 'login-body' class for the new background and centered layout -->
<body class="login-body flex items-center justify-center min-h-screen p-4">

    <!-- Frontend Change: Main login form container with the glassmorphism effect -->
    <div class="glass-container p-8 sm:p-10 rounded-2xl shadow-lg max-w-sm w-full">
        
        <!-- Frontend Change: Header section of the login form -->
        <div class="text-center mb-8">
            <!-- Icon Container -->
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white/10 rounded-full mb-4 border border-white/20">
                <i class="fa-solid fa-calendar-days text-yellow-400 text-2xl"></i>
            </div>
            <!-- Title and Subtitle -->
            <h2 class="text-3xl font-bold text-white">Welcome Back</h2>
            <p class="text-slate-300 text-sm mt-2">Sign in to access the event calendar</p>
        </div>

        <!-- Backend Note: This PHP block displays an error message. We only styled the container. -->
        <?php if ($error): ?>
            <!-- Frontend Change: Styled error message container -->
            <div class="bg-red-500/20 border border-red-500/50 text-red-300 p-3 rounded-lg mb-6 text-sm font-medium flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Backend Note: This is the main login form. We only styled the inputs and button. -->
        <form method="POST" action="">
            <!-- Username Input Container -->
            <div class="mb-5">
                <label class="block text-slate-300 text-sm font-semibold mb-2">Username</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-user text-slate-400"></i>
                    </div>
                    <!-- Frontend Change: Applied the 'form-input-glass' class to the username input -->
                    <input type="text" name="username" required 
                           class="form-input-glass w-full pl-10 pr-3 py-2.5 rounded-lg" 
                           placeholder="Enter username">
                </div>
            </div>

            <!-- Password Input Container -->
            <div class="mb-6">
                <label class="block text-slate-300 text-sm font-semibold mb-2">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-lock text-slate-400"></i>
                    </div>
                    <!-- Frontend Change: Applied the 'form-input-glass' class to the password input -->
                    <input type="password" name="password" required 
                           class="form-input-glass w-full pl-10 pr-3 py-2.5 rounded-lg" 
                           placeholder="Enter password">
                </div>
            </div>

            <!-- Frontend Change: Applied our custom 'btn-primary-glass' class to the submit button -->
            <button type="submit" class="btn-primary-glass w-full py-3 px-4 rounded-lg flex justify-center items-center gap-2 shadow-lg">
                <span>Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
    </div>

</body>
</html>