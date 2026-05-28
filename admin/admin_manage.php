<?php
session_start();

// 1. Check Permissions - STRICTLY ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

require_once '../functions/database.php';
require_once '../functions/get_pending_count.php';
require_once '../functions/logs.php';
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
    if (isset($_GET['delete_participant'])) {
        $stmt = $pdo->prepare("DELETE FROM participants WHERE id = ?");
        $stmt->execute([(int) $_GET['delete_participant']]);
        header("Location: admin_manage.php?msg=Participant+deleted");
        exit();
    }
    
    // Delete Event 
    if (isset($_GET['delete_event'])) {
        $eventId = (int) $_GET['delete_event'];
        $stmt = $pdo->prepare("SELECT publish_id, title FROM events WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        $publish_id = $event['publish_id'] ?? null;
        $title = $event['title'] ?? null;

        if ($publish_id) {
            $pdo->prepare("DELETE FROM event_publish WHERE id = ?")->execute([$publish_id]);
        }
        
        $pdo->prepare("DELETE FROM events WHERE event_id = ?")->execute([$eventId]);

        // Log deletion
        if (function_exists('write_event_log')) {
            $details = json_encode(['title' => $title]);
            write_event_log($pdo, $_SESSION['user_id'] ?? null, 'delete_event', $publish_id, $eventId, $details);
        }

        header("Location: admin_manage.php?msg=Event+successfully+deleted");
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
            header("Location: admin_manage.php?msg=User+successfully+added!");
            exit();
        }

        if ($_POST['action'] === 'add_venue') {
            $venue_name = trim($_POST['venue_name']);
            $is_off_campus = isset($_POST['is_off_campus']) ? 1 : 0;

            $stmt = $pdo->prepare("INSERT INTO venues (venue_name, is_off_campus) VALUES (?, ?)");
            $stmt->execute([$venue_name, $is_off_campus]);
            header("Location: admin_manage.php?msg=Venue+successfully+added!");
            exit();
        }

        if ($_POST['action'] === 'add_category') {
            $category_name = trim($_POST['category_name']);
            $category_type = trim($_POST['category_type']);
            $stmt = $pdo->prepare("INSERT INTO event_categories (category_name, category_type) VALUES (?, ?)");
            $stmt->execute([$category_name, $category_type]);
            header("Location: admin_manage.php?msg=Category+successfully+added!");
            exit();
        }

        if ($_POST['action'] === 'add_participant') {
            $base_name = trim($_POST['participant_name']);
            $strand = trim($_POST['strand'] ?? '');
            $department_id = (int) $_POST['department'];

            $participant_name = $base_name;
            if ($strand !== '') {
                $participant_name .= ' (' . $strand . ')';
            }

            $stmt = $pdo->prepare("INSERT INTO participants (name, department_id) VALUES (?, ?)");
            $stmt->execute([$participant_name, $department_id]);
            header("Location: admin_manage.php?msg=Participant+group+successfully+added!");
            exit();
        }
    }
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}

// Check for messages in URL
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}

