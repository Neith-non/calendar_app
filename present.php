<?php
// present.php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'functions/database.php';
require_once 'functions/get_pending_count.php';

$current_page = basename($_SERVER['PHP_SELF']);

// 1. Get Timeline (Default to current month)
$timeline = isset($_GET['timeline']) ? $_GET['timeline'] : date('Y-m');
list($year, $month) = explode('-', $timeline);

$dateString = "$year-$month-01";
$daysInMonth = date('t', strtotime($dateString));
$firstDayOfWeek = date('w', strtotime($dateString));
$monthName = date('F', strtotime($dateString));

// --- FIX: Calculate Prev and Next Months for Traversal ---
$prevMonth = date('Y-m', strtotime("-1 month", strtotime($dateString)));
$nextMonth = date('Y-m', strtotime("+1 month", strtotime($dateString)));

// 2. Fetch Categories for the Setup Checkboxes
$stmtCats = $pdo->query("SELECT * FROM event_categories ORDER BY category_name ASC");
$categories = $stmtCats->fetchAll();

// Generate an array of category names to pre-fill Alpine.js (so all boxes are checked by default)
$defaultCheckedCategories = array_map(function($c) { return "'" . addslashes($c['category_name']) . "'"; }, $categories);
$alpineCategoriesArray = implode(',', $defaultCheckedCategories);

