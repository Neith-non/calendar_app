<?php
// index.php

session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'functions/database.php';
require_once 'functions/get_pending_count.php';

$stmt = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmt->fetchAll();

// NEW: Fetch all participants linked to events using the upgraded ERD structure
$part_stmt = $pdo->query("
    SELECT ps.event_publish_id AS publish_id, p.name, d.name AS department
    FROM participant_schedule ps
    JOIN participants p ON ps.participant_id = p.id
    JOIN department d ON p.department_id = d.id
");

$event_participants_map = [];
while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
    // Since we embedded the strand directly into the name (e.g., 'Grade 11 (STEM)'), 
    // we no longer need the complex IF statement to combine them!
    $event_participants_map[$row['publish_id']][] = [
        'name' => $row['name'],
        'department' => $row['department']
    ];
}

$currentYear = date('Y');

$stmt = $pdo->prepare("
    SELECT e.*, c.category_name, p.status, v.venue_name 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id
    WHERE YEAR(e.start_date) = :current_year
    ORDER BY e.start_date ASC, e.start_time ASC
");

$stmt->execute([':current_year' => $currentYear]);
$events = $stmt->fetchAll();

$pendingEvents = [];
$holidayEvents = [];
$scheduledEvents = [];

foreach ($events as $event) {
    if ($event['status'] === 'Pending') {
        $pendingEvents[] = $event;
    } elseif (stripos($event['category_name'], 'holiday') !== false) {
        $holidayEvents[] = $event;
    } else {
        $scheduledEvents[] = $event;
    }
}

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
    <title>St. Joseph School Foundation - Event List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }
    </style>
</head>

