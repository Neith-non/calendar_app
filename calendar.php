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

$stmtCats = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmtCats->fetchAll();

// Group events by their exact date so we can easily put them in the right box
$eventsByDate = [];
foreach ($rawEvents as $event) {
    $eventsByDate[$event['start_date']][] = $event;
}

// Color Helper Function
function getCategoryColor($categoryName) {
    $name = strtolower($categoryName);    
    // Returns an array of Tailwind classes optimized for a dark theme
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
    // Default fallback (Slate)
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
    <!-- Frontend Change: Added Google Font for a more modern typeface -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Frontend Change: Linked our new global stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<!-- Frontend Change: Added 'dashboard-body' class for the new background and layout -->
<body class="dashboard-body h-screen flex overflow-hidden">

    <?php
    // We need session data for the sidebar, so let's start it
    // And also check if the user is logged in.
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    ?>

    <!-- Frontend Change: Main sidebar container with the glassmorphism effect. This is new to this page for consistency. -->
    <aside class="w-72 glass-container flex flex-col flex-shrink-0 z-10">
        <!-- Mini Profile Container (in sidebar) -->
        <div class="p-8 text-center border-b border-white/10">
            <!-- User Avatar Placeholder -->
            <div class="w-20 h-20 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white/20">
                <i class="fa-solid fa-user text-3xl text-white/50"></i>
            </div>
            <!-- Backend Note: User's name and role are displayed here. We just styled the text. -->
            <h2 class="text-xl font-bold text-white">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-sm text-yellow-400 capitalize">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <!-- Frontend Change: This container holds the main navigation sections of the sidebar -->
        <div class="flex-1 overflow-y-auto">
            <!-- Traversal Links Container -->
            <div class="p-6 border-b border-white/10">
                <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Traversal</h3>
                <div class="space-y-2">
                    <!-- "All Schedule Events" Link -->
                    <a href="index.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <!-- "View Calendar" Link (Active State) -->
                    <a href="calendar.php" class="w-full bg-white/20 text-white font-semibold py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors border border-white/30">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                </div>
            </div>

            <!-- Quick Actions Container -->
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
        
        <!-- Logout Button Container (at bottom of sidebar) -->
        <div class="p-6 mt-auto border-t border-white/10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Frontend Change: Main content area for the calendar -->
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">

        <!-- Frontend Change: Calendar Header Container -->
        <div class="flex items-center justify-between mb-6">
            <!-- Page Title -->
            <h1 class="text-3xl font-bold text-white">Monthly Calendar</h1>
            <!-- Month Navigation Controls -->
            <div class="flex items-center gap-4">
                <!-- Previous Month Arrow -->
                <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="p-2 rounded-full hover:bg-white/10 text-slate-300 hover:text-white transition">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                
                <!-- Current Month and Year Display -->
                <h2 class="text-xl font-bold w-48 text-center text-white">
                    <?php echo "$monthName $year"; ?>
                </h2>
                
                <!-- Next Month Arrow -->
                <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="p-2 rounded-full hover:bg-white/10 text-slate-300 hover:text-white transition">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                
                <!-- "Today" Button -->
                <a href="calendar.php" class="ml-2 bg-white/10 hover:bg-white/20 text-white px-4 py-1.5 rounded-lg text-sm font-semibold border border-white/20 transition">
                    Today
                </a>
            </div>
        </div>

        <div id="empty-state-message" class="hidden glass-container rounded-xl p-8 mb-6 flex-col items-center justify-center text-center border border-yellow-500/30 bg-yellow-500/10">
            <div class="w-16 h-16 bg-yellow-500/20 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-calendar-xmark text-3xl text-yellow-400"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">No events found</h3>
            <p class="text-slate-300">Try adjusting your search or category filters.</p>
        </div>

        <!-- Frontend Change: Controls container (Search and Filter) added for consistency. -->
        <div class="glass-container rounded-xl p-4 mb-6 flex flex-col sm:flex-row items-center gap-4 relative z-10">
            <!-- Search Bar Container -->
            <div class="relative w-full flex-1">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-slate-400"></i>
                </div>
                <!-- Search bar placeholder with 'form-input-glass' style -->
                <input type="text" id="search-bar" placeholder="Search events..." class="form-input-glass w-full pl-11 pr-4 py-2.5 rounded-lg">
            </div>

            <!-- Category Filter Dropdown Container -->
            <div x-data="{ open: false }" class="relative w-full sm:w-auto">
                <!-- Filter dropdown button with 'form-input-glass' style -->
                <button @click="open = !open" class="form-input-glass w-full sm:w-56 flex items-center justify-between gap-2 font-semibold py-2.5 px-4 rounded-lg transition">
                    <i class="fa-solid fa-filter text-slate-400"></i>
                    <span id="filter-button-text">All Categories</span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                </button>

                <!-- Dropdown panel with a solid background for visibility -->
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-full sm:w-72 bg-[#002a1d] border border-white/20 rounded-xl shadow-lg z-20 p-4" style="display: none;">
                    <h4 class="text-sm font-bold text-slate-300 mb-3">Filter by Category</h4>
                    <div class="space-y-3">
                        <?php foreach($categories as $cat): ?>
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

        <!-- Frontend Change: Main calendar grid container with glassmorphism effect -->
        <div class="glass-container rounded-xl overflow-hidden flex flex-col flex-1 min-h-[600px]">
            
            <!-- Day of the Week Header Row -->
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

            <!-- Calendar Days Grid -->
            <div class="grid grid-cols-7 flex-1 bg-white/10 gap-px">
                
                <?php
                // 1. Draw blank boxes for days before the 1st of the month
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo '<div class="bg-black/10 min-h-[120px]"></div>';
                }

                // 2. Draw the actual days (1 to $daysInMonth)
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    // Create the date string for this specific box (e.g., "2026-03-05")
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    
                    // Highlight today's date if we are currently looking at today
                    $isToday = ($currentDate === date('Y-m-d'));
                    $dayClass = $isToday ? "bg-black/20" : "bg-black/10";
                    $numberClass = $isToday ? "bg-yellow-500 text-dark-green rounded-full w-7 h-7 flex items-center justify-center font-bold" : "text-slate-300 font-semibold p-1";

                    echo "<div class='{$dayClass} min-h-[120px] p-2 hover:bg-black/20 transition relative group'>";
                    
                    // The Date Number (Now a Clickable Link!)
                    // Add a hover effect so the user knows they can click the number
                    $hoverClass = $isToday ? "hover:bg-yellow-600" : "hover:bg-white/10 hover:text-white cursor-pointer rounded-full transition";

                    // The Date Number Header
                    echo "<div class='flex justify-between items-start mb-1'>";

                    // Check role (Added parentheses around the OR statement for safety)
                    if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')) {
                    // HEAD SCHEDULER: Gets the clickable link and the '+' icon
                    echo "<a href='add_event.php?date={$currentDate}' class='text-sm {$numberClass} {$hoverClass} inline-flex items-center justify-center w-7 h-7' title='Add event on " . date('F j, Y', strtotime($currentDate)) . "'>{$day}</a>";
                    echo "<a href='add_event.php?date={$currentDate}' class='opacity-0 group-hover:opacity-100 text-slate-400 hover:text-yellow-400 transition p-1'><i class='fa-solid fa-plus text-xs'></i></a>";
                } else {
                    // ADMIN / VIEWER: Just sees the number as plain text, no links, no '+'
                    // We remove the $hoverClass so it doesn't look clickable
                    $plainNumberClass = $isToday ? "bg-yellow-500 text-dark-green rounded-full w-7 h-7 flex items-center justify-center font-bold" : "text-slate-300 font-semibold p-1 inline-flex items-center justify-center w-7 h-7";
                    echo "<span class='text-sm {$plainNumberClass}'>{$day}</span>";
                }

                echo "</div>";

                    // Display Events for this Day
                    if (isset($eventsByDate[$currentDate])) {
                        

                        echo "<div class='flex flex-col gap-1 mt-2'>";
                        foreach ($eventsByDate[$currentDate] as $evt) {
                            $color = getCategoryColor($evt['category_name']);

                            // Visual cue if it's pending
                            $opacity = ($evt['status'] === 'Pending') ? 'opacity-60 border-dashed' : '';
                            $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-clock mr-1"></i>' : '';

                            // Shorten the title so it fits in the box
                            $shortTitle = strlen($evt['title']) > 12 ? substr($evt['title'], 0, 12) . '...' : $evt['title'];

                            // Format Data for the Modal
                            
                            $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                            $formattedTime = ($evt['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));

                            // NEW: Format the End Date/Time
                            $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                            $formattedEndTime = ($evt['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));
                            $safeTitle = htmlspecialchars($evt['title']);
                            $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');
 
                           echo "
                            <div class='calendar-event-item {$color['bg']} {$color['text']} border {$color['border']} {$opacity} px-2 py-1 rounded text-xs font-semibold truncate cursor-pointer hover:bg-white/20 hover:border-yellow-400/50 transition' 
                                title='{$safeTitle}'
                                data-title='{$safeTitle}'
                                data-desc='{$safeDesc}'
                                data-category='" . htmlspecialchars($evt['category_name']) . "' 
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

                    echo "</div>"; // End Day Box
                }

                // 3. Fill in the remaining blank boxes at the end of the grid
                $totalBoxes = $firstDayOfWeek + $daysInMonth;
                $remainingBoxes = 42 - $totalBoxes; // 42 is a standard 6-row calendar grid
                if ($remainingBoxes < 7) { 
                    for ($i = 0; $i < $remainingBoxes; $i++) {
                        echo '<div class="bg-black/10 min-h-[120px]"></div>';
                    }
                }
                ?>
                
            </div>
        </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4">
    <div class="glass-container rounded-xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0" id="modalContent">
        
        <div class="bg-black/20 p-4 flex justify-between items-center border-b border-white/10">
            <h2 id="modalTitle" class="text-xl font-bold truncate text-yellow-400">Event Title</h2>
            <button onclick="closeModal()" class="text-white/70 hover:text-white transition bg-white/10 hover:bg-white/20 rounded-full w-8 h-8 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="p-6 space-y-4">
            
            <div class="bg-black/20 p-4 rounded-lg border border-white/10 space-y-3">
                
                <div class="flex items-center gap-3 text-slate-200 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">Start</span>
                    <i class="fa-regular fa-calendar text-emerald-500 text-lg"></i>
                    <span id="modalDate">Date</span>
                    <span class="text-white/20 mx-1">|</span>
                    <i class="fa-regular fa-clock text-emerald-500 text-lg"></i>
                    <span id="modalTime">Time</span>
                </div>

                <div class="h-px bg-white/10 w-full ml-12"></div>

                <div class="flex items-center gap-3 text-slate-200 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">End</span>
                    <i class="fa-regular fa-calendar-check text-red-400 text-lg"></i>
                    <span id="modalEndDate">Date</span>
                    <span class="text-white/20 mx-1">|</span>
                    <i class="fa-regular fa-clock text-red-400 text-lg"></i>
                    <span id="modalEndTime">Time</span>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Description</h3>
                <p id="modalDesc" class="text-slate-300 whitespace-pre-line leading-relaxed bg-black/20 p-4 rounded-lg border border-white/10 min-h-[80px]"></p>
            </div>
        </div>

        <div class="bg-black/20 px-6 py-4 border-t border-white/10 flex justify-end">
            <button onclick="closeModal()" class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2 px-4 rounded-lg transition">Close</button>
        </div>
    </div>
</div>

</body>
<!-- Frontend Change: Added AlpineJS for dropdown functionality -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
    // Basic modal functionality for demonstration
    const eventModal = document.getElementById('eventModal');
    const modalContent = document.getElementById('modalContent');

    function openModal(element) {
        // Populate modal with data attributes from the clicked event
        document.getElementById('modalTitle').innerText = element.dataset.title;
        document.getElementById('modalDesc').innerText = element.dataset.desc;
        document.getElementById('modalDate').innerText = element.dataset.date;
        document.getElementById('modalTime').innerText = element.dataset.time;
        document.getElementById('modalEndDate').innerText = element.dataset.endDate;
        document.getElementById('modalEndTime').innerText = element.dataset.endTime;

        // Show modal with transition
        eventModal.classList.remove('hidden');
        eventModal.classList.add('flex');
        setTimeout(() => {
            eventModal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeModal() {
        // Hide modal with transition
        eventModal.classList.add('opacity-0');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            eventModal.classList.add('hidden');
            eventModal.classList.remove('flex');
        }, 200);
    }

    // Close modal if backdrop is clicked
    eventModal.addEventListener('click', (e) => {
        if (e.target === eventModal) {
            closeModal();
        }
    });

</script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Grab our elements
        const checkboxes = document.querySelectorAll('.category-filter');
        const filterButtonText = document.getElementById('filter-button-text');
        const searchBar = document.getElementById('search-bar');
        const calendarEvents = document.querySelectorAll('.calendar-event-item');
        const emptyStateMessage = document.getElementById('empty-state-message'); // NEW

        // 1. Update the text on the dropdown button
        function updateFilterButton() {
            if (!filterButtonText) return;
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (checkedCount === 0) {
                filterButtonText.innerText = 'No Categories';
            } else if (checkedCount === checkboxes.length) {
                filterButtonText.innerText = 'All Categories';
            } else {
                filterButtonText.innerText = `${checkedCount} Categories Selected`;
            }
        }

        // 2. The Master Filter Engine! (Now tracks visible events)
        function filterEvents() {
            const searchTerm = searchBar ? searchBar.value.toLowerCase() : '';
            
            // Get an array of whatever category checkboxes are currently ticked
            const activeCategories = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value.toLowerCase());

            let visibleCount = 0; // NEW: Keep track of how many events are showing

            // Loop through every single event on the calendar
            calendarEvents.forEach(event => {
                const title = (event.getAttribute('data-title') || '').toLowerCase();
                const desc = (event.getAttribute('data-desc') || '').toLowerCase();
                const category = (event.getAttribute('data-category') || '').toLowerCase();
                const eventDate = (event.getAttribute('data-date') || '').toLowerCase();
                
                // Check if the search text matches the Title, Description, Category, OR the Date!
                const matchesSearch = title.includes(searchTerm) || 
                                      desc.includes(searchTerm) || 
                                      category.includes(searchTerm) || 
                                      eventDate.includes(searchTerm);
                
                // Check if the event's category is currently checked in the dropdown
                const matchesCategory = activeCategories.includes(category);

                // If it passes BOTH tests, show it and increase the count
                if (matchesSearch && matchesCategory) {
                    event.style.display = 'block'; 
                    visibleCount++; 
                } else {
                    event.style.display = 'none'; 
                }
            });

            // NEW: Show or hide the empty state message based on the count
            if (emptyStateMessage) {
                if (visibleCount === 0) {
                    emptyStateMessage.classList.remove('hidden');
                    emptyStateMessage.classList.add('flex');
                } else {
                    emptyStateMessage.classList.add('hidden');
                    emptyStateMessage.classList.remove('flex');
                }
            }
        }

        // --- Event Listeners ---
        
        // Listen for typing in the search bar
        if (searchBar) {
            searchBar.addEventListener('input', filterEvents);
        }

        // Listen for checking/unchecking boxes
        checkboxes.forEach(box => {
            box.addEventListener('change', () => {
                updateFilterButton();
                filterEvents();
            });
        });

        // Run once on page load to set the initial state
        updateFilterButton();
        filterEvents(); // NEW: Run filter on load just in case a month has 0 events to begin with!
    });
</script>
</html>