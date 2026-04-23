<?php
session_start();

// Check if user is logged in AND is specifically the Head Scheduler
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: calendar.php?error=unauthorized");
    exit;
}

// add_event.php
require_once 'functions/database.php';
require_once 'functions/get_pending_count.php'; // Added so the sidebar notification works!

$message = '';
$msgType = 'error'; // Can be 'error' or 'success'

// Fetch all holidays to pass to Javascript
$holidayStmt = $pdo->query("SELECT start_date, title FROM events WHERE category_id = 5");
$holidays = [];
while ($row = $holidayStmt->fetch(PDO::FETCH_ASSOC)) {
    $holidays[$row['start_date']] = $row['title'];
}
$holidaysJson = json_encode($holidays);

// 1. Fetch Categories and Venues
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int) $_POST['category_id'];
    $venue_id = (int) $_POST['venue_id'];

    $start_date = $_POST['start_date'];
    $start_time = $_POST['start_time'];
    $end_date = $_POST['end_date'];
    $end_time = $_POST['end_time'];

    // Combine Date and Time for easy math logic
    $start_datetime = $start_date . ' ' . $start_time;
    $end_datetime = $end_date . ' ' . $end_time;

    // RULE 1: End Time must be AFTER Start Time
    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $message = "Oops! The End Date/Time must be after the Start Date/Time.";
    } else {
        // RULE 2: Conflict Detection (The Overlap Formula)
        // Two events overlap if: (Existing Start < New End) AND (Existing End > New Start)
        $conflictStmt = $pdo->prepare("
            SELECT e.title, p.status 
            FROM events e
            JOIN event_publish p ON e.publish_id = p.id
            WHERE p.venue_id = ? 
            AND p.status IN ('Approved', 'Pending') 
            AND CONCAT(e.start_date, ' ', e.start_time) < ? 
            AND CONCAT(e.end_date, ' ', e.end_time) > ?
            LIMIT 1
        ");

        $conflictStmt->execute([$venue_id, $end_datetime, $start_datetime]);
        $conflict = $conflictStmt->fetch();

        if ($conflict) {
            $statusText = $conflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
            $message = "Venue Conflict! '{$conflict['title']}' {$statusText} at this venue during your selected time.";
        } else {
            // ALL CLEAR! Insert the data.
            try {
                $pdo->beginTransaction();

                // Step A: Create Request (Now with description!)
                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                // Step B: Create Calendar Block (Now with description!)
                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, description, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $description, $start_date, $start_time, $end_date, $end_time]);

                $pdo->commit();

                header("Location: index.php?sync_status=success&sync_msg=" . urlencode("Event '$title' successfully submitted for approval!"));
                exit();

            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = "Database Error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Event - SJSFI</title>
    
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
    <link rel="stylesheet" href="assets/css/styles.css">

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

    <style>
        body {
            color: #1e293b;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .dark body {
            color: #f1f5f9;
        }

        .nav-item {
            color: #64748b;
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            color: #004731;
            background-color: #f1f5f9;
        }
        
        .dark .nav-item {
            color: #94a3b8;
        }
        .dark .nav-item:hover {
            color: #10b981; 
            background-color: rgba(30, 41, 59, 0.5); 
        }

        .nav-item.active {
            background-color: #004731;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 71, 49, 0.15);
        }
        .dark .nav-item.active {
            background-color: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .input-premium {
            background-color: #f8faf9;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            transition: all 0.25s ease;
        }
        .input-premium:focus {
            background-color: #ffffff;
            border-color: #004731;
            box-shadow: 0 0 0 3px rgba(0, 71, 49, 0.05);
            outline: none;
        }
        .dark .input-premium {
            background-color: rgba(15, 23, 42, 0.6);
            border-color: #334155;
            color: #f1f5f9;
        }
        .dark .input-premium:focus {
            background-color: #0f172a;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .dark ::-webkit-scrollbar-thumb {
            background-color: #334155;
        }
        .dark ::-webkit-scrollbar-track {
            background-color: #0f172a;
        }
    </style>
</head>

<body class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <aside class="w-72 flex flex-col flex-shrink-0 z-20 bg-white dark:bg-[#0b1120] border-r border-slate-200 dark:border-slate-800 transition-all duration-300">
        
        <div class="p-8 text-center border-b border-slate-100 dark:border-slate-800/50">
            <div class="w-16 h-16 mx-auto bg-white dark:bg-slate-900 rounded-full flex items-center justify-center mb-4 shadow-sm border border-slate-100 dark:border-slate-700">
                <img src="assets/img/sjsfi_schoologo.png" alt="SJSFI Logo" 
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

                    <a href="index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-table-cells-large w-5 text-center"></i>
                        <span>Dashboard Hub</span>
                    </a>

                    <a href="calendar.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>

                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                        <a href="request_status.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                <div class="p-6">
                    <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                    <div class="space-y-3">
                        <a href="add_event.php" class="bg-sjsfi-green dark:bg-emerald-600 text-white w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm shadow-md transition-colors">
                            <i class="fa-solid fa-plus"></i> Add New Event
                        </a>
                        <a href="functions/sync_holidays.php" class="w-full bg-slate-800 dark:bg-slate-700 hover:bg-slate-900 dark:hover:bg-slate-600 text-white font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 shadow-sm text-sm">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                        </a>
                    </div>
                </div>
            <?php endif; ?>
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
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
                    </p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-sjsfi-green dark:text-emerald-500 truncate mt-0.5">
                        <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
                    </p>
                </div>
            </div>

            <a href="logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition font-bold text-sm border border-transparent hover:border-red-100 dark:hover:border-red-500/30">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Secure Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex justify-center items-start sm:items-center overflow-y-auto p-4 sm:p-6 md:p-10 relative">

        <div class="bg-white dark:bg-[#111827] rounded-[2rem] border border-slate-200 dark:border-slate-800 shadow-sm w-full max-w-3xl overflow-hidden mt-4 sm:mt-0">

            <div class="bg-sjsfi-light dark:bg-slate-900/50 p-6 sm:p-8 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-extrabold text-sjsfi-green dark:text-emerald-400 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center shadow-sm">
                            <i class="fa-solid fa-calendar-plus text-lg"></i>
                        </div>
                        Request New Event
                    </h2>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mt-2 font-medium ml-1">Submit a detailed schedule for administrative approval.</p>
                </div>
            </div>

            <div class="p-6 sm:p-10 max-h-[80vh] overflow-y-auto">

                <?php if ($message): ?>
                    <div class="mb-8 px-5 py-4 rounded-2xl border bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 flex items-start gap-4 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-xl mt-0.5"></i>
                        <p class="font-bold text-sm leading-relaxed"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="add_event.php" method="POST" class="space-y-8">

                    <div>
                        <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Event Title</label>
                        <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                            class="input-premium w-full px-5 py-3.5 rounded-xl font-medium text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Event Description</label>
                        <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..."
                            class="input-premium w-full px-5 py-3.5 rounded-xl font-medium text-sm resize-none"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-5 sm:p-6 bg-slate-50 dark:bg-slate-800/30 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Category</label>
                            <select name="category_id" required
                                class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold appearance-none bg-no-repeat cursor-pointer"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%2364748b\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Venue Location</label>
                            <select name="venue_id" required
                                class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold appearance-none bg-no-repeat cursor-pointer"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%2364748b\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="">-- Select Venue --</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?php echo $venue['venue_id']; ?>" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($venue['venue_name']); ?>
                                        <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <p id="holiday-warning" class="hidden text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 p-3 rounded-xl text-sm font-bold shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation mr-2"></i> Warning: This date falls on <strong id="holiday-name" class="underline decoration-amber-400/50 underline-offset-2"></strong>.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6">

                        <div class="space-y-4">
                            <h3 class="font-bold text-slate-800 dark:text-white text-base border-b border-slate-100 dark:border-slate-800 pb-2 flex items-center">
                                <i class="fa-solid fa-play text-emerald-500 mr-2 text-sm"></i> Starts
                            </h3>
                            <div>
                                <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Start Date</label>
                                <input type="date" name="start_date" required
                                    value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">Start Time</label>
                                <input type="time" name="start_time" required
                                    value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                    class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold cursor-pointer">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h3 class="font-bold text-slate-800 dark:text-white text-base border-b border-slate-100 dark:border-slate-800 pb-2 flex items-center">
                                <i class="fa-solid fa-stop text-rose-500 mr-2 text-sm"></i> Ends
                            </h3>
                            <div>
                                <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">End Date</label>
                                <input type="date" name="end_date" required
                                    value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-800 dark:text-white mb-2">End Time</label>
                                <input type="time" name="end_time" required
                                    value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                    class="input-premium w-full px-4 py-3 rounded-xl text-sm font-semibold cursor-pointer">
                            </div>
                        </div>
                    </div>

                    <div class="pt-8 mt-6 border-t border-slate-100 dark:border-slate-800 flex flex-col-reverse sm:flex-row gap-4">
                        <a href="javascript:history.back()"
                            class="text-center bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 font-bold py-3.5 px-8 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 shadow-sm text-sm">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 bg-sjsfi-yellow hover:bg-yellow-400 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-sjsfi-green dark:text-white font-bold py-3.5 rounded-xl transition-colors shadow-sm flex justify-center items-center gap-2 text-sm">
                            <i class="fa-solid fa-paper-plane"></i> Submit Request
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </main>

    <div id="holidayConfirmModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-50 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 dark:border-slate-800">
            
            <div class="p-8 text-center space-y-4">
                <div class="w-20 h-20 mx-auto bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/30 rounded-full flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-calendar-xmark text-4xl text-red-500"></i>
                </div>

                <h2 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100">Holiday Conflict</h2>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed px-4">
                    You are trying to schedule an event on <strong id="modalHolidayName" class="text-slate-800 dark:text-white border-b-2 border-red-400"></strong>.<br>Are you sure you want to proceed?
                </p>
            </div>

            <div class="bg-slate-50 dark:bg-slate-900 px-8 py-5 border-t border-slate-100 dark:border-slate-800 flex justify-center gap-3">
                <button type="button" onclick="closeHolidayModal()" class="flex-1 py-3 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold rounded-xl transition shadow-sm border border-slate-200 dark:border-slate-700 text-sm">
                    Cancel
                </button>
                <button type="button" onclick="submitFormForce()" class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl transition shadow-sm text-sm">
                    Yes, Add Event
                </button>
            </div>

        </div>
    </div>
</body>

<script>
    // --- DARK MODE TOGGLE LOGIC ---
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
        if (document.documentElement.classList.contains('dark')) {
            localStorage.setItem('color-theme', 'dark');
        } else {
            localStorage.setItem('color-theme', 'light');
        }
        updateToggleUI();
    });

    // --- HOLIDAY MODAL & FORM LOGIC ---
    const holidays = <?php echo $holidaysJson; ?>;

    const dateInput = document.querySelector('input[name="start_date"]');
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.querySelector('form'); 
    const modal = document.getElementById('holidayConfirmModal');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 

    // Listen for date changes
    if (dateInput) {
        dateInput.addEventListener('change', function () {
            const selectedDate = this.value;
            if (holidays[selectedDate]) {
                holidayNameSpan.textContent = holidays[selectedDate];
                warningText.classList.remove('hidden');
            } else {
                warningText.classList.add('hidden');
            }
        });
    }

    // Intercept the form submission
    eventForm.addEventListener('submit', function (e) {
        const selectedDate = dateInput.value;

        // If it's a holiday and they haven't clicked Yes yet...
        if (holidays[selectedDate] && !isHolidayBypassed) {
            e.preventDefault(); 
            modalNameSpan.textContent = holidays[selectedDate];
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    });

    function closeHolidayModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function submitFormForce() {
        isHolidayBypassed = true; 
        eventForm.submit(); 
    }
</script>

</html>