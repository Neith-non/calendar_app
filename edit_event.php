<?php
session_start();

// Check if user is logged in AND is specifically the Head Scheduler or Admin
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: calendar.php?error=unauthorized");
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

// SECURITY CHECK
if (!$current_event || strtolower($current_event['status']) !== 'pending') {
    header("Location: index.php?sync_status=error&sync_msg=" . urlencode("You can only edit events that are currently Pending Approval."));
    exit;
}

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

// Fetch Participants
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

// 3. FETCH EXISTING PARTICIPANTS & CUSTOM TIMES
$stmt_curr_parts = $pdo->prepare("SELECT participant_id, start_time, end_time FROM participant_schedule WHERE event_publish_id = ?");
$stmt_curr_parts->execute([$publish_id]);
$current_participants = [];
$existing_custom_times = [];

$main_start = $current_event['start_time'];
$main_end = $current_event['end_time'];

while ($row = $stmt_curr_parts->fetch(PDO::FETCH_ASSOC)) {
    $current_participants[] = $row['participant_id'];
    // Group participants who share the same custom time that differs from the main time
    if ($row['start_time'] != $main_start || $row['end_time'] != $main_end) {
        if ($row['start_time'] !== null && $row['end_time'] !== null) {
            $time_key = $row['start_time'] . '|' . $row['end_time'];
            $existing_custom_times[$time_key][] = $row['participant_id'];
        }
    }
}

// Prepare custom blocks for JS injection
$prefilled_blocks_json = json_encode($existing_custom_times);
$is_all_day = ($current_event['start_time'] == '00:00:00' && $current_event['end_time'] == '23:59:59');