// --- 3. FETCH DATA ARRAYS ---
$roles_list = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll(PDO::FETCH_ASSOC);
$departments_list = $pdo->query("SELECT id, name FROM department ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Main Tables
$users = $pdo->query("SELECT u.user_id, u.username, u.full_name, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id ORDER BY r.role_name, u.full_name")->fetchAll(PDO::FETCH_ASSOC);
$venues = $pdo->query("SELECT venue_id, venue_name, is_off_campus FROM venues ORDER BY venue_name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM event_categories ORDER BY category_type, category_name")->fetchAll(PDO::FETCH_ASSOC);
$participants = $pdo->query("SELECT p.id AS participant_id, p.name, d.name AS department FROM participants p JOIN department d ON p.department_id = d.id ORDER BY d.id ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct actions for filter dropdown (safe if table missing)
try {
    $actionsList = $pdo->query("SELECT DISTINCT action FROM event_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $actionsList = [];
}

// Detect whether `level` column exists and provide level options
try {
    $col = $pdo->query("SHOW COLUMNS FROM event_logs LIKE 'level'");
    $hasLevel = ($col && $col->rowCount() > 0);
} catch (PDOException $e) {
    $hasLevel = false;
}
$levelsList = ['debug','info','warn','error'];

// Fetch all Events to manage
$events_list = $pdo->query("
    SELECT e.event_id, e.title, e.category_id, p.id AS publish_id,
           DATE_FORMAT(e.start_date, '%b %d, %Y') as formatted_date, 
           TIME_FORMAT(e.start_time, '%h:%i %p') as formatted_time, 
           c.category_name, p.status 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    ORDER BY e.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Logs: grouped summary to avoid flooding. Admin can drill down per publish_id via ?logs_for=<id> (use 0 for system/null)
$selected_logs_for = isset($_GET['logs_for']) ? $_GET['logs_for'] : null;

// Filters from query string
$filter_user = (isset($_GET['filter_user']) && $_GET['filter_user'] !== '') ? (int) $_GET['filter_user'] : null;
$filter_action = (isset($_GET['filter_action']) && $_GET['filter_action'] !== '') ? trim($_GET['filter_action']) : null;
$filter_from = (isset($_GET['filter_from']) && $_GET['filter_from'] !== '') ? $_GET['filter_from'] : null;
$filter_to = (isset($_GET['filter_to']) && $_GET['filter_to'] !== '') ? $_GET['filter_to'] : null;
$filter_level = (isset($_GET['filter_level']) && $_GET['filter_level'] !== '') ? trim($_GET['filter_level']) : null;

$whereParts = [];
$params = [];
if ($filter_user) { $whereParts[] = 'l.user_id = ?'; $params[] = $filter_user; }
if ($filter_action) { $whereParts[] = 'l.action = ?'; $params[] = $filter_action; }
if ($filter_from) { $whereParts[] = 'l.created_at >= ?'; $params[] = $filter_from . ' 00:00:00'; }
if ($filter_to) { $whereParts[] = 'l.created_at <= ?'; $params[] = $filter_to . ' 23:59:59'; }
if ($filter_level && $hasLevel) { $whereParts[] = 'l.level = ?'; $params[] = $filter_level; }
// Exclude logs created by users with the Viewer role from Recent Activity
$whereParts[] = "(l.user_id IS NULL OR EXISTS (SELECT 1 FROM users uu JOIN roles rr ON uu.role_id = rr.role_id WHERE uu.user_id = l.user_id AND rr.role_name <> 'Viewer'))";
$whereSql = count($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

try {
    $sqlGroups = "SELECT l.event_publish_id AS publish_id, COUNT(*) AS cnt, MAX(l.created_at) AS last_at FROM event_logs l $whereSql GROUP BY l.event_publish_id ORDER BY last_at DESC LIMIT 50";
    $stmt_groups = $pdo->prepare($sqlGroups);
    $stmt_groups->execute($params);
    $groups_raw = $stmt_groups->fetchAll(PDO::FETCH_ASSOC);
    $log_groups = [];
    foreach ($groups_raw as $g) {
        $publish_id = $g['publish_id'];

        $rowWhere = $whereParts; // copy
        $rowParams = $params;
        if ($publish_id === null) {
            $rowWhere[] = 'l.event_publish_id IS NULL';
        } else {
            $rowWhere[] = 'l.event_publish_id = ?';
            $rowParams[] = $publish_id;
        }

        $rowSql = 'SELECT l.*, u.username, u.full_name FROM event_logs l LEFT JOIN users u ON l.user_id = u.user_id';
        if (count($rowWhere)) $rowSql .= ' WHERE ' . implode(' AND ', $rowWhere);
        $rowSql .= ' ORDER BY l.created_at DESC LIMIT 1';

        $stmt = $pdo->prepare($rowSql);
        $stmt->execute($rowParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['cnt'] = $g['cnt'];
            $row['publish_id'] = $publish_id;
            $log_groups[] = $row;
        }
    }
} catch (PDOException $e) {
    $log_groups = [];
}

// If admin requested drilldown for a group, fetch full entries for that publish_id
$selected_group_logs = [];
if ($selected_logs_for !== null) {
    try {
        // Pagination params
        $log_page = isset($_GET['log_page']) ? max(1, (int)$_GET['log_page']) : 1;
        $per_page = 50; // entries per page
        $offset = ($log_page - 1) * $per_page;
        $show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';

        $selWhere = $whereParts; // copy filters
        $selParams = $params;
        if ($selected_logs_for === '0') {
            $selWhere[] = 'l.event_publish_id IS NULL';
        } else {
            $selWhere[] = 'l.event_publish_id = ?';
            $selParams[] = (int)$selected_logs_for;
        }

        $selSqlBase = 'FROM event_logs l LEFT JOIN users u ON l.user_id = u.user_id';
        if (count($selWhere)) $selSqlBase .= ' WHERE ' . implode(' AND ', $selWhere);

        // total count for pagination
        $countSql = 'SELECT COUNT(*) ' . $selSqlBase;
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($selParams);
        $total_count = (int)$stmtCount->fetchColumn();
        $total_pages = $per_page > 0 ? (int) ceil($total_count / $per_page) : 1;

        $selSql = 'SELECT l.*, u.username, u.full_name ' . $selSqlBase . ' ORDER BY l.created_at DESC';
        if (!$show_all) {
            $selSql .= ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset;
        }

        $stmt = $pdo->prepare($selSql);
        $stmt->execute($selParams);
        $selected_group_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $selected_group_logs = [];
        $total_count = 0;
        $total_pages = 0;
        $log_page = 1;
    }
}

// Build current filter query params to preserve state when navigating
$filterQueryParams = [];
if ($filter_user) $filterQueryParams['filter_user'] = $filter_user;
if ($filter_action) $filterQueryParams['filter_action'] = $filter_action;
if ($filter_from) $filterQueryParams['filter_from'] = $filter_from;
if ($filter_to) $filterQueryParams['filter_to'] = $filter_to;
if ($filter_level && $hasLevel) $filterQueryParams['filter_level'] = $filter_level;

// Define current logged in user ID so we can't delete ourselves
$current_user_id = $_SESSION['user_id'];
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'], }, colors: { sjsfi: { green: '#004731', greenHover: '#003323', yellow: '#ffbb00' } } } } }
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

          /* Converted to Tailwind utilities: removed .bento-card and .input-premium rules to avoid conflicts.
              Card and input styles are applied inline via Tailwind utility classes for consistency. */

        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(16, 185, 129, 0.2); border-radius: 10px; }
    </style>

    <script>
        const usersData = <?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const venuesData = <?php echo json_encode($venues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const categoriesData = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const participantsData = <?php echo json_encode($participants, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const eventsData = <?php echo json_encode($events_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const logGroupsData = <?php
            $log_groups_json = array_map(function($g) {
                return [
                    'publish_id' => $g['publish_id'],
                    'action'     => $g['action'] ?? '',
                    'level'      => $g['level'] ?? '',
                    'cnt'        => intval($g['cnt']),
                    'full_name'  => $g['full_name'] ?? null,
                    'username'   => $g['username'] ?? null,
                    'created_at' => $g['created_at'] ?? '',
                    'details'    => $g['details'] ?? '',
                ];
            }, $log_groups);
            echo json_encode($log_groups_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>;
        const logFilterParams = <?php echo json_encode($filterQueryParams, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
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

            <div class="flex items-center justify-between bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm p-6 sm:p-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 flex items-center justify-center shadow-sm">
                        <i class="fa-solid fa-screwdriver-wrench text-xl text-blue-500"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">Admin Management</h1>
                        <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mt-1">Configure users, venues, categories, participants, and manage events.</p>
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

                <div class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm overflow-hidden flex flex-col h-[600px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-users text-purple-500"></i> Users
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_user">
                            <input type="text" name="full_name" placeholder="Full Name" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-3 py-2 rounded-lg text-sm font-semibold col-span-2">
                            <input type="text" name="username" placeholder="Username" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-3 py-2 rounded-lg text-sm font-semibold">
                            <input type="password" name="password" placeholder="Password" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-3 py-2 rounded-lg text-sm font-semibold">
                            <select name="role_id" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-3 py-2 rounded-lg text-sm font-bold cursor-pointer col-span-2">
                                <option value="" disabled selected>Assign Role...</option>
                                <?php foreach ($roles_list as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="col-span-2 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Add New User</button>
                        </form>
                    </div>

                    <div x-data="{ search: '', page: 1, limit: 7, items: usersData, get filtered() { return this.search === '' ? this.items : this.items.filter(i => i.full_name.toLowerCase().includes(this.search.toLowerCase()) || i.username.toLowerCase().includes(this.search.toLowerCase())); }, get paginated() { return this.filtered.slice((this.page - 1) * this.limit, this.page * this.limit); }, get maxPage() { return Math.ceil(this.filtered.length / this.limit) || 1; } }" class="flex flex-col flex-1 overflow-hidden">
                        <div class="px-5 pt-3 pb-2 border-b border-slate-100 dark:border-[#123f29]">
                            <input type="text" x-model="search" @input="page = 1" placeholder="Search user..." class="w-full border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-3 py-2 rounded-lg text-sm font-semibold">
                        </div>
                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                            <template x-for="u in paginated" :key="u.user_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29] group">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800 dark:text-white"><span x-text="u.full_name"></span> <span class="text-[10px] text-slate-400 font-medium ml-1">@<span x-text="u.username"></span></span></p>
                                        <span class="inline-block mt-1 px-2 py-0.5 bg-purple-50 dark:bg-purple-950/30 text-purple-600 dark:text-purple-400 border border-purple-100 dark:border-purple-900/50 rounded text-[10px] font-black uppercase tracking-wider" x-text="u.role_name"></span>
                                    </div>
                                    <template x-if="u.user_id != <?php echo $current_user_id; ?>">
                                        <a :href="'?delete_user=' + u.user_id" onclick="return confirm('Permanently delete this user?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                                    </template>
                                </div>
                            </template>
                            <div x-show="filtered.length === 0" class="text-center text-sm text-slate-500 py-4 italic">No users found.</div>
                        </div>
                        <div class="p-3 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] flex justify-between items-center text-xs font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}" class="px-2 py-1 hover:text-purple-600 transition">Prev</button>
                            <span x-text="'Page ' + page + ' of ' + maxPage"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}" class="px-2 py-1 hover:text-purple-600 transition">Next</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm overflow-hidden flex flex-col h-[600px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-location-dot text-amber-500"></i> Venues
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="add_venue">
                            <div class="flex gap-2">
                                <input type="text" name="venue_name" placeholder="New Venue Name" required class="flex-1 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-amber-500 focus:ring-amber-100 px-4 py-2.5 rounded-lg text-sm font-semibold">
                                <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-6 py-2.5 rounded-xl transition-all shadow-md text-sm">Add</button>
                            </div>
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <input type="checkbox" name="is_off_campus" value="1" class="w-4 h-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500">
                                <span class="text-xs font-bold text-slate-500 dark:text-slate-400 group-hover:text-amber-600 transition-colors">Is this an Off-Campus location?</span>
                            </label>
                        </form>
                    </div>

                    <div x-data="{ page: 1, limit: 7, items: venuesData, get paginated() { return this.items.slice((this.page - 1) * this.limit, this.page * this.limit); }, get maxPage() { return Math.ceil(this.items.length / this.limit) || 1; } }" class="flex flex-col flex-1 overflow-hidden">
                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                            <template x-for="v in paginated" :key="v.venue_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="v.venue_name"></p>
                                        <template x-if="v.is_off_campus == 1">
                                            <span class="inline-block mt-1 px-2 py-0.5 bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 border border-amber-100 dark:border-amber-900/50 rounded text-[10px] font-black uppercase tracking-wider italic"><i class="fa-solid fa-bus mr-1"></i> Off Campus</span>
                                        </template>
                                    </div>
                                    <a :href="'?delete_venue=' + v.venue_id" onclick="return confirm('Delete this venue?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                                </div>
                            </template>
                        </div>
                        <div class="p-3 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] flex justify-between items-center text-xs font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}" class="px-2 py-1 hover:text-amber-600 transition">Prev</button>
                            <span x-text="'Page ' + page + ' of ' + maxPage"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}" class="px-2 py-1 hover:text-amber-600 transition">Next</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm overflow-hidden flex flex-col h-[600px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-tags text-emerald-500"></i> Event Categories
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_category">
                            <input type="text" name="category_name" placeholder="Name (e.g. Mass)" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <input type="text" name="category_type" placeholder="Type (e.g. Religious)" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <button type="submit" class="col-span-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Create Category</button>
                        </form>
                    </div>

                    <div x-data="{ page: 1, limit: 7, items: categoriesData, get paginated() { return this.items.slice((this.page - 1) * this.limit, this.page * this.limit); }, get maxPage() { return Math.ceil(this.items.length / this.limit) || 1; } }" class="flex flex-col flex-1 overflow-hidden">
                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                            <template x-for="c in paginated" :key="c.category_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="c.category_name"></p>
                                        <p class="text-[10px] font-extrabold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest mt-1 opacity-70" x-text="c.category_type"></p>
                                    </div>
                                    <a :href="'?delete_category=' + c.category_id" onclick="return confirm('Delete this category?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                                </div>
                            </template>
                        </div>
                        <div class="p-3 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] flex justify-between items-center text-xs font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}" class="px-2 py-1 hover:text-emerald-600 transition">Prev</button>
                            <span x-text="'Page ' + page + ' of ' + maxPage"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}" class="px-2 py-1 hover:text-emerald-600 transition">Next</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm overflow-hidden flex flex-col h-[600px]">
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29]">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-users-viewfinder text-pink-500"></i> Participants
                        </h2>
                    </div>

                    <div class="p-5 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent">
                        <form method="POST" class="grid grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="add_participant">
                            <input type="text" name="participant_name" placeholder="Grade (e.g. Grade 11)" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-pink-500 focus:ring-pink-100 px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <input type="text" name="strand" placeholder="Strand (Optional)" class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-pink-500 focus:ring-pink-100 px-4 py-2.5 rounded-lg text-sm font-semibold">
                            <select name="department" required class="border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:border-emerald-500 focus:ring-emerald-100 px-4 py-2.5 rounded-lg text-sm font-bold cursor-pointer col-span-2">
                                <option value="" disabled selected>Select Department...</option>
                                <?php foreach ($departments_list as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="col-span-2 bg-pink-600 hover:bg-pink-700 text-white font-bold py-2.5 rounded-xl transition-all shadow-md text-sm">Add Participant Group</button>
                        </form>
                    </div>

                    <div x-data="{ page: 1, limit: 7, items: participantsData, get paginated() { return this.items.slice((this.page - 1) * this.limit, this.page * this.limit); }, get maxPage() { return Math.ceil(this.items.length / this.limit) || 1; } }" class="flex flex-col flex-1 overflow-hidden">
                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar">
                            <template x-for="p in paginated" :key="p.participant_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29]">
                                    <div>
                                        <p class="text-sm font-bold text-slate-800 dark:text-white" x-text="p.name"></p>
                                        <p class="text-[10px] font-extrabold text-pink-600 dark:text-pink-400 uppercase tracking-widest mt-1 opacity-70" x-text="p.department"></p>
                                    </div>
                                    <a :href="'?delete_participant=' + p.participant_id" onclick="return confirm('Delete this group?');" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all"><i class="fa-solid fa-trash-can text-sm"></i></a>
                                </div>
                            </template>
                        </div>
                        <div class="p-3 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] flex justify-between items-center text-xs font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}" class="px-2 py-1 hover:text-pink-600 transition">Prev</button>
                            <span x-text="'Page ' + page + ' of ' + maxPage"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}" class="px-2 py-1 hover:text-pink-600 transition">Next</button>
                        </div>
                    </div>
                </div>

                <!-- Activity Logs -->
                <div id="activity-logs" class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm overflow-hidden flex flex-col col-span-1 lg:col-span-2 mt-4 transition-all">

                    <!-- Header: title + filter bar -->
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29] rounded-t-[1.5rem]">

                        <!-- Top row: title + export/archive actions -->
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                            <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-emerald-500"></i> Recent Activity Logs
                            </h2>

                            <!-- Archive + Export Controls -->
                            <div x-data="{ showArchiveModal:false, archiveDays:90, previewCount:null, previewing:false, previewArchive(){ this.previewing=true; fetch('archive_logs.php?preview=1&days='+this.archiveDays).then(r=>{ if(!r.ok) throw r; return r.json(); }).then(j=>{ this.previewCount = j.count; this.previewing=false; this.showArchiveModal = true; }).catch(async e=>{ this.previewing=false; let text='Preview failed'; try{ const err = await e.json(); text = err.error || text; }catch(err){} alert(text); }); }, doArchive(){ window.location.href='archive_logs.php?days='+this.archiveDays; } }" class="flex flex-wrap items-center gap-2">
                                <form @submit.prevent="previewArchive()" class="flex items-center gap-2 bg-white dark:bg-[#0a1a12] px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm text-xs">
                                    <i class="fa-solid fa-box-archive text-amber-500 text-sm"></i>
                                    <span class="text-slate-500 dark:text-slate-400 font-semibold">Archive older than</span>
                                    <input type="number" name="days" x-model="archiveDays" min="1" class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 px-2 py-1 rounded-lg font-black w-14 text-center text-slate-800 dark:text-slate-200 focus:outline-none focus:border-amber-500 transition-colors">
                                    <span class="text-slate-400 dark:text-slate-500 font-medium">days</span>
                                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-3 py-1.5 rounded-lg transition-colors shadow-sm text-xs">Run</button>
                                </form>
                                <a href="export_archived_logs.php" class="flex items-center gap-1.5 bg-white dark:bg-[#0a1a12] hover:bg-slate-50 dark:hover:bg-slate-800 text-emerald-700 dark:text-emerald-400 border border-slate-200 dark:border-slate-700 font-bold py-2 px-3.5 rounded-xl transition-all shadow-sm text-xs">
                                    <i class="fa-solid fa-file-export"></i> Export
                                </a>

                                <!-- Archive Confirm Modal -->
                                <div x-show="showArchiveModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" x-transition>
                                    <div @click.away="showArchiveModal=false" class="bg-white dark:bg-[#0b1120] rounded-[1.5rem] p-8 w-full max-w-sm border border-slate-100 dark:border-slate-800 shadow-2xl text-center">
                                        <div class="w-16 h-16 bg-amber-50 dark:bg-amber-500/10 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-5 border border-amber-100 dark:border-amber-500/20">
                                            <i class="fa-solid fa-box-archive text-2xl"></i>
                                        </div>
                                        <h3 class="text-lg font-black text-slate-800 dark:text-white mb-2">Confirm Archive</h3>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">This will move <strong class="text-amber-600"><span x-text="previewCount"></span></strong> log entries older than <strong><span x-text="archiveDays"></span> days</strong> into historical archives. This action cannot be reversed.</p>
                                        <div class="flex gap-3 text-sm font-bold">
                                            <button @click="showArchiveModal=false" class="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition">Cancel</button>
                                            <button @click="doArchive()" class="flex-1 py-3 rounded-xl bg-amber-500 text-white hover:bg-amber-600 transition shadow-lg shadow-amber-500/20">Confirm Archive</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filter bar -->
                        <form method="GET" action="admin_manage.php#activity-logs" class="flex flex-wrap items-center gap-2 text-xs">
                            <div class="flex flex-wrap items-center gap-2 bg-white dark:bg-[#0a1a12] px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm flex-1 min-w-0">
                                <i class="fa-solid fa-filter text-slate-400 text-[11px]"></i>

                                <select name="filter_user" class="bg-transparent text-slate-700 dark:text-slate-300 px-1 py-1 rounded-lg font-semibold focus:outline-none max-w-[150px] cursor-pointer">
                                    <option value="">All users</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo $u['user_id']; ?>" <?php echo ($filter_user && $filter_user == $u['user_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['full_name'] . ' @' . $u['username']); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <span class="h-4 w-px bg-slate-200 dark:bg-slate-700 shrink-0"></span>

                                <select name="filter_action" class="bg-transparent text-slate-700 dark:text-slate-300 px-1 py-1 rounded-lg font-semibold focus:outline-none max-w-[140px] cursor-pointer">
                                    <option value="">All actions</option>
                                    <?php foreach ($actionsList as $action): ?>
                                        <option value="<?php echo htmlspecialchars($action); ?>" <?php echo ($filter_action && $filter_action == $action) ? 'selected' : ''; ?>><?php echo htmlspecialchars($action); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ($hasLevel): ?>
                                    <span class="h-4 w-px bg-slate-200 dark:bg-slate-700 shrink-0"></span>
                                    <select name="filter_level" class="bg-transparent text-slate-700 dark:text-slate-300 px-1 py-1 rounded-lg font-semibold focus:outline-none cursor-pointer">
                                        <option value="">All levels</option>
                                        <?php foreach ($levelsList as $lvl): ?>
                                            <option value="<?php echo $lvl; ?>" <?php echo ($filter_level && $filter_level == $lvl) ? 'selected' : ''; ?>><?php echo strtoupper($lvl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>

                                <span class="h-4 w-px bg-slate-200 dark:bg-slate-700 shrink-0"></span>

                                <div class="flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                                    <i class="fa-regular fa-calendar text-slate-400 text-[11px]"></i>
                                    <input type="date" name="filter_from" value="<?php echo htmlspecialchars($filter_from ?? ''); ?>" class="bg-transparent text-slate-600 dark:text-slate-400 font-semibold px-1 py-1 focus:outline-none max-w-[120px]">
                                    <span class="text-slate-300 dark:text-slate-700 font-light">→</span>
                                    <input type="date" name="filter_to" value="<?php echo htmlspecialchars($filter_to ?? ''); ?>" class="bg-transparent text-slate-600 dark:text-slate-400 font-semibold px-1 py-1 focus:outline-none max-w-[120px]">
                                </div>
                            </div>

                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl transition-colors shadow-md shadow-emerald-600/15 flex items-center gap-1.5 shrink-0">
                                <i class="fa-solid fa-magnifying-glass text-[11px]"></i> Apply
                            </button>
                            <a href="admin_manage.php#activity-logs" class="bg-white dark:bg-[#0a1a12] hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 border border-slate-200 dark:border-slate-700 font-bold px-3.5 py-2.5 rounded-xl transition-all shadow-sm shrink-0">Clear</a>
                        </form>
                    </div>

                    <!-- Drilldown: selected group logs -->
                    <?php if (!empty($selected_group_logs)): ?>
                    <div class="p-5 overflow-y-auto custom-scrollbar max-h-[52vh] lg:max-h-[560px]">
                        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-[#123f29]">
                            <div class="flex items-center gap-2 text-sm font-black text-slate-700 dark:text-slate-300">
                                <span class="w-2 h-2 rounded-full bg-blue-500 shrink-0"></span>
                                <span>Logs for <?php echo ($selected_logs_for === '0' ? 'System Space' : 'Publish Container <span class="font-mono text-blue-600 dark:text-blue-400">#' . htmlspecialchars($selected_logs_for) . '</span>'); ?></span>
                            </div>
                            <a href="admin_manage.php?<?php echo http_build_query($filterQueryParams); ?>#activity-logs"
                               class="flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-emerald-700 dark:hover:text-emerald-400 bg-white dark:bg-[#0a1a12] border border-slate-200 dark:border-slate-700 px-3 py-1.5 rounded-xl hover:border-emerald-200 dark:hover:border-emerald-900 transition-all shadow-sm">
                                <i class="fa-solid fa-arrow-left text-[10px]"></i> Back to summary
                            </a>
                        </div>

                        <ul class="space-y-2">
                            <?php foreach ($selected_group_logs as $log): ?>
                                <?php
                                    $isError = isset($log['level']) && $log['level'] === 'error';
                                    $isWarn  = isset($log['level']) && $log['level'] === 'warn';
                                    $borderAccent = $isError ? 'border-l-red-500' : ($isWarn ? 'border-l-amber-500' : 'border-l-emerald-500');
                                    $levelBadge = '';
                                    if ($isError) $levelBadge = '<span class="text-[9px] px-1.5 py-0.5 rounded font-black uppercase tracking-wide bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400">error</span>';
                                    elseif ($isWarn) $levelBadge = '<span class="text-[9px] px-1.5 py-0.5 rounded font-black uppercase tracking-wide bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400">warn</span>';
                                ?>
                                <li class="p-4 rounded-2xl bg-[#f8fafc] dark:bg-[#0a1a12] border border-slate-100 dark:border-[#123f29] border-l-4 <?php echo $borderAccent; ?> hover:border-blue-200 dark:hover:border-blue-900/50 transition-colors">
                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-start">
                                        <div class="md:col-span-3 min-w-0">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="text-[13px] font-extrabold text-slate-800 dark:text-white"><?php echo htmlspecialchars($log['action']); ?></span>
                                                <?php if (!empty($log['event_publish_id'])): ?>
                                                    <span class="text-[10px] font-mono text-slate-400 bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">#<?php echo htmlspecialchars($log['event_publish_id']); ?></span>
                                                <?php endif; ?>
                                                <?php echo $levelBadge; ?>
                                            </div>
                                        </div>
                                        <div class="md:col-span-6 text-xs text-slate-500 dark:text-slate-400 leading-relaxed font-medium">
                                            <?php
                                                $detailsData = json_decode($log['details'], true);
                                                if (json_last_error() === JSON_ERROR_NONE && is_array($detailsData)) {
                                                    $detailsPills = [];
                                                    foreach ($detailsData as $key => $val) {
                                                        if (is_bool($val)) $val = $val ? 'true' : 'false';
                                                        if (is_array($val)) $val = json_encode($val);
                                                        $detailsPills[] = "<span class='inline-block bg-white dark:bg-slate-900 px-1.5 py-0.5 rounded border border-slate-200/60 dark:border-slate-800 text-[11px]'><strong class='text-slate-400 dark:text-slate-500 font-semibold'>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($val) . "</span>";
                                                    }
                                                    echo '<div class="flex flex-wrap gap-1">' . implode('', $detailsPills) . '</div>';
                                                } else {
                                                    echo htmlspecialchars($log['details']);
                                                }
                                            ?>
                                        </div>
                                        <div class="md:col-span-3 flex md:flex-col items-center md:items-end justify-between md:justify-center gap-1 text-right">
                                            <div class="text-xs font-bold text-slate-700 dark:text-slate-300">
                                                <i class="fa-regular fa-user text-[11px] text-slate-400 mr-1"></i><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'System'); ?>
                                            </div>
                                            <div class="text-[10px] text-slate-400 font-medium"><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (!empty($total_pages) && $total_pages > 1): ?>
                            <div class="mt-5 pt-4 border-t border-slate-100 dark:border-[#123f29] flex items-center justify-between text-xs font-bold text-slate-500">
                                <div class="text-slate-400">Page <?php echo $log_page; ?> of <?php echo $total_pages; ?> <span class="font-normal opacity-60">(<?php echo intval($total_count); ?> items)</span></div>
                                <div class="flex items-center gap-1.5">
                                    <?php
                                        $baseParams = $filterQueryParams;
                                        $baseParams['logs_for'] = $selected_logs_for;
                                    ?>
                                    <?php if ($log_page > 1): ?>
                                        <?php $baseParams['log_page'] = $log_page - 1; $prevQuery = http_build_query($baseParams); ?>
                                        <a href="?<?php echo $prevQuery; ?>#activity-logs" class="px-3 py-1.5 rounded-xl border bg-white dark:bg-[#0a1a12] border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"><i class="fa-solid fa-chevron-left mr-1 text-[10px]"></i>Prev</a>
                                    <?php endif; ?>
                                    <?php if ($log_page < $total_pages): ?>
                                        <?php $baseParams['log_page'] = $log_page + 1; $nextQuery = http_build_query($baseParams); ?>
                                        <a href="?<?php echo $nextQuery; ?>#activity-logs" class="px-3 py-1.5 rounded-xl bg-emerald-600 text-white shadow-sm shadow-emerald-600/15 hover:bg-emerald-700 transition-colors">Next<i class="fa-solid fa-chevron-right ml-1 text-[10px]"></i></a>
                                    <?php endif; ?>
                                    <?php if ($total_count > $per_page): ?>
                                        <?php $baseParams['show_all'] = 1; $showAllQuery = http_build_query($baseParams); ?>
                                        <a href="?<?php echo $showAllQuery; ?>#activity-logs" class="px-3 py-1.5 rounded-xl border bg-white dark:bg-[#0a1a12] border-slate-200 dark:border-slate-700 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">Show all</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Alpine.js summary list -->
                    <div x-data="{
                            search: '',
                            page: 1,
                            limit: 7,
                            items: logGroupsData,
                            get filtered() {
                                if (this.search === '') return this.items;
                                var q = this.search.toLowerCase();
                                return this.items.filter(function(g) {
                                    return g.action.toLowerCase().includes(q) ||
                                        (g.full_name && g.full_name.toLowerCase().includes(q)) ||
                                        (g.username && g.username.toLowerCase().includes(q)) ||
                                        (g.publish_id !== null && String(g.publish_id).includes(q));
                                });
                            },
                            get paginated() { return this.filtered.slice((this.page - 1) * this.limit, this.page * this.limit); },
                            get maxPage() { return Math.ceil(this.filtered.length / this.limit) || 1; },
                            viewUrl(g) {
                                var pid = g.publish_id === null ? '0' : g.publish_id;
                                var p = Object.assign({}, logFilterParams, { logs_for: pid });
                                return '?' + Object.entries(p).map(function(e) { return encodeURIComponent(e[0])+'='+encodeURIComponent(e[1]); }).join('&') + '#activity-logs';
                            },
                            levelClass(level) {
                                if (level === 'error') return 'bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400';
                                if (level === 'warn')  return 'bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400';
                                return 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400';
                            },
                            borderClass(level) {
                                if (level === 'error') return 'border-l-red-500';
                                if (level === 'warn')  return 'border-l-amber-500';
                                return 'border-l-emerald-500';
                            },
                            formatDate(s) {
                                if (!s) return '';
                                var d = new Date(s.replace(' ', 'T'));
                                return d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' })
                                    + ' ' + d.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit', hour12:false });
                            }
                         }" class="flex flex-col flex-1 overflow-hidden">

                        <!-- Search bar -->
                        <div class="p-4 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent flex gap-3 items-center">
                            <div class="relative flex-1">
                                <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" x-model="search" @input="page = 1"
                                    placeholder="Search by action, user, or publish ID..."
                                    class="w-full pl-10 pr-4 py-3 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 rounded-xl text-sm font-semibold">
                            </div>
                        </div>

                        <!-- Log summary rows -->
                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar min-h-[300px] max-h-[560px]">
                            <template x-for="g in paginated" :key="g.action + '_' + g.publish_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border-l-4 border border-slate-100 dark:border-[#123f29] hover:border-emerald-200 dark:hover:border-emerald-900/50 transition-colors group"
                                     :class="borderClass(g.level)">
                                    <div class="min-w-0 flex-1 pr-3">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <p class="text-[15px] font-black text-slate-800 dark:text-white" x-text="g.action"></p>
                                            <template x-if="g.publish_id !== null">
                                                <span class="text-[10px] font-mono font-bold text-slate-400 bg-slate-100 dark:bg-slate-800/60 px-1.5 py-0.5 rounded" x-text="'#' + g.publish_id"></span>
                                            </template>
                                            <template x-if="g.publish_id === null">
                                                <span class="text-[9px] uppercase tracking-wider font-extrabold text-blue-500 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/30 border border-blue-100/30 dark:border-blue-900/30 px-1.5 py-0.5 rounded">System</span>
                                            </template>
                                            <template x-if="g.level">
                                                <span class="text-[9px] px-1.5 py-0.5 rounded font-black uppercase tracking-wide" :class="levelClass(g.level)" x-text="g.level"></span>
                                            </template>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3 mt-2">
                                            <span class="text-[11px] text-slate-500 font-bold">
                                                <i class="fa-regular fa-user text-slate-400 mr-1"></i>
                                                <span x-text="g.full_name || g.username || 'System'"></span>
                                            </span>
                                            <span class="text-[11px] text-slate-500 font-bold">
                                                <i class="fa-regular fa-clock text-slate-400 mr-1"></i>
                                                <span x-text="formatDate(g.created_at)"></span>
                                            </span>
                                            <span class="text-[10px] font-extrabold uppercase tracking-widest bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded-full" x-text="g.cnt + ' entries'"></span>
                                        </div>
                                    </div>
                                    <div class="flex items-center opacity-60 group-hover:opacity-100 transition-opacity flex-shrink-0">
                                        <a :href="viewUrl(g)"
                                           class="w-10 h-10 rounded-xl flex items-center justify-center text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-emerald-950/20 transition-all border border-transparent hover:border-emerald-100 dark:hover:border-emerald-900"
                                           title="View entries">
                                            <i class="fa-solid fa-angle-right text-sm"></i>
                                        </a>
                                    </div>
                                </div>
                            </template>

                            <div x-show="filtered.length === 0" class="flex flex-col items-center justify-center py-12 text-slate-500">
                                <i class="fa-regular fa-folder-open text-4xl mb-3 text-slate-300 dark:text-slate-600"></i>
                                <span class="font-medium">No activity logs matching your search.</span>
                            </div>
                        </div>

                        <!-- Pagination bar -->
                        <div class="p-4 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] rounded-b-[1.5rem] flex justify-between items-center text-sm font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}"
                                    class="px-4 py-2 hover:text-emerald-600 hover:bg-white dark:hover:bg-slate-800 rounded-lg transition shadow-sm">
                                <i class="fa-solid fa-chevron-left mr-1.5"></i> Prev
                            </button>
                            <span x-text="'Page ' + page + ' of ' + maxPage"
                                  class="bg-white dark:bg-slate-800 px-3 py-1 rounded border border-slate-200 dark:border-slate-700"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}"
                                    class="px-4 py-2 hover:text-emerald-600 hover:bg-white dark:hover:bg-slate-800 rounded-lg transition shadow-sm">
                                Next <i class="fa-solid fa-chevron-right ml-1.5"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#07160f] border border-slate-100 dark:border-[#123f29] rounded-[1.5rem] shadow-sm flex flex-col col-span-1 lg:col-span-2 mt-4 relative"

                     x-data="{ 
                        search: '', page: 1, limit: 7, items: eventsData, 
                        showDeleteModal: false, eventToDelete: null,
                        get filtered() { return this.search === '' ? this.items : this.items.filter(i => i.title.toLowerCase().includes(this.search.toLowerCase()) || i.category_name.toLowerCase().includes(this.search.toLowerCase())); }, 
                        get paginated() { return this.filtered.slice((this.page - 1) * this.limit, this.page * this.limit); }, 
                        get maxPage() { return Math.ceil(this.filtered.length / this.limit) || 1; } 
                     }">
                    
                    <div class="bg-slate-50/50 dark:bg-slate-900/50 p-5 border-b border-[#d1f0e0] dark:border-[#123f29] rounded-t-[1.5rem] flex justify-between items-center">
                        <h2 class="text-lg font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <i class="fa-solid fa-calendar-check text-blue-500"></i> Manage Events Database
                        </h2>
                    </div>

                    <div class="flex flex-col flex-1 overflow-hidden">
                        <div class="p-4 border-b border-slate-100 dark:border-[#123f29] bg-slate-50/30 dark:bg-transparent flex gap-3 items-center">
                            <div class="relative flex-1">
                                <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input type="text" x-model="search" @input="page = 1" placeholder="Search events by title or category name..." class="w-full pl-10 pr-4 py-3 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 rounded-xl text-sm font-semibold">
                            </div>
                        </div>

                        <div class="p-5 space-y-3 overflow-y-auto flex-1 custom-scrollbar min-h-[400px] max-h-[600px]">
                            <template x-for="ev in paginated" :key="ev.event_id">
                                <div class="flex justify-between items-center p-4 bg-[#f8fafc] dark:bg-[#0a1a12] rounded-2xl border border-slate-100 dark:border-[#123f29] hover:border-blue-200 dark:hover:border-blue-900/50 transition-colors group">
                                    <div>
                                        <p class="text-[15px] font-black text-slate-800 dark:text-white" x-text="ev.title"></p>
                                        <div class="flex flex-wrap items-center gap-3 mt-2">
                                            <span class="text-[10px] font-extrabold text-blue-600 dark:text-blue-400 uppercase tracking-widest bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded border border-blue-100 dark:border-blue-800/50" x-text="ev.category_name"></span>
                                            <span class="text-[11px] text-slate-500 font-bold"><i class="fa-regular fa-calendar text-slate-400 mr-1"></i> <span x-text="ev.formatted_date"></span></span>
                                            
                                            <template x-if="ev.formatted_time != '12:00 AM'">
                                                <span class="text-[11px] text-slate-500 font-bold"><i class="fa-regular fa-clock text-slate-400 mr-1"></i> <span x-text="ev.formatted_time"></span></span>
                                            </template>
                                            
                                            <template x-if="ev.status">
                                                <span class="text-[10px] font-bold px-2 py-1 rounded uppercase" :class="ev.status === 'Approved' ? 'bg-emerald-100 text-emerald-700 border border-emerald-200' : 'bg-amber-100 text-amber-700 border border-amber-200'" x-text="ev.status"></span>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5 opacity-60 group-hover:opacity-100 transition-opacity">
                                        <button type="button" @click.prevent="eventToDelete = ev.event_id; showDeleteModal = true" class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-all border border-transparent hover:border-red-100 dark:hover:border-red-900" title="Delete Event"><i class="fa-solid fa-trash-can text-sm"></i></button>
                                    </div>
                                </div>
                            </template>
                            <div x-show="filtered.length === 0" class="flex flex-col items-center justify-center py-12 text-slate-500">
                                <i class="fa-regular fa-folder-open text-4xl mb-3 text-slate-300 dark:text-slate-600"></i>
                                <span class="font-medium">No events found matching your search.</span>
                            </div>
                        </div>

                        <div class="p-4 border-t border-slate-100 dark:border-[#123f29] bg-slate-50/50 dark:bg-[#0a1a12] rounded-b-[1.5rem] flex justify-between items-center text-sm font-bold text-slate-600 dark:text-slate-400">
                            <button @click="if(page > 1) page--" :class="{'opacity-50 cursor-not-allowed': page === 1}" class="px-4 py-2 hover:text-blue-600 hover:bg-white dark:hover:bg-slate-800 rounded-lg transition shadow-sm"><i class="fa-solid fa-chevron-left mr-1.5"></i> Prev</button>
                            <span x-text="'Page ' + page + ' of ' + maxPage" class="bg-white dark:bg-slate-800 px-3 py-1 rounded border border-slate-200 dark:border-slate-700"></span>
                            <button @click="if(page < maxPage) page++" :class="{'opacity-50 cursor-not-allowed': page === maxPage}" class="px-4 py-2 hover:text-blue-600 hover:bg-white dark:hover:bg-slate-800 rounded-lg transition shadow-sm">Next <i class="fa-solid fa-chevron-right ml-1.5"></i></button>
                        </div>
                    </div>

                    <div x-show="showDeleteModal" style="display: none;" class="fixed inset-0 z-[100] bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4">
                        <div @click.away="showDeleteModal = false" x-show="showDeleteModal" x-transition.scale.origin.center class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-md overflow-hidden transform transition-all">
                            <div class="p-8 text-center">
                                <div class="w-20 h-20 bg-red-50 dark:bg-red-500/10 rounded-full flex items-center justify-center mx-auto mb-6 border border-red-100 dark:border-red-500/20">
                                    <i class="fa-solid fa-triangle-exclamation text-4xl text-red-500 dark:text-red-400"></i>
                                </div>
                                <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-2">Delete Event?</h3>
                                <p class="text-slate-500 dark:text-slate-400 font-medium mb-8">This action cannot be undone. Are you sure you want to permanently delete this event from the calendar?</p>
                                
                                <div class="flex gap-4">
                                    <button @click="showDeleteModal = false" class="flex-1 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-extrabold py-4 rounded-xl transition">Cancel</button>
                                    <button @click="window.location.href='?delete_event=' + eventToDelete" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-extrabold py-4 rounded-xl transition shadow-lg shadow-red-600/20">Yes, Delete</button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </main>
    <script src="../assets/js/theme_toggle.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="../assets/js/pdf_modal.js"></script>
</body>
</html>