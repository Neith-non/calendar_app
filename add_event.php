<?php
session_start();

// Check if user is logged in AND is specifically the Head Scheduler
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: calendar.php?error=unauthorized");
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

// 2. Fetch all participants and group them by your 'department' table dynamically
$partStmt = $pdo->query("
    SELECT p.id AS participant_id, p.name, d.name AS department 
    FROM participants p
    JOIN department d ON p.department_id = d.id
    ORDER BY d.id ASC, p.id ASC
");
$participantsList = $partStmt->fetchAll(PDO::FETCH_ASSOC);

$grouped_participants = [];
foreach ($participantsList as $p) {
    $p['display_name'] = htmlspecialchars($p['name']); 
    $grouped_participants[$p['department']][] = $p;
}

// 3. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int) $_POST['category_id'];
    $venue_id = (int) $_POST['venue_id'];
    $participant_ids = $_POST['participants'] ?? []; 

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    $is_all_day = isset($_POST['is_all_day']);
    
    if ($is_all_day) {
        $start_time = '00:00:00';
        $end_time = '23:59:59';
        if (empty($end_date)) {
            $end_date = $start_date;
        }
    } else {
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
    }

    $start_datetime = $start_date . ' ' . $start_time;
    $end_datetime = $end_date . ' ' . $end_time;

    // Validation
    // Validation
    if (empty($participant_ids)) {
        $message = "Oops! You must select at least one participant group.";
    } elseif (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $message = "Oops! The End Date/Time must be after the Start Date/Time.";
    } else {
        
        $is_off_campus = false;
        foreach ($venues as $v) {
            if ($v['venue_id'] == $venue_id && $v['is_off_campus']) {
                $is_off_campus = true;
                break;
            }
        }

        // --- 1. EXTRACT CUSTOM TIMES EARLY ---
        // We need to know everyone's custom time before we can check for conflicts!
        $custom_times = [];
        if (isset($_POST['custom_blocks']) && is_array($_POST['custom_blocks'])) {
            foreach ($_POST['custom_blocks'] as $block) {
                if (isset($block['pids']) && is_array($block['pids'])) {
                    foreach ($block['pids'] as $pid) {
                        $custom_times[$pid] = [
                            'start' => !empty($block['start_time']) ? $block['start_time'] : $start_time,
                            'end' => !empty($block['end_time']) ? $block['end_time'] : $end_time
                        ];
                    }
                }
            }
        }

        $hasConflict = false;

        // --- 2. SMART VENUE CHECKER ---
        if (!$is_off_campus) {
            $venueConflictStmt = $pdo->prepare("
                SELECT e.title, p.status
                FROM events e
                JOIN event_publish p ON e.publish_id = p.id
                WHERE p.status IN ('Approved', 'Pending') 
                AND p.venue_id = ?
                AND CONCAT(e.start_date, ' ', e.start_time) < ? 
                AND CONCAT(e.end_date, ' ', e.end_time) > ?
                LIMIT 1
            ");
            $venueConflictStmt->execute([$venue_id, $end_datetime, $start_datetime]);
            if ($venueConflict = $venueConflictStmt->fetch()) {
                $statusText = $venueConflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Venue Conflict! '{$venueConflict['title']}' {$statusText} at this venue during your selected time.";
                $hasConflict = true;
            }
        }

        // --- 3. SMART PARTICIPANT CHECKER ---
        // Now checks the specific participant's custom schedule against the database!
        if (!$hasConflict) {
            $partConflictStmt = $pdo->prepare("
                SELECT e.title, pub.status, p.name 
                FROM participant_schedule ps
                JOIN event_publish pub ON ps.event_publish_id = pub.id
                JOIN events e ON pub.id = e.publish_id
                JOIN participants p ON ps.participant_id = p.id
                WHERE ps.participant_id = ?
                AND pub.status IN ('Approved', 'Pending')
                AND CONCAT(e.start_date, ' ', ps.start_time) < ?
                AND CONCAT(e.end_date, ' ', ps.end_time) > ?
                LIMIT 1
            ");

            foreach ($participant_ids as $pid) {
                // Calculate the exact time this participant is being scheduled for
                if ($is_all_day) {
                    $p_start = '00:00:00';
                    $p_end = '23:59:59';
                } else {
                    if (isset($custom_times[$pid])) {
                        $p_start = $custom_times[$pid]['start'];
                        $p_end = $custom_times[$pid]['end'];
                    } else {
                        $p_start = $start_time;
                        $p_end = $end_time;
                    }
                }

                $p_start_datetime = $start_date . ' ' . $p_start;
                $p_end_datetime = $end_date . ' ' . $p_end;

                // Test their custom time against the database
                $partConflictStmt->execute([$pid, $p_end_datetime, $p_start_datetime]);
                
                if ($partConflict = $partConflictStmt->fetch()) {
                    $statusText = $partConflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                    $message = "Participant Conflict! '{$partConflict['name']}' is already scheduled for '{$partConflict['title']}' which {$statusText} from " . date('g:i A', strtotime($p_start)) . " to " . date('g:i A', strtotime($p_end)) . ".";
                    $hasConflict = true;
                    break;
                }
            }
        }

        // --- 4. INSERT INTO DATABASE ---
        if (!$hasConflict) {
            try {
                $pdo->beginTransaction();

                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, description, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $description, $start_date, $start_time, $end_date, $end_time]);

                $stmt_link = $pdo->prepare("INSERT INTO participant_schedule (event_publish_id, participant_id, start_time, end_time) VALUES (?, ?, ?, ?)");
                
                foreach ($participant_ids as $pid) {
                    if ($is_all_day) {
                        $p_start = '00:00:00';
                        $p_end = '23:59:59';
                    } else {
                        if (isset($custom_times[$pid])) {
                            $p_start = $custom_times[$pid]['start'];
                            $p_end = $custom_times[$pid]['end'];
                        } else {
                            $p_start = $start_time;
                            $p_end = $end_time;
                        }
                    }
                    $stmt_link->execute([$publish_id, $pid, $p_start, $p_end]);
                }

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
        .dark .nav-item.active { background-color: #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }

        .input-premium {
            background-color: #f8faf9; border: 1px solid #e2e8f0; color: #0f172a; transition: all 0.2s ease;
        }
        .input-premium:focus {
            background-color: #ffffff; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); outline: none;
        }
        .dark .input-premium {
            background-color: rgba(15, 23, 42, 0.6); border-color: #334155; color: #f1f5f9;
        }
        .dark .input-premium:focus {
            background-color: #0f172a; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
        }

        .dark ::-webkit-scrollbar-thumb { background-color: #334155; }
        .dark ::-webkit-scrollbar-track { background-color: #0f172a; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.05); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(0, 0, 0, 0.3); }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }
        
        .sr-only-custom { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0; }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <?php include 'includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 lg:p-10 relative custom-scrollbar">

        <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full max-w-4xl mx-auto">
            <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
            <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green dark:hover:text-emerald-400 transition-colors">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="bg-white dark:bg-[#111827] rounded-[2rem] border border-slate-200 dark:border-slate-800 shadow-sm w-full max-w-4xl mx-auto overflow-hidden mt-4 sm:mt-0 flex flex-col h-auto">

            <div class="bg-slate-50 dark:bg-slate-900/50 p-6 sm:p-8 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center shrink-0">
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

            <div class="p-6 sm:p-10 overflow-y-auto custom-scrollbar flex-1">

                <?php if ($message): ?>
                    <div class="mb-8 px-5 py-4 rounded-2xl border bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 flex items-start gap-4 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-xl mt-0.5"></i>
                        <p class="font-bold text-sm leading-relaxed"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="add_event.php" method="POST" id="eventForm" class="space-y-12" x-data="{ selectedDept: '' }">

                    <div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <span class="bg-sjsfi-green dark:bg-emerald-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">1</span>
                            Event Details
                        </h3>

                        <div class="space-y-5">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Event Title</label>
                                <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                                    class="input-premium w-full px-4 py-3 rounded-lg font-medium text-sm">
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Description</label>
                                <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..."
                                    class="input-premium w-full px-4 py-3 rounded-lg font-medium text-sm resize-none"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Category</label>
                                    <select name="category_id" required
                                        class="input-premium w-full px-4 py-3 rounded-lg text-sm font-semibold appearance-none bg-no-repeat cursor-pointer"
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
                                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Venue Location</label>
                                    <select name="venue_id" required
                                        class="input-premium w-full px-4 py-3 rounded-lg text-sm font-semibold appearance-none bg-no-repeat cursor-pointer"
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
                        </div>
                    </div>

                    <div>
                        <div class="border-b border-slate-100 dark:border-slate-800 pb-4 mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                <span class="bg-sjsfi-green dark:bg-emerald-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">2</span>
                                Schedule
                            </h3>
                            
                            <label for="is_all_day" class="flex items-center gap-3 cursor-pointer bg-slate-50 dark:bg-slate-800 px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-emerald-500 transition-colors shadow-sm">
                                <span class="text-xs text-slate-600 dark:text-slate-300 font-bold uppercase tracking-wider">All-Day Event</span>
                                <div class="relative">
                                    <input type="checkbox" name="is_all_day" id="is_all_day" class="sr-only-custom peer" <?php echo isset($_POST['is_all_day']) ? 'checked' : ''; ?>>
                                    <div class="w-10 h-5 bg-slate-300 dark:bg-slate-600 rounded-full peer peer-checked:bg-emerald-500 transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-transform peer-checked:after:translate-x-5"></div>
                                </div>
                            </label>
                        </div>

                        <p id="holiday-warning" class="hidden mb-5 text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 p-3 rounded-lg text-sm font-bold shadow-sm animate-pulse">
                            <i class="fa-solid fa-triangle-exclamation mr-2"></i> Warning: This date falls on <strong id="holiday-name"></strong>.
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div class="bg-slate-50 dark:bg-slate-800/30 p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                                <h4 class="font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest text-[11px] flex items-center mb-4">
                                    <i class="fa-solid fa-play text-emerald-500 mr-2 text-sm"></i> Starts
                                </h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Date</label>
                                        <input type="date" name="start_date" required id="main_start_date"
                                            value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                                    </div>
                                    <div class="main-time-input-container transition-all duration-300 overflow-hidden">
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Time</label>
                                        <input type="time" name="start_time" id="main_start_time"
                                            value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer main-time-input">
                                    </div>
                                </div>
                            </div>

                            <div class="bg-slate-50 dark:bg-slate-800/30 p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                                <h4 class="font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest text-[11px] flex items-center mb-4">
                                    <i class="fa-solid fa-stop text-rose-500 mr-2 text-sm"></i> Ends
                                </h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Date</label>
                                        <input type="date" name="end_date" required id="main_end_date"
                                            value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                                    </div>
                                    <div class="main-time-input-container transition-all duration-300 overflow-hidden">
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Time</label>
                                        <input type="time" name="end_time" id="main_end_time" 
                                            value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer main-time-input">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                <span class="bg-sjsfi-green dark:bg-emerald-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">3</span>
                                Participants & Routing
                            </h3>
                        </div>

                        <div class="mb-6 bg-slate-50 dark:bg-slate-800/30 p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2"><i class="fa-solid fa-sitemap text-emerald-500 mr-2"></i>Select Department Category</label>
                            
                            <select x-model="selectedDept" class="input-premium w-full px-4 py-3 rounded-lg text-sm font-semibold appearance-none bg-no-repeat cursor-pointer"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%2364748b\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="" disabled selected>-- Select Department Category --</option>
                                <option value="all">Show All Departments</option>
                                <?php foreach (array_keys($grouped_participants) as $deptName): ?>
                                    <option value="<?php echo htmlspecialchars($deptName); ?>">
                                        <?php echo htmlspecialchars($deptName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div x-show="selectedDept === ''" class="mb-8 text-center py-10 bg-slate-50 dark:bg-slate-800/20 rounded-2xl border border-dashed border-slate-300 dark:border-slate-700">
                            <i class="fa-solid fa-users text-4xl text-slate-300 dark:text-slate-600 mb-3"></i>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Please select a department category above to view participants.</p>
                        </div>

                        <div x-show="selectedDept !== ''" x-cloak class="mb-8">
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-3 pl-1">Select Specific Grades/Participants</label>
                            <div class="max-h-72 overflow-y-auto custom-scrollbar pr-3 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-6">
                                
                                <?php foreach ($grouped_participants as $dept => $parts): ?>
                                    <?php $dept_id = md5($dept); ?>
                                    
                                    <div x-show="selectedDept === 'all' || selectedDept === '<?php echo htmlspecialchars($dept, ENT_QUOTES); ?>'" 
                                         x-transition.opacity.duration.300ms
                                         class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-700 rounded-xl p-4 shadow-sm h-fit">
                                        
                                        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 mb-3 pb-2">
                                            <h4 class="text-[11px] font-extrabold text-sjsfi-green dark:text-emerald-400 uppercase tracking-widest"><?php echo htmlspecialchars($dept); ?></h4>
                                            <label class="flex items-center space-x-1.5 cursor-pointer group">
                                                <input type="checkbox" data-target="dept-<?php echo $dept_id; ?>" class="select-all-dept w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-sjsfi-green dark:text-emerald-500 focus:ring-sjsfi-green bg-slate-50 dark:bg-slate-900 transition-colors cursor-pointer">
                                                <span class="text-[10px] text-slate-500 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-white font-bold uppercase tracking-wider transition-colors">Select All</span>
                                            </label>
                                        </div>

                                        <div class="space-y-1.5 dept-group" id="dept-<?php echo $dept_id; ?>">
                                            <?php foreach ($parts as $p): ?>
                                                <?php $isChecked = isset($_POST['participants']) && in_array($p['participant_id'], $_POST['participants']) ? 'checked' : ''; ?>
                                                <label class="flex items-center space-x-3 cursor-pointer group p-1.5 rounded hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                                    <input type="checkbox" name="participants[]" value="<?php echo $p['participant_id']; ?>" data-name="<?php echo $p['display_name']; ?>" <?php echo $isChecked; ?>
                                                        class="participant-cb w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-sjsfi-yellow dark:text-emerald-500 focus:ring-sjsfi-yellow dark:focus:ring-emerald-500 bg-white dark:bg-slate-900 cursor-pointer">
                                                    <span class="text-sm text-slate-700 dark:text-slate-300 font-semibold group-hover:text-slate-900 dark:group-hover:text-white transition-colors"><?php echo $p['display_name']; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="bg-violet-50 dark:bg-violet-900/10 border border-violet-100 dark:border-violet-500/20 rounded-xl p-5">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-3">
                                <div>
                                    <h3 class="text-sm font-bold text-violet-800 dark:text-violet-300"><i class="fa-solid fa-puzzle-piece mr-2"></i>Custom Time Blocks</h3>
                                    <p class="text-xs text-violet-600/70 dark:text-violet-400/70 mt-1 font-medium">Assign specific hours to subgroups within the main event time.</p>
                                </div>
                                <button type="button" onclick="addScheduleBlock()" class="bg-white dark:bg-slate-800 hover:bg-violet-100 dark:hover:bg-slate-700 text-violet-600 dark:text-violet-400 border border-violet-200 dark:border-violet-500/30 px-4 py-2 rounded-lg text-xs font-bold transition-colors flex items-center justify-center gap-2 shadow-sm shrink-0">
                                    <i class="fa-solid fa-plus"></i> Add Block
                                </button>
                            </div>
                            
                            <div id="custom-blocks-container" class="space-y-4">
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row gap-4 pt-6 border-t border-slate-100 dark:border-slate-800">
                        <a href="javascript:history.back()"
                            class="text-center bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 font-bold py-3.5 px-8 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 shadow-sm text-sm">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 bg-sjsfi-green dark:bg-emerald-500 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-400 text-white font-bold py-3.5 rounded-xl transition-colors shadow-lg flex justify-center items-center gap-2 text-sm">
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
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed px-4 font-medium">
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

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<script>
    // --- DARK MODE TOGGLE LOGIC ---
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeToggleKnob = document.getElementById('theme-toggle-knob');
    const themeToggleIcon = document.getElementById('theme-toggle-icon');
    const themeToggleText = document.getElementById('theme-toggle-text');

    if (themeToggleBtn) {
        function updateToggleUI() {
            if (document.documentElement.classList.contains('dark')) {
                if(themeToggleKnob) themeToggleKnob.classList.add('translate-x-5');
                if(themeToggleIcon) themeToggleIcon.className = 'fa-solid fa-sun text-yellow-400';
                if(themeToggleText) themeToggleText.innerText = 'Light Mode';
            } else {
                if(themeToggleKnob) themeToggleKnob.classList.remove('translate-x-5');
                if(themeToggleIcon) themeToggleIcon.className = 'fa-solid fa-moon text-slate-400';
                if(themeToggleText) themeToggleText.innerText = 'Dark Mode';
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
    }

    // --- FORM LOGIC ---
    const holidays = <?php echo $holidaysJson; ?>;

    const startDateInput = document.getElementById('main_start_date');
    const endDateInput = document.getElementById('main_end_date');
    const mainStartTimeInput = document.getElementById('main_start_time');
    const mainEndTimeInput = document.getElementById('main_end_time');
    
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.getElementById('eventForm'); 
    const modal = document.getElementById('holidayConfirmModal');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 
    let conflictingHolidays = []; 

    // --- USER FRIENDLY ALL-DAY LOGIC ---
    const allDayToggle = document.getElementById('is_all_day');

    function updateTimeFields() {
        const mainTimeContainers = document.querySelectorAll('.main-time-input-container');
        const mainTimeInputs = document.querySelectorAll('.main-time-input');

        if (allDayToggle.checked) {
            mainTimeContainers.forEach(container => {
                container.style.height = '0px';
                container.style.opacity = '0';
                container.style.marginTop = '0px';
            });
            mainTimeInputs.forEach(input => { input.disabled = true; input.required = false; });
        } else {
            mainTimeContainers.forEach(container => {
                container.style.height = 'auto';
                container.style.opacity = '1';
                container.style.marginTop = '1rem'; 
            });
            mainTimeInputs.forEach(input => { input.disabled = false; input.required = true; });
        }
    }

    if (allDayToggle) {
        allDayToggle.addEventListener('change', updateTimeFields);
        updateTimeFields(); 
    }

    // --- CUSTOM SCHEDULE BLOCKS (+ BUTTON LOGIC) ---
    const blocksContainer = document.getElementById('custom-blocks-container');
    let blockCounter = 0;

    function addScheduleBlock() {
        blockCounter++;
        const blockId = blockCounter;
        
        const defaultStart = mainStartTimeInput && !mainStartTimeInput.disabled ? mainStartTimeInput.value : '';
        const defaultEnd = mainEndTimeInput && !mainEndTimeInput.disabled ? mainEndTimeInput.value : '';

        const blockHTML = `
            <div class="bg-white dark:bg-[#111827] border border-violet-200 dark:border-violet-800 rounded-xl p-5 relative shadow-sm" id="block-${blockId}">
                <button type="button" onclick="removeBlock(${blockId})" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 transition-colors bg-slate-50 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/10 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-trash text-xs"></i>
                </button>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5 pr-10">
                    <div>
                        <label class="block text-[10px] font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Block Start Time</label>
                        <input type="time" name="custom_blocks[${blockId}][start_time]" value="${defaultStart}" required 
                            onblur="validateBlockTime(this)" class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                    </div>
                    <div>
                        <label class="block text-[10px] font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Block End Time</label>
                        <input type="time" name="custom_blocks[${blockId}][end_time]" value="${defaultEnd}" required 
                            onblur="validateBlockTime(this)" class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2.5">Apply this time to (Must be checked in main list):</label>
                    <div class="custom-block-participants flex flex-wrap gap-2 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800 min-h-[50px]" data-block-id="${blockId}">
                    </div>
                </div>
            </div>
        `;
        
        blocksContainer.insertAdjacentHTML('beforeend', blockHTML);
        updateCustomBlockParticipants(); 
    }

    function removeBlock(id) {
        document.getElementById(`block-${id}`).remove();
    }

    function updateCustomBlockParticipants() {
        const checkedMain = Array.from(document.querySelectorAll('.participant-cb:checked')).map(cb => ({
            id: cb.value,
            name: cb.getAttribute('data-name')
        }));

        document.querySelectorAll('.custom-block-participants').forEach(container => {
            const blockId = container.getAttribute('data-block-id');
            const currentlyChecked = Array.from(container.querySelectorAll('input:checked')).map(cb => cb.value);

            container.innerHTML = ''; 
            
            if (checkedMain.length === 0) {
                container.innerHTML = '<span class="text-xs text-slate-400 dark:text-slate-500 italic font-medium">Check participants in the main list above first.</span>';
                return;
            }

            checkedMain.forEach(p => {
                const isChecked = currentlyChecked.includes(p.id) ? 'checked' : '';
                container.innerHTML += `
                    <label class="flex items-center space-x-2 text-xs bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 px-3 py-1.5 rounded-lg cursor-pointer transition-colors border border-slate-200 dark:border-slate-700 shadow-sm">
                        <input type="checkbox" name="custom_blocks[${blockId}][pids][]" value="${p.id}" ${isChecked} class="w-3.5 h-3.5 text-violet-500 rounded border-slate-300 dark:border-slate-600 focus:ring-violet-500 bg-transparent cursor-pointer">
                        <span class="text-slate-700 dark:text-slate-200 font-bold">${p.name}</span>
                    </label>
                `;
            });
        });
    }

    document.querySelectorAll('.participant-cb').forEach(cb => {
        cb.addEventListener('change', updateCustomBlockParticipants);
    });

    function formatTo12Hour(time24) {
        let [hours, minutes] = time24.split(':');
        hours = parseInt(hours, 10);
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; 
        return hours + ':' + minutes + ' ' + ampm;
    }

    function validateBlockTime(inputElement) {
        if (allDayToggle.checked) return;

        const mainStart = mainStartTimeInput.value;
        const mainEnd = mainEndTimeInput.value;
        const inputTime = inputElement.value;

        if (!mainStart || !mainEnd || !inputTime) return;

        const toMins = t => { const [h, m] = t.split(':'); return parseInt(h) * 60 + parseInt(m); };

        const ms = toMins(mainStart);
        const me = toMins(mainEnd);
        const val = toMins(inputTime);

        if (val < ms || val > me) {
            const friendlyStart = formatTo12Hour(mainStart);
            const friendlyEnd = formatTo12Hour(mainEnd);
            
            alert(`Custom time must be within the main event hours (${friendlyStart} - ${friendlyEnd}).`);
            
            if (val < ms) inputElement.value = mainStart;
            if (val > me) inputElement.value = mainEnd;
        }
    }

    // --- DEPARTMENTS & HOLIDAYS LOGIC ---
    document.querySelectorAll('.select-all-dept').forEach(selectAllCheckbox => {
        selectAllCheckbox.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const targetContainer = document.getElementById(targetId);
            
            if (targetContainer) {
                const checkboxes = targetContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateCustomBlockParticipants(); 
            }
        });
    });

    document.querySelectorAll('.dept-group').forEach(group => {
        const checkboxes = group.querySelectorAll('input[type="checkbox"]');
        const selectAllCheckbox = group.previousElementSibling.querySelector('.select-all-dept');

        checkboxes.forEach(cb => {
            cb.addEventListener('change', () => {
                const allChecked = Array.from(checkboxes).every(c => c.checked);
                selectAllCheckbox.checked = allChecked;
            });
        });
    });

    function getDatesInRange(startDate, endDate) {
        const dateArray = [];
        let currentDate = new Date(startDate);
        const lastDate = new Date(endDate);

        if (isNaN(currentDate.getTime()) || isNaN(lastDate.getTime()) || currentDate > lastDate) {
            return [startDate]; 
        }

        while (currentDate <= lastDate) {
            const yyyy = currentDate.getFullYear();
            const mm = String(currentDate.getMonth() + 1).padStart(2, '0');
            const dd = String(currentDate.getDate()).padStart(2, '0');
            dateArray.push(`${yyyy}-${mm}-${dd}`);
            currentDate.setDate(currentDate.getDate() + 1); 
        }
        return dateArray;
    }

    function checkHolidayRange() {
        const startVal = startDateInput.value;
        const endVal = endDateInput.value || startVal; 
        conflictingHolidays = []; 

        if (startVal) {
            const datesToCheck = getDatesInRange(startVal, endVal);
            datesToCheck.forEach(date => {
                if (holidays[date] && !conflictingHolidays.includes(holidays[date])) {
                    conflictingHolidays.push(holidays[date]);
                }
            });
        }

        if (conflictingHolidays.length > 0) {
            holidayNameSpan.textContent = conflictingHolidays.join(' and ');
            warningText.classList.remove('hidden');
        } else {
            warningText.classList.add('hidden');
        }
    }

    if (startDateInput) startDateInput.addEventListener('change', checkHolidayRange);
    if (endDateInput) endDateInput.addEventListener('change', checkHolidayRange);

    eventForm.addEventListener('submit', function (e) {
        const checkboxes = document.querySelectorAll('.participant-cb:checked');

        if (checkboxes.length === 0) {
            e.preventDefault();
            alert("Please select at least one participant group from the main list.");
            return;
        }

        if (conflictingHolidays.length > 0 && !isHolidayBypassed) {
            e.preventDefault(); 
            modalNameSpan.textContent = conflictingHolidays.join(' and ');
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
<script src="assets/js/pdf_modal.js"></script>
</html>