// 4. Process Form Submission (UPDATE)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int) $_POST['category_id'];
    $venue_id = (int) $_POST['venue_id'];
    $participant_ids = $_POST['participants'] ?? []; 

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    $is_all_day_post = isset($_POST['is_all_day']);
    
    if ($is_all_day_post) {
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

        // Extract Custom Times
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

        // Venue Checker (Excluding Current Event)
        if (!$is_off_campus) {
            $venueConflictStmt = $pdo->prepare("
                SELECT e.title, p.status
                FROM events e
                JOIN event_publish p ON e.publish_id = p.id
                WHERE p.status IN ('Approved', 'Pending') 
                AND p.id != ?
                AND p.venue_id = ?
                AND CONCAT(e.start_date, ' ', e.start_time) < ? 
                AND CONCAT(e.end_date, ' ', e.end_time) > ?
                LIMIT 1
            ");
            $venueConflictStmt->execute([$publish_id, $venue_id, $end_datetime, $start_datetime]);
            if ($venueConflict = $venueConflictStmt->fetch()) {
                $statusText = $venueConflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                $message = "Venue Conflict! '{$venueConflict['title']}' {$statusText} at this venue during your selected time.";
                $hasConflict = true;
            }
        }

        // Participant Checker (Excluding Current Event)
        if (!$hasConflict) {
            $partConflictStmt = $pdo->prepare("
                SELECT e.title, pub.status, p.name, ps.start_time AS conflict_start, ps.end_time AS conflict_end
                FROM participant_schedule ps
                JOIN event_publish pub ON ps.event_publish_id = pub.id
                JOIN events e ON pub.id = e.publish_id
                JOIN participants p ON ps.participant_id = p.id
                WHERE ps.participant_id = ?
                AND pub.status IN ('Approved', 'Pending')
                AND pub.id != ?
                AND CONCAT(e.start_date, ' ', ps.start_time) < ?
                AND CONCAT(e.end_date, ' ', ps.end_time) > ?
                LIMIT 1
            ");

            $participantConflicts = []; 

            foreach ($participant_ids as $pid) {
                if ($is_all_day_post) {
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

                $partConflictStmt->execute([$pid, $publish_id, $p_end_datetime, $p_start_datetime]);
                
                if ($partConflict = $partConflictStmt->fetch()) {
                    $statusText = $partConflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
                    $db_start_time = date('g:i A', strtotime($partConflict['conflict_start']));
                    $db_end_time = date('g:i A', strtotime($partConflict['conflict_end']));
                    $safeName = htmlspecialchars($partConflict['name'], ENT_QUOTES, 'UTF-8');
                    $safeTitle = htmlspecialchars($partConflict['title'], ENT_QUOTES, 'UTF-8');
                    $participantConflicts[] = "<strong>{$safeName}</strong> is already scheduled for '{$safeTitle}' ({$statusText}) from {$db_start_time} to {$db_end_time}.";
                }
            }

            if (!empty($participantConflicts)) {
                $hasConflict = true;
                $message = "<strong>Participant Conflict(s) Detected:</strong><br><ul class='list-disc pl-5 mt-2 space-y-1 text-xs'>";
                foreach ($participantConflicts as $conflictMsg) {
                    $message .= "<li>{$conflictMsg}</li>";
                }
                $message .= "</ul>";
            }
        }

        // UPDATE DATABASE
        if (!$hasConflict) {
            try {
                $pdo->beginTransaction();

                $stmt_pub = $pdo->prepare("UPDATE event_publish SET venue_id = ?, title = ?, description = ? WHERE id = ?");
                $stmt_pub->execute([$venue_id, $title, $description, $publish_id]);

                $stmt_event_upd = $pdo->prepare("UPDATE events SET category_id = ?, title = ?, description = ?, start_date = ?, start_time = ?, end_date = ?, end_time = ? WHERE publish_id = ?");
                $stmt_event_upd->execute([$category_id, $title, $description, $start_date, $start_time, $end_date, $end_time, $publish_id]);

                // Wipe old participants and insert new ones
                $pdo->prepare("DELETE FROM participant_schedule WHERE event_publish_id = ?")->execute([$publish_id]);

                $stmt_link = $pdo->prepare("INSERT INTO participant_schedule (event_publish_id, participant_id, start_time, end_time) VALUES (?, ?, ?, ?)");
                
                foreach ($participant_ids as $pid) {
                    if ($is_all_day_post) {
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
    <title>Edit Event - SJSFI</title>
    
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
    
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

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
        [x-cloak] { display: none !important; }
        
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
        .input-premium:focus, .input-premium-focus {
            background-color: #ffffff; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); outline: none;
        }
        .dark .input-premium {
            background-color: rgba(15, 23, 42, 0.6); border-color: #334155; color: #f1f5f9;
        }
        .dark .input-premium:focus, .dark .input-premium-focus {
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

    <main class="flex-1 flex justify-center items-start overflow-y-auto p-4 sm:p-6 lg:p-10 relative custom-scrollbar">

        <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full max-w-4xl mx-auto">
            <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
            <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green dark:hover:text-emerald-400 transition-colors">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="bg-white dark:bg-[#111827] rounded-[2rem] border border-slate-200 dark:border-slate-800 shadow-sm w-full max-w-4xl mx-auto overflow-hidden mt-4 sm:mt-0 flex flex-col h-auto">

            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 sm:p-8 border-b border-blue-100 dark:border-blue-900/50 flex justify-between items-center shrink-0">
                <div>
                    <h2 class="text-2xl font-extrabold text-blue-600 dark:text-blue-400 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center shadow-sm">
                            <i class="fa-solid fa-pen-to-square text-lg text-blue-500"></i>
                        </div>
                        Edit Pending Event
                    </h2>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mt-2 font-medium ml-1">Make changes before the admin reviews it.</p>
                </div>
                <a href="javascript:history.back()" class="bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 font-bold py-2.5 px-4 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 shadow-sm flex items-center gap-2 text-sm shrink-0">
                    <i class="fa-solid fa-xmark"></i> <span class="hidden sm:inline">Cancel</span>
                </a>
            </div>

            <div class="p-6 sm:p-10 overflow-y-auto custom-scrollbar flex-1">

                <?php if ($message): ?>
                    <div class="mb-8 px-5 py-4 rounded-2xl border bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 flex items-start gap-4 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-xl mt-0.5"></i>
                        <div class="font-medium text-sm w-full"><?php echo $message; ?></div>
                    </div>
                <?php endif; ?>

                <form action="edit_event.php?id=<?php echo $publish_id; ?>" method="POST" id="eventForm" class="space-y-12" x-data="{ selectedDept: '' }">

                    <div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <span class="bg-blue-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">1</span>
                            Event Details
                        </h3>

                        <div class="space-y-5">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Event Title</label>
                                <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                                    value="<?php echo htmlspecialchars($_POST['title'] ?? $current_event['title']); ?>"
                                    class="input-premium w-full px-4 py-3 rounded-lg font-medium text-sm">
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Description</label>
                                <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..."
                                    class="input-premium w-full px-4 py-3 rounded-lg font-medium text-sm resize-none"><?php echo htmlspecialchars($_POST['description'] ?? $current_event['description']); ?></textarea>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Category</label>
                                    <div x-data="{ 
                                        open: false, search: '', selectedId: '<?php echo $current_event['category_id']; ?>', 
                                        selectedName: '<?php 
                                            $catName = ''; 
                                            foreach($categories as $c){ 
                                                if($c['category_id'] == $current_event['category_id']) { $catName = $c['category_name']; break; } 
                                            } 
                                            echo addslashes($catName); 
                                        ?>',
                                        options: [
                                            <?php foreach($categories as $cat): ?>
                                            { id: '<?php echo $cat['category_id']; ?>', name: '<?php echo addslashes(htmlspecialchars($cat['category_name'])); ?>' },
                                            <?php endforeach; ?>
                                        ],
                                        get filteredOptions() { return this.options.filter(opt => opt.name.toLowerCase().includes(this.search.toLowerCase())); },
                                        selectOption(opt) { this.selectedId = opt.id; this.selectedName = opt.name; this.open = false; this.search = ''; }
                                    }" class="relative">
                                        <input type="hidden" name="category_id" :value="selectedId" required>
                                        <div @click="open = !open" class="input-premium w-full px-4 py-3 rounded-lg text-sm font-semibold cursor-pointer flex justify-between items-center transition-colors" :class="{ 'input-premium-focus': open }">
                                            <span x-text="selectedName || '-- Select Category --'" :class="{'text-slate-400 dark:text-slate-500': !selectedName}"></span>
                                            <i class="fa-solid fa-chevron-down text-slate-400 dark:text-slate-500 transition-transform" :class="{'rotate-180': open}"></i>
                                        </div>
                                        <div x-show="open" @click.away="open = false" x-transition.opacity class="absolute z-50 w-full mt-2 bg-white dark:bg-[#1e293b] border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl overflow-hidden" style="display: none;">
                                            <div class="p-2 border-b border-slate-100 dark:border-slate-700">
                                                <div class="relative">
                                                    <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-xs"></i>
                                                    <input type="text" x-model="search" placeholder="Search..." class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-lg pl-8 pr-3 py-2 text-sm focus:ring-0 text-slate-700 dark:text-slate-200 outline-none">
                                                </div>
                                            </div>
                                            <ul class="max-h-48 overflow-y-auto custom-scrollbar p-1">
                                                <template x-for="opt in filteredOptions" :key="opt.id">
                                                    <li @click="selectOption(opt)" class="px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 rounded-lg cursor-pointer transition-colors" x-text="opt.name"></li>
                                                </template>
                                                <li x-show="filteredOptions.length === 0" class="px-3 py-3 text-sm text-center text-slate-400 italic">No matches found</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Venue Location</label>
                                    <div x-data="{ 
                                        open: false, search: '', selectedId: '<?php echo $current_event['venue_id']; ?>', 
                                        selectedName: '<?php 
                                            $venName = ''; 
                                            foreach($venues as $v){ 
                                                if($v['venue_id'] == $current_event['venue_id']) { $venName = $v['venue_name'] . ($v['is_off_campus'] ? ' (Off-Campus)' : ''); break; } 
                                            } 
                                            echo addslashes($venName); 
                                        ?>',
                                        options: [
                                            <?php foreach($venues as $venue): ?>
                                            { id: '<?php echo $venue['venue_id']; ?>', name: '<?php echo addslashes(htmlspecialchars($venue['venue_name'] . ($venue['is_off_campus'] ? ' (Off-Campus)' : ''))); ?>' },
                                            <?php endforeach; ?>
                                        ],
                                        get filteredOptions() { return this.options.filter(opt => opt.name.toLowerCase().includes(this.search.toLowerCase())); },
                                        selectOption(opt) { this.selectedId = opt.id; this.selectedName = opt.name; this.open = false; this.search = ''; }
                                    }" class="relative">
                                        <input type="hidden" name="venue_id" :value="selectedId" required>
                                        <div @click="open = !open" class="input-premium w-full px-4 py-3 rounded-lg text-sm font-semibold cursor-pointer flex justify-between items-center transition-colors" :class="{ 'input-premium-focus': open }">
                                            <span x-text="selectedName || '-- Select Venue --'" :class="{'text-slate-400 dark:text-slate-500': !selectedName}"></span>
                                            <i class="fa-solid fa-chevron-down text-slate-400 dark:text-slate-500 transition-transform" :class="{'rotate-180': open}"></i>
                                        </div>
                                        <div x-show="open" @click.away="open = false" x-transition.opacity class="absolute z-50 w-full mt-2 bg-white dark:bg-[#1e293b] border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl overflow-hidden" style="display: none;">
                                            <div class="p-2 border-b border-slate-100 dark:border-slate-700">
                                                <div class="relative">
                                                    <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 text-xs"></i>
                                                    <input type="text" x-model="search" placeholder="Search..." class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-lg pl-8 pr-3 py-2 text-sm focus:ring-0 text-slate-700 dark:text-slate-200 outline-none">
                                                </div>
                                            </div>
                                            <ul class="max-h-48 overflow-y-auto custom-scrollbar p-1">
                                                <template x-for="opt in filteredOptions" :key="opt.id">
                                                    <li @click="selectOption(opt)" class="px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:text-emerald-400 rounded-lg cursor-pointer transition-colors" x-text="opt.name"></li>
                                                </template>
                                                <li x-show="filteredOptions.length === 0" class="px-3 py-3 text-sm text-center text-slate-400 italic">No matches found</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <div class="flex items-center gap-2">
                                <span class="bg-blue-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">2</span>
                                Main Schedule
                            </div>
                            
                            <label class="relative inline-flex items-center cursor-pointer bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700">
                                <input type="checkbox" name="is_all_day" id="is_all_day" class="sr-only peer" <?php echo $is_currently_all_day ? 'checked' : ''; ?>>
                                <div class="w-8 h-4 bg-slate-300 dark:bg-slate-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[8px] after:left-[14px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-500"></div>
                                <span class="ml-3 text-xs font-bold text-slate-600 dark:text-slate-300">All-Day</span>
                            </label>
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50 dark:bg-[#111827] p-6 rounded-2xl border border-slate-100 dark:border-slate-800">
                            <div>
                                <h4 class="font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest text-[11px] flex items-center mb-4">
                                    <i class="fa-solid fa-play text-emerald-500 mr-2 text-sm"></i> Starts
                                </h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Date</label>
                                        <input type="date" name="start_date" required id="main_start_date"
                                            value="<?php echo $current_event['start_date']; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                                    </div>
                                    <div class="main-time-input-container transition-all duration-300 overflow-hidden">
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Time</label>
                                        <input type="time" name="start_time" id="main_start_time"
                                            value="<?php echo $current_event['start_time']; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer main-time-input">
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 md:mt-0 pt-4 md:pt-0 border-t md:border-t-0 md:border-l border-slate-200 dark:border-slate-700 md:pl-6">
                                <h4 class="font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest text-[11px] flex items-center mb-4">
                                    <i class="fa-solid fa-stop text-red-500 mr-2 text-sm"></i> Ends
                                </h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Date</label>
                                        <input type="date" name="end_date" required id="main_end_date"
                                            value="<?php echo $current_event['end_date']; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                                    </div>
                                    <div class="main-time-input-container transition-all duration-300 overflow-hidden">
                                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Time</label>
                                        <input type="time" name="end_time" id="main_end_time" 
                                            value="<?php echo $current_event['end_time']; ?>"
                                            class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer main-time-input">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p id="holiday-warning" class="hidden mt-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-500 p-4 rounded-xl text-sm font-bold flex items-start gap-3 shadow-sm">
                            <i class="fa-solid fa-triangle-exclamation text-lg animate-pulse mt-0.5"></i> 
                            <span>This date falls on <strong id="holiday-name" class="underline"></strong>. An admin will need to approve this carefully.</span>
                        </p>
                    </div>

                    <div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <span class="bg-blue-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">3</span>
                            Participant Selection
                        </h3>

                        <div class="bg-slate-50 dark:bg-slate-900/50 p-3 sm:p-5 rounded-2xl border border-slate-100 dark:border-slate-800">
                            
                            <div class="flex flex-wrap items-center gap-2 mb-5 pb-4 border-b border-slate-200 dark:border-slate-700">
                                <span class="text-xs font-bold text-slate-500 dark:text-slate-400 mr-2 uppercase tracking-widest"><i class="fa-solid fa-filter mr-1"></i> Filter:</span>
                                <button type="button" @click="selectedDept = 'all'" 
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors border"
                                    :class="selectedDept === 'all' || selectedDept === '' ? 'bg-slate-800 text-white border-slate-800 dark:bg-emerald-500 dark:border-emerald-500 dark:text-white shadow-sm' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'">
                                    All
                                </button>
                                <?php foreach (array_keys($grouped_participants) as $dept): ?>
                                    <button type="button" @click="selectedDept = '<?php echo htmlspecialchars($dept, ENT_QUOTES); ?>'" 
                                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors border"
                                        :class="selectedDept === '<?php echo htmlspecialchars($dept, ENT_QUOTES); ?>' ? 'bg-slate-800 text-white border-slate-800 dark:bg-emerald-500 dark:border-emerald-500 dark:text-white shadow-sm' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'">
                                        <?php echo htmlspecialchars($dept); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="max-h-64 overflow-y-auto custom-scrollbar pr-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($grouped_participants as $dept => $parts): ?>
                                    <?php $dept_id = md5($dept); ?>
                                    <div x-show="selectedDept === 'all' || selectedDept === '' || selectedDept === '<?php echo htmlspecialchars($dept, ENT_QUOTES); ?>'" x-transition.opacity.duration.300ms class="bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-700 rounded-xl p-4 shadow-sm h-fit">
                                        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 mb-3 pb-2">
                                            <h4 class="text-[11px] font-extrabold text-blue-600 dark:text-blue-400 uppercase tracking-widest"><?php echo htmlspecialchars($dept); ?></h4>
                                            <label class="flex items-center space-x-1.5 cursor-pointer group">
                                                <input type="checkbox" data-target="dept-<?php echo $dept_id; ?>" class="select-all-dept w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 bg-slate-50 dark:bg-slate-900 transition-colors cursor-pointer">
                                                <span class="text-[10px] text-slate-500 dark:text-slate-400 group-hover:text-slate-800 dark:group-hover:text-white font-bold uppercase tracking-wider transition-colors">Select All</span>
                                            </label>
                                        </div>
                                        <div class="space-y-2 dept-group" id="dept-<?php echo $dept_id; ?>">
                                            <?php foreach ($parts as $p): ?>
                                                <?php $isChecked = in_array($p['participant_id'], $current_participants) ? 'checked' : ''; ?>
                                                <label class="flex items-center space-x-3 cursor-pointer group p-1.5 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg transition-colors">
                                                    <input type="checkbox" name="participants[]" value="<?php echo $p['participant_id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" <?php echo $isChecked; ?>
                                                        class="participant-cb w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 bg-slate-50 dark:bg-slate-900 transition-colors cursor-pointer">
                                                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-300 group-hover:text-blue-700 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($p['name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                            <h3 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
                                <span class="bg-blue-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-black">4</span>
                                Custom Time Blocks (Optional)
                            </h3>
                            <button type="button" onclick="addCustomBlock()" class="bg-violet-100 dark:bg-violet-900/30 hover:bg-violet-200 dark:hover:bg-violet-800/50 text-violet-700 dark:text-violet-300 font-bold py-2 px-4 rounded-xl text-xs transition-colors border border-violet-200 dark:border-violet-700 shadow-sm flex items-center gap-2">
                                <i class="fa-solid fa-plus"></i> Add Exception
                            </button>
                        </div>
                        
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-4">Use this section if certain participants (like Senior High School) need to join the event at a different time.</p>

                        <div id="custom-blocks-container" class="space-y-4">
                            </div>
                    </div>

                    <div class="pt-8 border-t border-slate-200 dark:border-slate-800">
                        <button type="submit" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-extrabold py-4 rounded-xl transition-all shadow-lg flex justify-center items-center gap-2 text-lg">
                            <i class="fa-solid fa-floppy-disk"></i> Update Request
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </main>

    <div id="holidayConfirmModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 dark:border-slate-700 transform transition-all scale-95 opacity-0 text-center" id="holidayModalContent">
            
            <div class="bg-red-50 dark:bg-red-900/20 p-8 border-b border-red-100 dark:border-red-900/50">
                <div class="w-20 h-20 mx-auto bg-white dark:bg-slate-800 rounded-full flex items-center justify-center mb-4 shadow-sm border-4 border-red-100 dark:border-red-900/50">
                    <i class="fa-solid fa-calendar-xmark text-4xl text-red-500"></i>
                </div>
                <h2 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100">Holiday Conflict</h2>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed px-4 font-medium mt-2">
                    You are trying to schedule an event on <strong id="modalHolidayName" class="text-slate-800 dark:text-white border-b-2 border-red-400"></strong>.<br>Are you sure you want to proceed?
                </p>
            </div>

            <div class="bg-slate-50 dark:bg-slate-900 px-8 py-5 flex justify-center gap-3">
                <button type="button" onclick="closeHolidayModal()" class="flex-1 py-3 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold rounded-xl transition shadow-sm border border-slate-200 dark:border-slate-700 text-sm">
                    Cancel
                </button>
                <button type="button" onclick="submitFormForce()" class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl transition shadow-sm text-sm">
                    Yes, Update
                </button>
            </div>
        </div>
    </div>

</body>

<script>
    const holidays = <?php echo $holidaysJson; ?>;
    let blockCounter = 0;
    const prefilledBlocks = <?php echo $prefilled_blocks_json; ?>;

    const startDateInput = document.getElementById('main_start_date');
    const endDateInput = document.getElementById('main_end_date');
    const mainStartTimeInput = document.getElementById('main_start_time');
    const mainEndTimeInput = document.getElementById('main_end_time');
    
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.getElementById('eventForm'); 
    const modal = document.getElementById('holidayConfirmModal');
    const modalContent = document.getElementById('holidayModalContent');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; 
    let conflictingHolidays = [];

    // --- ALL DAY TOGGLE LOGIC ---
    const allDayToggle = document.getElementById('is_all_day');
    const timeInputs = document.querySelectorAll('.main-time-input');
    const timeContainers = document.querySelectorAll('.main-time-input-container');

    function updateTimeFields() {
        if (allDayToggle.checked) {
            timeInputs.forEach(input => { input.disabled = true; input.required = false; });
            timeContainers.forEach(container => {
                container.classList.add('opacity-30', 'pointer-events-none', 'h-0');
                container.classList.remove('mt-4');
            });
            document.querySelectorAll('input[type="time"]').forEach(input => {
                if(input.name.includes('custom_blocks')) { input.disabled = true; input.required = false; }
            });
        } else {
            timeInputs.forEach(input => { input.disabled = false; input.required = true; });
            timeContainers.forEach(container => {
                container.classList.remove('opacity-30', 'pointer-events-none', 'h-0');
                container.classList.add('mt-4');
            });
            document.querySelectorAll('input[type="time"]').forEach(input => {
                if(input.name.includes('custom_blocks')) { input.disabled = false; input.required = true; }
            });
        }
    }

    if (allDayToggle) {
        allDayToggle.addEventListener('change', updateTimeFields);
        updateTimeFields(); 
    }

    // --- CUSTOM BLOCKS LOGIC ---
    function formatTime12h(timeStr) {
        if (!timeStr) return '';
        let [hours, minutes] = timeStr.split(':');
        hours = parseInt(hours);
        let ampm = hours >= 12 ? 'PM' : 'AM';
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
        const it = toMins(inputTime);

        if (it < ms || it > me) {
            alert(`Custom time must be within the Main Event Schedule (${formatTime12h(mainStart)} to ${formatTime12h(mainEnd)}).`);
            inputElement.value = ''; 
        }
    }

    function addCustomBlock(prefillStart = '', prefillEnd = '', prefillPids = []) {
        const container = document.getElementById('custom-blocks-container');
        const blockId = blockCounter++;
        
        const defaultStart = prefillStart || mainStartTimeInput.value;
        const defaultEnd = prefillEnd || mainEndTimeInput.value;

        const blockHTML = `
            <div class="bg-white dark:bg-[#111827] border border-violet-200 dark:border-violet-800 rounded-xl p-5 relative shadow-sm" id="block-${blockId}">
                <button type="button" onclick="removeBlock(${blockId})" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 transition-colors bg-slate-50 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/10 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-trash text-xs"></i>
                </button>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5 pr-10">
                    <div>
                        <label class="block text-[10px] font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Block Start Time</label>
                        <input type="time" name="custom_blocks[${blockId}][start_time]" value="${defaultStart}" required onblur="validateBlockTime(this)" class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                    </div>
                    <div>
                        <label class="block text-[10px] font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Block End Time</label>
                        <input type="time" name="custom_blocks[${blockId}][end_time]" value="${defaultEnd}" required onblur="validateBlockTime(this)" class="input-premium w-full px-4 py-2.5 rounded-lg text-sm font-semibold cursor-pointer">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2 border-b border-slate-100 dark:border-slate-800 pb-2">Select Participants for this Time</label>
                    <div class="max-h-40 overflow-y-auto custom-scrollbar pr-2 space-y-1.5" id="block-pids-${blockId}">
                        </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', blockHTML);
        populateBlockCheckboxes(blockId, prefillPids);
        if (allDayToggle.checked) updateTimeFields(); 
    }

    function removeBlock(blockId) {
        document.getElementById(`block-${blockId}`).remove();
    }

    function populateBlockCheckboxes(blockId, prefillPids = []) {
        const targetDiv = document.getElementById(`block-pids-${blockId}`);
        const allParticipants = document.querySelectorAll('.participant-cb');
        
        let hasItems = false;
        
        allParticipants.forEach(cb => {
            if (cb.checked) {
                hasItems = true;
                const name = cb.getAttribute('data-name');
                const val = cb.value;
                const isChecked = prefillPids.includes(parseInt(val)) ? 'checked' : '';
                
                const html = `
                    <label class="flex items-center space-x-3 cursor-pointer group p-1.5 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-lg transition-colors border border-transparent hover:border-slate-200 dark:hover:border-slate-700">
                        <input type="checkbox" name="custom_blocks[${blockId}][pids][]" value="${val}" ${isChecked} class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-violet-600 focus:ring-violet-500 bg-white dark:bg-slate-900 transition-colors cursor-pointer">
                        <span class="text-xs font-bold text-slate-600 dark:text-slate-300 group-hover:text-violet-600 dark:group-hover:text-violet-400 transition-colors">${name}</span>
                    </label>
                `;
                targetDiv.insertAdjacentHTML('beforeend', html);
            }
        });

        if (!hasItems) {
            targetDiv.innerHTML = '<p class="text-xs text-red-500 dark:text-red-400 font-bold italic py-2">Please check participants in Step 3 first!</p>';
        }
    }

    // Initialize prefilled blocks from PHP
    if (Object.keys(prefilledBlocks).length > 0) {
        for (const [timeKey, pids] of Object.entries(prefilledBlocks)) {
            const [start, end] = timeKey.split('|');
            addCustomBlock(start, end, pids);
        }
    }

    // Update block checkboxes when main participants change
    document.querySelectorAll('.participant-cb').forEach(cb => {
        cb.addEventListener('change', () => {
            const blocks = document.querySelectorAll('[id^="block-pids-"]');
            blocks.forEach(block => {
                const blockId = block.id.replace('block-pids-', '');
                
                const existingChecks = Array.from(block.querySelectorAll('input[type="checkbox"]:checked')).map(c => parseInt(c.value));
                block.innerHTML = '';
                populateBlockCheckboxes(blockId, existingChecks);
            });
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
                    if (cb.checked !== this.checked) {
                        cb.checked = this.checked;
                        cb.dispatchEvent(new Event('change')); 
                    }
                });
            }
        });
    });

    document.querySelectorAll('.dept-group').forEach(group => {
        const checkboxes = group.querySelectorAll('.participant-cb');
        const selectAllCheckbox = group.previousElementSibling.querySelector('.select-all-dept');

        const updateSelectAll = () => {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            selectAllCheckbox.checked = allChecked && checkboxes.length > 0;
        };

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectAll);
        });
        
        updateSelectAll(); // Initialize on load
    });

    // --- HOLIDAY CHECKER ---
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
    checkHolidayRange(); // Run on load

    // --- FORM SUBMIT LOGIC ---
    eventForm.addEventListener('submit', function (e) {
        const checkboxes = document.querySelectorAll('.participant-cb:checked');

        if (checkboxes.length === 0) {
            e.preventDefault();
            alert("Please select at least one participant group from Step 3.");
            return;
        }

        // Validate that custom block participants aren't duplicated across blocks
        const allCustomPids = [];
        let duplicateFound = false;
        
        document.querySelectorAll('input[name^="custom_blocks"][name$="[pids][]"]:checked').forEach(cb => {
            if (allCustomPids.includes(cb.value)) {
                duplicateFound = true;
            }
            allCustomPids.push(cb.value);
        });

        if (duplicateFound) {
            e.preventDefault();
            alert("A participant cannot be assigned to multiple custom time blocks! Please check your exceptions.");
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
        }
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