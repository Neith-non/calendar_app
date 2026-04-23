<?php
// calendar.php
require_once 'functions/database.php';
require_once 'functions/get_pending_count.php';

// 1. Get the requested Month and Year (Default to current month)
$month = isset($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// 2. Calendar Math
$dateString = "$year-$month-01";
$daysInMonth = date('t', strtotime($dateString));
$firstDayOfWeek = date('w', strtotime($dateString)); // 0 (Sun) to 6 (Sat)
$monthName = date('F', strtotime($dateString));

// Previous and Next month calculations for the arrows
$prevMonth = date('m', strtotime("-1 month", strtotime($dateString)));
$prevYear = date('Y', strtotime("-1 month", strtotime($dateString)));
$nextMonth = date('m', strtotime("+1 month", strtotime($dateString)));
$nextYear = date('Y', strtotime("+1 month", strtotime($dateString)));

// 3. Fetch Events for THIS specific month
$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        c.category_name, 
        p.status,
        v.venue_name -- Grabbing the actual name of the venue
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id -- Joining the venues table
    WHERE DATE_FORMAT(e.start_date, '%Y-%m') = ?
    ORDER BY e.start_time ASC
");
$stmt->execute(["$year-$month"]);
$rawEvents = $stmt->fetchAll();

$stmtCats = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmtCats->fetchAll();

// Fetch all participants linked to events using the upgraded ERD structure
$part_stmt = $pdo->query("
    SELECT ps.event_publish_id AS publish_id, p.name, d.name AS department
    FROM participant_schedule ps
    JOIN participants p ON ps.participant_id = p.id
    JOIN department d ON p.department_id = d.id
");

$event_participants_map = [];
while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
    // The strand is now perfectly baked into the name (e.g., 'Grade 11 (STEM)')
    $event_participants_map[$row['publish_id']][] = [
        'name' => $row['name'],
        'department' => $row['department']
    ];
}

// Group events by their exact date so we can easily put them in the right box
$eventsByDate = [];
foreach ($rawEvents as $event) {
    $eventsByDate[$event['start_date']][] = $event;
}

// Color Helper Function
function getCategoryColor($categoryName)
{
    $name = strtolower($categoryName);
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false)
        return ['text' => 'text-sky-300', 'bg' => 'bg-sky-500/20', 'border' => 'border-sky-500/30', 'ring' => 'focus:ring-sky-500', 'checkbox' => 'text-sky-500'];
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false)
        return ['text' => 'text-emerald-300', 'bg' => 'bg-emerald-500/20', 'border' => 'border-emerald-500/30', 'ring' => 'focus:ring-emerald-500', 'checkbox' => 'text-emerald-500'];
    if (strpos($name, 'mass') !== false)
        return ['text' => 'text-violet-300', 'bg' => 'bg-violet-500/20', 'border' => 'border-violet-500/30', 'ring' => 'focus:ring-violet-500', 'checkbox' => 'text-violet-500'];
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false)
        return ['text' => 'text-orange-300', 'bg' => 'bg-orange-500/20', 'border' => 'border-orange-500/30', 'ring' => 'focus:ring-orange-500', 'checkbox' => 'text-orange-500'];
    if (strpos($name, 'holiday') !== false)
        return ['text' => 'text-yellow-300', 'bg' => 'bg-yellow-500/20', 'border' => 'border-yellow-500/30', 'ring' => 'focus:ring-yellow-500', 'checkbox' => 'text-yellow-500'];
    return ['text' => 'text-slate-300', 'bg' => 'bg-slate-500/20', 'border' => 'border-slate-500/30', 'ring' => 'focus:ring-slate-500', 'checkbox' => 'text-slate-500'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="assets/css/styles.css">

    <style>
        body.presentation-mode aside { display: none !important; }
        body.presentation-mode a, body.presentation-mode button, body.presentation-mode input, body.presentation-mode .calendar-event-item {
            pointer-events: none !important; cursor: default !important;
        }
        body.presentation-mode .action-btn { opacity: 0 !important; }
        body.presentation-mode #presentationToggle { pointer-events: auto !important; cursor: pointer !important; }

        /* Custom scrollbar for modal */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }
    </style>