// 3. Fetch Events for the Table & Calendar
$stmt = $pdo->prepare("
    SELECT e.*, c.category_name, p.status, v.venue_name 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id 
    WHERE DATE_FORMAT(e.start_date, '%Y-%m') = ?
    AND (p.status = 'Approved' OR e.publish_id IS NULL)
    ORDER BY e.start_date ASC, e.start_time ASC
");
$stmt->execute(["$year-$month"]);
$rawEvents = $stmt->fetchAll();

// Fetch Participants Map
$part_stmt = $pdo->query("
    SELECT ps.event_publish_id AS publish_id, p.name, d.name AS department, ps.start_time, ps.end_time
    FROM participant_schedule ps
    JOIN participants p ON ps.participant_id = p.id
    JOIN department d ON p.department_id = d.id
");
$event_participants_map = [];
while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
    $event_participants_map[$row['publish_id']][] = [
        'name' => $row['name'], 'department' => $row['department'],
        'start_time' => $row['start_time'], 'end_time' => $row['end_time']
    ];
}

// 4. Build Calendar Matrix (Copied perfectly from calendar.php)
$calendarWeeks = [];
$currentWeek = 0;
$dayCounter = 0;

for ($i = 0; $i < $firstDayOfWeek; $i++) {
    $calendarWeeks[$currentWeek]['days'][$i] = ['type' => 'blank'];
    $dayCounter++;
}

for ($day = 1; $day <= $daysInMonth; $day++) {
    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $calendarWeeks[$currentWeek]['days'][$dayCounter % 7] = [
        'type' => 'day', 'date' => $dateStr, 'day' => $day, 'isToday' => ($dateStr === date('Y-m-d'))
    ];
    $dayCounter++;
    if ($dayCounter % 7 == 0 && $day < $daysInMonth) $currentWeek++;
}

while ($dayCounter % 7 != 0) {
    $calendarWeeks[$currentWeek]['days'][$dayCounter % 7] = ['type' => 'blank'];
    $dayCounter++;
}

foreach ($calendarWeeks as &$weekData) $weekData['events'] = [];
unset($weekData);

usort($rawEvents, function($a, $b) {
    $aStart = strtotime($a['start_date']);
    $aEnd = (!empty($a['end_date']) && $a['end_date'] !== '0000-00-00') ? strtotime($a['end_date']) : $aStart;
    $bStart = strtotime($b['start_date']);
    $bEnd = (!empty($b['end_date']) && $b['end_date'] !== '0000-00-00') ? strtotime($b['end_date']) : $bStart;
    
    $aDuration = $aEnd - $aStart; $bDuration = $bEnd - $bStart;
    if ($aDuration !== $bDuration) return $bDuration <=> $aDuration; 
    if ($aStart !== $bStart) return $aStart <=> $bStart; 
    return strtotime($a['start_time']) <=> strtotime($b['start_time']);
});

foreach ($rawEvents as $evt) {
    $startDt = new DateTime($evt['start_date']);
    $endStr = (!empty($evt['end_date']) && $evt['end_date'] !== '0000-00-00') ? $evt['end_date'] : $evt['start_date'];
    $endDt = new DateTime($endStr);
    if ($endDt < $startDt) $endDt = clone $startDt;

    foreach ($calendarWeeks as &$weekData) {
        $colStart = -1; $colEnd = -1;
        foreach ($weekData['days'] as $colIdx => $dayData) {
            if ($dayData['type'] === 'day') {
                $currentDayDt = new DateTime($dayData['date']);
                if ($currentDayDt >= $startDt && $currentDayDt <= $endDt) {
                    if ($colStart === -1) $colStart = $colIdx + 1; 
                    $colEnd = $colIdx + 1;
                }
            }
        }
        if ($colStart !== -1) {
            $span = $colEnd - $colStart + 1;
            $evtCopy = $evt;
            $evtCopy['col_start'] = $colStart; $evtCopy['col_span'] = $span;
            $evtCopy['is_start_of_event'] = ($startDt->format('Y-m-d') === $weekData['days'][$colStart-1]['date']);
            $evtCopy['is_end_of_event'] = ($weekData['days'][$colEnd-1]['type'] === 'day' && $endDt->format('Y-m-d') === $weekData['days'][$colEnd-1]['date']);
            $weekData['events'][] = $evtCopy;
        }
    }
    unset($weekData);
}

function getCategoryColor($categoryName) {
    $name = strtolower($categoryName);
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false) return ['text' => 'text-sky-800 dark:text-sky-200', 'bg' => 'bg-gradient-to-r from-sky-100 to-sky-50 dark:from-sky-900/50 dark:to-sky-800/20', 'border' => 'border-sky-200 dark:border-sky-700', 'accent' => 'border-sky-500 dark:border-sky-400'];
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false) return ['text' => 'text-emerald-800 dark:text-emerald-200', 'bg' => 'bg-gradient-to-r from-emerald-100 to-emerald-50 dark:from-emerald-900/50 dark:to-emerald-800/20', 'border' => 'border-emerald-200 dark:border-emerald-700', 'accent' => 'border-emerald-500 dark:border-emerald-400'];
    if (strpos($name, 'mass') !== false) return ['text' => 'text-violet-800 dark:text-violet-200', 'bg' => 'bg-gradient-to-r from-violet-100 to-violet-50 dark:from-violet-900/50 dark:to-violet-800/20', 'border' => 'border-violet-200 dark:border-violet-700', 'accent' => 'border-violet-500 dark:border-violet-400'];
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false) return ['text' => 'text-orange-800 dark:text-orange-200', 'bg' => 'bg-gradient-to-r from-orange-100 to-orange-50 dark:from-orange-900/50 dark:to-orange-800/20', 'border' => 'border-orange-200 dark:border-orange-700', 'accent' => 'border-orange-500 dark:border-orange-400'];
    if (strpos($name, 'holiday') !== false) return ['text' => 'text-yellow-800 dark:text-yellow-200', 'bg' => 'bg-gradient-to-r from-yellow-100 to-yellow-50 dark:from-yellow-900/50 dark:to-yellow-800/20', 'border' => 'border-yellow-200 dark:border-yellow-700', 'accent' => 'border-yellow-500 dark:border-yellow-400'];
    return ['text' => 'text-slate-800 dark:text-slate-200', 'bg' => 'bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-800/50 dark:to-slate-700/20', 'border' => 'border-slate-200 dark:border-slate-700', 'accent' => 'border-slate-500 dark:border-slate-400'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SJSFI - Present Schedule</title>
    
    <script>
        if (localStorage.getItem('color-theme') === 'dark') { document.documentElement.classList.add('dark'); } 
        else { document.documentElement.classList.remove('dark'); }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">

    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'], },
                    colors: { sjsfi: { green: '#004731', greenHover: '#003323', light: '#f8faf9', yellow: '#ffbb00' } }
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

        .bento-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02); }
        .dark .bento-card { background: #111827; border-color: #1e293b; }

        .dark ::-webkit-scrollbar-thumb { background-color: #334155; }
        .dark ::-webkit-scrollbar-track { background-color: #0f172a; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.05); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }

        .custom-checkbox:checked { background-color: #004731; border-color: #004731; }
        .dark .custom-checkbox:checked { background-color: #10b981; border-color: #10b981; }
    </style>
</head>

<body x-data="{ 
        isPresenting: false, 
        presentTab: 'table', 
        showQuitModal: false, 
        sidebarOpen: false,
        
        // Data Binding for Setup Options
        selectedCategories: [<?php echo $alpineCategoriesArray; ?>],
        colDate: true,
        colDetails: true,
        colVenue: true,
        colParticipants: true,
        
        startPresentation() {
            if (this.selectedCategories.length === 0) {
                alert('Please select at least one category to display.');
                return;
            }
            this.isPresenting = true;
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(err => console.log(err));
            }
        },
        exitPresentation() {
            this.isPresenting = false;
            this.showQuitModal = false;
            if (document.exitFullscreen && document.fullscreenElement) {
                document.exitFullscreen().catch(err => console.log(err));
            }
        }
    }" 
    @keydown.window="
        if (isPresenting) {
            if ($event.key === 'ArrowUp' && presentTab !== 'table') {
                $event.preventDefault();
                presentTab = 'table';
            }
            if ($event.key === 'ArrowDown' && presentTab !== 'calendar') {
                $event.preventDefault();
                presentTab = 'calendar';
            }
        }
    "
    class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <div x-show="!isPresenting" class="flex h-full shrink-0">
        <?php include 'includes/sidebar.php'; ?>
    </div>

    <main class="flex-1 flex flex-col min-w-0 h-full relative custom-scrollbar overflow-y-auto">

        <div x-show="!isPresenting" x-transition.opacity.duration.300ms class="p-6 md:p-8 lg:p-10">
            
            <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full">
                <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
                <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <div class="mb-8">
                <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-2">Presentation Setup</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Configure what data will be shown before entering immersive mode.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-regular fa-calendar text-sjsfi-green dark:text-emerald-500"></i> Select Timeline
                    </h3>
                    <div class="space-y-3">
                        <select id="timeline-select" onchange="window.location.href='?timeline='+this.value" class="w-full px-4 py-3 text-sm font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-sjsfi-green text-slate-800 dark:text-slate-200 cursor-pointer">
                            <?php
                            // Generate exactly 12 months strictly for the currently selected year
                            for ($m = 1; $m <= 12; $m++) {
                                // Format the value as YYYY-MM (e.g., "2026-04")
                                $val = sprintf("%04d-%02d", $year, $m);
                                // Format the label as "Month Year" (e.g., "April 2026")
                                $lbl = date('F', mktime(0, 0, 0, $m, 10));
                                
                                $sel = ($val === $timeline) ? 'selected' : '';
                                echo "<option value='$val' $sel>$lbl</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-filter text-sjsfi-green dark:text-emerald-500"></i> Event Categories
                    </h3>
                    <div class="grid grid-cols-2 gap-3 max-h-48 overflow-y-auto custom-scrollbar">
                        <?php foreach ($categories as $cat): ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" value="<?php echo htmlspecialchars($cat['category_name']); ?>" x-model="selectedCategories" class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                                <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-table-columns text-sjsfi-green dark:text-emerald-500"></i> Display Columns
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center space-x-3 cursor-pointer group opacity-50" title="Event Name cannot be hidden">
                            <input type="checkbox" checked disabled class="w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-200 dark:bg-slate-700">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300">Event Name</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" x-model="colDate" class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green transition">Date & Time</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" x-model="colDetails" class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green transition">Event Details</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" x-model="colVenue" class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green transition">Venue</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" x-model="colParticipants" class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green transition">Participants</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button @click="startPresentation()" class="bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 text-white font-extrabold text-lg py-4 px-10 rounded-2xl transition shadow-xl flex items-center justify-center gap-3 transform hover:scale-105 duration-300">
                    <i class="fa-solid fa-desktop"></i> Start Presentation
                </button>
            </div>
        </div>


        <div id="presentation-layer" x-show="isPresenting" x-transition.opacity.duration.500ms style="display: none;" class="absolute inset-0 z-50 bg-[#f8faf9] dark:bg-[#030712] flex flex-col h-screen w-screen overflow-hidden">
            
            <div class="bg-white dark:bg-[#111827] border-b border-slate-200 dark:border-slate-800 shadow-sm shrink-0 flex items-center justify-between px-6">
                <div class="flex items-center gap-2">
                    <a id="presentPrevBtn" href="?timeline=<?php echo $prevMonth; ?>" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg hover:bg-sjsfi-green dark:hover:bg-emerald-600 hover:text-white text-slate-500 transition"><i class="fa-solid fa-chevron-left"></i></a>
                    <h2 id="presentMonthTitle" class="text-xl font-black text-slate-800 dark:text-slate-100 uppercase tracking-widest w-48 text-center"><?php echo "$monthName $year"; ?></h2>
                    <a id="presentNextBtn" href="?timeline=<?php echo $nextMonth; ?>" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg hover:bg-sjsfi-green dark:hover:bg-emerald-600 hover:text-white text-slate-500 transition"><i class="fa-solid fa-chevron-right"></i></a>
                </div>

                <div class="flex items-center justify-center gap-8">
                    <button @click="presentTab = 'table'" :class="presentTab === 'table' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'" class="py-6 px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                        <i class="fa-solid fa-table-list"></i> Table of Events
                    </button>
                    <button @click="presentTab = 'calendar'" :class="presentTab === 'calendar' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'" class="py-6 px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                        <i class="fa-regular fa-calendar-days"></i> Calendar View
                    </button>
                </div>
                
                <div class="w-48"></div> </div>

            <div x-show="presentTab === 'table'" x-transition.opacity class="flex-1 overflow-y-auto p-8 lg:p-12">
                <div class="max-w-[1600px] mx-auto bg-white dark:bg-[#111827] rounded-3xl shadow-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-700">
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%]">Event Name</th>
                                <th x-show="colDate" class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Date & Time</th>
                                <th x-show="colDetails" class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[30%]">Event Details</th>
                                <th x-show="colVenue" class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Venue</th>
                                <th x-show="colParticipants" class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%]">Participants</th>
                            </tr>
                        </thead>
                        <tbody id="events-table-body" class="divide-y divide-slate-100 dark:divide-slate-800/50 text-base">
                            <?php if (count($rawEvents) > 0): ?>
                                <?php foreach ($rawEvents as $event): ?>
                                    <?php 
                                        $color = getCategoryColor($event['category_name']); 
                                        $formattedDate = date('M j, Y', strtotime($event['start_date']));
                                        $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                                    ?>
                                    <tr x-show="selectedCategories.includes('<?php echo addslashes($event['category_name']); ?>')" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                                        <td class="py-6 px-6 align-top">
                                            <h3 class="text-lg font-black text-slate-800 dark:text-white mb-2"><?php echo htmlspecialchars($event['title']); ?></h3>
                                            <span class="<?php echo $color['bg'].' '.$color['text'].' '.$color['border']; ?> border text-xs font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider"><?php echo htmlspecialchars($event['category_name']); ?></span>
                                        </td>
                                        
                                        <td x-show="colDate" class="py-6 px-6 align-top">
                                            <div class="flex flex-col gap-2 font-bold text-slate-700 dark:text-slate-300">
                                                <div class="flex items-center gap-2"><i class="fa-regular fa-calendar text-slate-400 w-5"></i> <?php echo $formattedDate; ?></div>
                                                <div class="flex items-center gap-2"><i class="fa-regular fa-clock text-slate-400 w-5"></i> <?php echo $formattedTime; ?></div>
                                            </div>
                                        </td>
                                        
                                        <td x-show="colDetails" class="py-6 px-6 align-top">
                                            <p class="font-medium text-slate-600 dark:text-slate-400 leading-relaxed"><?php echo nl2br(htmlspecialchars($event['description'] ?? 'No description provided.')); ?></p>
                                        </td>
                                        
                                        <td x-show="colVenue" class="py-6 px-6 align-top">
                                            <div class="font-bold text-slate-800 dark:text-slate-200 flex items-start gap-2">
                                                <i class="fa-solid fa-location-dot text-red-500 mt-1"></i> <?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>
                                            </div>
                                        </td>
                                        
                                        <td x-show="colParticipants" class="py-6 px-6 align-top">
                                            <div class="flex flex-col gap-1.5">
                                                <?php 
                                                    if (!empty($event_participants_map[$event['publish_id']])) {
                                                        // Group by department to keep the table clean
                                                        $depts = array_unique(array_column($event_participants_map[$event['publish_id']], 'department'));
                                                        foreach ($depts as $dept) {
                                                            echo "<span class='bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 inline-block w-max'>" . htmlspecialchars($dept) . "</span>";
                                                        }
                                                    } else {
                                                        echo "<span class='text-slate-400 italic text-sm'>Unspecified</span>";
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="py-12 text-center text-slate-500 font-medium">No approved events found for this month.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="presentTab === 'calendar'" x-transition.opacity style="display: none;" class="flex-1 overflow-hidden p-8 lg:p-12 flex flex-col">
                <div class="max-w-[1600px] w-full mx-auto bg-white dark:bg-[#07160f] border border-slate-200 dark:border-[#123f29] rounded-[2rem] shadow-xl flex flex-col flex-1 overflow-hidden transition-all">
                    
                    <div id="calendar-grid-wrapper" class="flex flex-col flex-1">
                        <div class="grid grid-cols-7 border-b border-slate-200 dark:border-[#123f29] bg-slate-50 dark:bg-[#0a1a12] shrink-0">
                            <?php
                            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($days as $day): ?>
                                <div class="py-4 text-center text-[11px] font-extrabold text-slate-500 dark:text-emerald-400 uppercase tracking-widest">
                                    <?php echo $day; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex-1 flex flex-col bg-slate-200 dark:bg-[#123f29] gap-[1px]">
                            <?php foreach ($calendarWeeks as $weekIdx => $week): ?>
                                <div class="relative flex-1 min-h-[120px] flex flex-col bg-white dark:bg-[#07160f]">
                                    
                                    <div class="absolute inset-0 grid grid-cols-7 divide-x divide-slate-100 dark:divide-[#123f29]">
                                        <?php foreach ($week['days'] as $colIdx => $day): ?>
                                            <?php if ($day['type'] === 'blank'): ?>
                                                <div class="bg-slate-50/50 dark:bg-[#05140b] h-full"></div>
                                            <?php else: ?>
                                                <?php 
                                                $dayClass = $day['isToday'] ? "bg-emerald-50 dark:bg-[#0a1a12]" : "";
                                                $numberClass = $day['isToday'] ? "bg-emerald-500 text-white rounded-full w-8 h-8 flex items-center justify-center font-black shadow-md ring-4 ring-emerald-100 dark:ring-emerald-900" : "text-slate-600 dark:text-slate-400 font-bold p-1 inline-flex items-center justify-center w-8 h-8";
                                                ?>
                                                <div class="p-3 <?php echo $dayClass; ?> h-full">
                                                    <span class="text-xs <?php echo $numberClass; ?> z-20 relative"><?php echo $day['day']; ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="relative z-10 grid grid-cols-7 gap-x-0 gap-y-1.5 pt-12 pb-2 px-1 pointer-events-none">
                                        <?php foreach ($week['events'] as $evt): ?>
                                            <?php
                                            $color = getCategoryColor($evt['category_name']);
                                            $accentBorder = $evt['is_start_of_event'] ? "border-l-[4px] {$color['accent']}" : "border-l border-l-transparent";
                                            
                                            $rounded = 'rounded-md';
                                            $borderFix = 'border mx-1 px-2.5';
                                            if ($evt['col_span'] > 1) {
                                                if ($evt['is_start_of_event'] && !$evt['is_end_of_event']) { $rounded = 'rounded-l-md rounded-r-none'; $borderFix = 'border-y border-r-0 ml-1 -mr-1 pr-3'; } 
                                                elseif (!$evt['is_start_of_event'] && $evt['is_end_of_event']) { $rounded = 'rounded-r-md rounded-l-none'; $borderFix = 'border-y border-r -ml-1 mr-1 pl-3'; $accentBorder = "border-l-0"; } 
                                                elseif (!$evt['is_start_of_event'] && !$evt['is_end_of_event']) { $rounded = 'rounded-none'; $borderFix = 'border-y border-x-0 -mx-1 px-3'; $accentBorder = ""; }
                                            }

                                            $formattedTime = ($evt['start_time'] == '00:00:00' || $evt['start_time'] == '23:59:59') ? '' : date('g:i', strtotime($evt['start_time']));
                                            $timeDisplay = ($evt['is_start_of_event'] && $formattedTime !== '') ? "<span class='opacity-70 font-semibold mr-1.5 text-[10px]'>{$formattedTime}</span>" : "";
                                            $finalClasses = "{$color['bg']} {$color['text']} {$borderFix} {$accentBorder} {$color['border']} {$rounded}";
                                            ?>
                                            <div x-show="selectedCategories.includes('<?php echo addslashes($evt['category_name']); ?>')" 
                                                class="col-start-<?php echo $evt['col_start']; ?> col-span-<?php echo $evt['col_span']; ?> <?php echo $finalClasses; ?> flex items-center h-[28px] mt-1 text-xs font-bold truncate overflow-hidden">
                                                <div class="truncate w-full"><?php echo $timeDisplay . htmlspecialchars($evt['title']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <button @click="showQuitModal = true" class="fixed bottom-8 right-8 z-50 bg-red-600/90 backdrop-blur-md text-white px-6 py-4 rounded-full font-bold shadow-2xl hover:bg-red-700 hover:scale-105 transition-all duration-300 flex items-center gap-3 border border-red-500">
                <i class="fa-solid fa-compress text-lg"></i> Exit Presentation
            </button>
        </div>
        
    </main>

    <div x-show="showQuitModal" style="display: none;" class="fixed inset-0 z-[100] bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div @click.away="showQuitModal = false" x-show="showQuitModal" x-transition.scale.origin.center class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-md overflow-hidden transform transition-all">
            <div class="p-8 text-center">
                <div class="w-20 h-20 bg-red-50 dark:bg-red-500/10 rounded-full flex items-center justify-center mx-auto mb-6 border border-red-100 dark:border-red-500/20">
                    <i class="fa-solid fa-person-walking-arrow-right text-4xl text-red-500 dark:text-red-400"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-2">Exit Presentation?</h3>
                <p class="text-slate-500 dark:text-slate-400 font-medium mb-8">Are you sure you want to exit fullscreen mode and return to the setup dashboard?</p>
                
                <div class="flex gap-4">
                    <button @click="showQuitModal = false" class="flex-1 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-extrabold py-4 rounded-xl transition">
                        Cancel
                    </button>
                    <button @click="exitPresentation()" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-extrabold py-4 rounded-xl transition shadow-lg shadow-red-600/20">
                        Yes, Exit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleKnob = document.getElementById('theme-toggle-knob');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');
        const themeToggleText = document.getElementById('theme-toggle-text');

        function updateToggleUI() {
            if (!themeToggleKnob) return;
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
        
        setTimeout(() => {
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
        }, 100);
        
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                const alpineComponent = document.querySelector('[x-data]').__x.$data;
                alpineComponent.isPresenting = false;
                alpineComponent.showQuitModal = false;
            }
        });

        // --- NEW: AJAX NAVIGATION TO PREVENT FULL-SCREEN EXIT ---
        async function navigatePresentation(url) {
            try {
                document.body.style.cursor = 'wait';
                
                const response = await fetch(url);
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // 1. Swap the Table & Calendar Grids
                const idsToReplace = ['events-table-body', 'calendar-grid-wrapper', 'presentMonthTitle', 'timeline-select'];
                
                idsToReplace.forEach(id => {
                    const currentEl = document.getElementById(id);
                    const newEl = doc.getElementById(id);
                    if (currentEl && newEl) {
                        currentEl.innerHTML = newEl.innerHTML;
                    }
                });

                // 2. Update the hidden URLs on the navigation arrows
                const prevBtn = document.getElementById('presentPrevBtn');
                const nextBtn = document.getElementById('presentNextBtn');
                const newPrev = doc.getElementById('presentPrevBtn');
                const newNext = doc.getElementById('presentNextBtn');
                
                if (prevBtn && newPrev) prevBtn.href = newPrev.href;
                if (nextBtn && newNext) nextBtn.href = newNext.href;

                window.history.pushState({}, '', url);

            } catch (error) {
                console.error('Seamless traversal failed:', error);
                window.location.href = url; // Fallback to normal load
            } finally {
                document.body.style.cursor = 'default';
            }
        }

        // Intercept Mouse Clicks on the Arrows
        document.addEventListener('click', (e) => {
            const prevBtn = document.getElementById('presentPrevBtn');
            const nextBtn = document.getElementById('presentNextBtn');
            
            if (prevBtn && prevBtn.contains(e.target)) {
                e.preventDefault();
                navigatePresentation(prevBtn.href);
            } else if (nextBtn && nextBtn.contains(e.target)) {
                e.preventDefault();
                navigatePresentation(nextBtn.href);
            }
        });

        // Intercept Keyboard Left/Right Arrows for Months
        document.addEventListener('keydown', (e) => {
            const presentingLayer = document.getElementById('presentation-layer');
            
            // Only trigger if we are actively presenting
            if (presentingLayer && presentingLayer.style.display !== 'none') {
                if (e.key === 'ArrowLeft') {
                    const prevBtn = document.getElementById('presentPrevBtn');
                    if (prevBtn) navigatePresentation(prevBtn.href);
                } else if (e.key === 'ArrowRight') {
                    const nextBtn = document.getElementById('presentNextBtn');
                    if (nextBtn) navigatePresentation(nextBtn.href);
                } 
                // Note: Up, Down, and Escape are handled perfectly by Alpine on the body tag!
            }
        });
    </script>
</body>
</html>