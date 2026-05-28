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
$stmtCats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Personal' ORDER BY category_name ASC");
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
    AND c.category_name != 'Personal'
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

// 4. Build Calendar Matrix
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
        /* CRITICAL: Hide Alpine-controlled elements before Alpine boots.
           Without this, x-cloak does nothing and the presentation layer flashes on every page load. */
        [x-cloak] { display: none !important; }

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

        /* Customizes the native date/month picker to fit the dark theme */
        input[type="month"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        input[type="month"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }
        .dark input[type="month"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }
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
    x-on:presentation-exited.document="isPresenting = false; showQuitModal = false;"
    class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <div x-show="!isPresenting" class="flex h-full shrink-0">
        <?php include 'includes/sidebar.php'; ?>
    </div>

    <main class="flex-1 flex flex-col min-w-0 h-full relative custom-scrollbar overflow-y-auto">

        <div x-show="!isPresenting" x-transition.opacity.duration.300ms class="p-6 md:p-8 lg:p-10 max-w-[1400px] mx-auto w-full">
            
            <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full">
                <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
                <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-2">Presentation Setup</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Configure what data will be shown before entering immersive mode.</p>
                </div>
                <button @click="startPresentation()" 
                        :disabled="selectedCategories.length === 0"
                        :class="selectedCategories.length === 0 ? 'opacity-50 cursor-not-allowed bg-slate-400 dark:bg-slate-700 text-slate-200 dark:text-slate-400' : 'bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 text-white shadow-xl transform hover:scale-105'"
                        class="font-extrabold text-sm sm:text-base py-3.5 px-8 rounded-2xl transition-all duration-300 flex items-center justify-center gap-3">
                    <i class="fa-solid fa-desktop"></i> Launch Presentation
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="bento-card p-6 flex flex-col h-full">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-regular fa-calendar text-sjsfi-green dark:text-emerald-500"></i> Select Timeline
                    </h3>
                    <div class="relative">
                        <input type="month" id="timeline-select" 
                               value="<?php echo htmlspecialchars($timeline); ?>" 
                               onchange="window.location.href='?timeline='+this.value" 
                               class="w-full px-5 py-4 text-base font-bold border-2 border-slate-200 dark:border-slate-700 rounded-2xl bg-slate-50 dark:bg-slate-900 focus:outline-none focus:border-sjsfi-green dark:focus:border-emerald-500 text-slate-800 dark:text-slate-200 cursor-pointer transition-colors shadow-sm">
                    </div>
                </div>

                <div class="bento-card p-6 lg:col-span-2">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-filter text-sjsfi-green dark:text-emerald-500"></i> Include Categories
                    </h3>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php foreach ($categories as $cat): 
                            $safeCat = addslashes($cat['category_name']);
                        ?>
                            <label class="cursor-pointer relative group">
                                <input type="checkbox" value="<?php echo htmlspecialchars($cat['category_name']); ?>" x-model="selectedCategories" class="sr-only">
                                <div class="px-4 py-3.5 rounded-xl border-2 transition-all duration-200 flex items-center justify-between shadow-sm"
                                     :class="selectedCategories.includes('<?php echo $safeCat; ?>') 
                                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/10 dark:border-blue-500/50' 
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:border-blue-200 dark:hover:border-slate-600'">
                                    
                                    <span class="text-sm font-bold transition-colors"
                                          :class="selectedCategories.includes('<?php echo $safeCat; ?>') ? 'text-blue-700 dark:text-blue-400' : 'text-slate-600 dark:text-slate-300'">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </span>
                                    
                                    <div class="w-5 h-5 rounded-md flex items-center justify-center transition-all"
                                         :class="selectedCategories.includes('<?php echo $safeCat; ?>') ? 'bg-blue-500 text-white scale-100' : 'bg-slate-100 dark:bg-slate-700 text-transparent scale-90 group-hover:bg-slate-200 dark:group-hover:bg-slate-600'">
                                        <i class="fa-solid fa-check text-[10px]"></i>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bento-card p-6 lg:col-span-3">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-table-columns text-sjsfi-green dark:text-emerald-500"></i> Display Columns (Table View)
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                        
                        <div class="px-4 py-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-between opacity-60 cursor-not-allowed">
                            <span class="text-sm font-bold text-slate-500 dark:text-slate-400">Event Name</span>
                            <div class="w-5 h-5 rounded-md bg-slate-300 dark:bg-slate-600 text-white flex items-center justify-center"><i class="fa-solid fa-lock text-[10px]"></i></div>
                        </div>

                        <?php
                        $cols = [
                            ['model' => 'colDate', 'label' => 'Date & Time'],
                            ['model' => 'colDetails', 'label' => 'Event Details'],
                            ['model' => 'colVenue', 'label' => 'Venue'],
                            ['model' => 'colParticipants', 'label' => 'Participants'],
                        ];
                        foreach ($cols as $c): ?>
                            <label class="cursor-pointer relative group">
                                <input type="checkbox" x-model="<?php echo $c['model']; ?>" class="sr-only">
                                <div class="px-4 py-3.5 rounded-xl border-2 transition-all duration-200 flex items-center justify-between shadow-sm"
                                     :class="<?php echo $c['model']; ?> 
                                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-500/10 dark:border-purple-500/50' 
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:border-purple-200 dark:hover:border-slate-600'">
                                    
                                    <span class="text-sm font-bold transition-colors"
                                          :class="<?php echo $c['model']; ?> ? 'text-purple-700 dark:text-purple-400' : 'text-slate-600 dark:text-slate-300'">
                                        <?php echo $c['label']; ?>
                                    </span>
                                    
                                    <div class="w-5 h-5 rounded-md flex items-center justify-center transition-all"
                                         :class="<?php echo $c['model']; ?> ? 'bg-purple-500 text-white scale-100' : 'bg-slate-100 dark:bg-slate-700 text-transparent scale-90 group-hover:bg-slate-200 dark:group-hover:bg-slate-600'">
                                        <i class="fa-solid fa-check text-[10px]"></i>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>


        <div id="presentation-layer" x-show="isPresenting" x-transition.opacity.duration.500ms x-cloak class="fixed inset-0 z-[100] bg-[#f8faf9] dark:bg-[#030712] flex flex-col h-screen w-screen">
            
            <div class="shrink-0 bg-white dark:bg-[#111827] border-b border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between px-6 h-[80px] z-50 w-full">
                <div class="flex items-center gap-2">
                    <a id="presentPrevBtn" href="?timeline=<?php echo $prevMonth; ?>" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg hover:bg-sjsfi-green dark:hover:bg-emerald-600 hover:text-white text-slate-500 transition"><i class="fa-solid fa-chevron-left"></i></a>
                    <h2 id="presentMonthTitle" class="text-xl font-black text-slate-800 dark:text-slate-100 uppercase tracking-widest w-48 text-center"><?php echo "$monthName $year"; ?></h2>
                    <a id="presentNextBtn" href="?timeline=<?php echo $nextMonth; ?>" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg hover:bg-sjsfi-green dark:hover:bg-emerald-600 hover:text-white text-slate-500 transition"><i class="fa-solid fa-chevron-right"></i></a>
                </div>

                <div class="flex items-center justify-center gap-8 h-full">
                    <button @click="presentTab = 'table'" :class="presentTab === 'table' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'" class="h-full px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                        <i class="fa-solid fa-table-list"></i> Table of Events
                    </button>
                    <button @click="presentTab = 'calendar'" :class="presentTab === 'calendar' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'" class="h-full px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                        <i class="fa-regular fa-calendar-days"></i> Calendar View
                    </button>
                </div>
                
                <div class="w-48"></div> 
            </div>

            <div class="flex-1 overflow-y-auto custom-scrollbar relative z-40 w-full">
                <div class="w-full max-w-[1600px] mx-auto p-6 lg:p-10">

                    <div x-show="presentTab === 'table'" x-transition.opacity class="w-full bg-white dark:bg-[#111827] rounded-[2rem] shadow-xl border border-slate-200 dark:border-slate-800 pt-2 px-2 pb-8">
                        <table class="w-full text-left border-separate border-spacing-0">
                            
                            <thead class="sticky top-0 z-30 bg-slate-50 dark:bg-[#1e293b] shadow-md rounded-2xl">
                                <tr>
                                    <th class="py-5 px-6 border-b border-slate-200 dark:border-slate-700 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%] rounded-tl-2xl">Event Name</th>
                                    <th x-show="colDate" class="py-5 px-6 border-b border-slate-200 dark:border-slate-700 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Date & Time</th>
                                    <th x-show="colDetails" class="py-5 px-6 border-b border-slate-200 dark:border-slate-700 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[30%]">Event Details</th>
                                    <th x-show="colVenue" class="py-5 px-6 border-b border-slate-200 dark:border-slate-700 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Venue</th>
                                    <th x-show="colParticipants" class="py-5 px-6 border-b border-slate-200 dark:border-slate-700 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%] rounded-tr-2xl">Participants</th>
                                </tr>
                            </thead>
                            
                            <tbody id="events-table-body" class="divide-y divide-slate-100 dark:divide-slate-800/50 text-base">
                                <?php if (count($rawEvents) > 0): ?>
                                    <?php foreach ($rawEvents as $event): ?>
                                        <?php 
                                            $color = getCategoryColor($event['category_name']);

                                            // --- DATE RANGE ---
                                            $startTs = strtotime($event['start_date']);
                                            $hasEndDate = !empty($event['end_date']) && $event['end_date'] !== '0000-00-00' && $event['end_date'] !== $event['start_date'];
                                            $endTs = $hasEndDate ? strtotime($event['end_date']) : $startTs;

                                            if (!$hasEndDate) {
                                                $formattedDate = date('M j, Y', $startTs);
                                            } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
                                                $formattedDate = date('M j', $startTs) . ' – ' . date('j, Y', $endTs);
                                            } else {
                                                $formattedDate = date('M j', $startTs) . ' – ' . date('M j, Y', $endTs);
                                            }

                                            // --- TIME RANGE ---
                                            $isAllDayStart = ($event['start_time'] == '00:00:00' || $event['start_time'] == '23:59:59');
                                            $isAllDayEnd   = (empty($event['end_time']) || $event['end_time'] == '00:00:00' || $event['end_time'] == '23:59:59');
                                            if ($isAllDayStart) {
                                                $formattedTime = 'All Day';
                                            } elseif ($isAllDayEnd || $event['start_time'] === $event['end_time']) {
                                                $formattedTime = date('g:i A', strtotime($event['start_time']));
                                            } else {
                                                $formattedTime = date('g:i A', strtotime($event['start_time'])) . ' – ' . date('g:i A', strtotime($event['end_time']));
                                            }
                                        ?>
                                        <tr x-show="selectedCategories.includes('<?php echo addslashes(htmlspecialchars($event['category_name'] ?? '')); ?>')" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
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
                                    <tr><td colspan="5" class="py-12 text-center text-slate-500 font-medium bg-white dark:bg-[#111827]">No approved events found for this month. (If testing, remember to approve events first).</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div x-show="presentTab === 'calendar'" x-transition.opacity x-cloak class="w-full bg-white dark:bg-[#07160f] border border-slate-200 dark:border-[#123f29] rounded-[2rem] shadow-xl pt-2 px-2 pb-8">
                        
                        <div id="calendar-grid-wrapper">
                            
                            <div class="sticky top-0 z-30 grid grid-cols-7 border-b border-slate-200 dark:border-[#123f29] bg-slate-50 dark:bg-[#0a1a12] shadow-sm rounded-t-[1.5rem]">
                                <?php
                                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                foreach ($days as $day): ?>
                                    <div class="py-4 text-center text-[11px] font-extrabold text-slate-500 dark:text-emerald-400 uppercase tracking-widest">
                                        <?php echo $day; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="flex flex-col bg-slate-200 dark:bg-[#123f29] gap-[1px]">
                                <?php foreach ($calendarWeeks as $weekIdx => $week): ?>
                                    <div class="relative w-full min-h-[140px] bg-white dark:bg-[#07160f]">
                                        
                                        <div class="absolute inset-0 grid grid-cols-7 divide-x divide-slate-100 dark:divide-[#123f29]">
                                            <?php foreach ($week['days'] as $colIdx => $day): ?>
                                                <?php if ($day['type'] === 'blank'): ?>
                                                    <div class="bg-slate-50/50 dark:bg-[#05140b] h-full"></div>
                                                <?php else: ?>
                                                    <?php 
                                                    $dayClass = $day['isToday'] ? "bg-emerald-50 dark:bg-[#0a1a12]" : "";
                                                    $numberClass = $day['isToday'] ? "bg-emerald-500 text-white rounded-full w-8 h-8 flex items-center justify-center font-black shadow-md ring-4 ring-emerald-100 dark:ring-emerald-900" : "text-slate-600 dark:text-slate-400 font-bold p-1 inline-flex items-center justify-center w-8 h-8";
                                                    ?>
                                                    <div class="p-3 <?php echo $dayClass; ?> h-full border-b border-slate-50 dark:border-[#05140b]">
                                                        <span class="text-xs <?php echo $numberClass; ?> z-20 relative"><?php echo $day['day']; ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="relative z-10 grid grid-cols-7 gap-x-0 gap-y-1.5 pt-12 pb-3 px-1 pointer-events-auto">
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
                                                <div x-show="selectedCategories.includes('<?php echo addslashes(htmlspecialchars($evt['category_name'] ?? '')); ?>')" 
                                                    class="<?php echo $finalClasses; ?> flex items-center h-[28px] mt-1 text-xs font-bold truncate overflow-hidden pointer-events-auto shadow-sm"
                                                    style="grid-column: <?php echo $evt['col_start']; ?> / span <?php echo $evt['col_span']; ?>;">
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
            </div>

            <button x-show="isPresenting" @click="showQuitModal = true" title="Exit Presentation (ESC)" class="fixed top-4 right-6 z-[110] bg-red-600/90 backdrop-blur-md text-white px-5 h-12 rounded-xl font-extrabold shadow-2xl flex items-center justify-center gap-2 hover:bg-red-700 transition-all duration-500 transform hover:scale-105 border border-red-500">
                <i class="fa-solid fa-right-from-bracket text-lg"></i> <span class="hidden md:inline text-sm">Exit</span>
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
    <script src="assets/js/event_modal.js"></script>
    <script src="assets/js/pdf_modal.js"></script>
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
                // Alpine v3: dispatch a custom event that Alpine listens to on the body
                document.dispatchEvent(new CustomEvent('presentation-exited'));
            }
        });

        // --- FIX: AJAX NAVIGATION TO PREVENT FULL-SCREEN EXIT ---
        async function navigatePresentation(url) {
            try {
                document.body.style.cursor = 'wait';
                
                const response = await fetch(url);
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // 1. Swap the Table body (innerHTML works fine for tbody)
                const tableBody = document.getElementById('events-table-body');
                const newTableBody = doc.getElementById('events-table-body');
                if (tableBody && newTableBody) {
                    tableBody.innerHTML = newTableBody.innerHTML;
                }

                // 2. Swap the Calendar Grid wrapper
                const calendarWrapper = document.getElementById('calendar-grid-wrapper');
                const newCalendarWrapper = doc.getElementById('calendar-grid-wrapper');
                if (calendarWrapper && newCalendarWrapper) {
                    calendarWrapper.innerHTML = newCalendarWrapper.innerHTML;
                    // Re-init Alpine on the new calendar DOM so x-show directives work
                    if (window.Alpine) {
                        Alpine.initTree(calendarWrapper);
                    }
                }

                // 3. Update the month title text (it's a text node, not innerHTML)
                const monthTitle = document.getElementById('presentMonthTitle');
                const newMonthTitle = doc.getElementById('presentMonthTitle');
                if (monthTitle && newMonthTitle) {
                    monthTitle.textContent = newMonthTitle.textContent;
                }

                // 4. Update the timeline month picker value (input — use .value, not innerHTML)
                const timelineSelect = document.getElementById('timeline-select');
                const newTimelineSelect = doc.getElementById('timeline-select');
                if (timelineSelect && newTimelineSelect) {
                    timelineSelect.value = newTimelineSelect.value;
                }

                // 5. Update the hidden URLs on the navigation arrows
                const prevBtn = document.getElementById('presentPrevBtn');
                const nextBtn = document.getElementById('presentNextBtn');
                const newPrev = doc.getElementById('presentPrevBtn');
                const newNext = doc.getElementById('presentNextBtn');
                
                if (prevBtn && newPrev) prevBtn.setAttribute('href', newPrev.getAttribute('href'));
                if (nextBtn && newNext) nextBtn.setAttribute('href', newNext.getAttribute('href'));

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
            const prevBtn = e.target.closest('#presentPrevBtn');
            const nextBtn = e.target.closest('#presentNextBtn');
            
            if (prevBtn) {
                e.preventDefault();
                navigatePresentation(prevBtn.href);
            } else if (nextBtn) {
                e.preventDefault();
                navigatePresentation(nextBtn.href);
            }
        });

        // Intercept Keyboard Left/Right Arrows for Months and ESC for Exit
        document.addEventListener('keydown', (e) => {
            // Check Alpine state via the body element's _x_dataStack
            const bodyEl = document.querySelector('body[x-data]');
            const alpineData = bodyEl && bodyEl._x_dataStack && bodyEl._x_dataStack[0];
            const isPresenting = alpineData ? alpineData.isPresenting : false;
            
            if (isPresenting) {
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