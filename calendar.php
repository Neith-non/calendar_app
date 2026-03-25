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

// Color Helper Function
function getCategoryColor($categoryName) {
    $name = strtolower($categoryName);
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false) return ['text' => 'text-blue-700', 'bg' => 'bg-blue-100', 'border' => 'border-blue-200'];
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false) return ['text' => 'text-green-700', 'bg' => 'bg-green-100', 'border' => 'border-green-200'];
    if (strpos($name, 'mass') !== false) return ['text' => 'text-purple-700', 'bg' => 'bg-purple-100', 'border' => 'border-purple-200'];
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false) return ['text' => 'text-orange-700', 'bg' => 'bg-orange-100', 'border' => 'border-orange-200'];
    if (strpos($name, 'holiday') !== false) return ['text' => 'text-yellow-700', 'bg' => 'bg-yellow-100', 'border' => 'border-yellow-200'];
    return ['text' => 'text-slate-700', 'bg' => 'bg-slate-100', 'border' => 'border-slate-200'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar View - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col font-sans">

    <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between shadow-sm shrink-0">
        <div class="flex items-center gap-4">
            <a href="index.php" class="text-slate-500 hover:text-blue-600 font-medium transition flex items-center gap-2">
                <i class="fa-solid fa-list"></i> List View
            </a>
            <div class="h-6 w-px bg-slate-300"></div>
            <h1 class="text-2xl font-bold text-slate-800">
                <i class="fa-regular fa-calendar-days text-blue-600 mr-2"></i> Monthly Calendar
            </h1>
        </div>
        
        <div class="flex items-center gap-4">
            <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="p-2 rounded-full hover:bg-slate-100 text-slate-600 transition">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            
            <h2 class="text-xl font-bold w-48 text-center text-slate-700">
                <?php echo "$monthName $year"; ?>
            </h2>
            
            <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="p-2 rounded-full hover:bg-slate-100 text-slate-600 transition">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            
            <a href="calendar.php" class="ml-2 bg-blue-50 text-blue-600 hover:bg-blue-100 px-4 py-1.5 rounded-lg text-sm font-semibold border border-blue-200 transition">
                Today
            </a>
        </div>
    </header>

    <main class="flex-1 overflow-auto p-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full min-h-[600px]">
            
            <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50">
                <?php 
                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($days as $day): 
                ?>
                    <div class="py-3 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">
                        <?php echo $day; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-7 flex-1 bg-slate-200 gap-px border-b border-slate-200">
                
                <?php
                // 1. Draw blank boxes for days before the 1st of the month
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo '<div class="bg-slate-50 min-h-[120px]"></div>';
                }

                // 2. Draw the actual days (1 to $daysInMonth)
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    // Create the date string for this specific box (e.g., "2026-03-05")
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    
                    // Highlight today's date if we are currently looking at today
                    $isToday = ($currentDate === date('Y-m-d'));
                    $dayClass = $isToday ? "bg-blue-50" : "bg-white";
                    $numberClass = $isToday ? "bg-blue-600 text-white rounded-full w-7 h-7 flex items-center justify-center font-bold" : "text-slate-600 font-semibold p-1";

                    echo "<div class='{$dayClass} min-h-[120px] p-2 hover:bg-slate-50 transition relative group'>";
                    
                    // The Date Number (Now a Clickable Link!)
                    echo "<div class='flex justify-between items-start mb-1'>";

                    // Add a hover effect so the user knows they can click the number
                    $hoverClass = $isToday ? "hover:bg-blue-700 hover:shadow-md" : "hover:bg-blue-100 hover:text-blue-700 cursor-pointer rounded-full transition";

                    echo "<a href='add_event.php?date={$currentDate}' class='text-sm {$numberClass} {$hoverClass} inline-flex items-center justify-center w-7 h-7' title='Add event on " . date('F j, Y', strtotime($currentDate)) . "'>{$day}</a>";

                    // Keep the tiny "+" button for extra clarity
                    echo "<a href='add_event.php?date={$currentDate}' class='opacity-0 group-hover:opacity-100 text-slate-400 hover:text-blue-600 transition p-1'><i class='fa-solid fa-plus text-xs'></i></a>";
                    echo "</div>";

                    // Display Events for this Day
                    if (isset($eventsByDate[$currentDate])) {
                        

                        echo "<div class='flex flex-col gap-1 mt-2'>";
                        foreach ($eventsByDate[$currentDate] as $evt) {
                            $color = getCategoryColor($evt['category_name']);
                            
                            $color = getCategoryColor($evt['category_name']);

                            // Visual cue if it's pending
                            $opacity = ($evt['status'] === 'Pending') ? 'opacity-60 border-dashed' : '';
                            $pendingIcon = ($evt['status'] === 'Pending') ? '<i class="fa-solid fa-clock mr-1"></i>' : '';

                            // Shorten the title so it fits in the box
                            $shortTitle = strlen($evt['title']) > 15 ? substr($evt['title'], 0, 15) . '...' : $evt['title'];

                            // Format Data for the Modal
                            
                            $formattedDate = date('F j, Y', strtotime($evt['start_date']));
                            $formattedTime = ($evt['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['start_time']));

                            // NEW: Format the End Date/Time
                            $formattedEndDate = date('F j, Y', strtotime($evt['end_date']));
                            $formattedEndTime = ($evt['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($evt['end_time']));
                            $safeTitle = htmlspecialchars($evt['title']);
                            $safeDesc = htmlspecialchars($evt['description'] ?? 'No description provided.');

                            echo "
                            <div class='{$color['bg']} {$color['text']} border {$color['border']} {$opacity} px-2 py-1 rounded text-xs font-semibold truncate shadow-sm cursor-pointer hover:shadow-md transition' 
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

                    echo "</div>"; // End Day Box
                }

                // 3. Fill in the remaining blank boxes at the end of the grid
                $totalBoxes = $firstDayOfWeek + $daysInMonth;
                $remainingBoxes = 42 - $totalBoxes; // 42 is a standard 6-row calendar grid
                if ($remainingBoxes < 7) { 
                    for ($i = 0; $i < $remainingBoxes; $i++) {
                        echo '<div class="bg-slate-50 min-h-[120px]"></div>';
                    }
                }
                ?>
                
            </div>
        </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0" id="modalContent">
        
        <div class="bg-blue-600 p-4 flex justify-between items-center text-white">
            <h2 id="modalTitle" class="text-xl font-bold truncate">Event Title</h2>
            <button onclick="closeModal()" class="text-white hover:text-blue-200 transition bg-blue-700 hover:bg-blue-800 rounded-full w-8 h-8 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="p-6 space-y-4">
            
            <div class="bg-slate-50 p-4 rounded-lg border border-slate-100 space-y-3 shadow-inner">
                
                <div class="flex items-center gap-3 text-slate-700 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">Start</span>
                    <i class="fa-regular fa-calendar text-emerald-500 text-lg"></i>
                    <span id="modalDate">Date</span>
                    <span class="text-slate-300 mx-1">|</span>
                    <i class="fa-regular fa-clock text-emerald-500 text-lg"></i>
                    <span id="modalTime">Time</span>
                </div>

                <div class="h-px bg-slate-200 w-full ml-12"></div>

                <div class="flex items-center gap-3 text-slate-700 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">End</span>
                    <i class="fa-regular fa-calendar-check text-red-400 text-lg"></i>
                    <span id="modalEndDate">Date</span>
                    <span class="text-slate-300 mx-1">|</span>
                    <i class="fa-regular fa-clock text-red-400 text-lg"></i>
                    <span id="modalEndTime">Time</span>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Description</h3>
                <p id="modalDesc" class="text-slate-700 whitespace-pre-line leading-relaxed bg-slate-50 p-4 rounded-lg border border-slate-200 min-h-[80px]"></p>
            </div>
        </div>

        <div class="bg-slate-50 px-6 py-4 border-t border-slate-200 flex justify-end">
            <button onclick="closeModal()" class="bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold py-2 px-4 rounded-lg transition">Close</button>
        </div>
    </div>
</div>

</body>
<script src="assets/js/filter.js"></script>
</html>