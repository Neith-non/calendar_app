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
        // Find the user in the database
        $stmt = $pdo->prepare("SELECT user_id, role_id, username, password, full_name FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if the user exists AND the password matches
        // (Note: We are using a basic text match since we manually added 'password123' earlier)
        if ($user && $password === $user['password']) {
            
            // Success! Give them their Session Wristband
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['full_name'] = $user['full_name'];
            
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-xl shadow-lg max-w-sm w-full border border-slate-200">
        
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full mb-3">
                <i class="fa-solid fa-calendar-days text-blue-600 text-xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-slate-800">Welcome Back</h2>
            <p class="text-slate-500 text-sm mt-1">Please sign in to your account</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded mb-4 text-sm font-medium flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-slate-700 text-sm font-bold mb-2">Username</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-user text-slate-400"></i>
                    </div>
                    <input type="text" name="username" required 
                           class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-slate-50 focus:bg-white transition" 
                           placeholder="Enter username (admin)">
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-slate-700 text-sm font-bold mb-2">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="password" required 
                           class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-slate-50 focus:bg-white transition" 
                           placeholder="Enter password (password123)">
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200 flex justify-center items-center gap-2 shadow-md">
                <span>Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
    </div>

</body>
</html>