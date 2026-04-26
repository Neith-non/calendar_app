<?php
session_start();

// 1. Check Permissions - STRICTLY ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

require_once '../functions/database.php';
require_once '../functions/get_pending_count.php';
$msg = '';
$error = '';

// --- 2. PROCESS CRUD OPERATIONS (UNCHANGED) ---

try {
    // Handle Deletions (GET requests)
    if (isset($_GET['delete_user'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([(int) $_GET['delete_user']]);
        header("Location: admin_manage.php?msg=User+deleted");
        exit();
    }
    if (isset($_GET['delete_venue'])) {
        $stmt = $pdo->prepare("DELETE FROM venues WHERE venue_id = ?");
        $stmt->execute([(int) $_GET['delete_venue']]);
        header("Location: admin_manage.php?msg=Venue+deleted");
        exit();
    }
    if (isset($_GET['delete_category'])) {
        $stmt = $pdo->prepare("DELETE FROM event_categories WHERE category_id = ?");
        $stmt->execute([(int) $_GET['delete_category']]);
        header("Location: admin_manage.php?msg=Category+deleted");
        exit();
    }
    
    // UPDATED: Delete Participant using the new 'id' column
    if (isset($_GET['delete_participant'])) {
        $stmt = $pdo->prepare("DELETE FROM participants WHERE id = ?");
        $stmt->execute([(int) $_GET['delete_participant']]);
        header("Location: admin_manage.php?msg=Participant+deleted");
        exit();
    }

    // Handle Additions (POST requests)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

        if ($_POST['action'] === 'add_user') {
            $username = trim($_POST['username']);
            $full_name = trim($_POST['full_name']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role_id = (int) $_POST['role_id'];

            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $password, $full_name, $role_id]);
            $msg = "User successfully added!";
        }

        if ($_POST['action'] === 'add_venue') {
            $venue_name = trim($_POST['venue_name']);
            $is_off_campus = isset($_POST['is_off_campus']) ? 1 : 0; 
            
            $stmt = $pdo->prepare("INSERT INTO venues (venue_name, is_off_campus) VALUES (?, ?)");
            $stmt->execute([$venue_name, $is_off_campus]);
            $msg = "Venue successfully added!";
        }

        if ($_POST['action'] === 'add_category') {
            $category_name = trim($_POST['category_name']);
            $category_type = trim($_POST['category_type']);
            $stmt = $pdo->prepare("INSERT INTO event_categories (category_name, category_type) VALUES (?, ?)");
            $stmt->execute([$category_name, $category_type]);
            $msg = "Category successfully added!";
        }

        // UPDATED: Insert Participant handling numeric department_id and combined strand
        if ($_POST['action'] === 'add_participant') {
            $base_name = trim($_POST['participant_name']);
            $strand = trim($_POST['strand'] ?? '');
            
            // We now expect the HTML form to pass the numeric department_id
            $department_id = (int) $_POST['department']; 

            // Combine the name and strand into a single string (e.g., "Grade 11 (STEM)")
            $participant_name = $base_name;
            if ($strand !== '') {
                $participant_name .= ' (' . $strand . ')';
            }

            // Insert into the new ERD structure
            $stmt = $pdo->prepare("INSERT INTO participants (name, department_id) VALUES (?, ?)");
            $stmt->execute([$participant_name, $department_id]);
            $msg = "Participant group successfully added!";
        }
    }
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}

// Check for messages in URL
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// --- 3. FETCH CURRENT DATA (UNCHANGED) ---
$roles_list = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll();
$users = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, r.role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.role_id 
    ORDER BY r.role_name, u.full_name
")->fetchAll();

$venues = $pdo->query("SELECT venue_id, venue_name, is_off_campus FROM venues ORDER BY venue_name")->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM event_categories ORDER BY category_type, category_name")->fetchAll();

// NEW: Fetch Departments for the dropdown list
$departments_list = $pdo->query("SELECT id, name FROM department ORDER BY id")->fetchAll();