</head>

<body class="dashboard-body h-screen flex overflow-hidden">

    <?php
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    ?>

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
                <i class="fa-solid fa-arrow-right-from-bracket"></i> <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-white">Monthly Calendar</h1>
            <div class="flex items-center gap-4">
                <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="p-2 rounded-full hover:bg-white/10 text-slate-300 hover:text-white transition">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <h2 class="text-xl font-bold w-48 text-center text-white"><?php echo "$monthName $year"; ?></h2>
                <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="p-2 rounded-full hover:bg-white/10 text-slate-300 hover:text-white transition">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                <a href="calendar.php" class="ml-2 bg-white/10 hover:bg-white/20 text-white px-4 py-1.5 rounded-lg text-sm font-semibold border border-white/20 transition">Today</a>
                <?php if (isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], ['Head Scheduler', 'Admin'])): ?>
                    <button id="presentationToggle" onclick="togglePresentationMode()" class="bg-blue-500/20 hover:bg-blue-500/40 text-blue-300 px-4 py-1.5 rounded-lg text-sm font-semibold border border-blue-500/30 transition flex items-center gap-2">
                        <i class="fa-solid fa-desktop"></i> <span>Present</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="empty-state-message" class="hidden glass-container rounded-xl p-8 mb-6 flex-col items-center justify-center text-center border border-yellow-500/30 bg-yellow-500/10">
            <div class="w-16 h-16 bg-yellow-500/20 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-calendar-xmark text-3xl text-yellow-400"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">No events found</h3>
            <p class="text-slate-300">Try adjusting your search or category filters.</p>
        </div>

        <div class="glass-container rounded-xl p-4 mb-6 flex flex-col sm:flex-row items-center gap-4 relative z-10">
            <div class="relative w-full flex-1">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-slate-400"></i>
                </div>
                <input type="text" id="search-bar" placeholder="Search events..." class="form-input-glass w-full pl-11 pr-4 py-2.5 rounded-lg">
            </div>

            <div x-data="{ open: false }" class="relative w-full sm:w-auto">
                <button @click="open = !open" class="form-input-glass w-full sm:w-56 flex items-center justify-between gap-2 font-semibold py-2.5 px-4 rounded-lg transition">
                    <i class="fa-solid fa-filter text-slate-400"></i>
                    <span id="filter-button-text">All Categories</span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                </button>

                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-full sm:w-72 bg-[#002a1d] border border-white/20 rounded-xl shadow-lg z-20 p-4" style="display: none;">
                    <h4 class="text-sm font-bold text-slate-300 mb-3">Filter by Category</h4>
                    <div class="space-y-3">
                        <?php foreach ($categories as $cat): ?>
                            <?php $color = getCategoryColor($cat['category_name']); ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" checked value="<?php echo htmlspecialchars($cat['category_name']); ?>" class="category-filter w-5 h-5 rounded <?php echo $color['checkbox']; ?> bg-transparent border-slate-500 focus:ring-offset-0 focus:ring-offset-transparent <?php echo $color['ring']; ?>">
                                <span class="group-hover:text-yellow-400 transition-colors text-slate-200 font-medium"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="glass-container rounded-xl overflow-hidden flex flex-col flex-1 min-h-[600px]">
            <div class="grid grid-cols-7 border-b border-white/10 bg-black/20">
                <?php
                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($days as $day):
                    ?>
                    <div class="py-3 text-center text-xs font-bold text-slate-400 uppercase tracking-wider">
                        <?php echo $day; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-7 grid-rows-6 flex-1 bg-white/10 gap-px">
                <?php
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    // FIX 2: Removed 'min-h-[120px]' so the grid handles the height automatically
                    echo '<div class="bg-black/10"></div>';
                }

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $isToday = ($currentDate === date('Y-m-d'));
                    $dayClass = $isToday ? "bg-black/20" : "bg-black/10";
                    $numberClass = $isToday ? "bg-yellow-500 text-dark-green rounded-full w-7 h-7 flex items-center justify-center font-bold" : "text-slate-300 font-semibold p-1";

                    // FIX 3: Removed 'min-h-[120px]' and added 'overflow-y-auto custom-scrollbar flex flex-col' 
                    // Now, if a day has too many events, you can scroll inside that specific day!
                    echo "<div class='{$dayClass} p-2 hover:bg-black/20 transition relative group overflow-y-auto custom-scrollbar flex flex-col'>";
                    $hoverClass = $isToday ? "hover:bg-yellow-600" : "hover:bg-white/10 hover:text-white cursor-pointer rounded-full transition";

                    echo "<div class='flex justify-between items-start mb-1 flex-shrink-0'>";

                    if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')) {
                        echo "<a href='add_event.php?date={$currentDate}' class='action-btn text-sm {$numberClass} {$hoverClass} inline-flex items-center justify-center w-7 h-7' title='Add event on " . date('F j, Y', strtotime($currentDate)) . "'>{$day}</a>";
                        echo "<a href='add_event.php?date={$currentDate}' class='action-btn opacity-0 group-hover:opacity-100 text-slate-400 hover:text-yellow-400 transition p-1'><i class='fa-solid fa-plus text-xs'></i></a>";
                    } else {
                        $plainNumberClass = $isToday ? "bg-yellow-500 text-dark-green rounded-full w-7 h-7 flex items-center justify-center font-bold" : "text-slate-300 font-semibold p-1 inline-flex items-center justify-center w-7 h-7";
                        echo "<span class='text-sm {$plainNumberClass}'>{$day}</span>";
                    }

                    echo "</div>";

                    if (isset($eventsByDate[$currentDate])) {
                        echo "<div class='flex flex-col gap-1 mt-1'>";
                        foreach ($eventsByDate[$currentDate] as $evt) {
                            $color = getCategoryColor($evt['category_name']);
                            $opacity = ($evt['status'] === 'Pending') ? 'opacity-60 border-dashed' : '';
                            $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-clock mr-1"></i>' : '';
                            $shortTitle = strlen($evt['title']) > 12 ? substr($evt['title'], 0, 12) . '...' : $evt['title'];

                            $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                            $formattedTime = ($evt['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));
                            $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                            $formattedEndTime = ($evt['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));

                            $safeTitle = htmlspecialchars($evt['title']);
                            $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');
                            $safeVenue = htmlspecialchars($evt['venue_name'] ?? 'Not specified');

                            // Fetch Participants
                            $participants_array = $evt['publish_id'] ? ($event_participants_map[$evt['publish_id']] ?? []) : [];
                            $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');

                            echo "
                            <div class='calendar-event-item {$color['bg']} {$color['text']} border {$color['border']} {$opacity} px-2 py-1 rounded text-xs font-semibold truncate cursor-pointer hover:bg-white/20 hover:border-yellow-400/50 transition' 
                                title='{$safeTitle}'
                                data-title='{$safeTitle}'
                                data-desc='{$safeDesc}'
                                data-category='" . htmlspecialchars($evt['category_name']) . "' 
                                data-venue='{$safeVenue}'
                                data-date='{$formattedDate}'
                                data-time='{$formattedTime}'
                                data-end-date='{$formattedEndDate}'
                                data-end-time='{$formattedEndTime}'
                                data-participants='{$jsParticipants}'
                                onclick='openModal(this)'>
                                {$pendingIcon}{$shortTitle}
                            </div>
                            ";
                        }
                        echo "</div>";
                    }

                    echo "</div>"; 
                }

                $totalBoxes = $firstDayOfWeek + $daysInMonth;
                $remainingBoxes = 42 - $totalBoxes;
                if ($remainingBoxes > 0) {
                    for ($i = 0; $i < $remainingBoxes; $i++) {
                        // FIX 2 Applied here as well: Removed min-h-[120px]
                        echo '<div class="bg-black/10"></div>';
                    }
                }
                ?>
            </div>
        </div>
    </main>


</body>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="assets/js/event_modal.js"></script>
<script src="assets/js/calendar.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/pdf_modal.js"></script>

</html>