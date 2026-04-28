<?php
// calendar.php
session_start();

// Redirect if not logged in BEFORE sending any HTML
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'functions/database.php';
require_once 'functions/get_pending_count.php';

// Check if we are currently traversing while in presentation mode
$isPresenting = isset($_GET['present']) && $_GET['present'] == 'true';

// 1. Get the requested Month and Year (Default to current month)
$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// 2. Calendar Math
$dateString = "$year-$month-01";
$daysInMonth = date('t', strtotime($dateString));
$firstDayOfWeek = date('w', strtotime($dateString)); // 0 (Sun) to 6 (Sat)
$monthName = date('F', strtotime($dateString));

$prevMonth = date('m', strtotime("-1 month", strtotime($dateString)));
$prevYear = date('Y', strtotime("-1 month", strtotime($dateString)));
$nextMonth = date('m', strtotime("+1 month", strtotime($dateString)));
$nextYear = date('Y', strtotime("+1 month", strtotime($dateString)));

// 3. Fetch Events
$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        c.category_name, 
        p.status,
        v.venue_name 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id 
    WHERE DATE_FORMAT(e.start_date, '%Y-%m') = ?
    ORDER BY e.start_time ASC
");
$stmt->execute(["$year-$month"]);
$rawEvents = $stmt->fetchAll();

$stmtCats = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmtCats->fetchAll();

// Fetch Participants
$part_stmt = $pdo->query("
    SELECT ps.event_publish_id AS publish_id, p.name, d.name AS department, ps.start_time, ps.end_time
    FROM participant_schedule ps
    JOIN participants p ON ps.participant_id = p.id
    JOIN department d ON p.department_id = d.id
");

$event_participants_map = [];
while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
    $event_participants_map[$row['publish_id']][] = [
        'name' => $row['name'],
        'department' => $row['department'],
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time']
    ];
}

// --- VISUAL PROCESSOR: WEEK-BY-WEEK GRID SPANNING ---
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
        'type' => 'day',
        'date' => $dateStr,
        'day' => $day,
        'isToday' => ($dateStr === date('Y-m-d'))
    ];
    $dayCounter++;
    if ($dayCounter % 7 == 0 && $day < $daysInMonth) {
        $currentWeek++;
    }
}

while ($dayCounter % 7 != 0) {
    $calendarWeeks[$currentWeek]['days'][$dayCounter % 7] = ['type' => 'blank'];
    $dayCounter++;
}

foreach ($calendarWeeks as &$weekData) {
    $weekData['events'] = [];
}
unset($weekData);

usort($rawEvents, function($a, $b) {
    $aStart = strtotime($a['start_date']);
    $aEnd = (!empty($a['end_date']) && $a['end_date'] !== '0000-00-00') ? strtotime($a['end_date']) : $aStart;
    $bStart = strtotime($b['start_date']);
    $bEnd = (!empty($b['end_date']) && $b['end_date'] !== '0000-00-00') ? strtotime($b['end_date']) : $bStart;
    
    $aDuration = $aEnd - $aStart;
    $bDuration = $bEnd - $bStart;
    
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
        $colStart = -1;
        $colEnd = -1;
        
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
            $evtCopy['col_start'] = $colStart;
            $evtCopy['col_span'] = $span;
            
            $evtCopy['is_start_of_event'] = ($startDt->format('Y-m-d') === $weekData['days'][$colStart-1]['date']);
            $endMatch = false;
            if ($weekData['days'][$colEnd-1]['type'] === 'day') {
                $endMatch = ($endDt->format('Y-m-d') === $weekData['days'][$colEnd-1]['date']);
            }
            $evtCopy['is_end_of_event'] = $endMatch;
            
            $weekData['events'][] = $evtCopy;
        }
    }
    unset($weekData);
}

