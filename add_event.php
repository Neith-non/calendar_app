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

// Fetch all participants and their departments
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

// 2. Process Form Submission
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

        // Conflict Detection
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
            if (!$is_off_campus && $oe['venue_id'] == $venue_id) {
                $statusText = $oe['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Venue Conflict! '{$oe['title']}' {$statusText} at this venue during your selected time.";
                $hasConflict = true;
                break; 
            }

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

                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, description, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $description, $start_date, $start_time, $end_date, $end_time]);

                // --- NEW LOGIC: PROCESS CUSTOM SCHEDULE BLOCKS ---
                $custom_times = [];
                if (isset($_POST['custom_blocks']) && is_array($_POST['custom_blocks'])) {
                    foreach ($_POST['custom_blocks'] as $block) {
                        // Check if participants were assigned to this specific block
                        if (isset($block['pids']) && is_array($block['pids'])) {
                            foreach ($block['pids'] as $pid) {
                                // Store the custom time for this participant
                                $custom_times[$pid] = [
                                    'start' => !empty($block['start_time']) ? $block['start_time'] : $start_time,
                                    'end' => !empty($block['end_time']) ? $block['end_time'] : $end_time
                                ];
                            }
                        }
                    }
                }

                $stmt_link = $pdo->prepare("INSERT INTO participant_schedule (event_publish_id, participant_id, start_time, end_time) VALUES (?, ?, ?, ?)");
                
                foreach ($participant_ids as $pid) {
                    if ($is_all_day) {
                        $p_start = '00:00:00';
                        $p_end = '23:59:59';
                    } else {
                        // If they have a custom time block, use it. Otherwise, use the main event time.
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Event - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
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
            <h2 class="text-xl font-bold text-white"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?></h2>
            <p class="text-sm text-yellow-400 capitalize"><?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?></p>
        </div>
        
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="p-6 mt-auto border-t border-white/10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex justify-center items-center overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="glass-container rounded-2xl shadow-lg w-full max-w-3xl overflow-hidden my-auto">
            <div class="bg-black/20 p-6 border-b border-white/10 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-white"><i class="fa-solid fa-calendar-plus mr-3 text-yellow-400"></i>Request New Event</h2>
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

                <form action="add_event.php" method="POST" class="space-y-7" id="eventForm">

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
                            <select name="category_id" required class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none text-white bg-no-repeat bg-right-4">
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
                            <select name="venue_id" required class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none text-white bg-no-repeat bg-right-4">
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

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5 p-4 bg-black/20 rounded-lg border border-white/10">
                        <div class="col-span-full flex items-center justify-between mb-2">
                            <h3 class="font-bold text-slate-300"><i class="fa-regular fa-clock text-blue-400 mr-2"></i>Main Event Schedule</h3>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <span class="text-xs text-slate-400 mr-2 font-semibold">All-Day</span>
                                <input type="checkbox" name="is_all_day" id="is_all_day" class="sr-only peer" <?php echo isset($_POST['is_all_day']) ? 'checked' : ''; ?>>
                                <div class="w-9 h-5 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                            </label>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Start Date</label>
                                <input type="date" name="start_date" required id="main_start_date"
                                    value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2 rounded-lg text-white text-sm">
                            </div>
                            <div class="time-input-container transition-opacity duration-300">
                                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Start Time</label>
                                <input type="time" name="start_time" id="main_start_time"
                                    value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2 rounded-lg text-white text-sm time-input">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">End Date</label>
                                <input type="date" name="end_date" required id="main_end_date"
                                    value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2 rounded-lg text-white text-sm">
                            </div>
                            <div class="time-input-container transition-opacity duration-300">
                                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">End Time</label>
                                <input type="time" name="end_time" id="main_end_time" 
                                    value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2 rounded-lg text-white text-sm time-input">
                            </div>
                        </div>
                        
                        <div class="col-span-full">
                            <p id="holiday-warning" class="hidden text-red-500 text-sm mt-1.5 font-medium animate-pulse">
                                <i class="fa-solid fa-triangle-exclamation"></i> Warning: This date falls on <strong id="holiday-name"></strong>.
                            </p>
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
                                                <input type="checkbox" name="participants[]" value="<?php echo $p['participant_id']; ?>" data-name="<?php echo $p['display_name']; ?>" <?php echo $isChecked; ?>
                                                    class="participant-cb w-4 h-4 rounded border-gray-400 text-yellow-500 focus:ring-yellow-500 bg-white/10 group-hover:border-yellow-400 transition-colors">
                                                <span class="text-sm text-slate-200 group-hover:text-white transition-colors"><?php echo $p['display_name']; ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="p-4 bg-black/20 rounded-lg border border-white/10 time-input-container transition-opacity duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-300"><i class="fa-solid fa-puzzle-piece text-purple-400 mr-2"></i>Custom Schedules</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Assign specific times to subgroups. Leaves others on the main schedule.</p>
                            </div>
                            <button type="button" onclick="addScheduleBlock()" class="bg-purple-500/20 hover:bg-purple-500/40 text-purple-300 border border-purple-500/30 px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2">
                                <i class="fa-solid fa-plus"></i> Add Block
                            </button>
                        </div>
                        
                        <div id="custom-blocks-container" class="space-y-4">
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

    <div id="holidayConfirmModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden p-6 text-center">
            <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-calendar-xmark text-3xl text-red-500"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-800 mb-2">Holiday Conflict</h2>
            <p class="text-slate-600 mb-6">
                You are trying to schedule an event on <strong id="modalHolidayName" class="text-red-500"></strong>. Are you sure you want to proceed?
            </p>
            <div class="flex justify-center gap-3">
                <button type="button" onclick="closeHolidayModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg transition">Cancel</button>
                <button type="button" onclick="submitFormForce()" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition shadow-md">Yes, Add Event</button>
            </div>
        </div>
    </div>
</body>

<script>
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

    // --- NEW: CUSTOM SCHEDULE BLOCKS (+ BUTTON LOGIC) ---
    const blocksContainer = document.getElementById('custom-blocks-container');
    let blockCounter = 0;

    function addScheduleBlock() {
        blockCounter++;
        const blockId = blockCounter;
        
        // Default to main times if they exist
        const defaultStart = mainStartTimeInput.value;
        const defaultEnd = mainEndTimeInput.value;

        const blockHTML = `
            <div class="bg-black/30 border border-white/10 rounded-lg p-4 relative" id="block-${blockId}">
                <button type="button" onclick="removeBlock(${blockId})" class="absolute top-3 right-3 text-red-400 hover:text-red-300 transition-colors">
                    <i class="fa-solid fa-trash"></i>
                </button>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4 pr-6">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Block Start Time</label>
                        <input type="time" name="custom_blocks[${blockId}][start_time]" value="${defaultStart}" required 
                            onchange="validateBlockTime(this)" class="form-input-glass w-full px-3 py-1.5 rounded text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Block End Time</label>
                        <input type="time" name="custom_blocks[${blockId}][end_time]" value="${defaultEnd}" required 
                            onchange="validateBlockTime(this)" class="form-input-glass w-full px-3 py-1.5 rounded text-white text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-2">Apply this time to (Must be checked in main list):</label>
                    <div class="custom-block-participants flex flex-wrap gap-3 p-3 bg-black/20 rounded border border-white/5 min-h-[40px]" data-block-id="${blockId}">
                        </div>
                </div>
            </div>
        `;
        
        blocksContainer.insertAdjacentHTML('beforeend', blockHTML);
        updateCustomBlockParticipants(); // Populate the new block with currently checked main participants
    }

    function removeBlock(id) {
        document.getElementById(`block-${id}`).remove();
    }

    // This pushes the currently checked main participants down into the custom blocks
    function updateCustomBlockParticipants() {
        const checkedMain = Array.from(document.querySelectorAll('.participant-cb:checked')).map(cb => ({
            id: cb.value,
            name: cb.getAttribute('data-name')
        }));

        document.querySelectorAll('.custom-block-participants').forEach(container => {
            const blockId = container.getAttribute('data-block-id');
            
            // Remember which ones they already checked inside this block
            const currentlyChecked = Array.from(container.querySelectorAll('input:checked')).map(cb => cb.value);

            container.innerHTML = ''; 
            
            if (checkedMain.length === 0) {
                container.innerHTML = '<span class="text-xs text-slate-500 italic">Check participants in the main list above first.</span>';
                return;
            }

            checkedMain.forEach(p => {
                const isChecked = currentlyChecked.includes(p.id) ? 'checked' : '';
                container.innerHTML += `
                    <label class="flex items-center space-x-1.5 text-xs bg-white/5 hover:bg-white/10 px-2 py-1 rounded cursor-pointer transition-colors border border-white/5">
                        <input type="checkbox" name="custom_blocks[${blockId}][pids][]" value="${p.id}" ${isChecked} class="w-3 h-3 text-purple-500 rounded border-gray-500 bg-transparent focus:ring-purple-500">
                        <span class="text-slate-300">${p.name}</span>
                    </label>
                `;
            });
        });
    }

    // Attach listener to update blocks whenever main checkboxes change
    document.querySelectorAll('.participant-cb').forEach(cb => {
        cb.addEventListener('change', updateCustomBlockParticipants);
    });

    // --- NEW: STRICT TIME BOUNDARY VALIDATOR ---
    // --- NEW: STRICT TIME BOUNDARY VALIDATOR ---
    function formatTo12Hour(time24) {
        let [hours, minutes] = time24.split(':');
        hours = parseInt(hours, 10);
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; // the hour '0' should be '12'
        return hours + ':' + minutes + ' ' + ampm;
    }

    function validateBlockTime(inputElement) {
        const mainStart = mainStartTimeInput.value;
        const mainEnd = mainEndTimeInput.value;
        const inputTime = inputElement.value;

        if (!mainStart || !mainEnd || !inputTime) return;

        // Convert "HH:MM" string to raw minutes for easy math (e.g. "08:30" -> 510)
        const toMins = t => { const [h, m] = t.split(':'); return parseInt(h) * 60 + parseInt(m); };

        const ms = toMins(mainStart);
        const me = toMins(mainEnd);
        const val = toMins(inputTime);

        if (val < ms || val > me) {
            // Format the times to 12-hour format for the user alert
            const friendlyStart = formatTo12Hour(mainStart);
            const friendlyEnd = formatTo12Hour(mainEnd);
            
            alert(`Custom time must be within the main event hours (${friendlyStart} - ${friendlyEnd}).`);
            
            // Auto-correct it to the closest valid boundary
            if (val < ms) inputElement.value = mainStart;
            if (val > me) inputElement.value = mainEnd;
        }
    }

    // --- EXISTING LOGIC: DEPARTMENTS, ALL-DAY, HOLIDAYS ---
    document.querySelectorAll('.select-all-dept').forEach(selectAllCheckbox => {
        selectAllCheckbox.addEventListener('change', function() {
            const targetId = this.getAttribute('data-target');
            const targetContainer = document.getElementById(targetId);
            
            if (targetContainer) {
                const checkboxes = targetContainer.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateCustomBlockParticipants(); // Re-sync blocks
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

    const allDayToggle = document.getElementById('is_all_day');

    function updateTimeFields() {
        // Disables the entire time section (including the custom blocks section) if all-day is checked
        const timeContainers = document.querySelectorAll('.time-input-container');
        const timeInputs = document.querySelectorAll('.time-input');

        if (allDayToggle.checked) {
            timeContainers.forEach(container => container.classList.add('opacity-40', 'pointer-events-none'));
            timeInputs.forEach(input => { input.disabled = true; input.required = false; });
        } else {
            timeContainers.forEach(container => container.classList.remove('opacity-40', 'pointer-events-none'));
            timeInputs.forEach(input => { input.disabled = false; input.required = true; });
        }
    }

    if (allDayToggle) {
        allDayToggle.addEventListener('change', updateTimeFields);
        updateTimeFields(); 
    }

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
</html>