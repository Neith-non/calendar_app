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

// 1. Fetch Categories, Venues, and Participants
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// Fetch Participants and group them by department
$stmt_parts = $pdo->query("SELECT * FROM participants ORDER BY department ASC, name ASC, strand ASC");
$all_participants = $stmt_parts->fetchAll();
$grouped_participants = [];
foreach ($all_participants as $p) {
    // Generate the display name with the green strand tag
    $displayName = htmlspecialchars($p['name']);
    if (!empty($p['strand'])) {
        $displayName .= ' <span class="text-green-400 font-bold">(' . htmlspecialchars($p['strand']) . ')</span>';
    }
    $p['display_name'] = $displayName;
    
    $grouped_participants[$p['department']][] = $p;
}

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int) $_POST['category_id'];
    $venue_id = (int) $_POST['venue_id'];
    $participant_ids = $_POST['participants'] ?? []; // Array of selected participants

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    // NEW: Handle All-Day Events
    $is_all_day = isset($_POST['is_all_day']);
    
    if ($is_all_day) {
        $start_time = '00:00:00';
        $end_time = '23:59:59';
        // If they didn't pick an end date, make it the same as start date
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
        
        // NEW: Check if the requested venue is "Off-Campus"
        $is_off_campus = false;
        foreach ($venues as $v) {
            if ($v['venue_id'] == $venue_id && $v['is_off_campus']) {
                $is_off_campus = true;
                break;
            }
        }

        // RULE 2: Conflict Detection V2 (Venue & Participants)
        $conflictStmt = $pdo->prepare("
            SELECT e.title, p.status, p.venue_id, p.id as publish_id
            FROM events e
            JOIN event_publish p ON e.publish_id = p.id
            WHERE p.status IN ('Approved', 'Pending') 
            AND CONCAT(e.start_date, ' ', e.start_time) < ? 
            AND CONCAT(e.end_date, ' ', e.end_time) > ?
        ");
        $conflictStmt->execute([$end_datetime, $start_datetime]);
        $overlappingEvents = $conflictStmt->fetchAll();

        $hasConflict = false;

        foreach ($overlappingEvents as $oe) {
            
            // Check A: Venue Conflict (ONLY if it is NOT an off-campus venue!)
            if (!$is_off_campus && $oe['venue_id'] == $venue_id) {
                $statusText = $oe['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Venue Conflict! '{$oe['title']}' {$statusText} at this venue during your selected time.";
                $hasConflict = true;
                break; 
            }

            // Check B: Participant Conflict
            $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
            $partCheckStmt = $pdo->prepare("SELECT 1 FROM event_participants WHERE publish_id = ? AND participant_id IN ($placeholders) LIMIT 1");
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
            // ALL CLEAR! Insert the data.
            try {
                $pdo->beginTransaction();

                // Step A: Create Request
                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                // Step B: Create Calendar Block
                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, description, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $description, $start_date, $start_time, $end_date, $end_time]);

                // Step C: Link Participants
                $stmt_link = $pdo->prepare("INSERT INTO event_participants (publish_id, participant_id) VALUES (?, ?)");
                foreach ($participant_ids as $pid) {
                    $stmt_link->execute([$publish_id, $pid]);
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Event - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* Custom scrollbar for participants box */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }
    </style>
</head>

<body class="dashboard-body h-screen flex overflow-hidden">

    <aside class="w-72 glass-container flex flex-col flex-shrink-0 z-10 transition-all duration-300">
        <div class="p-8 text-center border-b border-white/10">
            <div class="w-20 h-20 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white/20">
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
                    <a href="index.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="calendar.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                    
                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                        <a href="request_status.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                                <span class="ml-auto relative flex h-3 w-3" title="<?php echo $pendingCount; ?> Pending Requests">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                <div class="p-6 border-b border-white/10">
                    <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
                    <div class="space-y-3">
                        <a href="add_event.php" class="w-full bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                            <i class="fa-solid fa-plus"></i> Add New Event
                        </a>
                        <a href="functions/sync_holidays.php" class="w-full bg-white/10 hover:bg-white/20 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-white/20">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-6 mt-auto border-t border-white/10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>
    <main class="flex-1 flex justify-center items-center overflow-y-auto p-4 sm:p-6 md:p-8">

        <div class="glass-container rounded-2xl shadow-lg w-full max-w-3xl overflow-hidden my-auto">

            <div class="bg-black/20 p-6 border-b border-white/10 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-white"><i class="fa-solid fa-calendar-plus mr-3 text-yellow-400"></i>
                        Request New Event</h2>
                    <p class="text-slate-300 text-sm mt-1">Submit a schedule for admin approval.</p>
                </div>
                <i class="fa-solid fa-clock text-4xl text-white/20"></i>
            </div>

            <div class="p-6 sm:p-8 max-h-[75vh] overflow-y-auto custom-scrollbar">
                
                <?php if ($message): ?>
                    <div class="mb-6 px-4 py-3 rounded-lg border bg-red-500/20 border-red-500/50 text-red-300 flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                        <p class="font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="add_event.php" method="POST" class="space-y-7">

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Event Title</label>
                        <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                            class="form-input-glass w-full px-4 py-2.5 rounded-lg text-white">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Event Description</label>
                        <textarea name="description" rows="2" placeholder="Optional details, instructions, or agenda..."
                            class="form-input-glass w-full px-4 py-2.5 rounded-lg resize-none text-white"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-4 bg-black/20 rounded-lg border border-white/10">
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Category</label>
                            <select name="category_id" required
                                class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4 text-white"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="" class="text-slate-800">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" class="text-slate-800" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Venue Location</label>
                            <select name="venue_id" required
                                class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4 text-white"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="" class="text-slate-800">-- Select Venue --</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?php echo $venue['venue_id']; ?>" class="text-slate-800" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($venue['venue_name']); ?>
                                        <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="p-4 bg-black/20 rounded-lg border border-white/10">
                        <label class="block text-sm font-semibold text-slate-300 mb-3"><i class="fa-solid fa-users text-emerald-400 mr-2"></i>Select Participants</label>
                        <div class="max-h-48 overflow-y-auto custom-scrollbar pr-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-6">
                            <?php foreach ($grouped_participants as $dept => $parts): ?>
                                <?php $dept_id = md5($dept); ?>
                                <div>
                                    <div class="flex items-center justify-between border-b border-white/10 mb-2 pb-1">
                                        <h4 class="text-xs font-bold text-yellow-400 uppercase"><?php echo htmlspecialchars($dept); ?></h4>
                                        <label class="flex items-center space-x-1 cursor-pointer group">
                                            <input type="checkbox" data-target="dept-<?php echo $dept_id; ?>" class="select-all-dept w-3 h-3 rounded border-gray-400 text-emerald-500 focus:ring-emerald-500 bg-white/10 transition-colors">
                                            <span class="text-[10px] text-slate-300 group-hover:text-white uppercase tracking-wider">All</span>
                                        </label>
                                    </div>
                                    <div class="space-y-1.5 dept-group" id="dept-<?php echo $dept_id; ?>">
                                        <?php foreach ($parts as $p): ?>
                                            <?php $isChecked = isset($_POST['participants']) && in_array($p['participant_id'], $_POST['participants']) ? 'checked' : ''; ?>
                                            <label class="flex items-center space-x-2 cursor-pointer group">
                                                <input type="checkbox" name="participants[]" value="<?php echo $p['participant_id']; ?>" <?php echo $isChecked; ?>
                                                    class="w-4 h-4 rounded border-gray-400 text-yellow-500 focus:ring-yellow-500 bg-white/10 group-hover:border-yellow-400 transition-colors">
                                                <span class="text-sm text-slate-200 group-hover:text-white transition-colors"><?php echo $p['display_name']; ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    
                    <div class="p-4 bg-black/20 rounded-lg border border-white/10 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-300"><i class="fa-solid fa-calendar-day text-blue-400 mr-2"></i>All-Day Event</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Toggle this if the event spans the entire day.</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_all_day" id="is_all_day" class="sr-only peer" <?php echo isset($_POST['is_all_day']) ? 'checked' : ''; ?>>
                            <div class="w-11 h-6 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500"></div>
                        </label>
                    </div>

                    <p id="holiday-warning" class="hidden text-red-500 text-sm mt-1.5 font-medium animate-pulse">
                        <i class="fa-solid fa-triangle-exclamation"></i> Warning: This date falls on <strong
                            id="holiday-name"></strong>.
                    </p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                        <div class="space-y-4">
                            <h3 class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2">
                                <i class="fa-solid fa-play text-emerald-400 mr-2"></i> Starts
                            </h3>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Start Date</label>
                                <input type="date" name="start_date" required
                                    value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg text-white">
                            </div>
                            <div class="time-input-container transition-opacity duration-300">
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Start Time</label>
                                <input type="time" name="start_time" 
                                    value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg text-white time-input">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h3 class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2">
                                <i class="fa-solid fa-stop text-red-400 mr-2"></i> Ends
                            </h3>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">End Date</label>
                                <input type="date" name="end_date" required
                                    value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg text-white">
                            </div>
                            <div class="time-input-container transition-opacity duration-300">
                                <label class="block text-sm font-semibold text-slate-300 mb-2">End Time</label>
                                <input type="time" name="end_time" value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg text-white time-input">
                            </div>
                        </div>
                    </div>

                    <div class="pt-6 mt-4 border-t border-white/10 flex flex-col-reverse sm:flex-row gap-4">
                        <a href="javascript:history.back()"
                            class="text-center bg-white/10 hover:bg-white/20 text-white font-bold py-3 px-6 rounded-lg transition-colors border border-white/20">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-3 rounded-lg transition-colors shadow-lg flex justify-center items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Submit Request
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </main>
    <div id="holidayConfirmModal"
        class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden p-6 text-center">

            <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-calendar-xmark text-3xl text-red-500"></i>
            </div>

            <h2 class="text-xl font-bold text-slate-800 mb-2">Holiday Conflict</h2>
            <p class="text-slate-600 mb-6">
                You are trying to schedule an event on <strong id="modalHolidayName" class="text-red-500"></strong>. Are
                you sure you want to proceed?
            </p>

            <div class="flex justify-center gap-3">
                <button type="button" onclick="closeHolidayModal()"
                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg transition">
                    Cancel
                </button>
                <button type="button" onclick="submitFormForce()"
                    class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition shadow-md">
                    Yes, Add Event
                </button>
            </div>
        </div>
    </div>
</body>

<script>
    const holidays = <?php echo $holidaysJson; ?>;

    const dateInput = document.querySelector('input[name="start_date"]');
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.querySelector('form'); 
    const modal = document.getElementById('holidayConfirmModal');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 

    // NEW: All-Day Toggle Javascript
    const allDayToggle = document.getElementById('is_all_day');
    const timeInputs = document.querySelectorAll('.time-input');
    const timeContainers = document.querySelectorAll('.time-input-container');

    function updateTimeFields() {
        if (allDayToggle.checked) {
            timeInputs.forEach(input => {
                input.disabled = true;
                input.required = false;
            });
            timeContainers.forEach(container => container.classList.add('opacity-40', 'pointer-events-none'));
        } else {
            timeInputs.forEach(input => {
                input.disabled = false;
                input.required = true;
            });
            timeContainers.forEach(container => container.classList.remove('opacity-40', 'pointer-events-none'));
        }
    }

    if (allDayToggle) {
        allDayToggle.addEventListener('change', updateTimeFields);
        updateTimeFields(); // Run on page load just in case it was already checked
    }

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

    document.querySelectorAll('.select-all-dept').forEach(selectAllCheckbox => {
        selectAllCheckbox.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const targetContainer = document.getElementById(targetId);
            
            if (targetContainer) {
                const checkboxes = targetContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
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

    eventForm.addEventListener('submit', function (e) {
        const selectedDate = dateInput.value;
        const checkboxes = document.querySelectorAll('input[name="participants[]"]:checked');

        if (checkboxes.length === 0) {
            e.preventDefault();
            alert("Please select at least one participant group.");
            return;
        }

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