// UPDATED: Fetch Participants using JOIN
$participants = $pdo->query("
    SELECT p.id AS participant_id, p.name, d.name AS department 
    FROM participants p
    JOIN department d ON p.department_id = d.id
    ORDER BY d.id ASC, p.id ASC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - SJSFI</title>
    
    <script>
        if (localStorage.getItem('color-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    colors: {
                        sjsfi: {
                            green: '#004731',
                            greenHover: '#003323',
                            yellow: '#ffbb00'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        body { color: #1e293b; transition: background-color 0.3s ease, color 0.3s ease; }
        .dark body { color: #f1f5f9; }
        .nav-item { color: #64748b; transition: all 0.2s ease; }
        .nav-item:hover { color: #004731; background-color: #f1f5f9; }
        .dark .nav-item { color: #94a3b8; }
        .dark .nav-item:hover { color: #10b981; background-color: rgba(30, 41, 59, 0.5); }
        .nav-item.active { background-color: #004731; color: #ffffff; box-shadow: 0 4px 12px rgba(0, 71, 49, 0.15); }
        .dark .nav-item.active { background-color: #10b981; }

        .bento-card {
            background: #ffffff; border: 1px solid #d1f0e0; border-radius: 1.5rem; box-shadow: 0 4px 12px rgba(209, 240, 224, 0.2);
            transition: all 0.3s ease;
        }
        .dark .bento-card { background: #07160f; border-color: #123f29; box-shadow: 0 4px 12px rgba(0,0,0,0.4); }

        .input-premium {
            background-color: #f8faf9; border: 1px solid #e2e8f0; color: #0f172a; transition: all 0.2s ease;
        }
        .input-premium:focus {
            background-color: #ffffff; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); outline: none;
        }
        .dark .input-premium {
            background-color: rgba(15, 23, 42, 0.6); border-color: #334155; color: #f1f5f9;
        }
        .dark .input-premium:focus { background-color: #0f172a; border-color: #10b981; }

        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(16, 185, 129, 0.2); border-radius: 10px; }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="h-screen flex overflow-hidden bg-[#f4fcf7] dark:bg-[#04120a] transition-colors duration-300 font-sans">

    <?php include '../includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto w-full flex flex-col gap-6">

            <div class="lg:hidden flex items-center justify-between mb-2">
                <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#0a1a12] rounded-xl border border-[#d1f0e0] dark:border-[#123f29] text-emerald-700 flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <div class="flex items-center justify-between bento-card p-6 sm:p-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 flex items-center justify-center shadow-sm">
                        <i class="fa-solid fa-screwdriver-wrench text-xl text-blue-500"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">Admin Management</h1>
                        <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mt-1">Configure users, venues, categories, and participant groups.</p>
                    </div>
                </div>
                <a href="../index.php" class="hidden sm:flex bg-white dark:bg-[#0a1a12] hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold py-2.5 px-5 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 shadow-sm items-center gap-2 text-sm">
                    <i class="fa-solid fa-arrow-left"></i> Dashboard
                </a>
            </div>

            <?php if ($msg): ?>
                <div class="bg-[#f0fcf5] dark:bg-[#0a1a12] border border-emerald-200 dark:border-emerald-900/50 text-emerald-700 dark:text-emerald-400 p-4 rounded-2xl flex items-center gap-3 shadow-sm font-bold text-sm">
                    <i class="fa-solid fa-circle-check text-lg"></i> <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 text-red-700 dark:text-red-400 p-4 rounded-2xl flex items-start gap-3 shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation text-lg mt-0.5"></i>
                    <div class="text-sm font-bold">
                        <p>Action Failed!</p>
                        <p class="text-xs opacity-75 mt-1 font-medium">Record is likely linked to existing events. <?php echo $error; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                <div class="bento-card overflow-hidden flex flex-col h-[550px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29] flex justify-between items-center">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-users text-purple-500"></i> Users
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_user">
                            <input type="text" name="full_name" placeholder="Full Name" required class="input-premium px-3 py-2 rounded-lg text-sm font-semibold col-span-2">
                            <input type="text" name="username" placeholder="Username" required class="input-premium px-3 py-2 rounded-lg text-sm font-semibold">
                            <input type="password" name="password" placeholder="Password" required class="input-premium px-3 py-2 rounded-lg text-sm font-semibold">
                            <select name="role_id" required class="input-premium px-3 py-2 rounded-lg text-sm font-bold cursor-pointer col-span-2">
                                <option value="" disabled selected>Assign Role...</option>
                                <?php foreach ($roles_list as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="col-span-2 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Add New User</button>
                        </form>
                    </div>

                    <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                        <?php foreach ($users as $u): ?>
                            <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29] group">
                                <div>
                                    <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($u['full_name']); ?> <span class="text-[10px] text-slate-400 font-medium ml-1">@<?php echo htmlspecialchars($u['username']); ?></span></p>
                                    <span class="inline-block mt-1 px-2 py-0.5 bg-purple-50 dark:bg-purple-950/30 text-purple-600 dark:text-purple-400 border border-purple-100 dark:border-purple-900/50 rounded text-[10px] font-black uppercase tracking-wider"><?php echo $u['role_name']; ?></span>
                                </div>
                                <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                                    <a href="?delete_user=<?php echo $u['user_id']; ?>" onclick="return confirm('Permanently delete this user?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bento-card overflow-hidden flex flex-col h-[550px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-location-dot text-amber-500"></i> Venues
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="add_venue">
                            <div class="flex gap-2">
                                <input type="text" name="venue_name" placeholder="New Venue Name" required class="flex-1 input-premium px-4 py-2.5 rounded-lg text-sm font-semibold">
                                <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-6 py-2.5 rounded-xl transition-all shadow-md text-sm">Add</button>
                            </div>
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <input type="checkbox" name="is_off_campus" value="1" class="w-4 h-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
                                <span class="text-xs font-bold text-slate-500 dark:text-slate-400 group-hover:text-amber-600 transition-colors">Is this an Off-Campus location?</span>
                            </label>
                        </form>
                    </div>

                    <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                        <?php foreach ($venues as $v): ?>
                            <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                <div>
                                    <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($v['venue_name']); ?></p>
                                    <?php if ($v['is_off_campus']): ?>
                                        <span class="inline-block mt-1 px-2 py-0.5 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 border border-amber-100 dark:border-amber-900/50 rounded text-[10px] font-black uppercase tracking-wider italic"><i class="fa-solid fa-bus mr-1"></i> Off Campus</span>
                                    <?php endif; ?>
                                </div>
                                <a href="?delete_venue=<?php echo $v['venue_id']; ?>" onclick="return confirm('Delete this venue?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bento-card overflow-hidden flex flex-col h-[550px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-tags text-emerald-500"></i> Event Categories
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_category">
                            <input type="text" name="category_name" placeholder="Name (e.g. Mass)" required class="input-premium px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <input type="text" name="category_type" placeholder="Type (e.g. Religious)" required class="input-premium px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <button type="submit" class="col-span-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Create Category</button>
                        </form>
                    </div>

                    <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                        <?php foreach ($categories as $c): ?>
                            <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                <div>
                                    <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($c['category_name']); ?></p>
                                    <p class="text-[10px] font-extrabold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest mt-1 opacity-70"><?php echo htmlspecialchars($c['category_type']); ?></p>
                                </div>
                                <a href="?delete_category=<?php echo $c['category_id']; ?>" onclick="return confirm('Delete this category?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bento-card overflow-hidden flex flex-col h-[550px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-users-viewfinder text-pink-500"></i> Participants
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_participant">
                            <input type="text" name="participant_name" placeholder="Grade (e.g. Grade 11)" required class="input-premium px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <input type="text" name="strand" placeholder="Strand (Optional)" class="input-premium px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <select name="department" required class="input-premium px-4 py-2.5 rounded-lg text-sm font-bold cursor-pointer col-span-2">
                                <option value="" disabled selected>Select Department...</option>
                                <?php foreach ($departments_list as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="col-span-2 bg-pink-600 hover:bg-pink-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Add Participant Group</button>
                        </form>
                    </div>

                    <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                        <?php foreach ($participants as $p): ?>
                            <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                <div>
                                    <p class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($p['name']); ?></p>
                                    <p class="text-[10px] font-extrabold text-pink-600 dark:text-pink-400 uppercase tracking-widest mt-1 opacity-70"><?php echo htmlspecialchars($p['department']); ?></p>
                                </div>
                                <a href="?delete_participant=<?php echo $p['participant_id']; ?>" onclick="return confirm('Delete this group?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="../assets/js/pdf_modal.js"></script>
</body>

</html>