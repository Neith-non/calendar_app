<?php
// calendar.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'functions/database.php';
require_once 'functions/get_pending_count.php';

$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$dateString = "$year-$month-01";
$daysInMonth = date('t', strtotime($dateString));
$firstDayOfWeek = date('w', strtotime($dateString)); 
$monthName = date('F', strtotime($dateString));

$prevMonth = date('m', strtotime("-1 month", strtotime($dateString)));
$prevYear = date('Y', strtotime("-1 month", strtotime($dateString)));
$nextMonth = date('m', strtotime("+1 month", strtotime($dateString)));
$nextYear = date('Y', strtotime("+1 month", strtotime($dateString)));

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

$eventsByDate = [];
foreach ($rawEvents as $event) {
    $eventsByDate[$event['start_date']][] = $event;
}

function getCategoryColor($categoryName)
{
    $name = strtolower($categoryName);
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false)
        return ['border' => 'border-sky-500', 'text' => 'text-sky-600 dark:text-sky-400', 'bg' => 'bg-white dark:bg-slate-800', 'icon' => 'fa-book-open', 'iconColor' => 'text-sky-500'];

    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false)
        return ['border' => 'border-emerald-500', 'text' => 'text-emerald-600 dark:text-emerald-400', 'bg' => 'bg-white dark:bg-slate-800', 'icon' => 'fa-volleyball', 'iconColor' => 'text-emerald-500'];

    if (strpos($name, 'mass') !== false)
        return ['border' => 'border-violet-500', 'text' => 'text-violet-600 dark:text-violet-400', 'bg' => 'bg-white dark:bg-slate-800', 'icon' => 'fa-church', 'iconColor' => 'text-violet-500'];

    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false)
        return ['border' => 'border-orange-500', 'text' => 'text-orange-600 dark:text-orange-400', 'bg' => 'bg-white dark:bg-slate-800', 'icon' => 'fa-users', 'iconColor' => 'text-orange-500'];

    if (strpos($name, 'holiday') !== false)
        return ['border' => 'border-amber-400', 'text' => 'text-amber-600 dark:text-amber-400', 'bg' => 'bg-amber-50/50 dark:bg-slate-800', 'icon' => 'fa-umbrella-beach', 'iconColor' => 'text-amber-500'];

    return ['border' => 'border-slate-400', 'text' => 'text-slate-600 dark:text-slate-300', 'bg' => 'bg-white dark:bg-slate-800', 'icon' => 'fa-calendar-day', 'iconColor' => 'text-slate-400'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - St. Joseph School</title>
    
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <link rel="stylesheet" href="assets/css/calendar.css?v=<?php echo time(); ?>">

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

        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800/50">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
                <div class="space-y-2">

                    <a href="index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-table-cells-large w-5 text-center"></i>
                        <span>Dashboard Hub</span>
                    </a>

                    <a href="calendar.php" class="nav-item active w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-regular fa-calendar-days w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>View Calendar</span>
                    </a>

                    <?php if ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler'): ?>
                        <a href="request_status.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-clipboard-list w-5 text-center text-slate-400 dark:text-slate-500"></i>
                            <span>Event Status</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center text-slate-400 dark:text-slate-500"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin'): ?>
                        <button onclick="openPdfModal()" class="w-full mt-4 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 text-sm shadow-sm">
                            <i class="fa-solid fa-print text-slate-400 dark:text-slate-500"></i> Print Schedule
                        </button>
                    <?php endif; ?>

                </div>
            </div>

            <div class="p-6">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] !== 'Viewer'): ?>
                        <a href="add_event.php" class="bg-sjsfi-yellow hover:bg-yellow-400 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-sjsfi-green dark:text-white w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm shadow-sm transition-colors">
                            <i class="fa-solid fa-plus"></i> Add New Event
                        </a>
                    <?php endif; ?>

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
                <div class="w-10 h-10 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-full flex items-center justify-center text-sjsfi-green dark:text-emerald-400 shrink-0 shadow-sm">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div class="overflow-hidden">
                    <p class="text-sm font-extrabold text-slate-800 dark:text-slate-100 leading-tight truncate">
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?>
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

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 md:p-8 lg:p-10 relative">

        <div class="bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 p-6 rounded-2xl flex flex-col lg:flex-row justify-between items-center shadow-sm mb-6 gap-4">
            <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100">Monthly Calendar</h1>

            <div class="flex flex-wrap justify-center items-center gap-2 sm:gap-4">
                <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="w-10 h-10 flex items-center justify-center rounded-full bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 hover:text-sjsfi-green dark:hover:text-emerald-400 transition shadow-sm border border-slate-200 dark:border-slate-700">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>

                <h2 class="text-lg md:text-xl font-bold w-40 md:w-48 text-center text-slate-800 dark:text-slate-100">
                    <?php echo "$monthName $year"; ?>
                </h2>

                <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="w-10 h-10 flex items-center justify-center rounded-full bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 hover:text-sjsfi-green dark:hover:text-emerald-400 transition shadow-sm border border-slate-200 dark:border-slate-700">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>

                <a href="calendar.php" class="ml-0 sm:ml-2 bg-white dark:bg-[#0b1120] hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 px-4 py-2 rounded-xl text-sm font-bold border border-slate-200 dark:border-slate-700 transition shadow-sm">
                    Today
                </a>

                <?php if (isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], ['Head Scheduler', 'Admin'])): ?>
                    <button id="presentationToggle" onclick="togglePresentationMode()" class="bg-sjsfi-light dark:bg-sky-500/10 hover:bg-slate-100 dark:hover:bg-sky-500/20 text-sjsfi-green dark:text-sky-400 border border-slate-200 dark:border-sky-500/30 px-4 py-2 rounded-xl text-sm font-bold transition shadow-sm flex items-center gap-2">
                        <i class="fa-solid fa-desktop"></i> <span class="hidden sm:inline">Present</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="filterBar" class="bento-card bg-white dark:bg-[#111827] p-2 pl-4 mb-6 flex flex-col sm:flex-row items-center gap-2 relative z-10 shadow-sm border border-slate-200 dark:border-slate-800 rounded-xl">
            <div class="relative w-full flex-1 group">
                <div class="absolute inset-y-0 left-0 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-slate-400 group-focus-within:text-sjsfi-green dark:group-focus-within:text-emerald-500 transition-colors"></i>
                </div>
                <input type="text" id="search-bar" placeholder="Search events..."
                    class="w-full pl-8 pr-4 py-3 text-sm font-medium border-none shadow-none bg-transparent focus:outline-none focus:ring-0 text-slate-800 dark:text-slate-200 dark:placeholder-slate-500">
            </div>

            <div x-data="{ open: false }" class="relative w-full sm:w-auto border-t sm:border-t-0 sm:border-l border-slate-100 dark:border-slate-700 pt-2 sm:pt-0 sm:pl-2">
                <button @click="open = !open" class="w-full sm:w-56 flex items-center justify-between gap-2 font-bold text-sm py-2.5 px-4 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-filter text-slate-400 dark:text-slate-500"></i>
                        <span id="filter-button-text" class="text-slate-700 dark:text-slate-300">All Categories</span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-slate-600 transition-transform" :class="{ 'rotate-180': open }"></i>
                </button>

                <div x-show="open" @click.away="open = false" x-transition
                    class="absolute right-0 mt-2 w-full sm:w-72 bg-white dark:bg-[#0b1120] border border-slate-200 dark:border-slate-700 rounded-2xl shadow-xl z-20 p-5" style="display: none;">
                    <h4 class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-4">Filter by Category</h4>
                    <div class="space-y-2 max-h-60 overflow-y-auto pr-2 custom-scrollbar">
                        <?php foreach ($categories as $cat): ?>
                            <?php $color = getCategoryColor($cat['category_name']); ?>
                            <label class="flex items-center space-x-3 cursor-pointer group p-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition border border-transparent">
                                <input type="checkbox" checked value="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                    class="category-filter w-5 h-5 rounded text-sjsfi-green bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-600 focus:ring-offset-0 focus:ring-offset-transparent focus:ring-sjsfi-green">
                                <span class="group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition-colors text-slate-700 dark:text-slate-200 font-bold text-sm">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden flex flex-col flex-1 shadow-sm min-h-[700px]">
            <div class="overflow-x-auto w-full flex-1 flex flex-col custom-scrollbar">
                <div class="min-w-[800px] flex-1 flex flex-col">

                    <div class="grid grid-cols-7 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900">
                        <?php
                        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        foreach ($days as $day):
                            ?>
                            <div class="py-4 text-center text-xs font-extrabold text-slate-500 dark:text-slate-400 uppercase tracking-widest">
                                <?php echo $day; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-7 flex-1 bg-slate-200 dark:bg-slate-800 gap-px">
                        <?php
                        for ($i = 0; $i < $firstDayOfWeek; $i++) {
                            echo '<div class="bg-slate-50 dark:bg-[#0b1120] min-h-[140px] pointer-events-none"></div>';
                        }

                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $isToday = ($currentDate === date('Y-m-d'));
                            
                            $dayClass = $isToday ? "bg-sjsfi-light dark:bg-emerald-900/10" : "bg-white dark:bg-[#111827]";
                            $numberClass = $isToday ? "bg-sjsfi-green dark:bg-emerald-500 text-white shadow-sm" : "text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800";

                            echo "<div class='calendar-day {$dayClass} min-h-[140px] p-3 hover:bg-slate-50 dark:hover:bg-[#1f2937] transition-all duration-200 relative group flex flex-col'>";

                            echo "<div class='flex justify-between items-start mb-2'>";

                            if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')) {
                                echo "<a href='add_event.php?date={$currentDate}' class='action-btn text-sm font-bold w-8 h-8 rounded-full flex items-center justify-center transition {$numberClass}' title='Add event on " . date('F j, Y', strtotime($currentDate)) . "'>{$day}</a>";
                                echo "<a href='add_event.php?date={$currentDate}' class='action-btn opacity-0 group-hover:opacity-100 text-slate-400 hover:text-sjsfi-green dark:hover:text-emerald-400 transition p-1.5 rounded-full hover:bg-slate-100 dark:hover:bg-slate-800'><i class='fa-solid fa-plus text-xs'></i></a>";
                            } else {
                                $plainNumberClass = $isToday ? "bg-sjsfi-green dark:bg-emerald-500 text-white shadow-sm" : "text-slate-600 dark:text-slate-300";
                                echo "<span class='text-sm font-bold w-8 h-8 rounded-full flex items-center justify-center {$plainNumberClass}'>{$day}</span>";
                            }

                            echo "</div>";

                            if (isset($eventsByDate[$currentDate])) {
                                echo "<div class='flex flex-col gap-2 mt-1 overflow-y-auto flex-1 pr-1 custom-scrollbar'>";
                                foreach ($eventsByDate[$currentDate] as $evt) {
                                    $color = getCategoryColor($evt['category_name']);
                                    $opacity = ($evt['status'] === 'Pending') ? 'opacity-60 border-dashed' : 'border-solid';
                                    $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-clock mr-1 text-[10px]"></i>' : '';

                                    $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                                    $formattedTime = ($evt['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));
                                    $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                                    $formattedEndTime = ($evt['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));
                                    $safeTitle = htmlspecialchars($evt['title']);
                                    $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');
                                    $safeVenue = htmlspecialchars($evt['venue_name'] ?? 'Not specified');

                                    echo "
                                    <div class='calendar-event-item {$color['bg']} border-l-[3px] border-y border-r border-y-slate-100 border-r-slate-100 dark:border-y-slate-700 dark:border-r-slate-700 {$color['border']} {$opacity} px-2 py-1.5 rounded-md text-[11px] font-bold truncate cursor-pointer hover:shadow-md hover:-translate-y-[2px] transition-all duration-200' 
                                        title='{$safeTitle}'
                                        data-title='{$safeTitle}'
                                        data-desc='{$safeDesc}'
                                        data-category='" . htmlspecialchars($evt['category_name']) . "' 
                                        data-venue='{$safeVenue}'
                                        data-date='{$formattedDate}'
                                        data-time='{$formattedTime}'
                                        data-end-date='{$formattedEndDate}'
                                        data-end-time='{$formattedEndTime}'
                                        onclick='openModal(this)'>
                                        <span class='{$color['text']}'>{$pendingIcon}{$safeTitle}</span>
                                    </div>
                                    ";
                                }
                                echo "</div>";
                            }

                            echo "</div>"; 
                        }

                        $totalBoxes = $firstDayOfWeek + $daysInMonth;
                        $remainingBoxes = 42 - $totalBoxes;
                        if ($remainingBoxes < 7) {
                            for ($i = 0; $i < $remainingBoxes; $i++) {
                                echo '<div class="bg-slate-50 dark:bg-[#0b1120] min-h-[140px] pointer-events-none"></div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[100] backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-lg overflow-hidden border border-slate-100 dark:border-slate-700 transform transition-all scale-95 opacity-0" id="modalContent">
            
            <div class="bg-slate-50 dark:bg-slate-900 p-6 flex justify-between items-start border-b border-slate-100 dark:border-slate-800">
                <h2 id="modalTitle" class="text-xl font-extrabold text-slate-800 dark:text-slate-100 leading-tight pr-4">Event Title</h2>
                <button onclick="closeModal()" class="text-slate-400 hover:text-red-500 transition bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-6">
                <div class="bg-white dark:bg-[#111827] p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Start</span>
                        <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700">
                            <i class="fa-regular fa-calendar text-sjsfi-green dark:text-emerald-500"></i>
                            <span id="modalDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700">
                            <i class="fa-regular fa-clock text-sjsfi-green dark:text-emerald-500"></i>
                            <span id="modalTime">Time</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="w-10 text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">End</span>
                        <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700">
                            <i class="fa-regular fa-calendar-check text-slate-400 dark:text-slate-500"></i>
                            <span id="modalEndDate">Date</span>
                        </div>
                        <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700">
                            <i class="fa-regular fa-clock text-slate-400 dark:text-slate-500"></i>
                            <span id="modalEndTime">Time</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Category</h3>
                        <p class="text-slate-700 dark:text-slate-200 font-bold bg-slate-50 dark:bg-slate-900 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-tag text-slate-400"></i>
                            <span id="modalCategory" class="truncate">Not categorized</span>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Venue</h3>
                        <p class="text-slate-700 dark:text-slate-200 font-bold bg-slate-50 dark:bg-slate-900 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-location-dot text-slate-400"></i>
                            <span id="modalVenue" class="truncate">Not specified</span>
                        </p>
                    </div>
                </div>

                <div>
                    <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Description</h3>
                    <p id="modalDesc" class="text-slate-600 dark:text-slate-300 text-sm whitespace-pre-line leading-relaxed bg-slate-50 dark:bg-slate-900 p-5 rounded-xl border border-slate-100 dark:border-slate-800 min-h-[100px] font-medium"></p>
                </div>
            </div>

            <div class="bg-white dark:bg-[#0b1120] px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                <button onclick="closeModal()" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm">Close Details</button>
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

    // --- MODAL LOGIC ---
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');

    function openModal(element) {
        document.getElementById('modalTitle').innerText = element.dataset.title;
        document.getElementById('modalDesc').innerText = element.dataset.desc;
        document.getElementById('modalDate').innerText = element.dataset.date;
        document.getElementById('modalTime').innerText = element.dataset.time;
        document.getElementById('modalEndDate').innerText = element.dataset.endDate;
        document.getElementById('modalEndTime').innerText = element.dataset.endTime;
        document.getElementById('modalCategory').innerText = element.dataset.category || 'Not categorized';
        document.getElementById('modalVenue').innerText = element.dataset.venue || 'Not specified';

        eventModal.classList.remove('hidden');
        eventModal.classList.add('flex');
        setTimeout(() => {
            eventModal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeModal() {
        eventModal.classList.add('opacity-0');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            eventModal.classList.add('hidden');
            eventModal.classList.remove('flex');
        }, 200);
    }

    eventModal.addEventListener('click', (e) => {
        if (e.target === eventModal) closeModal();
    });

    // --- PRESENTATION MODE LOGIC (ENHANCED) ---
    function togglePresentationMode() {
        const body = document.body;
        const btn = document.getElementById('presentationToggle');
        const btnText = btn.querySelector('span');
        const btnIcon = btn.querySelector('i');

        body.classList.toggle('presentation-mode');
        const isPresenting = body.classList.contains('presentation-mode');

        if (isPresenting) {
            btnText.innerText = 'Exit Presenting';
            btnIcon.className = 'fa-solid fa-compress';
            btn.classList.replace('bg-sjsfi-light', 'bg-red-500/10');
            btn.classList.replace('dark:bg-sky-500/10', 'dark:bg-red-500/10');
            btn.classList.replace('text-sjsfi-green', 'text-red-500');
            btn.classList.replace('dark:text-sky-400', 'dark:text-red-400');
            
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(e => console.log(e));
            }
        } else {
            btnText.innerText = 'Present';
            btnIcon.className = 'fa-solid fa-desktop';
            btn.classList.replace('bg-red-500/10', 'bg-sjsfi-light');
            btn.classList.replace('dark:bg-red-500/10', 'dark:bg-sky-500/10');
            btn.classList.replace('text-red-500', 'text-sjsfi-green');
            btn.classList.replace('dark:text-red-400', 'dark:text-sky-400');

            if (document.fullscreenElement) {
                document.exitFullscreen();
            }
        }
    }

    // --- FILTER LOGIC ---
    const searchBar = document.getElementById('search-bar');
    const categoryCheckboxes = document.querySelectorAll('.category-filter');
    const eventItems = document.querySelectorAll('.calendar-event-item');

    function applyFilters() {
        const searchTerm = searchBar ? searchBar.value.toLowerCase().trim() : '';
        const activeCategories = Array.from(categoryCheckboxes).filter(cb => cb.checked).map(cb => cb.value);

        eventItems.forEach(item => {
            const title = (item.getAttribute('data-title') || '').toLowerCase();
            const category = item.getAttribute('data-category') || '';
            const matchesSearch = title.includes(searchTerm);
            const matchesCategory = activeCategories.includes(category);

            if (matchesSearch && matchesCategory) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    if (searchBar) searchBar.addEventListener('input', applyFilters);
    categoryCheckboxes.forEach(cb => cb.addEventListener('change', applyFilters));

</script>
<script src="assets/js/pdf_modal.js"></script>
</html>