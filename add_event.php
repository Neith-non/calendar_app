<?php
session_start();

// Check if user is logged in AND is specifically the Head Scheduler or Admin
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: index.php?error=unauthorized");
    exit;
}

require_once 'functions/database.php';
require_once 'functions/get_pending_count.php'; 

$message = '';
$msgType = 'error'; 

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

    $start_datetime = $start_date . ' ' . $start_time;
    $end_datetime = $end_date . ' ' . $end_time;

    // RULE 1: End Time must be AFTER Start Time
    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $message = "Oops! The End Date/Time must be after the Start Date/Time.";
    } else {
        // RULE 2: Conflict Detection 
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
            try {
                $pdo->beginTransaction();

                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

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
    
    <link rel="stylesheet" href="assets/css/add_event.css?v=<?php echo time(); ?>">

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
                <img src="assets/img/sjsfi_schoologo.png" alt="SJSFI Logo" class="w-full h-full object-contain rounded-full" onerror="this.outerHTML='<i class=\'fa-solid fa-graduation-cap text-sjsfi-green dark:text-emerald-500 text-3xl\'></i>'">
            </div>
            <h2 class="text-sm font-extrabold text-sjsfi-green dark:text-emerald-400 leading-tight mb-1">Saint Joseph School<br>Foundation Inc.</h2>
            <h3 class="text-xs font-bold font-chinese text-slate-400 dark:text-slate-500 tracking-widest">三寶颜忠義中學</h3>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800/50">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
                <div class="space-y-2">
                    <a href="index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-table-cells-large w-5 text-center"></i><span>Dashboard Hub</span>
                    </a>
                    <a href="calendar.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i><span>View Calendar</span>
                    </a>
                    <a href="request_status.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-clipboard-list w-5 text-center"></i><span>Event Status</span>
                        <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                            <span class="notification-dot" title="<?php echo $pendingCount; ?> Pending Requests"></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i><span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-6">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="add_event.php" class="bg-sjsfi-yellow hover:bg-yellow-400 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-sjsfi-green dark:text-white w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm shadow-sm transition-colors">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <a href="functions/sync_holidays.php" class="w-full bg-slate-800 dark:bg-slate-700 hover:bg-slate-900 dark:hover:bg-slate-600 text-white font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 shadow-sm text-sm">
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
                <div class="w-10 h-10 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-full flex items-center justify-center text-sjsfi-green dark:text-emerald-400 shrink-0 shadow-sm"><i class="fa-solid fa-user"></i></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-extrabold text-slate-800 dark:text-slate-100 leading-tight truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?></p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-sjsfi-green dark:text-emerald-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?></p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition font-bold text-sm border border-transparent hover:border-red-100 dark:hover:border-red-500/30">
                <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Secure Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex justify-center items-start overflow-y-auto p-4 sm:p-6 md:p-10 relative">

        <div class="w-full max-w-3xl mx-auto flex flex-col gap-6 mt-2 pb-12">

            <div class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 p-8 md:p-10 rounded-3xl shadow-sm relative overflow-hidden w-full">
                <div class="absolute -right-10 -top-10 w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="relative z-10">
                    <h1 class="text-4xl font-extrabold text-sjsfi-green dark:text-emerald-400 mb-3 flex items-center gap-4">
                        <i class="fa-solid fa-calendar-plus"></i> Request New Event
                    </h1>
                    <p class="text-slate-600 dark:text-slate-300 text-lg font-medium">Please fill out the form below. Large text and buttons are provided for your convenience.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="px-8 py-6 rounded-2xl border bg-red-50 dark:bg-red-500/10 border-red-300 dark:border-red-500/30 text-red-800 dark:text-red-300 flex items-start gap-5 shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation text-3xl mt-1"></i>
                    <p class="font-bold text-lg leading-relaxed"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <form action="add_event.php" method="POST" class="space-y-8" id="addEventForm">

                <div class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-3xl p-8 md:p-10 shadow-sm focus-within:border-emerald-500 transition-colors duration-300">
                    <h3 class="text-2xl text-slate-800 dark:text-white font-extrabold mb-8 flex items-center gap-3 border-b border-slate-200 dark:border-slate-700 pb-4">
                        <i class="fa-solid fa-file-lines text-emerald-500"></i> Step 1: Event Details
                    </h3>
                    
                    <div class="space-y-8">
                        <div>
                            <label class="block text-lg font-bold text-slate-800 dark:text-slate-200 mb-3">What is the name of the event?</label>
                            <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" class="input-premium w-full">
                        </div>

                        <div>
                            <label class="block text-lg font-bold text-slate-800 dark:text-slate-200 mb-3">
                                Event Description <span class="text-base text-slate-500 font-normal">(Optional)</span>
                            </label>
                            <textarea name="description" rows="4" placeholder="Type any extra instructions or details here..." class="input-premium w-full resize-none"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-3xl p-8 md:p-10 shadow-sm focus-within:border-emerald-500 transition-colors duration-300">
                    <h3 class="text-2xl text-slate-800 dark:text-white font-extrabold mb-8 flex items-center gap-3 border-b border-slate-200 dark:border-slate-700 pb-4">
                        <i class="fa-solid fa-tags text-emerald-500"></i> Step 2: Location & Category
                    </h3>
                    
                    <div class="space-y-8">
                        <div>
                            <label class="block text-lg font-bold text-slate-800 dark:text-slate-200 mb-3">Select a Category</label>
                            <div class="relative">
                                <select name="category_id" required class="input-premium w-full appearance-none bg-no-repeat cursor-pointer" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%23475569\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 1.5rem center; background-size: 1.5em;">
                                    <option value="" disabled selected>Tap here to choose...</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-lg font-bold text-slate-800 dark:text-slate-200 mb-3">Where will it be held?</label>
                            <div class="relative">
                                <select name="venue_id" required class="input-premium w-full appearance-none bg-no-repeat cursor-pointer" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%23475569\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 1.5rem center; background-size: 1.5em;">
                                    <option value="" disabled selected>Tap here to choose...</option>
                                    <?php foreach ($venues as $venue): ?>
                                        <option value="<?php echo $venue['venue_id']; ?>" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($venue['venue_name']); ?>
                                            <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="holiday-warning" class="hidden bg-amber-100 dark:bg-amber-500/20 border-2 border-amber-300 dark:border-amber-500/50 p-6 rounded-2xl shadow-sm flex items-start gap-5 transition-all">
                    <i class="fa-solid fa-umbrella-beach text-4xl text-amber-600 dark:text-amber-400 mt-1"></i>
                    <div>
                        <h4 class="text-xl font-extrabold text-amber-900 dark:text-amber-300 mb-1">Holiday Warning</h4>
                        <p class="text-lg text-amber-800 dark:text-amber-200 font-medium">The date you selected is a holiday: <strong id="holiday-name" class="font-extrabold border-b-2 border-amber-500"></strong>.</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-3xl p-8 md:p-10 shadow-sm focus-within:border-emerald-500 transition-colors duration-300">
                    <h3 class="text-2xl text-slate-800 dark:text-white font-extrabold mb-8 flex items-center gap-3 border-b border-slate-200 dark:border-slate-700 pb-4">
                        <i class="fa-regular fa-clock text-emerald-500"></i> Step 3: Date & Time
                    </h3>
                    
                    <div class="space-y-10">
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-6 rounded-2xl border border-slate-200 dark:border-slate-700">
                            <h4 class="text-xl font-extrabold text-slate-800 dark:text-white mb-6 flex items-center gap-3">
                                <i class="fa-solid fa-play text-emerald-500"></i> When does it start?
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-base font-bold text-slate-600 dark:text-slate-300 mb-2">Start Date</label>
                                    <input type="date" name="start_date" id="start_date" required value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>" class="input-premium w-full cursor-pointer">
                                </div>
                                <div>
                                    <label class="block text-base font-bold text-slate-600 dark:text-slate-300 mb-2">Start Time</label>
                                    <input type="time" name="start_time" required value="<?php echo $_POST['start_time'] ?? ''; ?>" class="input-premium w-full cursor-pointer">
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-50 dark:bg-slate-800/50 p-6 rounded-2xl border border-slate-200 dark:border-slate-700">
                            <h4 class="text-xl font-extrabold text-slate-800 dark:text-white mb-6 flex items-center gap-3">
                                <i class="fa-solid fa-stop text-rose-500"></i> When does it end?
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-base font-bold text-slate-600 dark:text-slate-300 mb-2">End Date</label>
                                    <input type="date" name="end_date" id="end_date" required value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>" class="input-premium w-full cursor-pointer">
                                </div>
                                <div>
                                    <label class="block text-base font-bold text-slate-600 dark:text-slate-300 mb-2">End Time</label>
                                    <input type="time" name="end_time" required value="<?php echo $_POST['end_time'] ?? ''; ?>" class="input-premium w-full cursor-pointer">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex flex-col sm:flex-row justify-between gap-6 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <a href="javascript:history.back()" class="text-center bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold py-5 px-10 rounded-2xl border-2 border-slate-300 dark:border-slate-600 shadow-sm text-xl transition-all">
                        <i class="fa-solid fa-arrow-left mr-2"></i> Go Back
                    </a>
                    <button type="submit" class="flex-1 bg-sjsfi-green hover:bg-sjsfi-greenHover dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white font-bold py-5 px-10 rounded-2xl shadow-lg flex justify-center items-center gap-3 text-xl transition-all border-2 border-transparent">
                        Submit Event <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>

            </form>
        </div>
    </main>

    <div id="holidayConfirmModal" class="fixed inset-0 bg-slate-900/80 hidden items-center justify-center z-50 backdrop-blur-md p-6 transition-opacity">
        <div class="bg-white dark:bg-[#0b1120] rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden border-2 border-slate-200 dark:border-slate-700">
            
            <div class="p-10 text-center space-y-6">
                <div class="w-24 h-24 mx-auto bg-amber-100 dark:bg-amber-500/20 border-4 border-amber-200 dark:border-amber-500/40 rounded-full flex items-center justify-center shadow-md">
                    <i class="fa-solid fa-umbrella-beach text-5xl text-amber-500"></i>
                </div>

                <h2 class="text-3xl font-extrabold text-slate-800 dark:text-slate-100">Holiday Notice</h2>
                <p class="text-slate-600 dark:text-slate-300 text-lg leading-relaxed">
                    You are trying to schedule an event on <strong id="modalHolidayName" class="text-slate-900 dark:text-white border-b-2 border-amber-400"></strong>.<br><br>Are you sure you want to continue?
                </p>
            </div>

            <div class="bg-slate-50 dark:bg-slate-900 px-8 py-6 border-t border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row justify-center gap-4">
                <button type="button" onclick="closeHolidayModal()" class="w-full py-4 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold rounded-2xl transition shadow-sm border-2 border-slate-300 dark:border-slate-600 text-lg">
                    No, Cancel
                </button>
                <button type="button" onclick="submitFormForce()" class="w-full py-4 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-2xl transition shadow-md border-2 border-transparent text-lg">
                    Yes, Continue
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

    // --- UX: AUTO-FILL END DATE & HOLIDAY LOGIC ---
    const holidays = <?php echo $holidaysJson; ?>;

    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.getElementById('addEventForm'); 
    const modal = document.getElementById('holidayConfirmModal');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 

    if (startDateInput) {
        startDateInput.addEventListener('change', function () {
            const selectedDate = this.value;
            
            // UX Feature: Auto-set End Date to match Start Date
            if (selectedDate && !endDateInput.value) {
                endDateInput.value = selectedDate;
            } else if (selectedDate && endDateInput.value < selectedDate) {
                endDateInput.value = selectedDate;
            }

            // Holiday Check
            if (holidays[selectedDate]) {
                holidayNameSpan.textContent = holidays[selectedDate];
                warningText.classList.remove('hidden');
            } else {
                warningText.classList.add('hidden');
            }
        });
    }

    // Intercept form submission for Holiday Warning
    eventForm.addEventListener('submit', function (e) {
        const selectedDate = startDateInput.value;

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