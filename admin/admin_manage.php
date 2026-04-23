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

// --- 2. PROCESS CRUD OPERATIONS ---

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

// --- 3. FETCH CURRENT DATA ---
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>

<body class="dashboard-body h-screen flex overflow-hidden">

    <aside class="w-72 glass-container flex flex-col flex-shrink-0 z-10">
        <div class="p-8 text-center border-b border-white/10">
            <div
                class="w-20 h-20 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white/20">
                <i class="fa-solid fa-user text-3xl text-white/50"></i>
            </div>
            <h2 class="text-xl font-bold text-white">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-sm text-yellow-400 capitalize">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-white/10">
                <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Traversal</h3>
                <div class="space-y-2">
                    <a href="../index.php"
                        class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="../calendar.php"
                        class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                    <a href="../request_status.php"
                        class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                        <span>Event Status</span>
                        <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                            <span class="ml-auto relative flex h-3 w-3"
                                title="<?php echo $pendingCount; ?> Pending Requests">
                                <span
                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a href="admin_manage.php"
                        class="w-full bg-white/20 text-white font-semibold py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors border border-white/30">
                        <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                        <span>Admin Panel</span>
                    </a>
                    <button onclick="openPdfModal()"
                        class="w-full bg-slate-600 hover:bg-slate-500 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm mt-3 border border-slate-500 block text-center">
                        <i class="fa-solid fa-print text-slate-300"></i> Print Schedule
                    </button>
                </div>
            </div>

            <div class="p-6 border-b border-white/10">
                <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="../add_event.php"
                        class="w-full bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <a href="../functions/sync_holidays.php"
                        class="w-full bg-white/10 hover:bg-white/20 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-white/20">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                    </a>
                </div>
            </div>
        </div>

        <div class="p-6 mt-auto border-t border-white/10">
            <a href="../logout.php"
                class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="max-w-7xl mx-auto w-full flex flex-col gap-6">

            <div class="flex items-center justify-between glass-container p-6 rounded-xl border border-white/10">
                <div>
                    <h1 class="text-3xl font-bold text-white"><i
                            class="fa-solid fa-screwdriver-wrench text-blue-400 mr-3"></i> Admin Management</h1>
                    <p class="text-slate-300 text-sm mt-1">Manage users, venues, categories, and participants.</p>
                </div>
                <a href="../index.php"
                    class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2.5 px-5 rounded-lg transition-colors border border-white/20 flex items-center gap-2">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
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
                        <strong>Action Failed!</strong> You might be trying to delete a record that is currently attached to an existing event.
                        <br><span class="text-xs opacity-75"><?php echo $error; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                    <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-users text-purple-400 mr-2"></i>
                            Users</h2>
                    </div>

                    <div class="p-4 border-b border-white/10 bg-white/5">
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="add_user">
                            <input type="text" name="full_name" placeholder="Full Name (e.g. Juan Cruz)" required
                                class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                            <input type="text" name="username" placeholder="Login Username" required
                                class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                            <input type="password" name="password" placeholder="Password" required
                                class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                            <select name="role_id" required
                                class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-400">
                                <option value="" disabled selected class="text-black">Select Role...</option>
                                <?php foreach ($roles_list as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>" class="text-black">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit"
                                class="w-full bg-purple-500/20 hover:bg-purple-500/40 text-purple-300 border border-purple-500/30 py-2 rounded-lg text-sm font-semibold transition-colors">Add
                                User</button>
                        </form>
                    </div>

                    <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                        <?php foreach ($users as $u): ?>
                            <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                                <div>
                                    <p class="text-white text-sm font-medium">
                                        <?php echo htmlspecialchars($u['full_name']); ?> <span
                                            class="text-xs text-slate-500">(@<?php echo htmlspecialchars($u['username']); ?>)</span>
                                    </p>
                                    <p class="text-xs text-purple-400 font-medium">
                                        <?php echo htmlspecialchars($u['role_name']); ?>
                                    </p>
                                </div>
                                <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                                    <a href="?delete_user=<?php echo $u['user_id']; ?>"
                                        onclick="return confirm('Permanently delete this user?');"
                                        class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                    <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white"><i
                                class="fa-solid fa-location-dot text-yellow-400 mr-2"></i> Venues</h2>
                    </div>

                    <div class="p-4 border-b border-white/10 bg-white/5">
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="add_venue">
                            <div class="flex gap-2">
                                <input type="text" name="venue_name" placeholder="New Venue Name" required
                                    class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-yellow-400">
                                <button type="submit"
                                    class="bg-yellow-500/20 hover:bg-yellow-500/40 text-yellow-300 border border-yellow-500/30 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">Add</button>
                            </div>
                            <label class="flex items-center gap-2 text-slate-300 text-sm cursor-pointer pl-1">
                                <input type="checkbox" name="is_off_campus" value="1"
                                    class="w-4 h-4 rounded border-gray-400 text-yellow-500 focus:ring-yellow-500 bg-white/10">
                                This is an Off-Campus location (Allows double-booking)
                            </label>
                        </form>
                    </div>

                    <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                        <?php foreach ($venues as $v): ?>
                            <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                                <div>
                                    <p class="text-white text-sm"><?php echo htmlspecialchars($v['venue_name']); ?></p>
                                    <?php if ($v['is_off_campus']): ?>
                                        <p class="text-[10px] font-bold text-yellow-400 uppercase tracking-wider mt-0.5"><i class="fa-solid fa-bus text-[9px] mr-1"></i> Off Campus</p>
                                    <?php endif; ?>
                                </div>
                                <a href="?delete_venue=<?php echo $v['venue_id']; ?>"
                                    onclick="return confirm('Delete this venue?');"
                                    class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                    <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-tags text-emerald-400 mr-2"></i>
                            Categories</h2>
                    </div>

                    <div class="p-4 border-b border-white/10 bg-white/5">
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="add_category">
                            <input type="text" name="category_name" placeholder="Category Name (e.g. Mass)" required
                                class="w-full bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-emerald-400">
                            <div class="flex gap-2">
                                <input type="text" name="category_type" placeholder="Type (e.g. Religious)" required
                                    class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-emerald-400">
                                <button type="submit"
                                    class="bg-emerald-500/20 hover:bg-emerald-500/40 text-emerald-300 border border-emerald-500/30 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">Add</button>
                            </div>
                        </form>
                    </div>

                    <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                        <?php foreach ($categories as $c): ?>
                            <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                                <div>
                                    <p class="text-white text-sm"><?php echo htmlspecialchars($c['category_name']); ?></p>
                                    <p class="text-xs text-slate-400"><?php echo htmlspecialchars($c['category_type']); ?>
                                    </p>
                                </div>
                                <a href="?delete_category=<?php echo $c['category_id']; ?>"
                                    onclick="return confirm('Delete this category?');"
                                    class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="glass-container rounded-xl border border-white/10 overflow-hidden flex flex-col">
                    <div class="bg-black/30 p-4 border-b border-white/10 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-users-viewfinder text-pink-400 mr-2"></i> 
                            Participants</h2>
                    </div>

                    <div class="p-4 border-b border-white/10 bg-white/5">
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="add_participant">
                            <div class="flex gap-2">
                                <input type="text" name="participant_name" placeholder="Grade (e.g. Grade 11)" required
                                    class="w-1/2 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-pink-400">
                                <input type="text" name="strand" placeholder="Strand (Optional, e.g. STEM)" 
                                    class="w-1/2 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-pink-400">
                            </div>
                            
                            <div class="flex gap-2">
                                <select name="department" required
                                    class="flex-1 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-pink-400">
                                    <option value="" disabled selected class="text-black">Select Department...</option>
                                    <?php foreach ($departments_list as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" class="text-black">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="bg-pink-500/20 hover:bg-pink-500/40 text-pink-300 border border-pink-500/30 px-4 py-2 rounded-lg text-sm font-semibold transition-colors">Add</button>
                            </div>
                        </form>
                    </div>

                    <div class="p-4 space-y-2 overflow-y-auto max-h-96 flex-1">
                        <?php foreach ($participants as $p): ?>
                            <div class="flex justify-between items-center bg-black/20 p-3 rounded border border-white/5">
                                <div>
                                    <p class="text-white text-sm">
                                        <?php echo htmlspecialchars($p['name']); ?>
                                    </p>
                                    <p class="text-xs text-slate-400"><?php echo htmlspecialchars($p['department']); ?></p>
                                </div>
                                <a href="?delete_participant=<?php echo $p['participant_id']; ?>"
                                    onclick="return confirm('Delete this participant group?');"
                                    class="text-red-400 hover:text-red-300 p-2 transition-colors">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
    <script src="../assets/js/pdf_modal.js"></script>
</body>

</html>