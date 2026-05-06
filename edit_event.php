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

// 1. GET THE EVENT ID
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}
$publish_id = (int)$_GET['id'];

// 2. FETCH EXISTING EVENT DATA
$stmt_event = $pdo->prepare("
    SELECT e.*, p.venue_id, p.status 
    FROM events e 
    JOIN event_publish p ON e.publish_id = p.id 
    WHERE p.id = ?
");
$stmt_event->execute([$publish_id]);
$current_event = $stmt_event->fetch(PDO::FETCH_ASSOC);

// SECURITY CHECK: Ensure it exists and is Pending
if (!$current_event || strtolower($current_event['status']) !== 'pending') {
    header("Location: index.php?sync_status=error&sync_msg=" . urlencode("You can only edit events that are currently Pending Approval."));
    exit;
}

// 3. FETCH EXISTING PARTICIPANTS & THEIR CUSTOM TIMES
$stmt_curr_parts = $pdo->prepare("SELECT participant_id, start_time, end_time FROM participant_schedule WHERE event_publish_id = ?");
$stmt_curr_parts->execute([$publish_id]);
$current_participants = [];
while ($row = $stmt_curr_parts->fetch(PDO::FETCH_ASSOC)) {
    $current_participants[$row['participant_id']] = [
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time']
    ];
}

// Check if current event is all-day
$is_currently_all_day = ($current_event['start_time'] == '00:00:00' && $current_event['end_time'] == '23:59:59');

// Fetch all holidays to pass to Javascript
$holidayStmt = $pdo->query("SELECT start_date, title FROM events WHERE category_id = 5");
$holidays = [];
while ($row = $holidayStmt->fetch(PDO::FETCH_ASSOC)) {
    $holidays[$row['start_date']] = $row['title'];
}
$holidaysJson = json_encode($holidays);

// Fetch Categories and Venues
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// Fetch all participants and group them by department
$partStmt = $pdo->query("
    SELECT p.id AS participant_id, p.name, d.name AS department 
    FROM participants p 
    JOIN department d ON p.department_id = d.id 
    ORDER BY d.id ASC, p.id ASC
");
$all_participants = $partStmt->fetchAll(PDO::FETCH_ASSOC);
$grouped_participants = [];
foreach ($all_participants as $p) {
    $grouped_participants[$p['department']][] = $p;
}

// --- PROCESS FORM SUBMISSION (UPDATE LOGIC) ---
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

    // RULE 1: Validation
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

        // RULE 2: Conflict Detection (Exclude current event!)
        $conflictStmt = $pdo->prepare("
            SELECT e.title, p.status, p.venue_id, p.id as publish_id
            FROM events e
            JOIN event_publish p ON e.publish_id = p.id
            WHERE p.status IN ('Approved', 'Pending') 
            AND p.id != ?  -- EXCLUDE CURRENT EVENT
            AND CONCAT(e.start_date, ' ', e.start_time) < ? 
            AND CONCAT(e.end_date, ' ', e.end_time) > ?
        ");
        $conflictStmt->execute([$publish_id, $end_datetime, $start_datetime]);
        $overlappingEvents = $conflictStmt->fetchAll();

        $hasConflict = false;

        foreach ($overlappingEvents as $oe) {
            if (!$is_off_campus && $oe['venue_id'] == $venue_id) {
                $statusText = $oe['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Venue Conflict! '{$oe['title']}' {$statusText} at this venue during your selected time.";
                $hasConflict = true;
                break; 
            }

            // Participant Conflict Check
            $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
            $partCheckStmt = $pdo->prepare("SELECT 1 FROM participant_schedule WHERE event_publish_id = ? AND participant_id IN ($placeholders) LIMIT 1");
            $params = array_merge([$oe['publish_id']], $participant_ids);
            $partCheckStmt->execute($params);
            
            if ($partCheckStmt->fetch()) {
                $statusText = $oe['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Participant Conflict! Some of your selected participants are already tied to '{$oe['title']}' which {$statusText} during this time.";
                $hasConflict = true;
                break;
            }
        }

        if (!$hasConflict) {
            try {
                $pdo->beginTransaction();

                // Step A: Update Publish Record
                $stmt_pub = $pdo->prepare("UPDATE event_publish SET venue_id = ?, title = ?, description = ? WHERE id = ?");
                $stmt_pub->execute([$venue_id, $title, $description, $publish_id]);

                // Step B: Update Event Block
                $stmt_event_upd = $pdo->prepare("UPDATE events SET category_id = ?, title = ?, description = ?, start_date = ?, start_time = ?, end_date = ?, end_time = ? WHERE publish_id = ?");
                $stmt_event_upd->execute([$category_id, $title, $description, $start_date, $start_time, $end_date, $end_time, $publish_id]);

                // Step C: Overwrite Participants and Custom Times
                $pdo->prepare("DELETE FROM participant_schedule WHERE event_publish_id = ?")->execute([$publish_id]);
                
                $stmt_link = $pdo->prepare("INSERT INTO participant_schedule (event_publish_id, participant_id, start_time, end_time) VALUES (?, ?, ?, ?)");
                
                foreach ($participant_ids as $pid) {
                    $part_start = (!empty($_POST["part_start_{$pid}"])) ? $_POST["part_start_{$pid}"] : NULL;
                    $part_end = (!empty($_POST["part_end_{$pid}"])) ? $_POST["part_end_{$pid}"] : NULL;
                    
                    $stmt_link->execute([$publish_id, $pid, $part_start, $part_end]);
                }

                $pdo->commit();

                header("Location: index.php?sync_status=success&sync_msg=" . urlencode("Event '$title' successfully updated!"));
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
    <title>Edit Pending Event - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        sjsfi: {
                            green: '#002a1d',
                            yellow: '#f3c20c',
                            'green-light': '#003d2a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(156, 163, 175, 0.3); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(71, 85, 105, 0.5); }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(156, 163, 175, 0.5); }
    </style>
</head>

<body class="bg-slate-100 dark:bg-[#0f172a] h-screen flex overflow-hidden text-slate-800 dark:text-slate-200 transition-colors duration-300">

    <aside class="w-72 bg-white dark:bg-[#0b1120] border-r border-slate-200 dark:border-slate-800 flex flex-col flex-shrink-0 z-10 transition-colors duration-300 shadow-sm relative">
        <div class="p-8 text-center border-b border-slate-200 dark:border-slate-800 relative z-10">
            <div class="w-20 h-20 mx-auto bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white dark:border-slate-700 shadow-sm transition-colors">
                <i class="fa-solid fa-user text-3xl text-slate-400 dark:text-slate-500"></i>
            </div>
            <h2 class="text-xl font-extrabold text-slate-800 dark:text-white">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-sm text-sjsfi-green dark:text-sjsfi-yellow font-bold uppercase tracking-wider mt-1">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto custom-scrollbar relative z-10">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h3 class="text-[10px] uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
                <div class="space-y-2">
                    <a href="index.php" class="w-full hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium py-2.5 px-4 rounded-xl flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="calendar.php" class="w-full hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium py-2.5 px-4 rounded-xl flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                    
                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                        <a href="request_status.php" class="w-full hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium py-2.5 px-4 rounded-xl flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                                <span class="ml-auto bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 py-0.5 px-2 rounded-full text-[10px] font-bold border border-red-200 dark:border-red-500/30">
                                    <?php echo $pendingCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php" class="w-full hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium py-2.5 px-4 rounded-xl flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                <div class="p-6">
                    <h3 class="text-[10px] uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                    <div class="space-y-3">
                        <a href="add_event.php" class="w-full bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-green-light dark:hover:bg-emerald-500 text-white font-bold py-3 px-4 rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm block text-center">
                            <i class="fa-solid fa-plus"></i> Add New Event
                        </a>
                        <a href="functions/sync_holidays.php" class="w-full bg-white dark:bg-[#111827] hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium py-3 px-4 rounded-xl transition-all flex items-center justify-center gap-2 shadow-sm block text-center border border-slate-200 dark:border-slate-700">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-6 mt-auto border-t border-slate-200 dark:border-slate-800 relative z-10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition-colors font-bold">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex justify-center items-center overflow-y-auto p-4 sm:p-6 md:p-8 relative">

        <button id="theme-toggle" class="absolute top-6 right-6 w-12 h-6 rounded-full bg-slate-300 dark:bg-slate-600 transition-colors duration-300 focus:outline-none shadow-inner border border-slate-400/20 z-50">
            <div id="theme-toggle-knob" class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow-sm transform transition-transform duration-300 flex items-center justify-center">
                <i id="theme-toggle-icon" class="fa-solid fa-sun text-[10px] text-yellow-500"></i>
            </div>
        </button>

        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-xl w-full max-w-4xl overflow-hidden my-auto border border-blue-200 dark:border-blue-900/50 flex flex-col max-h-[90vh]">

            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 sm:p-8 border-b border-blue-100 dark:border-blue-900/50 flex justify-between items-center shrink-0">
                <div>
                    <h2 class="text-2xl font-extrabold text-blue-700 dark:text-blue-400 tracking-tight flex items-center gap-3">
                        <i class="fa-solid fa-pen-to-square text-blue-500 dark:text-blue-500"></i>
                        Edit Pending Event
                    </h2>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mt-1.5 font-medium">Modify this request before the admin reviews it.</p>
                </div>
            </div>

            <div class="p-6 sm:p-8 overflow-y-auto custom-scrollbar flex-1">
                
                <?php if ($message): ?>
                    <div class="mb-6 px-4 py-3 rounded-xl border bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-900/50 text-red-600 dark:text-red-400 flex items-center gap-3 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-xl animate-pulse"></i>
                        <p class="font-bold text-sm"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="edit_event.php?id=<?php echo $publish_id; ?>" method="POST" class="space-y-8" id="eventForm">

                    <div class="space-y-5">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2">Event Title</label>
                            <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                                value="<?php echo htmlspecialchars($_POST['title'] ?? $current_event['title']); ?>"
                                class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-semibold">
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2">Event Description</label>
                            <textarea name="description" rows="2" placeholder="Optional details, instructions, or agenda..."
                                class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition resize-none font-medium"><?php echo htmlspecialchars($_POST['description'] ?? $current_event['description']); ?></textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 bg-slate-50 dark:bg-slate-900/50 p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2">Category</label>
                            <select name="category_id" required
                                class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-medium appearance-none"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em; background-repeat: no-repeat;">
                                <option value="" class="text-slate-500">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php echo ($current_event['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-2">Venue Location</label>
                            <select name="venue_id" required
                                class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-medium appearance-none"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em; background-repeat: no-repeat;">
                                <option value="" class="text-slate-500">-- Select Venue --</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?php echo $venue['venue_id']; ?>" <?php echo ($current_event['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($venue['venue_name']); ?>
                                        <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-900/50 p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                <i class="fa-regular fa-clock text-blue-500"></i> Event Schedule
                            </label>
                            
                            <label class="relative inline-flex items-center cursor-pointer bg-white dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 shadow-sm">
                                <input type="checkbox" name="is_all_day" id="is_all_day" class="sr-only peer" <?php echo $is_currently_all_day ? 'checked' : ''; ?>>
                                <div class="w-8 h-4 bg-slate-300 dark:bg-slate-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[8px] after:left-[14px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-blue-500"></div>
                                <span class="ml-3 text-xs font-bold text-slate-600 dark:text-slate-300">All-Day Event</span>
                            </label>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                            <div class="space-y-4">
                                <h3 class="font-extrabold text-slate-800 dark:text-slate-200 text-sm border-b border-slate-200 dark:border-slate-700 pb-2 flex items-center">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span> Starts
                                </h3>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-1.5">Date</label>
                                    <input type="date" name="start_date" id="start_date" required
                                        value="<?php echo $current_event['start_date']; ?>"
                                        class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500 outline-none transition font-medium">
                                </div>
                                <div class="time-input-container transition-all duration-300">
                                    <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-1.5">Time</label>
                                    <input type="time" name="start_time" id="start_time"
                                        value="<?php echo $current_event['start_time']; ?>"
                                        class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500 outline-none transition font-medium time-input">
                                </div>
                            </div>

                            <div class="space-y-4">
                                <h3 class="font-extrabold text-slate-800 dark:text-slate-200 text-sm border-b border-slate-200 dark:border-slate-700 pb-2 flex items-center">
                                    <span class="w-2 h-2 rounded-full bg-red-500 mr-2"></span> Ends
                                </h3>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-1.5">Date</label>
                                    <input type="date" name="end_date" id="end_date" required
                                        value="<?php echo $current_event['end_date']; ?>"
                                        class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-red-500 outline-none transition font-medium">
                                </div>
                                <div class="time-input-container transition-all duration-300">
                                    <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-1.5">Time</label>
                                    <input type="time" name="end_time" id="end_time" 
                                        value="<?php echo $current_event['end_time']; ?>"
                                        class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-red-500 outline-none transition font-medium time-input">
                                </div>
                            </div>
                        </div>

                        <p id="holiday-warning" class="hidden text-red-600 dark:text-red-400 text-xs mt-4 font-bold bg-red-50 dark:bg-red-900/20 p-3 rounded-xl border border-red-200 dark:border-red-900/50 flex items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation animate-pulse text-sm"></i> 
                            <span>Warning: This date range includes holiday(s): <strong id="holiday-name" class="underline"></strong>.</span>
                        </p>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest flex items-center gap-2">
                                <i class="fa-solid fa-users text-blue-500"></i> Select Participants
                            </label>
                            <span class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded">Check boxes to add to event</span>
                        </div>
                        
                        <div class="bg-white dark:bg-[#111827] rounded-2xl border border-slate-200 dark:border-slate-700 max-h-[400px] overflow-y-auto custom-scrollbar shadow-inner">
                            <?php foreach ($grouped_participants as $dept => $parts): ?>
                                <?php $dept_id = md5($dept); ?>
                                <div class="border-b border-slate-100 dark:border-slate-800 last:border-0 p-4 sm:p-5">
                                    <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-50 dark:border-slate-800/50">
                                        <h4 class="text-xs font-black text-slate-800 dark:text-slate-200 uppercase tracking-widest"><?php echo htmlspecialchars($dept); ?></h4>
                                        <label class="flex items-center space-x-1.5 cursor-pointer group bg-slate-50 dark:bg-slate-800 px-2.5 py-1 rounded-md border border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                                            <input type="checkbox" data-target="dept-<?php echo $dept_id; ?>" class="select-all-dept w-3 h-3 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 bg-white dark:bg-slate-900 transition-colors">
                                            <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-slate-200 uppercase tracking-wider transition">Select All</span>
                                        </label>
                                    </div>
                                    
                                    <div class="space-y-2 dept-group" id="dept-<?php echo $dept_id; ?>">
                                        <?php foreach ($parts as $p): ?>
                                            <?php 
                                            // Pre-fill logic for checking the box and showing custom times
                                            $isChecked = isset($current_participants[$p['participant_id']]) ? 'checked' : '';
                                            $hasCustomTime = (isset($current_participants[$p['participant_id']]) && $current_participants[$p['participant_id']]['start_time'] !== null);
                                            $pStart = $hasCustomTime ? $current_participants[$p['participant_id']]['start_time'] : '';
                                            $pEnd = $hasCustomTime ? $current_participants[$p['participant_id']]['end_time'] : '';
                                            $timeContainerClass = $hasCustomTime ? 'flex' : 'hidden';
                                            $timeToggleChecked = $hasCustomTime ? 'checked' : '';
                                            ?>
                                            <div class="p-3 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-slate-100 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-600 transition-colors">
                                                <div class="flex items-center justify-between">
                                                    <label class="flex items-center space-x-3 cursor-pointer group flex-1">
                                                        <input type="checkbox" name="participants[]" value="<?php echo $p['participant_id']; ?>" <?php echo $isChecked; ?>
                                                            class="participant-cb w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 bg-white dark:bg-slate-900 transition-colors">
                                                        <span class="text-sm font-bold text-slate-700 dark:text-slate-300 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($p['name']); ?></span>
                                                    </label>

                                                    <label class="flex items-center space-x-1.5 cursor-pointer group">
                                                        <input type="checkbox" class="toggle-custom-time sr-only peer" <?php echo $timeToggleChecked; ?>>
                                                        <div class="w-7 h-3.5 bg-slate-200 dark:bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-2.5 after:w-2.5 after:transition-all peer-checked:bg-purple-500 relative"></div>
                                                        <span class="text-[10px] font-bold text-slate-400 group-hover:text-purple-500 transition">Custom Time</span>
                                                    </label>
                                                </div>

                                                <div class="participant-time-inputs <?php echo $timeContainerClass; ?> mt-3 pl-7 items-center gap-2 flex-wrap sm:flex-nowrap">
                                                    <i class="fa-solid fa-turn-up fa-rotate-90 text-slate-300 dark:text-slate-600 mr-1 hidden sm:block"></i>
                                                    <div class="flex items-center gap-1.5 flex-1 bg-white dark:bg-slate-800 px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                                        <span class="text-[9px] font-black uppercase text-slate-400 w-10">Start</span>
                                                        <input type="time" name="part_start_<?php echo $p['participant_id']; ?>" value="<?php echo $pStart; ?>" class="w-full bg-transparent text-xs text-slate-700 dark:text-slate-300 outline-none font-medium">
                                                    </div>
                                                    <span class="text-slate-300 dark:text-slate-600 hidden sm:block">-</span>
                                                    <div class="flex items-center gap-1.5 flex-1 bg-white dark:bg-slate-800 px-2 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                                        <span class="text-[9px] font-black uppercase text-slate-400 w-10">End</span>
                                                        <input type="time" name="part_end_<?php echo $p['participant_id']; ?>" value="<?php echo $pEnd; ?>" class="w-full bg-transparent text-xs text-slate-700 dark:text-slate-300 outline-none font-medium">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </form>
            </div>
            
            <div class="bg-slate-50 dark:bg-[#111827] px-6 py-4 border-t border-slate-200 dark:border-slate-800 flex flex-col-reverse sm:flex-row gap-3 shrink-0">
                <a href="index.php" class="text-center bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold py-3 px-6 rounded-xl transition border border-slate-200 dark:border-slate-700 shadow-sm text-sm">
                    Cancel
                </a>
                <button type="button" id="submitBtn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition shadow-lg flex justify-center items-center gap-2 text-sm">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </div>
    </main>

    <div id="holidayConfirmModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 dark:border-slate-700 transform transition-all scale-95 opacity-0 text-center" id="holidayModalContent">
            
            <div class="bg-red-50 dark:bg-red-900/20 p-8 border-b border-red-100 dark:border-red-900/50">
                <div class="w-20 h-20 mx-auto bg-white dark:bg-slate-800 rounded-full flex items-center justify-center mb-4 shadow-sm border-4 border-red-100 dark:border-red-900/50">
                    <i class="fa-solid fa-calendar-xmark text-4xl text-red-500"></i>
                </div>
                <h2 class="text-2xl font-extrabold text-slate-800 dark:text-white leading-tight">Holiday Conflict</h2>
            </div>

            <div class="p-8">
                <p class="text-slate-600 dark:text-slate-400 font-medium mb-6 leading-relaxed">
                    You are trying to schedule an event during <strong id="modalHolidayName" class="text-red-500 dark:text-red-400"></strong>. Are you sure you want to proceed?
                </p>

                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <button type="button" onclick="closeHolidayModal()" class="w-full sm:w-auto px-6 py-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold rounded-xl transition shadow-sm text-sm">
                        Cancel
                    </button>
                    <button type="button" onclick="submitFormForce()" class="w-full sm:w-auto px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl transition shadow-md text-sm">
                        Yes, Add Event
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>

<script>
    // --- DARK MODE TOGGLE LOGIC ---
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeToggleKnob = document.getElementById('theme-toggle-knob');
    const themeToggleIcon = document.getElementById('theme-toggle-icon');

    function updateToggleUI() {
        if (!themeToggleKnob) return;
        if (document.documentElement.classList.contains('dark')) {
            themeToggleKnob.classList.add('translate-x-6');
            themeToggleIcon.className = 'fa-solid fa-moon text-[10px] text-slate-700';
        } else {
            themeToggleKnob.classList.remove('translate-x-6');
            themeToggleIcon.className = 'fa-solid fa-sun text-[10px] text-yellow-500';
        }
    }

    updateToggleUI();

    if(themeToggleBtn) {
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

    const holidays = <?php echo $holidaysJson; ?>;

    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.getElementById('eventForm'); 
    const submitBtn = document.getElementById('submitBtn');
    
    const modal = document.getElementById('holidayConfirmModal');
    const modalContent = document.getElementById('holidayModalContent');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 
    let conflictingHolidays = [];

    // --- ALL DAY TOGGLE LOGIC ---
    const allDayToggle = document.getElementById('is_all_day');
    const timeInputs = document.querySelectorAll('.time-input');
    const timeContainers = document.querySelectorAll('.time-input-container');

    function updateTimeFields() {
        if (allDayToggle.checked) {
            timeInputs.forEach(input => {
                input.disabled = true;
                input.required = false;
            });
            timeContainers.forEach(container => {
                container.classList.add('opacity-40', 'pointer-events-none');
            });
        } else {
            timeInputs.forEach(input => {
                input.disabled = false;
                input.required = true;
            });
            timeContainers.forEach(container => {
                container.classList.remove('opacity-40', 'pointer-events-none');
            });
        }
    }

    if (allDayToggle) {
        allDayToggle.addEventListener('change', updateTimeFields);
        updateTimeFields(); 
    }

    // --- PARTICIPANT CUSTOM TIME TOGGLE LOGIC ---
    document.querySelectorAll('.toggle-custom-time').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const timeInputsContainer = this.closest('.p-3').querySelector('.participant-time-inputs');
            const timeInputs = timeInputsContainer.querySelectorAll('input[type="time"]');
            
            if (this.checked) {
                timeInputsContainer.classList.remove('hidden');
                timeInputsContainer.classList.add('flex');
                timeInputs.forEach(input => input.required = true);
                
                const mainCheckbox = this.closest('.p-3').querySelector('.participant-cb');
                mainCheckbox.checked = true;
                checkSelectAllState(this.closest('.dept-group'));
            } else {
                timeInputsContainer.classList.add('hidden');
                timeInputsContainer.classList.remove('flex');
                timeInputs.forEach(input => {
                    input.required = false;
                    input.value = ''; 
                });
            }
        });
    });

    // --- SELECT ALL LOGIC ---
    document.querySelectorAll('.select-all-dept').forEach(selectAllCheckbox => {
        selectAllCheckbox.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const targetContainer = document.getElementById(targetId);
            
            if (targetContainer) {
                const checkboxes = targetContainer.querySelectorAll('.participant-cb');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
            }
        });
    });

    function checkSelectAllState(group) {
        if (!group) return;
        const checkboxes = group.querySelectorAll('.participant-cb');
        const selectAllCheckbox = group.previousElementSibling.querySelector('.select-all-dept');
        if (selectAllCheckbox) {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            selectAllCheckbox.checked = allChecked;
        }
    }

    document.querySelectorAll('.dept-group').forEach(group => {
        const checkboxes = group.querySelectorAll('.participant-cb');
        checkboxes.forEach(cb => {
            cb.addEventListener('change', () => checkSelectAllState(group));
        });
        
        checkSelectAllState(group);
    });

    // --- HOLIDAY CHECKER LOGIC ---
    function checkHolidayRange() {
        const start = startDateInput.value;
        const end = endDateInput.value;
        conflictingHolidays = [];

        if (start && end && start <= end) {
            let currentDate = new Date(start);
            const endDateObj = new Date(end);

            while (currentDate <= endDateObj) {
                const dateString = currentDate.toISOString().split('T')[0];
                if (holidays[dateString] && !conflictingHolidays.includes(holidays[dateString])) {
                    conflictingHolidays.push(holidays[dateString]);
                }
                currentDate.setDate(currentDate.getDate() + 1);
            }
        } else if (start) {
            Object.keys(holidays).forEach(date => {
                if (start === date && !conflictingHolidays.includes(holidays[date])) {
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
    
    checkHolidayRange();

    // --- FORM SUBMISSION ---
    submitBtn.addEventListener('click', function (e) {
        
        if (!eventForm.checkValidity()) {
            eventForm.reportValidity();
            return;
        }

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
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95', 'opacity-0');
            }, 10);
            return;
        }
        
        eventForm.submit();
    });

    function closeHolidayModal() {
        modal.classList.add('opacity-0');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }, 300);
    }

    function submitFormForce() {
        isHolidayBypassed = true; 
        eventForm.submit(); 
    }
</script>

</html>