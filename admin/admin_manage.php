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

try {
    if (isset($_GET['delete_user'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([(int) $_GET['delete_user']]);
        header("Location: admin_manage.php?msg=User+deleted#users");
        exit();
    }
    if (isset($_GET['delete_venue'])) {
        $stmt = $pdo->prepare("DELETE FROM venues WHERE venue_id = ?");
        $stmt->execute([(int) $_GET['delete_venue']]);
        header("Location: admin_manage.php?msg=Venue+deleted#venues");
        exit();
    }
    if (isset($_GET['delete_category'])) {
        $stmt = $pdo->prepare("DELETE FROM event_categories WHERE category_id = ?");
        $stmt->execute([(int) $_GET['delete_category']]);
        header("Location: admin_manage.php?msg=Category+deleted#categories");
        exit();
    }

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
    $error = "Database Error: " . $e->getMessage();
}

if (isset($_GET['msg'])) { $msg = htmlspecialchars($_GET['msg']); }

$roles_list = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll();
$users = $pdo->query("SELECT u.user_id, u.username, u.full_name, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id ORDER BY r.role_name, u.full_name")->fetchAll();
$venues = $pdo->query("SELECT venue_id, venue_name, is_off_campus FROM venues ORDER BY venue_name")->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM event_categories ORDER BY category_type, category_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - St. Joseph School</title>
    
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
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="../assets/css/admin_manage.css?v=<?php echo time(); ?>">

    <script>
        tailwind.config = {
            darkMode: 'class',
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
                            light: '#f8faf9',
                            yellow: '#ffbb00'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <aside class="w-72 sidebar-panel flex flex-col flex-shrink-0 z-20 bg-white dark:bg-[#0b1120] border-r border-slate-200 dark:border-slate-800">
        
        <div class="p-8 text-center border-b border-slate-100 dark:border-slate-800/50">
            <div class="w-16 h-16 mx-auto bg-white dark:bg-slate-900 rounded-full flex items-center justify-center mb-4 shadow-sm border border-slate-100 dark:border-slate-700">
                <img src="../assets/img/sjsfi_schoologo.png" alt="SJSFI Logo" 
                     class="w-full h-full object-contain rounded-full" 
                     onerror="this.outerHTML='<i class=\'fa-solid fa-graduation-cap text-sjsfi-green dark:text-emerald-500 text-3xl\'></i>'">
            </div>
            <h2 class="text-sm font-extrabold text-sjsfi-green dark:text-emerald-400 leading-tight mb-1">
                Saint Joseph School<br>Foundation Inc.
            </h2>
            <h3 class="text-xs font-bold font-chinese text-slate-400 dark:text-slate-500 tracking-widest">
                三寶颜忠義中學
            </h3>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800/50">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
                <div class="space-y-2">

                    <a href="../index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-table-cells-large w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>Dashboard Hub</span>
                    </a>

                    <a href="../calendar.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-regular fa-calendar-days w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>View Calendar</span>
                    </a>

                    <a href="../request_status.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-clipboard-list w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>Event Status</span>
                        <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                            <span class="notification-dot" title="<?php echo $pendingCount; ?> Pending Requests"></span>
                        <?php endif; ?>
                    </a>

                    <a href="admin_manage.php" class="nav-item active w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                        <span>Admin Panel</span>
                    </a>

                    <button onclick="openPdfModal()" class="w-full mt-4 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 text-sm shadow-sm">
                        <i class="fa-solid fa-print text-slate-400 dark:text-slate-500"></i> Print Schedule
                    </button>
                </div>
            </div>

            <div class="p-6">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="../add_event.php" class="bg-sjsfi-yellow hover:bg-yellow-400 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-sjsfi-green dark:text-white w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm shadow-sm transition-colors">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <a href="../functions/sync_holidays.php" class="w-full bg-slate-800 dark:bg-slate-700 hover:bg-slate-900 dark:hover:bg-slate-600 text-white font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 shadow-sm text-sm">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                    </a>
                </div>
            </div>
        </div>

        <div class="p-5 mt-auto border-t border-slate-100 dark:border-slate-800 bg-slate-50/80 dark:bg-[#0b1120] flex flex-col gap-4">
            
            <button id="theme-toggle" class="flex items-center justify-between w-full p-3 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 transition-colors shadow-sm">
                <div class="flex items-center gap-2">
                    <i id="theme-toggle-icon" class="fa-solid fa-moon text-slate-400 dark:text-yellow-400"></i>
                    <span class="text-xs font-bold text-slate-600 dark:text-slate-300" id="theme-toggle-text">Dark Mode</span>
                </div>
                <div class="relative w-10 h-5 rounded-full bg-slate-200 dark:bg-emerald-500 transition-colors border border-slate-300 dark:border-transparent">
                    <div id="theme-toggle-knob" class="absolute left-1 top-1 bg-white dark:bg-white w-3 h-3 rounded-full transition-transform transform dark:translate-x-5 shadow-sm"></div>
                </div>
            </button>

            <div class="flex items-center gap-3 px-2">
                <div class="w-10 h-10 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-full flex items-center justify-center text-sjsfi-green dark:text-emerald-400 shrink-0 shadow-sm">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div class="overflow-hidden">
                    <p class="text-sm font-extrabold text-slate-800 dark:text-slate-100 leading-tight truncate">
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?>
                    </p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-sjsfi-green dark:text-emerald-500 truncate mt-0.5">
                        <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
                    </p>
                </div>
            </div>

            <a href="../logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition font-bold text-sm border border-transparent hover:border-red-100 dark:hover:border-red-500/30">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Secure Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-6 md:p-8 lg:p-10 relative">
        <div class="max-w-7xl mx-auto w-full flex flex-col gap-6" x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'users' }" @hashchange.window="activeTab = window.location.hash.substring(1)">

            <div class="bento-card admin-header bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 p-6 md:p-8 rounded-2xl flex flex-col md:flex-row justify-between items-start md:items-center shadow-sm relative overflow-hidden w-full">
                <div class="absolute -right-10 -top-10 w-48 h-48 bg-sky-500/5 dark:bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="relative z-10">
                    <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-1">
                        <i class="fa-solid fa-screwdriver-wrench text-sky-500 dark:text-sky-400 mr-2"></i> Admin Management
                    </h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Manage user credentials, venue locations, and categories.</p>
                </div>
                <a href="../index.php" class="relative z-10 bg-slate-50 dark:bg-[#0b1120] hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold py-2.5 px-6 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 flex items-center gap-2 shadow-sm text-sm mt-4 md:mt-0">
                    <i class="fa-solid fa-arrow-left"></i> Back to Hub
                </a>
            </div>

            <?php if ($msg): ?>
                <div class="px-5 py-4 rounded-2xl border bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-400 flex items-center gap-3 font-bold text-sm shadow-sm">
                    <i class="fa-solid fa-circle-check text-lg"></i> <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="px-5 py-4 rounded-2xl border bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 flex items-center gap-3 font-semibold text-sm shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                    <div>
                        <strong class="font-extrabold block mb-0.5">Action Failed!</strong> 
                        <span class="text-slate-600 dark:text-red-300">You might be trying to delete a venue or category that is currently attached to an existing event.</span>
                        <br><span class="text-xs opacity-75 text-slate-500 dark:text-red-400 mt-1 block"><?php echo $error; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-2 bg-white dark:bg-[#111827] p-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm w-full md:w-max mt-2">
                <button @click="activeTab = 'users'; window.location.hash = 'users'" :class="activeTab === 'users' ? 'bg-sjsfi-green dark:bg-emerald-600 text-white shadow-md' : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'" class="flex-1 md:flex-none px-6 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-users"></i> Users
                </button>
                <button @click="activeTab = 'venues'; window.location.hash = 'venues'" :class="activeTab === 'venues' ? 'bg-sjsfi-green dark:bg-emerald-600 text-white shadow-md' : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'" class="flex-1 md:flex-none px-6 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-location-dot"></i> Venues
                </button>
                <button @click="activeTab = 'categories'; window.location.hash = 'categories'" :class="activeTab === 'categories' ? 'bg-sjsfi-green dark:bg-emerald-600 text-white shadow-md' : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'" class="flex-1 md:flex-none px-6 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2">
                    <i class="fa-solid fa-tags"></i> Categories
                </button>
            </div>

            <div x-show="activeTab === 'users'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <div class="lg:col-span-4 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm h-max">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-user-plus text-violet-500"></i> Add New User</h2>
                    <form method="POST" action="#users" class="space-y-4">
                        <input type="hidden" name="action" value="add_user">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Full Name</label>
                            <input type="text" name="full_name" placeholder="e.g. Juan Cruz" required class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Username</label>
                            <input type="text" name="username" placeholder="Login ID" required class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Password</label>
                            <input type="password" name="password" placeholder="••••••••" required class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Role Access</label>
                            <select name="role_id" required class="form-input cursor-pointer">
                                <option value="" disabled selected class="text-slate-400">Select Role...</option>
                                <?php foreach ($roles_list as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full mt-2 bg-violet-50 dark:bg-violet-500/10 hover:bg-violet-100 dark:hover:bg-violet-500/20 text-violet-600 dark:text-violet-400 border border-violet-200 dark:border-violet-500/30 py-3 rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Create User Profile
                        </button>
                    </form>
                </div>
                <div class="lg:col-span-8 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-address-book text-slate-400"></i> User Directory</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-h-[600px] overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach ($users as $u): ?>
                            <div class="flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-700/50 hover:border-violet-200 dark:hover:border-violet-500/30 transition group">
                                <div class="flex items-center gap-4 overflow-hidden pr-2">
                                    <div class="w-10 h-10 rounded-full bg-violet-100 dark:bg-violet-500/20 text-violet-500 dark:text-violet-400 flex items-center justify-center shrink-0 border border-violet-200 dark:border-violet-500/30"><i class="fa-solid fa-user text-sm"></i></div>
                                    <div class="overflow-hidden">
                                        <p class="text-slate-800 dark:text-slate-200 text-sm font-bold truncate"><?php echo htmlspecialchars($u['full_name']); ?> </p>
                                        <p class="text-[10px] font-bold text-slate-400 dark:text-slate-500 mt-0.5 truncate"><span class="text-violet-500 dark:text-violet-400 uppercase tracking-widest mr-1"><?php echo htmlspecialchars($u['role_name']); ?></span> &bull; @<?php echo htmlspecialchars($u['username']); ?></p>
                                    </div>
                                </div>
                                <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                                    <a href="?delete_user=<?php echo $u['user_id']; ?>" onclick="return confirm('Permanently delete this user?');" class="text-slate-400 hover:text-red-500 bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/10 border border-slate-200 dark:border-slate-700 hover:border-red-200 dark:hover:border-red-500/30 w-8 h-8 rounded-full flex items-center justify-center transition opacity-0 group-hover:opacity-100 shrink-0 shadow-sm" title="Delete User">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase bg-slate-200 dark:bg-slate-700 px-2 py-1 rounded-md shrink-0">You</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'venues'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <div class="lg:col-span-4 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm h-max">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-map-pin text-amber-500"></i> Add New Venue</h2>
                    <form method="POST" action="#venues" class="space-y-4">
                        <input type="hidden" name="action" value="add_venue">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Venue Name</label>
                            <input type="text" name="venue_name" placeholder="e.g. Main Auditorium" required class="form-input">
                        </div>
                        <button type="submit" class="w-full mt-2 bg-amber-50 dark:bg-amber-500/10 hover:bg-amber-100 dark:hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30 py-3 rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Add Venue
                        </button>
                    </form>
                </div>
                <div class="lg:col-span-8 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-building text-slate-400"></i> Venue Directory</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-h-[600px] overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach ($venues as $v): ?>
                            <div class="flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-700/50 hover:border-amber-200 dark:hover:border-amber-500/30 transition group">
                                <p class="text-slate-700 dark:text-slate-200 text-sm font-bold truncate pr-2 flex items-center gap-2">
                                    <i class="fa-solid fa-location-dot text-slate-300 dark:text-slate-600 text-xs"></i><?php echo htmlspecialchars($v['venue_name']); ?>
                                </p>
                                <a href="?delete_venue=<?php echo $v['venue_id']; ?>" onclick="return confirm('Delete this venue?');" class="text-slate-400 hover:text-red-500 bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/10 border border-slate-200 dark:border-slate-700 hover:border-red-200 dark:hover:border-red-500/30 w-8 h-8 rounded-full flex items-center justify-center transition opacity-0 group-hover:opacity-100 shrink-0 shadow-sm" title="Delete Venue">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'categories'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <div class="lg:col-span-4 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm h-max">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-folder-plus text-emerald-500"></i> Add New Category</h2>
                    <form method="POST" action="#categories" class="space-y-4">
                        <input type="hidden" name="action" value="add_category">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Category Name</label>
                            <input type="text" name="category_name" placeholder="e.g. Mass" required class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Category Type</label>
                            <input type="text" name="category_type" placeholder="e.g. Religious" required class="form-input">
                        </div>
                        <button type="submit" class="w-full mt-2 bg-emerald-50 dark:bg-emerald-500/10 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30 py-3 rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Create Category
                        </button>
                    </form>
                </div>
                <div class="lg:col-span-8 bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2"><i class="fa-solid fa-tags text-slate-400"></i> Category Dictionary</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-h-[600px] overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach ($categories as $c): ?>
                            <div class="flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-700/50 hover:border-emerald-200 dark:hover:border-emerald-500/30 transition group">
                                <div class="overflow-hidden pr-2">
                                    <p class="text-slate-700 dark:text-slate-200 text-sm font-bold truncate"><?php echo htmlspecialchars($c['category_name']); ?></p>
                                    <p class="text-[10px] font-bold text-emerald-500 dark:text-emerald-400 uppercase tracking-widest mt-0.5 truncate"><?php echo htmlspecialchars($c['category_type']); ?></p>
                                </div>
                                <a href="?delete_category=<?php echo $c['category_id']; ?>" onclick="return confirm('Delete this category?');" class="text-slate-400 hover:text-red-500 bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/10 border border-slate-200 dark:border-slate-700 hover:border-red-200 dark:hover:border-red-500/30 w-8 h-8 rounded-full flex items-center justify-center transition opacity-0 group-hover:opacity-100 shrink-0 shadow-sm" title="Delete Category">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="../assets/js/pdf_modal.js"></script>
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleKnob = document.getElementById('theme-toggle-knob');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');
        const themeToggleText = document.getElementById('theme-toggle-text');

        function updateToggleUI() {
            if (document.documentElement.classList.contains('dark')) {
                themeToggleKnob.classList.add('translate-x-5');
                themeToggleIcon.className = 'fa-solid fa-sun text-yellow-400';
                themeToggleText.innerText = 'Light Mode';
            } else {
                themeToggleKnob.classList.remove('translate-x-5');
                themeToggleIcon.className = 'fa-solid fa-moon text-slate-400';
                themeToggleText.innerText = 'Dark Mode';
            }
        }

        updateToggleUI();
        themeToggleBtn.addEventListener('click', function() {
            document.documentElement.classList.toggle('dark');
            if (document.documentElement.classList.contains('dark')) localStorage.setItem('color-theme', 'dark');
            else localStorage.setItem('color-theme', 'light');
            updateToggleUI();
        });
    </script>
</body>

</html>