<?php
// calendar.php
require_once 'functions/database.php';

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
    SELECT e.*, c.category_name, p.status 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    WHERE DATE_FORMAT(e.start_date, '%Y-%m') = ?
    ORDER BY e.start_time ASC
");
$stmt->execute(["$year-$month"]);
$rawEvents = $stmt->fetchAll();

// Group events by their exact date so we can easily put them in the right box
$eventsByDate = [];
foreach ($rawEvents as $event) {
    $eventsByDate[$event['start_date']][] = $event;
}

// Updated Color Helper Function (Matched to index.php)
function getCategoryColor($categoryName) {
    $name = strtolower($categoryName);    
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false) 
        return ['text' => 'text-sky-300', 'bg' => 'bg-sky-500/10', 'border' => 'border-sky-500/30', 'ring' => 'focus:ring-sky-500', 'checkbox' => 'text-sky-500'];
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false) 
        return ['text' => 'text-emerald-300', 'bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-500/30', 'ring' => 'focus:ring-emerald-500', 'checkbox' => 'text-emerald-500'];
    if (strpos($name, 'mass') !== false) 
        return ['text' => 'text-violet-300', 'bg' => 'bg-violet-500/10', 'border' => 'border-violet-500/30', 'ring' => 'focus:ring-violet-500', 'checkbox' => 'text-violet-500'];
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false) 
        return ['text' => 'text-orange-300', 'bg' => 'bg-orange-500/10', 'border' => 'border-orange-500/30', 'ring' => 'focus:ring-orange-500', 'checkbox' => 'text-orange-500'];
    if (strpos($name, 'holiday') !== false) 
        return ['text' => 'text-amber-300', 'bg' => 'bg-amber-500/10', 'border' => 'border-amber-500/30', 'ring' => 'focus:ring-amber-500', 'checkbox' => 'text-amber-500'];
    return ['text' => 'text-zinc-200', 'bg' => 'bg-zinc-500/20', 'border' => 'border-zinc-500/40', 'ring' => 'focus:ring-zinc-500', 'checkbox' => 'text-zinc-500'];
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
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-zinc-950 text-zinc-200 font-sans h-screen flex overflow-hidden">

    <?php
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    ?>

    <aside class="w-80 bg-zinc-900/80 backdrop-blur-xl border-r border-zinc-800 flex flex-col flex-shrink-0 z-20">
        <div class="p-8 text-center border-b border-zinc-800">
            <div class="w-24 h-24 mx-auto bg-zinc-800 rounded-full flex items-center justify-center mb-4 overflow-hidden border-2 border-zinc-700 shadow-inner">
                <i class="fa-solid fa-user text-4xl text-zinc-500"></i>
            </div>
            <h2 class="text-2xl font-bold text-zinc-100">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-base text-emerald-400 capitalize font-medium mt-1">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-zinc-800">
                <h3 class="text-sm uppercase tracking-wider text-zinc-400 font-bold mb-4">Traversal</h3>
                <div class="space-y-3">
                    <a href="index.php" class="w-full hover:bg-zinc-800/50 text-zinc-300 hover:text-white font-semibold py-3 px-4 rounded-lg flex items-center gap-4 transition-colors text-lg">
                        <i class="fa-solid fa-list w-6 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="calendar.php" class="w-full bg-emerald-500/10 text-emerald-400 font-bold py-3 px-4 rounded-lg flex items-center gap-4 border border-emerald-500/30 transition-colors text-lg">
                        <i class="fa-regular fa-calendar-days w-6 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                </div>
            </div>

            <div class="p-6">
                <h3 class="text-sm uppercase tracking-wider text-zinc-400 font-bold mb-4">Quick Actions</h3>
                <div class="space-y-4">
                    <a href="add_event.php" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3.5 px-4 rounded-lg transition-all duration-300 flex items-center justify-center gap-3 shadow-lg shadow-emerald-900/20 text-lg">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <a href="functions/sync_holidays.php" class="w-full bg-zinc-800/80 hover:bg-zinc-700 text-zinc-200 font-semibold py-3.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-3 border border-zinc-600 text-lg">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                    </a>
                </div>
            </div>
        </div>
        
        <div class="p-6 mt-auto border-t border-zinc-800">
            <a href="logout.php" class="flex items-center gap-4 px-4 py-4 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-lg transition-colors font-bold text-lg border border-transparent hover:border-red-500/20">
                <i class="fa-solid fa-arrow-right-from-bracket text-xl"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8 relative bg-zinc-950">

        <div class="absolute top-0 left-1/4 w-96 h-96 bg-emerald-500 rounded-full mix-blend-screen filter blur-[100px] opacity-5 pointer-events-none z-0"></div>

        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-8 gap-4 relative z-10">
            <h1 class="text-4xl font-extrabold text-zinc-100 tracking-tight">Monthly Calendar</h1>
            
            <div class="flex items-center gap-3 bg-zinc-900/80 p-2 rounded-xl border border-zinc-700 backdrop-blur-sm">
                <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="p-3 rounded-lg hover:bg-zinc-800 text-zinc-300 hover:text-white transition-colors">
                    <i class="fa-solid fa-chevron-left text-xl"></i>
                </a>
                
                <h2 class="text-2xl font-bold w-48 text-center text-zinc-50">
                    <?php echo "$monthName $year"; ?>
                </h2>
                
                <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="p-3 rounded-lg hover:bg-zinc-800 text-zinc-300 hover:text-white transition-colors">
                    <i class="fa-solid fa-chevron-right text-xl"></i>
                </a>
                
                <div class="w-px h-8 bg-zinc-600 mx-2"></div>
                
                <a href="calendar.php" class="px-5 py-2.5 text-base font-bold text-zinc-200 hover:text-white hover:bg-zinc-700 rounded-lg transition-colors">
                    Today
                </a>
            </div>
        </div>

        <div class="bg-zinc-900/80 backdrop-blur-md border border-zinc-700 rounded-2xl p-5 mb-8 flex flex-col sm:flex-row items-center gap-5 relative z-20">
            <div class="relative w-full flex-1 group">
                <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-zinc-400 group-focus-within:text-emerald-400 transition-colors text-lg"></i>
                </div>
                <input type="text" id="search-bar" placeholder="Search events..." class="w-full pl-12 pr-5 py-3.5 bg-zinc-950/80 border border-zinc-600 text-zinc-100 text-lg rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/50 transition-all placeholder-zinc-500">
            </div>

            <div x-data="{ open: false }" class="relative w-full sm:w-auto">
                <button @click="open = !open" class="w-full sm:w-64 bg-zinc-950/80 border border-zinc-600 text-zinc-200 flex items-center justify-between gap-3 font-semibold py-3.5 px-5 rounded-xl hover:border-zinc-500 transition-all text-lg">
                    <i class="fa-solid fa-filter text-zinc-400"></i>
                    <span id="filter-button-text">All Categories</span>
                    <i class="fa-solid fa-chevron-down text-sm text-zinc-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                </button>

                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-3 w-full sm:w-80 bg-zinc-900 border border-zinc-600 rounded-2xl shadow-2xl z-30 p-5" style="display: none;">
                    <h4 class="text-sm font-bold text-zinc-400 uppercase tracking-wider mb-4">Filter by Category</h4>
                    <div class="space-y-4">
                        <?php foreach($categories as $cat): ?>
                            <?php $color = getCategoryColor($cat['category_name']); ?>
                            <label class="flex items-center space-x-4 cursor-pointer group">
                                <input type="checkbox" checked value="<?php echo htmlspecialchars($cat['category_name']); ?>" class="category-filter w-6 h-6 rounded <?php echo $color['checkbox']; ?> bg-zinc-950 border-zinc-500 focus:ring-offset-0 focus:ring-offset-transparent <?php echo $color['ring']; ?>">
                                <span class="group-hover:text-white transition-colors text-zinc-300 font-semibold text-lg"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-zinc-900/80 backdrop-blur-md border border-zinc-700 rounded-2xl overflow-hidden flex flex-col flex-1 min-h-[700px] relative z-10">
            
            <div class="grid grid-cols-7 border-b border-zinc-700 bg-zinc-950/80">
                <?php 
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                foreach ($days as $day): 
                ?>
                    <div class="py-4 text-center text-sm font-extrabold text-zinc-400 uppercase tracking-widest hidden lg:block">
                        <?php echo $day; ?>
                    </div>
                    <div class="py-4 text-center text-sm font-extrabold text-zinc-400 uppercase tracking-widest lg:hidden">
                        <?php echo substr($day, 0, 3); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-7 flex-1 bg-zinc-700 gap-px">
                
                <?php
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo '<div class="bg-zinc-950/60 min-h-[150px]"></div>';
                }

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    
                    $isToday = ($currentDate === date('Y-m-d'));
                    $dayClass = $isToday ? "bg-zinc-800 ring-2 ring-inset ring-emerald-500/50" : "bg-zinc-950/60 hover:bg-zinc-800 transition-colors";
                    
                    // The static number styling (no hover states needed)
                    $numberClass = $isToday ? "bg-emerald-500 text-white shadow-lg shadow-emerald-900/50" : "bg-zinc-900 text-zinc-300 border border-zinc-700 shadow-inner";

                    echo "<div class='{$dayClass} min-h-[150px] p-3 relative group flex flex-col'>";
                    
                    echo "<div class='flex justify-between items-start mb-4'>";
                    
                    // Left: The Static Date Number
                    echo "<span class='text-lg font-extrabold rounded-full w-10 h-10 flex items-center justify-center {$numberClass}'>{$day}</span>";

                    // Right: The Permanently Visible, Accessible Plus Button
                    echo "<a href='add_event.php?date={$currentDate}' class='text-zinc-400 bg-zinc-900/80 border border-zinc-700 hover:bg-emerald-500/20 hover:text-emerald-400 hover:border-emerald-500/50 rounded-lg w-10 h-10 flex items-center justify-center transition-all shadow-sm' title='Add new event to " . date('F j, Y', strtotime($currentDate)) . "'>";
                    echo "<i class='fa-solid fa-plus text-lg'></i>";
                    echo "</a>";
                    
                    echo "</div>";

                    if (isset($eventsByDate[$currentDate])) {
                        echo "<div class='flex flex-col gap-2 overflow-y-auto flex-1 no-scrollbar'>";
                        foreach ($eventsByDate[$currentDate] as $evt) {
                            $color = getCategoryColor($evt['category_name']);

                            $opacity = ($evt['status'] === 'Pending') ? 'opacity-70 border-dashed' : 'border-solid border-l-4';
                            $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-clock mr-1.5 text-xs"></i>' : '';

                            $shortTitle = strlen($evt['title']) > 20 ? substr($evt['title'], 0, 20) . '...' : $evt['title'];

                            $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                            $formattedTime = ($evt['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));
                            $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                            $formattedEndTime = ($evt['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));
                            $safeTitle = htmlspecialchars($evt['title']);
                            $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');
 
                            echo "
                            <div class='calendar-event-item {$color['bg']} {$color['text']} border {$color['border']} {$opacity} px-3 py-1.5 rounded-md text-sm font-bold truncate cursor-pointer hover:brightness-125 hover:shadow-md transition-all' 
                                title='{$safeTitle}'
                                data-title='{$safeTitle}'
                                data-desc='{$safeDesc}'
                                data-date='{$formattedDate}'
                                data-time='{$formattedTime}'
                                data-end-date='{$formattedEndDate}'
                                data-end-time='{$formattedEndTime}'
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
                if ($remainingBoxes < 7) { 
                    for ($i = 0; $i < $remainingBoxes; $i++) {
                        echo '<div class="bg-zinc-950/60 min-h-[150px]"></div>';
                    }
                }
                ?>
                
            </div>
        </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-zinc-950/90 hidden items-center justify-center z-50 backdrop-blur-md transition-opacity p-4">
        <div class="bg-zinc-900 border-2 border-zinc-700 rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden transform transition-all scale-95 opacity-0" id="modalContent">
            
            <div class="bg-zinc-950/80 p-6 flex justify-between items-center border-b border-zinc-700">
                <h2 id="modalTitle" class="text-3xl font-extrabold truncate text-emerald-400">Event Title</h2>
                <button onclick="closeModal()" class="text-zinc-400 hover:text-white transition bg-zinc-800 hover:bg-zinc-700 rounded-full w-12 h-12 flex items-center justify-center">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="p-8 space-y-6">
                
                <div class="bg-zinc-950/80 p-6 rounded-2xl border border-zinc-700 space-y-4 shadow-inner">
                    <div class="flex items-center gap-4 text-zinc-100 font-bold text-lg">
                        <span class="w-14 text-sm font-extrabold text-zinc-500 uppercase tracking-widest">Start</span>
                        <i class="fa-regular fa-calendar text-emerald-500 text-2xl"></i>
                        <span id="modalDate">Date</span>
                        <span class="text-zinc-600 mx-2">|</span>
                        <i class="fa-regular fa-clock text-emerald-500 text-2xl"></i>
                        <span id="modalTime">Time</span>
                    </div>

                    <div class="h-px bg-zinc-700 w-full ml-14"></div>

                    <div class="flex items-center gap-4 text-zinc-100 font-bold text-lg">
                        <span class="w-14 text-sm font-extrabold text-zinc-500 uppercase tracking-widest">End</span>
                        <i class="fa-regular fa-calendar-check text-red-400 text-2xl"></i>
                        <span id="modalEndDate">Date</span>
                        <span class="text-zinc-600 mx-2">|</span>
                        <i class="fa-regular fa-clock text-red-400 text-2xl"></i>
                        <span id="modalEndTime">Time</span>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-extrabold text-zinc-500 uppercase tracking-widest mb-3">Description</h3>
                    <p id="modalDesc" class="text-zinc-200 text-lg whitespace-pre-line leading-relaxed bg-zinc-950/80 p-6 rounded-2xl border border-zinc-700 min-h-[120px]"></p>
                </div>
            </div>

            <div class="bg-zinc-950/80 px-8 py-5 border-t border-zinc-700 flex justify-end">
                <button onclick="closeModal()" class="bg-zinc-700 hover:bg-zinc-600 text-white font-bold py-3 px-8 rounded-xl transition-colors text-lg shadow-md">Close</button>
            </div>
        </div>
    </div>

</body>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');

    function openModal(element) {
        document.getElementById('modalTitle').innerText = element.dataset.title;
        document.getElementById('modalDesc').innerText = element.dataset.desc;
        document.getElementById('modalDate').innerText = element.dataset.date;
        document.getElementById('modalTime').innerText = element.dataset.time;
        document.getElementById('modalEndDate').innerText = element.dataset.endDate;
        document.getElementById('modalEndTime').innerText = element.dataset.endTime;

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
        if (e.target === eventModal) {
            closeModal();
        }
    });
</script>
</html>