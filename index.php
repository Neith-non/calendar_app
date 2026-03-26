<?php
// index.php

// 1. Include database connection from the functions folder
require_once 'functions/database.php';

// 2. Fetch Categories for the Sidebar
$stmt = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmt->fetchAll();

// 3. Fetch ONLY This Year's Events (With Publish Status)
$currentYear = date('Y'); // Get the current year dynamically

$stmt = $pdo->prepare("
    SELECT e.*, c.category_name, p.status 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    WHERE YEAR(e.start_date) = :current_year
    ORDER BY e.start_date ASC, e.start_time ASC
");

// Execute the prepared statement securely
$stmt->execute([':current_year' => $currentYear]);
$events = $stmt->fetchAll();

// Helper function to map Category Names to FULL Tailwind Classes
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
    <title>St. Joseph School Foundation - Event List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Frontend Change: Added Google Font for a more modern typeface -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Note: A lightweight JS library is used for the dropdown component -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Frontend Change: Linked our new global stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<!-- Frontend Change: Added 'dashboard-body' class for the new background and layout -->
<body class="dashboard-body h-screen flex overflow-hidden">

    <!-- Frontend Change: Main sidebar container with the glassmorphism effect -->
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
                    <!-- "All Schedule Events" Link (Active State) -->
                    <a href="index.php" class="w-full bg-white/20 text-white font-semibold py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors border border-white/30">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <!-- "View Calendar" Link -->
                    <a href="calendar.php" class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                </div>
            </div>

            <!-- Quick Actions Container -->
            <div class="p-6">
                <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
                <div class="space-y-3">
                    <!-- "Add New Event" Button -->
                    <a href="add_event.php" class="w-full bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <!-- "Sync Holidays" Button -->
                    <a href="functions/sync_holidays.php" class="w-full bg-white/10 hover:bg-white/20 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-white/20">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Logout Button Container (at bottom of sidebar) -->
        <div class="p-6 mt-auto border-t border-white/10">
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>
    
    <!-- Frontend Change: Main content area -->
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
        
        <!-- Backend Note: This PHP block displays a status message. We only styled the container. -->
        <?php if (isset($_GET['sync_msg'])): ?>
            <?php 
                $isSuccess = $_GET['sync_status'] === 'success';
                // Frontend Change: Dynamic background/text colors for success/error messages
                $bgColor = $isSuccess ? 'bg-green-500/20 border-green-500/50 text-green-300' : 'bg-red-500/20 border-red-500/50 text-red-300';
                $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
            ?>
            <!-- Status Message Container -->
            <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $bgColor; ?> flex items-center gap-3">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <p class="font-medium"><?php echo htmlspecialchars($_GET['sync_msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="mb-6">
            <!-- Page Title -->
            <h1 class="text-3xl font-bold text-white">All Scheduled Events</h1>
        </div>

        <!-- Frontend Change: Controls container. Added `relative` and `z-10` to ensure the dropdown appears above the content below it. -->
        <div class="glass-container rounded-xl p-4 mb-6 flex flex-col sm:flex-row items-center gap-4 relative z-10">
            <!-- Search Bar Container -->
            <div class="relative w-full flex-1">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-slate-400"></i>
                </div>
                <!-- Frontend Change: Search bar placeholder with 'form-input-glass' style -->
                <input type="text" id="search-bar" placeholder="Search events..." class="form-input-glass w-full pl-11 pr-4 py-2.5 rounded-lg">
            </div>

            <!-- Category Filter Dropdown Container -->
            <div x-data="{ open: false }" class="relative w-full sm:w-auto">
                <!-- Frontend Change: Filter dropdown button with 'form-input-glass' style -->
                <button @click="open = !open" class="form-input-glass w-full sm:w-56 flex items-center justify-between gap-2 font-semibold py-2.5 px-4 rounded-lg transition">
                    <i class="fa-solid fa-filter text-slate-400"></i>
                    <span id="filter-button-text">All Categories</span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                </button>

                <!-- Frontend Change: Dropdown panel. Replaced 'glass-container' with a solid background for 100% visibility. -->
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-full sm:w-72 bg-[#002a1d] border border-white/20 rounded-xl shadow-lg z-20 p-4" style="display: none;">
                    <h4 class="text-sm font-bold text-slate-300 mb-3">Filter by Category</h4>
                    <!-- Backend Note: This PHP block lists categories. We only styled the checkboxes and labels. -->
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

        <!-- Frontend Change: Main event list container with glassmorphism effect -->
        <div class="glass-container rounded-xl p-4 sm:p-6 flex-1 overflow-y-auto">
            <!-- Event List Header -->
            <div class="flex items-center justify-between mb-6 border-b border-white/10 pb-4">
                <h2 class="text-xl font-bold text-white">Event Queue</h2>
                <!-- Event Counter -->
                <span id="event-counter" class="bg-black/20 text-slate-300 py-1 px-3 rounded-full text-sm font-semibold">
                    Total: <?php echo count($events); ?>
                </span>
            </div>

            <div class="space-y-3">
                <!-- Backend Note: This PHP block loops through events. We styled the 'event-card' inside it. -->
                <?php if (count($events) > 0): ?>
                    <?php foreach ($events as $event): ?>
                        <?php 
                            $color = getCategoryColor($event['category_name']); 
                            
                            // Format the Date (e.g., "March 15, 2026")
                           
                                $formattedDate = date('F j, Y', strtotime($event['start_date']));
                                $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));

                                // NEW: Format the End Date/Time
                                $formattedEndDate = date('F j, Y', strtotime($event['end_date']));
                                $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                                ?>

                                <!-- Frontend Change: An individual event card. This whole block is styled. -->
                                <div class="event-card cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border border-white/10 hover:border-yellow-400/50 hover:bg-white/10 transition-all duration-300 group" 
                                    data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                    data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                    data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                                    data-date="<?php echo $formattedDate; ?>"
                                    data-time="<?php echo $formattedTime; ?>"
                                    data-end-date="<?php echo $formattedEndDate; ?>"  
                                    data-end-time="<?php echo $formattedEndTime; ?>"  
                                    onclick="openModal(this)">
                            <!-- Left side of the card (Date & Title) -->
                            <div class="flex items-center gap-4">
                                <!-- Date Box -->
                                <div class="bg-black/20 border border-white/10 rounded-md text-center p-2 min-w-[70px]">
                                    <span class="block text-xs font-bold text-yellow-400 uppercase"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                    <span class="block text-2xl font-black text-white leading-none"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                </div>
                                
                                <!-- Event Info (Title, Time, Category) -->
                                <div>
                                    <h3 class="text-lg font-bold text-white group-hover:text-yellow-400 transition"><?php echo htmlspecialchars($event['title']); ?></h3>
                                    <div class="flex items-center gap-3 mt-1 text-sm text-slate-400">
                                        <span><i class="fa-regular fa-clock mr-1.5"></i> <?php echo $formattedTime; ?></span>
                                        <span class="text-slate-300">|</span>
                                        <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> px-2 py-0.5 rounded text-xs font-semibold">
                                            <!-- Backend Note: Category name is displayed here. -->
                                            <?php echo htmlspecialchars($event['category_name']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Frontend Change: Right side of the card (Status badges and action buttons) -->
                            <div class="text-right flex flex-col items-end gap-2 mt-3 sm:mt-0 w-full sm:w-auto">
    <?php if ($event['publish_id'] === null): ?>
        <span class="text-xs font-semibold text-emerald-600 bg-emerald-50 px-2 py-1 rounded border border-emerald-200">
            <i class="fa-solid fa-check-circle mr-1"></i> Auto-Approved
        </span>
        
    <?php elseif ($event['status'] === 'Approved'): ?>
        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded border border-blue-200">
            <i class="fa-solid fa-check-double mr-1"></i> Approved (ID: <?php echo $event['publish_id']; ?>)
        </span>

    <?php elseif ($event['status'] === 'Pending'): ?>
        <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-1 rounded border border-amber-200 mb-1 animate-pulse">
            <i class="fa-solid fa-clock mr-1"></i> Pending Approval
        </span>
        <div class="flex gap-2">
    <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=approve', 'approve')" 
            class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold py-1 px-3 rounded shadow-sm transition">
        <i class="fa-solid fa-check"></i>
    </button>
    
    <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=reject', 'reject')" 
            class="bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 px-3 rounded shadow-sm transition">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>
    <?php endif; ?>
</div>
                        </div>

                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Frontend Change: Styled message for when no events are found -->
                    <div class="text-center py-12 text-slate-400">
                        <i class="fa-regular fa-calendar-xmark text-5xl mb-4 text-slate-500"></i>
                        <p class="text-lg font-medium text-slate-300">No events found.</p>
                        <p class="text-sm">Click "Add New Event" to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
    <!-- Frontend Change: Event Details Modal Container -->
    <!-- Modal Backdrop -->
    <div id="eventModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4">
    <!-- Modal Panel with glassmorphism effect -->
    <div class="glass-container rounded-xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all scale-95 opacity-0" id="modalContent">
        
        <!-- Modal Header -->
        <div class="bg-black/20 p-4 flex justify-between items-center border-b border-white/10">
            <h2 id="modalTitle" class="text-xl font-bold truncate text-yellow-400">Event Title</h2>
            <button onclick="closeModal()" class="text-white/70 hover:text-white transition bg-white/10 hover:bg-white/20 rounded-full w-8 h-8 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-4">
            
            <!-- Date/Time Info Container -->
            <div class="bg-black/20 p-4 rounded-lg border border-white/10 space-y-3">
                
                <!-- Start Date/Time Row -->
                <div class="flex items-center gap-3 text-slate-200 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">Start</span>
                    <i class="fa-regular fa-calendar text-emerald-500 text-lg"></i>
                    <span id="modalDate">Date</span>
                    <span class="text-white/20 mx-1">|</span>
                    <i class="fa-regular fa-clock text-emerald-500 text-lg"></i>
                    <span id="modalTime">Time</span>
                </div>

                <!-- Divider -->
                <div class="h-px bg-white/10 w-full ml-12"></div>

                <!-- End Date/Time Row -->
                <div class="flex items-center gap-3 text-slate-200 font-medium">
                    <span class="w-12 text-xs font-bold text-slate-400 uppercase tracking-wider">End</span>
                    <i class="fa-regular fa-calendar-check text-red-400 text-lg"></i>
                    <span id="modalEndDate">Date</span>
                    <span class="text-white/20 mx-1">|</span>
                    <i class="fa-regular fa-clock text-red-400 text-lg"></i>
                    <span id="modalEndTime">Time</span>
                </div>
            </div>

            <!-- Description Container -->
            <div>
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">Description</h3>
                <p id="modalDesc" class="text-slate-300 whitespace-pre-line leading-relaxed bg-black/20 p-4 rounded-lg border border-white/10 min-h-[80px]"></p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="bg-black/20 px-6 py-4 border-t border-white/10 flex justify-end">
            <button onclick="closeModal()" class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2 px-4 rounded-lg transition">Close</button>
        </div>
    </div>
</div>
</body>
                    
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="assets/js/filter.js"></script>
<script>
    // This script updates the filter dropdown button text to show the selection status.
    document.addEventListener('DOMContentLoaded', () => {
        const checkboxes = document.querySelectorAll('.category-filter');
        const filterButtonText = document.getElementById('filter-button-text');

        function updateFilterButton() {
            if (!filterButtonText) return; // Exit if element not found
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (checkedCount === 0) {
                filterButtonText.innerText = 'No Categories';
            } else if (checkedCount === checkboxes.length) {
                filterButtonText.innerText = 'All Categories';
            } else {
                filterButtonText.innerText = `${checkedCount} Categories Selected`;
            }
        }

        checkboxes.forEach(box => {
            box.addEventListener('change', updateFilterButton);
        });

        // Initial update on page load
        updateFilterButton();
    });

</script>
</html>