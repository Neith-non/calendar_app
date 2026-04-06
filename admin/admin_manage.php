<?php
session_start();

// 1. Check Permissions - STRICTLY ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

require_once '../functions/database.php';

$msg = '';
$error = '';

// --- 2. PROCESS CRUD OPERATIONS ---

try {
    // Handle Deletions (GET requests)
    if (isset($_GET['delete_user'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([(int)$_GET['delete_user']]);
        header("Location: admin_manage.php?msg=User+deleted");
        exit();
    }
    if (isset($_GET['delete_venue'])) {
        $stmt = $pdo->prepare("DELETE FROM venues WHERE venue_id = ?");
        $stmt->execute([(int)$_GET['delete_venue']]);
        header("Location: admin_manage.php?msg=Venue+deleted");
        exit();
    }
    if (isset($_GET['delete_category'])) {
        $stmt = $pdo->prepare("DELETE FROM event_categories WHERE category_id = ?");
        $stmt->execute([(int)$_GET['delete_category']]);
        header("Location: admin_manage.php?msg=Category+deleted");
        exit();
    }

    // Handle Additions (POST requests)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        if ($_POST['action'] === 'add_user') {
            $username = trim($_POST['username']);
            $full_name = trim($_POST['full_name']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role_id = (int)$_POST['role_id'];
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $password, $full_name, $role_id]);
            $msg = "User successfully added!";
        }
        
        if ($_POST['action'] === 'add_venue') {
            $venue_name = trim($_POST['venue_name']);
            $stmt = $pdo->prepare("INSERT INTO venues (venue_name) VALUES (?)");
            $stmt->execute([$venue_name]);
            $msg = "Venue successfully added!";
        }
        
        if ($_POST['action'] === 'add_category') {
            $category_name = trim($_POST['category_name']);
            $category_type = trim($_POST['category_type']);
            $stmt = $pdo->prepare("INSERT INTO event_categories (category_name, category_type) VALUES (?, ?)");
            $stmt->execute([$category_name, $category_type]);
            $msg = "Category successfully added!";
        }
    }
} catch (PDOException $e) {
    // This will catch errors like trying to delete a venue that is already being used by an event
    $error = "Database Error: " . $e->getMessage();
}

// Check for messages in URL
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// --- 3. FETCH CURRENT DATA ---
// Fetch Roles for the User dropdown
$roles_list = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll();

// Fetch Users (Joined with roles to get the text name of the role)
$users = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, r.role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.role_id 
    ORDER BY r.role_name, u.full_name
")->fetchAll();

// Fetch Venues
$venues = $pdo->query("SELECT venue_id, venue_name, is_off_campus FROM venues ORDER BY venue_name")->fetchAll();

// Fetch Categories
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM event_categories ORDER BY category_type, category_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles.css">
</head>
<body class="dashboard-body p-4 sm:p-6 md:p-8 min-h-screen">

    <div class="max-w-7xl mx-auto flex flex-col gap-6">
        
        <div class="flex items-center justify-between glass-container p-6 rounded-xl border border-white/10">
            <div>
                <h1 class="text-3xl font-bold text-white"><i class="fa-solid fa-screwdriver-wrench text-blue-400 mr-3"></i> Admin Management</h1>
                <p class="text-slate-300 text-sm mt-1">Manage users, venues, and categories.</p>
            </div>
            <a href="javascript:history.back()" class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2.5 px-5 rounded-lg transition-colors border border-white/20 flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($msg): ?>
            <div class="bg-emerald-500/20 border border-emerald-500/50 text-emerald-400 p-4 rounded-lg flex items-center gap-3">
                <i class="fa-solid fa-circle-check"></i> <?php echo $msg; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-400 p-4 rounded-lg flex items-center gap-3 text-sm">
                <i class="fa-solid fa-triangle-exclamation text-lg"></i> 
                <div>
                    <strong>Action Failed!</strong> You might be trying to delete a venue or category that is currently attached to an existing event.
                    <br><span class="text-xs opacity-75"><?php echo $error; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                    <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-users text-purple-400 mr-2"></i> Users</h2>
                </div>
                
                <div class="p-4 border-b border-white/10 bg-white/5">
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="add_user">
                        <input type="text" name="full_name" placeholder="Full Name (e.g. Juan Cruz)" required class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                        <input type="text" name="username" placeholder="Login Username" required class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                        <input type="password" name="password" placeholder="Password" required class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                        <select name="role_id" required class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                            <option value="" disabled selected class="text-black">Select Role...</option>
                            <?php foreach ($roles_list as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>" class="text-black"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-purple-500/20 hover:bg-purple-500/40 text-purple-300 border border-purple-500/30 py-2 rounded-lg text-sm font-semibold transition-colors">Add User</button>
                    </form>
                </div>

                <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                    <?php foreach ($users as $u): ?>
                        <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                            <div>
                                <p class="text-white text-sm font-medium"><?php echo htmlspecialchars($u['full_name']); ?> <span class="text-xs text-slate-500">(@<?php echo htmlspecialchars($u['username']); ?>)</span></p>
                                <p class="text-xs text-purple-400 font-medium"><?php echo htmlspecialchars($u['role_name']); ?></p>
                            </div>
                            <?php if ($u['user_id'] !== $_SESSION['user_id']): // Don't let admin delete themselves ?>
                                <a href="?delete_user=<?php echo $u['user_id']; ?>" onclick="return confirm('Permanently delete this user?');" class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                    <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-location-dot text-yellow-400 mr-2"></i> Venues</h2>
                </div>
                
                <div class="p-4 border-b border-white/10 bg-white/5">
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action" value="add_venue">
                        <input type="text" name="venue_name" placeholder="New Venue Name" required class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-yellow-400">
                        <button type="submit" class="bg-yellow-500/20 hover:bg-yellow-500/40 text-yellow-300 border border-yellow-500/30 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">Add</button>
                    </form>
                </div>

                <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                    <?php foreach ($venues as $v): ?>
                        <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                            <p class="text-white text-sm"><?php echo htmlspecialchars($v['venue_name']); ?></p>
                            <a href="?delete_venue=<?php echo $v['venue_id']; ?>" onclick="return confirm('Delete this venue?');" class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                    <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-tags text-emerald-400 mr-2"></i> Categories</h2>
                </div>
                
                <div class="p-4 border-b border-white/10 bg-white/5">
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="add_category">
                        <input type="text" name="category_name" placeholder="Category Name (e.g. Mass)" required class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-emerald-400">
                        <div class="flex gap-2">
                            <input type="text" name="category_type" placeholder="Type (e.g. Religious)" required class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-emerald-400">
                            <button type="submit" class="bg-emerald-500/20 hover:bg-emerald-500/40 text-emerald-300 border border-emerald-500/30 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">Add</button>
                        </div>
                    </form>
                </div>

                <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                    <?php foreach ($categories as $c): ?>
                        <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                            <div>
                                <p class="text-white text-sm"><?php echo htmlspecialchars($c['category_name']); ?></p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars($c['category_type']); ?></p>
                            </div>
                            <a href="?delete_category=<?php echo $c['category_id']; ?>" onclick="return confirm('Delete this category?');" class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
</body>
</html>