<body class="dashboard-body h-screen flex overflow-hidden">

    <aside class="w-72 glass-container flex flex-col flex-shrink-0 z-10">
        <div class="p-8 text-center border-b border-white/10">
            <div class="w-20 h-20 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white/20">
                <i class="fa-solid fa-user text-3xl text-white/50"></i>
            </div>
            <h2 class="text-xl font-bold text-white"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?></h2>
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

        <?php if (isset($_GET['sync_msg'])): ?>
            <?php
            $isSuccess = $_GET['sync_status'] === 'success';
            $bgColor = $isSuccess ? 'bg-green-500/20 border-green-500/50 text-green-300' : 'bg-red-500/20 border-red-500/50 text-red-300';
            $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
            ?>
            <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $bgColor; ?> flex items-center gap-3">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <p class="font-medium"><?php echo htmlspecialchars($_GET['sync_msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="mb-6">
            <h1 class="text-3xl font-bold text-white">All Scheduled Events</h1>
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

        <div class="glass-container rounded-xl p-4 sm:p-6 flex-1 overflow-y-auto">
            <div class="flex items-center justify-between mb-6 border-b border-white/10 pb-4">
                <h2 class="text-xl font-bold text-white">Event Queue</h2>
                <span id="event-counter" class="bg-black/20 text-slate-300 py-1 px-3 rounded-full text-sm font-semibold">
                    Total: <?php echo count($events); ?>
                </span>
            </div>

            <div class="space-y-3 event-list-container">

                <div id="empty-state-message" class="hidden glass-container rounded-xl p-8 mb-6 flex-col items-center justify-center text-center border border-yellow-500/30 bg-yellow-500/10">
                    <div class="w-16 h-16 bg-yellow-500/20 rounded-full flex items-center justify-center mb-4">
                        <i class="fa-solid fa-calendar-xmark text-3xl text-yellow-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">No events found</h3>
                    <p class="text-slate-300">Try adjusting your search filters.</p>
                </div>

                <?php if (count($events) > 0): ?>

                    <?php if (count($pendingEvents) > 0): ?>
                        <div class="section-header mt-2 mb-4 border-b border-amber-500/30 pb-2 flex items-center gap-3">
                            <i class="fa-solid fa-clock text-amber-400 text-xl animate-pulse"></i>
                            <h2 class="text-lg font-bold text-amber-400 tracking-widest">PENDING APPROVALS</h2>
                        </div>

                        <?php foreach ($pendingEvents as $event): ?>
                            <?php
                            $color = getCategoryColor($event['category_name']);
                            $formattedDate = date('F j, Y', strtotime($event['start_date']));
                            $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                            $formattedEndDate = date('F j, Y', strtotime($event['end_date']));
                            $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                            
                            $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                            $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                            ?>

                            <div class="event-card cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border border-amber-500/30 bg-amber-500/5 hover:bg-amber-500/10 transition-all duration-300 group"
                                data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                                data-date="<?php echo $formattedDate; ?>"
                                data-time="<?php echo $formattedTime; ?>"
                                data-end-date="<?php echo $formattedEndDate; ?>"
                                data-end-time="<?php echo $formattedEndTime; ?>"
                                data-venue="<?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>"
                                data-participants="<?php echo $jsParticipants; ?>"
                                onclick="openModal(this)">

                                <div class="flex items-center gap-4">
                                    <div class="bg-black/30 border border-amber-500/20 rounded-md text-center p-2 min-w-[70px]">
                                        <span class="block text-xs font-bold text-amber-400 uppercase"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                        <span class="block text-2xl font-black text-white leading-none"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white group-hover:text-amber-400 transition">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </h3>
                                        <div class="flex items-center gap-3 mt-1 text-sm text-slate-400">
                                            <span><i class="fa-regular fa-clock mr-1.5"></i> <?php echo $formattedTime; ?></span>
                                            <span class="text-slate-300">|</span>
                                            <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> px-2 py-0.5 rounded text-xs font-semibold">
                                                <?php echo htmlspecialchars($event['category_name']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-right flex flex-col items-end gap-2 mt-3 sm:mt-0 w-full sm:w-auto">
                                    <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-1 rounded border border-amber-200 mb-1 animate-pulse">
                                        <i class="fa-solid fa-clock mr-1"></i> Pending Approval
                                    </span>
                                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                                        <div class="flex gap-2">
                                            <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=approve', 'approve')" class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold py-1 px-3 rounded shadow-sm transition">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=reject', 'reject')" class="bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-1 px-3 rounded shadow-sm transition">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (count($holidayEvents) > 0): ?>
                        <div class="section-header mt-8 mb-4 border-b border-yellow-500/30 pb-2 flex items-center gap-3">
                            <i class="fa-solid fa-star text-yellow-400 text-xl"></i>
                            <h2 class="text-lg font-bold text-yellow-400 tracking-widest">SCHOOL HOLIDAYS</h2>
                        </div>

                        <?php foreach ($holidayEvents as $event): ?>
                            <?php
                            $color = getCategoryColor($event['category_name']);
                            $formattedDate = date('F j, Y', strtotime($event['start_date']));
                            $formattedEndDate = date('F j, Y', strtotime($event['end_date']));
                            
                            $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                            $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="event-card cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border <?php echo $color['border']; ?> <?php echo $color['bg']; ?> hover:bg-white/10 transition-all duration-300 group"
                                data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                                data-date="<?php echo $formattedDate; ?>"
                                data-time="All Day"
                                data-end-date="<?php echo $formattedEndDate; ?>"
                                data-end-time="All Day"
                                data-venue="Not specified"
                                data-participants="<?php echo $jsParticipants; ?>"
                                onclick="openModal(this)">

                                <div class="flex items-center gap-4">
                                    <div class="bg-black/30 border <?php echo $color['border']; ?> rounded-md text-center p-2 min-w-[70px]">
                                        <span class="block text-xs font-bold <?php echo $color['text']; ?> uppercase"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                        <span class="block text-2xl font-black text-white leading-none"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white group-hover:<?php echo $color['text']; ?> transition">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </h3>
                                        <div class="flex items-center gap-3 mt-1 text-sm text-slate-400">
                                            <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> px-2 py-0.5 rounded text-xs font-semibold">
                                                <i class="fa-solid fa-umbrella-beach mr-1"></i> Holiday
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 sm:mt-0 w-full sm:w-auto text-right">
                                    <span class="text-xs font-semibold text-slate-300 bg-black/40 px-3 py-1.5 rounded-full border border-white/10">
                                        <i class="fa-regular fa-calendar-check mr-1 text-emerald-400"></i> Holiday
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (count($scheduledEvents) > 0): ?>
                        <div class="section-header mt-8 mb-4 border-b border-emerald-500/30 pb-2 flex items-center gap-3">
                            <i class="fa-regular fa-calendar-check text-emerald-400 text-xl"></i>
                            <h2 class="text-lg font-bold text-emerald-400 tracking-widest">UPCOMING SCHEDULE</h2>
                        </div>

                        <?php foreach ($scheduledEvents as $event): ?>
                            <?php
                            $color = getCategoryColor($event['category_name']);
                            $formattedDate = date('F j, Y', strtotime($event['start_date']));
                            $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                            $formattedEndDate = date('F j, Y', strtotime($event['end_date']));
                            $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                            
                            $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                            $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                            ?>

                            <div class="event-card cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border border-white/10 bg-black/20 hover:bg-white/10 hover:border-white/20 transition-all duration-300 group shadow-sm hover:shadow-md"
                                data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                                data-date="<?php echo $formattedDate; ?>"
                                data-time="<?php echo $formattedTime; ?>"
                                data-end-date="<?php echo $formattedEndDate; ?>"
                                data-end-time="<?php echo $formattedEndTime; ?>"
                                data-venue="<?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>"
                                data-participants="<?php echo $jsParticipants; ?>"
                                onclick="openModal(this)">

                                <div class="flex items-center gap-4">
                                    <div class="bg-white/5 border border-white/10 rounded-md text-center p-2 min-w-[70px] shadow-inner group-hover:bg-white/10 transition">
                                        <span class="block text-xs font-bold text-slate-400 uppercase"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                        <span class="block text-2xl font-black text-white leading-none"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white group-hover:text-yellow-400 transition">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </h3>
                                        <div class="flex flex-wrap items-center gap-3 mt-1 text-sm text-slate-400">
                                            <span class="flex items-center text-slate-300"><i class="fa-regular fa-clock mr-1.5 text-slate-500"></i> <?php echo $formattedTime; ?></span>
                                            <span class="hidden sm:inline text-white/20">|</span>
                                            <span class="flex items-center text-slate-300"><i class="fa-solid fa-location-dot mr-1.5 text-slate-500"></i> <?php echo htmlspecialchars($event['venue_name'] ?? 'Unknown Venue'); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 sm:mt-0 w-full sm:w-auto text-right">
                                    <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> <?php echo $color['border']; ?> border px-3 py-1 rounded text-xs font-semibold shadow-sm inline-block">
                                        <?php echo htmlspecialchars($event['category_name']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>
    </main>


</body>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="assets/js/event_modal.js"></script>
<script src="assets/js/pdf_modal.js"></script>

</html>