function getCategoryColor($categoryName)
{
    $name = strtolower($categoryName);
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false) 
        return ['text' => 'text-sky-800 dark:text-sky-200', 'bg' => 'bg-gradient-to-r from-sky-100 to-sky-50 dark:from-sky-900/50 dark:to-sky-800/20', 'border' => 'border-sky-200 dark:border-sky-700', 'accent' => 'border-sky-500 dark:border-sky-400', 'ring' => 'focus:ring-sky-500', 'checkbox' => 'text-sky-600'];
    
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false) 
        return ['text' => 'text-emerald-800 dark:text-emerald-200', 'bg' => 'bg-gradient-to-r from-emerald-100 to-emerald-50 dark:from-emerald-900/50 dark:to-emerald-800/20', 'border' => 'border-emerald-200 dark:border-emerald-700', 'accent' => 'border-emerald-500 dark:border-emerald-400', 'ring' => 'focus:ring-emerald-500', 'checkbox' => 'text-emerald-600'];
    
    if (strpos($name, 'mass') !== false) 
        return ['text' => 'text-violet-800 dark:text-violet-200', 'bg' => 'bg-gradient-to-r from-violet-100 to-violet-50 dark:from-violet-900/50 dark:to-violet-800/20', 'border' => 'border-violet-200 dark:border-violet-700', 'accent' => 'border-violet-500 dark:border-violet-400', 'ring' => 'focus:ring-violet-500', 'checkbox' => 'text-violet-600'];
    
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false) 
        return ['text' => 'text-orange-800 dark:text-orange-200', 'bg' => 'bg-gradient-to-r from-orange-100 to-orange-50 dark:from-orange-900/50 dark:to-orange-800/20', 'border' => 'border-orange-200 dark:border-orange-700', 'accent' => 'border-orange-500 dark:border-orange-400', 'ring' => 'focus:ring-orange-500', 'checkbox' => 'text-orange-600'];
    
    if (strpos($name, 'holiday') !== false) 
        return ['text' => 'text-yellow-800 dark:text-yellow-200', 'bg' => 'bg-gradient-to-r from-yellow-100 to-yellow-50 dark:from-yellow-900/50 dark:to-yellow-800/20', 'border' => 'border-yellow-200 dark:border-yellow-700', 'accent' => 'border-yellow-500 dark:border-yellow-400', 'ring' => 'focus:ring-yellow-500', 'checkbox' => 'text-yellow-600'];
    
    return ['text' => 'text-slate-800 dark:text-slate-200', 'bg' => 'bg-gradient-to-r from-slate-100 to-slate-50 dark:from-slate-800/50 dark:to-slate-700/20', 'border' => 'border-slate-200 dark:border-slate-700', 'accent' => 'border-slate-500 dark:border-slate-400', 'ring' => 'focus:ring-slate-500', 'checkbox' => 'text-slate-600'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - SJSFI</title>
    
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="assets/css/styles.css">

    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
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

        .bento-card {
            transition: all 0.3s ease;
            background: #ffffff; border: 1px solid #d1f0e0; border-radius: 1.5rem; box-shadow: 0 4px 12px rgba(209, 240, 224, 0.3);
        }
        .dark .bento-card { background: #07160f; border-color: #123f29; box-shadow: 0 4px 12px rgba(0,0,0,0.4); }

        .dark ::-webkit-scrollbar-thumb { background-color: #123f29; }
        .dark ::-webkit-scrollbar-track { background-color: #04120a; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.02); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(16, 185, 129, 0.2); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(16, 185, 129, 0.3); }

        /* --- IMMERSIVE PRESENTATION MODE CSS --- */
        body.presentation-mode { background-color: #ffffff !important; }
        .dark body.presentation-mode { background-color: #000000 !important; }
        body.presentation-mode main { padding: 0 !important; }
        
        body.presentation-mode .hide-in-presentation, 
        body.presentation-mode aside { 
            display: none !important; 
        }
        
        body.presentation-mode .presentation-header {
            padding: 1.5rem 2.5rem; margin-bottom: 0; border-bottom: 1px solid #d1f0e0;
            background-color: #ffffff; width: 100%;
        }
        .dark body.presentation-mode .presentation-header { border-color: #123f29; background-color: #000000; }
        
        /* FIX: Changed height to min-height to allow scaling in presentation mode */
        body.presentation-mode .calendar-container {
            border: none !important; border-radius: 0 !important; box-shadow: none !important; 
            min-height: calc(100vh - 80px) !important; height: auto !important;
        }
        
        body.presentation-mode a:not(.presentation-nav), 
        body.presentation-mode button:not(#exitPresentationFloat), 
        body.presentation-mode input, 
        body.presentation-mode .calendar-event-item {
            pointer-events: none !important; cursor: default !important;
        }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="h-screen flex overflow-hidden bg-[#f4fcf7] dark:bg-[#04120a] transition-colors duration-300 <?php echo $isPresenting ? 'presentation-mode' : ''; ?>">

    <?php include 'includes/sidebar.php'; ?>

    <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>&present=true" id="presentPrevBtn" class="presentation-nav fixed left-6 top-1/2 -translate-y-1/2 z-[100] bg-emerald-900/60 hover:bg-emerald-800/90 text-white w-16 h-16 rounded-full flex items-center justify-center text-2xl backdrop-blur-md transition-opacity duration-500 opacity-0 pointer-events-none <?php echo $isPresenting ? '' : 'hidden'; ?>">
        <i class="fa-solid fa-chevron-left"></i>
    </a>
    
    <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>&present=true" id="presentNextBtn" class="presentation-nav fixed right-6 top-1/2 -translate-y-1/2 z-[100] bg-emerald-900/60 hover:bg-emerald-800/90 text-white w-16 h-16 rounded-full flex items-center justify-center text-2xl backdrop-blur-md transition-opacity duration-500 opacity-0 pointer-events-none <?php echo $isPresenting ? '' : 'hidden'; ?>">
        <i class="fa-solid fa-chevron-right"></i>
    </a>

    <button id="exitPresentationFloat" onclick="exitPresentationMode()" class="fixed bottom-6 right-6 z-[100] bg-red-600/90 backdrop-blur-md text-white px-6 py-3 rounded-full font-bold shadow-2xl <?php echo $isPresenting ? 'flex' : 'hidden'; ?> items-center gap-2 hover:bg-red-700 transition-all duration-500 transform hover:scale-105">
        <i class="fa-solid fa-compress"></i> Exit Presentation
    </button>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8 relative custom-scrollbar">
        
        <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-[#d1f0e0] dark:border-[#123f29] w-full hide-in-presentation">
            <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
            <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#0a1a12] rounded-xl border border-[#d1f0e0] dark:border-[#123f29] text-emerald-700 dark:text-emerald-400 flex items-center justify-center shadow-sm hover:bg-emerald-50 dark:hover:bg-[#103322] transition-colors">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="flex flex-col xl:flex-row xl:items-center justify-between mb-6 gap-4 border-b border-[#d1f0e0] dark:border-[#123f29] pb-6 presentation-header transition-all">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#e0f5ea] dark:bg-[#103322] border border-[#bbf2d1] dark:border-[#1a4d33] flex items-center justify-center shadow-sm hide-in-presentation">
                    <i class="fa-solid fa-calendar-days text-lg text-emerald-600 dark:text-emerald-400"></i>
                </div>
                <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight" id="month-title">Calendar of Events for the Month of <span class="text-emerald-600 dark:text-emerald-400 ml-2 text-xl"><?php echo "$monthName $year"; ?></span></h1>
            </div>
            
            <div class="flex flex-wrap items-center gap-3 hide-in-presentation">
                <div class="flex items-center bg-white dark:bg-[#0a1a12] border border-[#d1f0e0] dark:border-[#123f29] rounded-xl p-1 shadow-sm">
                    <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="p-2 w-10 h-10 flex items-center justify-center rounded-lg hover:bg-[#e0f5ea] dark:hover:bg-[#103322] text-emerald-700 dark:text-emerald-400 transition">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                    <h2 class="text-sm font-extrabold w-36 text-center text-slate-800 dark:text-slate-200 tracking-wide uppercase"><?php echo "$monthName $year"; ?></h2>
                    <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="p-2 w-10 h-10 flex items-center justify-center rounded-lg hover:bg-[#e0f5ea] dark:hover:bg-[#103322] text-emerald-700 dark:text-emerald-400 transition">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>
                
                <a href="calendar.php" class="bg-white dark:bg-[#0a1a12] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] text-emerald-700 dark:text-emerald-400 px-5 py-2.5 rounded-xl text-sm font-bold border border-[#d1f0e0] dark:border-[#123f29] shadow-sm transition">Today</a>
                
                <?php if (isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], ['Head Scheduler', 'Admin'])): ?>
                    <button onclick="enterPresentationMode()" class="bg-[#ebfbf3] dark:bg-[#103322] hover:bg-[#d1f0e0] dark:hover:bg-[#1a4d33] text-emerald-700 dark:text-emerald-400 px-5 py-2.5 rounded-xl text-sm font-bold border border-[#bbf2d1] dark:border-[#215c3d] shadow-sm transition flex items-center gap-2">
                        <i class="fa-solid fa-desktop"></i> <span>Present</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="empty-state-message" class="hidden bento-card hide-in-presentation rounded-2xl p-8 mb-6 flex-col items-center justify-center text-center">
            <div class="w-16 h-16 bg-[#f0fcf5] dark:bg-[#0a1a12] rounded-full flex items-center justify-center mb-4 border border-[#d1f0e0] dark:border-[#123f29]">
                <i class="fa-solid fa-ghost text-3xl text-emerald-300 dark:text-emerald-700"></i>
            </div>
            <h3 class="text-xl font-extrabold text-slate-800 dark:text-white mb-1">No events found</h3>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Try adjusting your search or category filters.</p>
        </div>

        <div class="bento-card hide-in-presentation p-2 pl-4 mb-6 flex flex-col sm:flex-row items-center gap-2 relative z-20">
            <div class="relative w-full flex-1 group">
                <div class="absolute inset-y-0 left-0 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-emerald-400 group-focus-within:text-emerald-600 transition-colors"></i>
                </div>
                <input type="text" id="search-bar" placeholder="Search events, dates, or categories..." class="w-full pl-8 pr-4 py-3 text-sm font-medium border-none shadow-none bg-transparent focus:outline-none focus:ring-0 text-slate-800 dark:text-slate-200 dark:placeholder-slate-500 placeholder-slate-400">
            </div>

            <div x-data="{ filterOpen: false }" class="relative w-full sm:w-auto border-t sm:border-t-0 sm:border-l border-[#d1f0e0] dark:border-[#123f29] pt-2 sm:pt-0 sm:pl-2">
                <button @click="filterOpen = !filterOpen" class="w-full sm:w-56 flex items-center justify-between gap-2 font-bold text-sm py-2.5 px-4 rounded-xl hover:bg-[#f0fcf5] dark:hover:bg-[#103322] transition text-emerald-800 dark:text-emerald-300">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-filter text-emerald-500"></i>
                        <span id="filter-button-text">All Categories</span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-emerald-500 transition-transform" :class="{ 'rotate-180': filterOpen }"></i>
                </button>

                <div x-show="filterOpen" @click.away="filterOpen = false" x-transition.opacity.duration.200ms class="absolute right-0 mt-3 w-full sm:w-72 bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] rounded-2xl shadow-xl z-30 p-5" style="display: none;">
                    <h4 class="text-xs font-extrabold text-emerald-500 uppercase tracking-widest mb-4">Filter by Category</h4>
                    <div class="space-y-2 max-h-60 overflow-y-auto custom-scrollbar">
                        <?php foreach ($categories as $cat): ?>
                            <?php $color = getCategoryColor($cat['category_name']); ?>
                            <label class="flex items-center space-x-3 cursor-pointer p-2 rounded-lg hover:bg-[#f0fcf5] dark:hover:bg-[#103322] transition group">
                                <input type="checkbox" checked value="<?php echo htmlspecialchars($cat['category_name']); ?>" class="category-filter w-4 h-4 rounded <?php echo $color['checkbox']; ?> bg-white dark:bg-[#04120a] border-[#bbf2d1] dark:border-[#1a4d33] focus:ring-offset-0 <?php echo $color['ring']; ?>">
                                <span class="text-slate-700 dark:text-slate-300 font-bold text-sm group-hover:text-emerald-800 dark:group-hover:text-emerald-200 transition-colors"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="calendar-container bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] rounded-[2rem] shadow-xl flex flex-col flex-auto shrink-0 h-auto min-h-[700px] overflow-hidden transition-all z-10 relative">
            
            <div class="grid grid-cols-7 border-b border-[#d1f0e0] dark:border-[#123f29] bg-[#f0fcf5] dark:bg-[#0a1a12] shrink-0">
                <?php
                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($days as $day):
                    ?>
                    <div class="py-4 text-center text-[11px] font-extrabold text-emerald-700 dark:text-emerald-400 uppercase tracking-widest">
                        <?php echo $day; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="flex-auto flex flex-col bg-[#d1f0e0] dark:bg-[#123f29] gap-[1px]">
                <?php foreach ($calendarWeeks as $weekIdx => $week): ?>
                    <div class="relative flex-auto shrink-0 min-h-[130px] flex flex-col bg-white dark:bg-[#07160f]">
                        
                        <div class="absolute inset-0 grid grid-cols-7 divide-x divide-[#d1f0e0] dark:divide-[#123f29]">
                            <?php foreach ($week['days'] as $colIdx => $day): ?>
                                <?php if ($day['type'] === 'blank'): ?>
                                    <div class="bg-[#fafdfb] dark:bg-[#05140b] h-full"></div>
                                <?php else: ?>
                                    <?php 
                                    $dayClass = $day['isToday'] ? "bg-[#f0fcf5] dark:bg-[#0a1a12]" : "";
                                    $numberClass = $day['isToday'] ? "bg-emerald-500 text-white rounded-full w-8 h-8 flex items-center justify-center font-black shadow-md ring-4 ring-emerald-100 dark:ring-emerald-900" : "text-slate-600 dark:text-slate-400 font-bold p-1 inline-flex items-center justify-center w-8 h-8";
                                    ?>
                                    <div class="p-3 <?php echo $dayClass; ?> hover:bg-[#fafdfb] dark:hover:bg-[#0a1a12] transition-colors relative group h-full">
                                        <div class="flex justify-between items-start">
                                            <?php if (isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], ['Head Scheduler', 'Admin'])): ?>
                                                <a href="add_event.php?date=<?php echo $day['date']; ?>" class="action-btn text-xs <?php echo $numberClass; ?> z-20 relative"><?php echo $day['day']; ?></a>
                                                <a href="add_event.php?date=<?php echo $day['date']; ?>" class="action-btn opacity-0 group-hover:opacity-100 text-emerald-300 hover:text-emerald-600 transition p-1 z-20 relative"><i class="fa-solid fa-plus text-xs"></i></a>
                                            <?php else: ?>
                                                <span class="text-xs <?php echo $numberClass; ?> z-20 relative"><?php echo $day['day']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="relative z-10 grid grid-cols-7 gap-x-0 gap-y-1.5 pt-12 pb-2 px-1 pointer-events-none">
                            <?php foreach ($week['events'] as $evt): ?>
                                <?php
                                $color = getCategoryColor($evt['category_name']);
                                
                                $presentationHideClass = ($evt['status'] === 'Pending') ? 'hide-in-presentation' : '';
                                $accentBorder = $evt['is_start_of_event'] ? "border-l-[4px] {$color['accent']}" : "border-l border-l-transparent";
                                $opacity = ($evt['status'] === 'Pending') ? 'opacity-90 bg-white dark:bg-[#07160f]' : 'shadow-sm shadow-black/5';
                                $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-hourglass-half text-[9px] mr-1 opacity-70"></i><span class="text-[9px] uppercase tracking-wider opacity-80 font-extrabold mr-1">[PENDING]</span> ' : '';
                                
                                $safeTitle = htmlspecialchars($evt['title']);
                                $shortTitle = strlen($safeTitle) > ($evt['col_span'] * 15) ? substr($safeTitle, 0, ($evt['col_span'] * 15)) . '...' : $safeTitle;
                                
                                $rounded = 'rounded-md';
                                $borderFix = 'border mx-1 px-2.5';
                                
                                if ($evt['col_span'] > 1) {
                                    if ($evt['is_start_of_event'] && !$evt['is_end_of_event']) {
                                        $rounded = 'rounded-l-md rounded-r-none';
                                        $borderFix = 'border-y border-r-0 ml-1 -mr-1 pr-3';
                                    } elseif (!$evt['is_start_of_event'] && $evt['is_end_of_event']) {
                                        $rounded = 'rounded-r-md rounded-l-none';
                                        $borderFix = 'border-y border-r -ml-1 mr-1 pl-3'; 
                                        $accentBorder = "border-l-0"; 
                                    } elseif (!$evt['is_start_of_event'] && !$evt['is_end_of_event']) {
                                        $rounded = 'rounded-none';
                                        $borderFix = 'border-y border-x-0 -mx-1 px-3';
                                        $accentBorder = ""; 
                                    }
                                }

                                $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                                $formattedTime = ($evt['start_time'] == '00:00:00' || $evt['start_time'] == '23:59:59') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));
                                $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                                $formattedEndTime = ($evt['end_time'] == '00:00:00' || $evt['end_time'] == '23:59:59') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));
                                $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');
                                $safeVenue = htmlspecialchars($evt['venue_name'] ?? 'Not specified');

                                $participants_array = $evt['publish_id'] ? ($event_participants_map[$evt['publish_id']] ?? []) : [];
                                $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                                
                                $timeDisplay = ($evt['is_start_of_event'] && $formattedTime !== 'All Day') ? "<span class='opacity-70 font-semibold mr-1.5 text-[10px]'>{$formattedTime}</span>" : "";
                                
                                $finalClasses = "{$color['bg']} {$color['text']} {$borderFix} {$accentBorder} {$color['border']} {$opacity} {$rounded}";
                                ?>
                                
                                <div class="calendar-event-item <?php echo $presentationHideClass; ?> pointer-events-auto col-start-<?php echo $evt['col_start']; ?> col-span-<?php echo $evt['col_span']; ?> <?php echo $finalClasses; ?> flex items-center h-[28px] mt-1 text-xs font-bold truncate cursor-pointer hover:brightness-95 transition-all relative overflow-hidden"
                                    title='<?php echo $safeTitle; ?>'
                                    data-title='<?php echo $safeTitle; ?>'
                                    data-desc='<?php echo $safeDesc; ?>'
                                    data-category='<?php echo htmlspecialchars($evt['category_name']); ?>' 
                                    data-venue='<?php echo $safeVenue; ?>'
                                    data-date='<?php echo $formattedDate; ?>'
                                    data-time='<?php echo $formattedTime; ?>'
                                    data-end-date='<?php echo $formattedEndDate; ?>'
                                    data-end-time='<?php echo $formattedEndTime; ?>'
                                    data-participants='<?php echo $jsParticipants; ?>'
                                    onclick='openModal(this)'>
                                    <div class="truncate w-full">
                                        <?php echo $pendingIcon . $timeDisplay . $shortTitle; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[110] backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-lg overflow-hidden border border-[#d1f0e0] dark:border-[#123f29] transform transition-all scale-95 opacity-0" id="modalContent">
            
            <div class="bg-[#f0fcf5] dark:bg-[#0a1a12] p-6 flex justify-between items-start border-b border-[#d1f0e0] dark:border-[#123f29]">
                <h2 id="modalTitle" class="text-xl font-extrabold text-emerald-900 dark:text-emerald-100 leading-tight pr-4">Event Title</h2>
                <button onclick="closeModal()" class="text-emerald-400 hover:text-red-500 transition bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto custom-scrollbar">
                <div class="bg-white dark:bg-[#07160f] p-5 rounded-2xl border border-[#d1f0e0] dark:border-[#123f29] shadow-sm space-y-4">
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-emerald-400 uppercase tracking-widest">Start</span>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-calendar text-emerald-600"></i>
                            <span id="modalDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-clock text-emerald-600"></i>
                            <span id="modalTime">Time</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-emerald-400 uppercase tracking-widest">End</span>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-calendar-check text-slate-400"></i>
                            <span id="modalEndDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-[#f0fcf5] dark:bg-[#0a1a12] px-3 py-1.5 rounded-lg border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-regular fa-clock text-slate-400"></i>
                            <span id="modalEndTime">Time</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1.5">Category</h3>
                        <p class="text-emerald-800 dark:text-emerald-200 font-bold bg-[#f0fcf5] dark:bg-[#0a1a12] p-3.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-tag text-emerald-500"></i>
                            <span id="modalCategory" class="truncate">Not categorized</span>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-1.5">Venue</h3>
                        <p class="text-emerald-800 dark:text-emerald-200 font-bold bg-[#f0fcf5] dark:bg-[#0a1a12] p-3.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-location-dot text-emerald-500"></i>
                            <span id="modalVenue" class="truncate">Not specified</span>
                        </p>
                    </div>
                </div>

                <div>
                    <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-2">Participants</h3>
                    <div id="modalParticipants" class="bg-[#f0fcf5] dark:bg-[#0a1a12] p-4 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] min-h-[60px] flex flex-col gap-3">
                        <span class="text-slate-400 italic text-sm">Loading participants...</span>
                    </div>
                </div>

                <div>
                    <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-2">Description</h3>
                    <p id="modalDesc" class="text-slate-600 dark:text-slate-300 text-sm whitespace-pre-line leading-relaxed bg-[#f0fcf5] dark:bg-[#0a1a12] p-5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] min-h-[100px] font-medium"></p>
                </div>
            </div>

            <div class="bg-white dark:bg-[#07160f] px-6 py-4 border-t border-[#d1f0e0] dark:border-[#123f29] flex justify-end">
                <button onclick="closeModal()" class="bg-white dark:bg-[#0a1a12] border border-[#d1f0e0] dark:border-[#123f29] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] text-emerald-800 dark:text-emerald-200 font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm">Close Details</button>
            </div>
        </div>
    </div>

</body>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="assets/js/event_modal.js"></script>
<script src="assets/js/calendar.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/pdf_modal.js"></script>
<script src="assets/js/theme_toggle.js"></script>

<script>
    // --- IMMERSIVE PRESENTATION & TRAVERSAL LOGIC ---
    let mouseTimer;
    const prevBtn = document.getElementById('presentPrevBtn');
    const nextBtn = document.getElementById('presentNextBtn');
    const floatBtn = document.getElementById('exitPresentationFloat');
    let isPresenting = <?php echo $isPresenting ? 'true' : 'false'; ?>;

    function enterPresentationMode() {
        document.body.classList.add('presentation-mode');
        floatBtn.classList.remove('hidden');
        floatBtn.classList.add('flex');
        prevBtn.classList.remove('hidden');
        nextBtn.classList.remove('hidden');
        isPresenting = true;
        
        // Append present=true to URL so traversing keeps you in presentation mode
        const url = new URL(window.location);
        url.searchParams.set('present', 'true');
        window.history.replaceState({}, '', url);

        if (document.documentElement.requestFullscreen) {
            document.documentElement.requestFullscreen().catch(err => {
                console.log(`Error attempting to enable fullscreen: ${err.message}`);
            });
        }
        
        triggerMouseMove();
    }

    function exitPresentationMode() {
        document.body.classList.remove('presentation-mode');
        floatBtn.classList.add('hidden');
        floatBtn.classList.remove('flex');
        prevBtn.classList.add('hidden');
        nextBtn.classList.add('hidden');
        isPresenting = false;

        const url = new URL(window.location);
        url.searchParams.delete('present');
        window.history.replaceState({}, '', url);

        if (document.exitFullscreen && document.fullscreenElement) {
            document.exitFullscreen();
        }
    }

    function togglePresentationMode() {
        if (document.body.classList.contains('presentation-mode')) {
            exitPresentationMode();
        } else {
            enterPresentationMode();
        }
    }

    function triggerMouseMove() {
        if (!isPresenting) return;
        
        // Fade in UI on mouse move
        prevBtn.classList.remove('opacity-0', 'pointer-events-none');
        nextBtn.classList.remove('opacity-0', 'pointer-events-none');
        floatBtn.classList.remove('opacity-0'); 
        
        clearTimeout(mouseTimer);
        
        // Fade out UI after seconds of inactivity
        mouseTimer = setTimeout(() => {
            prevBtn.classList.add('opacity-0', 'pointer-events-none');
            nextBtn.classList.add('opacity-0', 'pointer-events-none');
            floatBtn.classList.add('opacity-0');
        }, 1500);
    }

    // --- NEW: AJAX NAVIGATION TO PREVENT FULL-SCREEN EXIT ---
    async function navigatePresentation(url) {
        try {
            document.body.style.cursor = 'wait'; // Show loading cursor
            
            // 1. Fetch the new month's HTML silently
            const response = await fetch(url);
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // 2. Extract and replace the Calendar Grid
            const currentCalendar = document.querySelector('.calendar-container');
            const newCalendar = doc.querySelector('.calendar-container');
            if (currentCalendar && newCalendar) {
                currentCalendar.innerHTML = newCalendar.innerHTML;
            }

            // 3. Extract and replace the Month Header Title
            const currentHeader = document.querySelector('.presentation-header');
            const newHeader = doc.querySelector('.presentation-header');
            if (currentHeader && newHeader) {
                currentHeader.innerHTML = newHeader.innerHTML;
            }

            // 4. Update the URLs on the invisible side-arrows
            const newPrev = doc.getElementById('presentPrevBtn');
            const newNext = doc.getElementById('presentNextBtn');
            if (prevBtn && newPrev) prevBtn.href = newPrev.href;
            if (nextBtn && newNext) nextBtn.href = newNext.href;

            // 5. Update the URL in the browser address bar cleanly
            window.history.pushState({}, '', url);

            // 6. Tell our filter engine to re-scan the new events
            if (typeof window.reapplyFilters === 'function') {
                window.reapplyFilters();
            }

        } catch (error) {
            console.error('Failed to load new month seamlessly:', error);
            window.location.href = url; // Fallback to a normal page reload if it fails
        } finally {
            document.body.style.cursor = 'default';
        }
    }

    // Intercept the arrow clicks so they trigger the AJAX function instead of a reload
    prevBtn.addEventListener('click', (e) => {
        e.preventDefault();
        navigatePresentation(prevBtn.href);
    });
    
    nextBtn.addEventListener('click', (e) => {
        e.preventDefault();
        navigatePresentation(nextBtn.href);
    });

    // Event Listeners
    document.addEventListener('mousemove', triggerMouseMove);

    document.addEventListener('keydown', (e) => {
        if (isPresenting) {
            if (e.key === 'ArrowLeft') navigatePresentation(prevBtn.href);
            else if (e.key === 'ArrowRight') navigatePresentation(nextBtn.href);
            else if (e.key === 'Escape') exitPresentationMode();
        }
    });
    
    // Sync if the user hits ESC to natively exit the browser's Fullscreen
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement && isPresenting) {
            exitPresentationMode();
        }
    });
    
    // Run an initial check if the page loaded natively into Presentation Mode
    if (isPresenting) {
        triggerMouseMove();
    }
</script>

</html>