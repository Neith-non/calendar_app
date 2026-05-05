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

// Fetch all participants linked to events using the upgraded ERD structure
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

$currentYear = date('Y');

$isAdmin = isset($_SESSION['role_name']) && in_array($_SESSION['role_name'], ['Head Scheduler', 'Admin']);
$isViewer = isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Viewer';

// If they are a normal viewer, ONLY pull Approved events. If Admin, pull everything.
$statusFilter = $isAdmin ? "" : "AND (p.status = 'Approved' OR e.publish_id IS NULL)";

// Fetch Events (Showing current month's past events + all future events)
$stmt = $pdo->prepare("
    SELECT e.*, c.category_name, p.status, v.venue_name 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id
    WHERE e.start_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    $statusFilter
    ORDER BY e.start_date ASC, e.start_time ASC
");
$stmt->execute();
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

// Light/Dark mode adapted category colors
function getCategoryColor($categoryName)
{
    $name = strtolower($categoryName);

    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false)
        return ['text' => 'text-sky-700 dark:text-sky-400', 'bg' => 'bg-sky-50 dark:bg-sky-500/10', 'border' => 'border-sky-200 dark:border-sky-500/20', 'ring' => 'focus:ring-sky-500', 'checkbox' => 'text-sky-600', 'icon' => 'fa-book-open', 'iconColor' => 'text-sky-500 dark:text-sky-400'];

    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false)
        return ['text' => 'text-emerald-700 dark:text-emerald-400', 'bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'border' => 'border-emerald-200 dark:border-emerald-500/20', 'ring' => 'focus:ring-emerald-500', 'checkbox' => 'text-emerald-600', 'icon' => 'fa-volleyball', 'iconColor' => 'text-emerald-500 dark:text-emerald-400'];

    if (strpos($name, 'mass') !== false)
        return ['text' => 'text-violet-700 dark:text-violet-400', 'bg' => 'bg-violet-50 dark:bg-violet-500/10', 'border' => 'border-violet-200 dark:border-violet-500/20', 'ring' => 'focus:ring-violet-500', 'checkbox' => 'text-violet-600', 'icon' => 'fa-church', 'iconColor' => 'text-violet-500 dark:text-violet-400'];

    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false)
        return ['text' => 'text-orange-700 dark:text-orange-400', 'bg' => 'bg-orange-50 dark:bg-orange-500/10', 'border' => 'border-orange-200 dark:border-orange-500/20', 'ring' => 'focus:ring-orange-500', 'checkbox' => 'text-orange-600', 'icon' => 'fa-users', 'iconColor' => 'text-orange-500 dark:text-orange-400'];

    if (strpos($name, 'holiday') !== false)
        return ['text' => 'text-yellow-700 dark:text-yellow-400', 'bg' => 'bg-yellow-50 dark:bg-yellow-500/10', 'border' => 'border-yellow-200 dark:border-yellow-500/20', 'ring' => 'focus:ring-yellow-500', 'checkbox' => 'text-yellow-600', 'icon' => 'fa-umbrella-beach', 'iconColor' => 'text-yellow-500 dark:text-yellow-400'];

    return ['text' => 'text-slate-700 dark:text-slate-300', 'bg' => 'bg-slate-50 dark:bg-slate-800/50', 'border' => 'border-slate-200 dark:border-slate-700', 'ring' => 'focus:ring-slate-500', 'checkbox' => 'text-slate-600', 'icon' => 'fa-calendar-day', 'iconColor' => 'text-slate-500 dark:text-slate-400'];
}
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SJSFI - Calendar of Events</title>
    
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
    <link rel="stylesheet" href="assets/css/styles.css">

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

    <style>
        body {
            color: #1e293b;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .dark body { color: #f1f5f9; }

        .nav-item {
            color: #64748b;
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            color: #004731;
            background-color: #f1f5f9;
        }
        
        .dark .nav-item { color: #94a3b8; }
        .dark .nav-item:hover {
            color: #10b981; 
            background-color: rgba(30, 41, 59, 0.5); 
        }

        .nav-item.active {
            background-color: #004731;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 71, 49, 0.15);
        }
        .dark .nav-item.active {
            background-color: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .bento-card {
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            background: #ffffff;
            border: 1px solid #e2e8f0; 
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }
        .dark .bento-card {
            background: #111827;
            border-color: #1e293b;
        }
        
        .event-bento {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .event-bento:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 30px -10px rgba(0, 71, 49, 0.08);
            border-color: #cbd5e1; 
        }
        .dark .event-bento:hover {
            box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.5);
            border-color: #475569; 
        }

        .dark ::-webkit-scrollbar-thumb { background-color: #334155; }
        .dark ::-webkit-scrollbar-track { background-color: #0f172a; }
        
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.05); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(0, 0, 0, 0.3); }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.3); }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <?php include 'includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-6 md:p-8 lg:p-10 relative custom-scrollbar">

        <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full">
            <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
            <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green dark:hover:text-emerald-400 transition-colors">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <?php if (isset($_GET['sync_msg'])): ?>
            <?php
            $isSuccess = $_GET['sync_status'] === 'success';
            $bgColor = $isSuccess ? 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-400' : 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400';
            $icon = $isSuccess ? 'fa-circle-check text-emerald-500 dark:text-emerald-400' : 'fa-triangle-exclamation text-red-500 dark:text-red-400';
            ?>
            <div class="mb-6 px-5 py-4 rounded-2xl border <?php echo $bgColor; ?> flex items-center gap-3 font-semibold text-sm shadow-sm">
                <i class="fa-solid <?php echo $icon; ?> text-lg"></i>
                <p><?php echo htmlspecialchars($_GET['sync_msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 mb-6">
            
            <div class="md:col-span-12 lg:col-span-6 bento-card p-8 relative flex flex-col justify-between">
                <div class="absolute -right-10 -top-10 w-48 h-48 bg-sjsfi-green/5 dark:bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
                
                <div class="relative z-10">
                    <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-1">Calendar of Events</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Today is <?php echo date('l, F j, Y'); ?></p>
                </div>

                <div class="mt-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4 relative z-10">
                    <div class="flex flex-wrap items-center gap-1.5 bg-slate-50 dark:bg-slate-900/50 p-1.5 rounded-xl border border-slate-200 dark:border-slate-700 w-full sm:w-max shadow-inner">
                        <button data-view="all" class="view-toggle bg-sjsfi-green dark:bg-emerald-600 text-white shadow-md font-bold text-xs px-4 py-2.5 rounded-lg transition-all flex-1 sm:flex-none">All Events</button>
                        
                        <?php if (!$isViewer): ?>
                            <button data-view="pending" class="view-toggle text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-800 font-bold text-xs px-4 py-2.5 rounded-lg transition-all flex-1 sm:flex-none">Pending</button>
                        <?php endif; ?>
                        
                        <button data-view="scheduled" class="view-toggle text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-800 font-bold text-xs px-4 py-2.5 rounded-lg transition-all flex-1 sm:flex-none">Approved</button>
                    </div>

                    <?php if (!$isViewer): ?>
                        <a href="add_event.php" class="bg-sjsfi-yellow hover:bg-yellow-400 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-sjsfi-green dark:text-white font-bold py-2.5 px-6 rounded-xl transition text-sm shadow-sm flex items-center justify-center gap-2 shrink-0 w-full sm:w-auto">
                            <i class="fa-solid fa-plus"></i> Create Event
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$isViewer): ?>
                <div class="md:col-span-6 lg:col-span-3 bento-card p-6 flex flex-col justify-center items-center text-center">
                    <div class="w-12 h-12 rounded-full bg-amber-50 dark:bg-amber-500/10 text-amber-500 dark:text-amber-400 flex items-center justify-center mb-4 border border-amber-100 dark:border-amber-500/20">
                        <i class="fa-solid fa-hourglass-half text-lg"></i>
                    </div>
                    <h3 class="text-4xl font-black text-slate-800 dark:text-slate-100 mb-1"><?php echo count($pendingEvents); ?></h3>
                    <p class="text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Pending</p>
                </div>

                <div class="md:col-span-6 lg:col-span-3 bento-card p-6 flex flex-col justify-center items-center text-center">
                    <div class="w-12 h-12 rounded-full bg-sjsfi-light dark:bg-emerald-500/10 text-sjsfi-green dark:text-emerald-400 flex items-center justify-center mb-4 border border-slate-100 dark:border-emerald-500/20">
                        <i class="fa-solid fa-calendar-check text-lg"></i>
                    </div>
                    <h3 class="text-4xl font-black text-slate-800 dark:text-slate-100 mb-1" id="event-counter"><?php echo count($scheduledEvents); ?></h3>
                    <p class="text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Scheduled</p>
                </div>
            <?php else: ?>
                <div class="md:col-span-12 lg:col-span-6 bento-card p-6 flex flex-col justify-center items-center text-center">
                    <div class="w-12 h-12 rounded-full bg-sjsfi-light dark:bg-emerald-500/10 text-sjsfi-green dark:text-emerald-400 flex items-center justify-center mb-4 border border-slate-100 dark:border-emerald-500/20">
                        <i class="fa-solid fa-calendar-check text-lg"></i>
                    </div>
                    <h3 class="text-4xl font-black text-slate-800 dark:text-slate-100 mb-1" id="event-counter"><?php echo count($scheduledEvents); ?></h3>
                    <p class="text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Total Approved Events</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="bento-card p-2 pl-4 mb-8 flex flex-col sm:flex-row items-center gap-2 relative z-10">
            <div class="relative w-full flex-1 group">
                <div class="absolute inset-y-0 left-0 flex items-center pointer-events-none">
                    <i class="fa-solid fa-search text-slate-400 group-focus-within:text-sjsfi-green dark:group-focus-within:text-emerald-500 transition-colors"></i>
                </div>
                <input type="text" id="search-bar" placeholder="Search by title, category, or date (Auto-loads events)..."
                    class="w-full pl-8 pr-4 py-3 text-sm font-medium border-none shadow-none bg-transparent focus:outline-none focus:ring-0 text-slate-800 dark:text-slate-200 dark:placeholder-slate-500">
            </div>

            <div class="relative w-full sm:w-auto border-t sm:border-t-0 sm:border-l border-slate-100 dark:border-slate-700 pt-2 sm:pt-0 sm:pl-2">
                <button onclick="document.getElementById('categoryModal').classList.remove('hidden'); document.getElementById('categoryModal').classList.add('flex')"
                    class="w-full sm:w-56 flex items-center justify-between gap-2 font-bold text-sm py-2.5 px-4 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-filter text-slate-400 dark:text-slate-500"></i>
                        <span id="filter-button-text" class="text-slate-700 dark:text-slate-300">Filter Categories</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-xs text-slate-400 dark:text-slate-600"></i>
                </button>
            </div>
        </div>

        <div class="flex-1 pb-10">
            
            <div id="events-unloaded-state" class="bg-white dark:bg-[#111827] rounded-3xl p-16 flex flex-col items-center justify-center text-center border border-slate-200 dark:border-slate-800 shadow-sm mt-4 transition-all duration-300">
                <div class="w-24 h-24 bg-slate-50 dark:bg-slate-800/50 rounded-full flex items-center justify-center mb-6 border border-slate-100 dark:border-slate-700 shadow-inner">
                    <i class="fa-solid fa-server text-5xl text-slate-300 dark:text-slate-600"></i>
                </div>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-200 mb-3 tracking-tight">Events Database on Standby</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mb-8 max-w-md">To conserve bandwidth and prevent slow load times, event cards are not loaded automatically. Click below or use the search bar to fetch the database.</p>
                
                <button onclick="loadEvents()" class="bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 text-white font-extrabold py-3.5 px-8 rounded-xl transition shadow-lg flex items-center justify-center gap-3 transform hover:scale-105">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Load Events
                </button>
            </div>

            <div id="events-error-state" class="hidden bg-white dark:bg-[#111827] rounded-3xl p-16 flex-col items-center justify-center text-center border border-red-200 dark:border-red-900/50 shadow-sm mt-4 transition-all duration-300">
                <div class="w-24 h-24 bg-red-50 dark:bg-red-500/10 rounded-full flex items-center justify-center mb-6 border border-red-100 dark:border-red-500/20 shadow-inner">
                    <i class="fa-solid fa-plug-circle-xmark text-5xl text-red-500 dark:text-red-400"></i>
                </div>
                <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-200 mb-3 tracking-tight">Connection Failed</h3>
                <p class="text-red-500 dark:text-red-400 text-sm font-bold mb-8 max-w-md bg-red-50 dark:bg-red-500/10 p-4 rounded-xl border border-red-100 dark:border-red-500/20">
                    Error connection: Cannot load events from the database. Please contact the administrator.
                </p>
                
                <button onclick="location.reload()" class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-extrabold py-3.5 px-8 rounded-xl transition shadow-sm flex items-center justify-center gap-3">
                    <i class="fa-solid fa-rotate-right"></i> Retry Connection
                </button>
            </div>

            <div id="events-loaded-state" class="hidden transition-all duration-500 opacity-0 transform translate-y-4">
                
                <div id="empty-state-message" class="hidden bg-white dark:bg-[#111827] rounded-3xl p-16 flex-col items-center justify-center text-center border border-slate-200 dark:border-slate-800 shadow-sm mt-4">
                    <div class="w-20 h-20 bg-slate-50 dark:bg-slate-800/50 rounded-full flex items-center justify-center mb-5 border border-slate-100 dark:border-slate-700">
                        <i class="fa-solid fa-ghost text-4xl text-slate-300 dark:text-slate-600"></i>
                    </div>
                    <h3 class="text-xl font-extrabold text-slate-800 dark:text-slate-200 mb-2">No events match your search</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Try adjusting your tabs, keywords, or category filters.</p>
                </div>

                <div class="event-list-container grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">

                    <?php if (!$isViewer): ?>
                        <?php foreach ($pendingEvents as $event): ?>
                            <?php
                            $color = getCategoryColor($event['category_name']);
                            $formattedDate = date('M j, Y', strtotime($event['start_date']));
                            $formattedTime = ($event['start_time'] == '00:00:00' || $event['start_time'] == '23:59:59') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                            $formattedEndDate = date('M j, Y', strtotime($event['end_date']));
                            $formattedEndTime = ($event['end_time'] == '00:00:00' || $event['end_time'] == '23:59:59') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                            
                            $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                            $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                            ?>
                            
                            <div class="bento-card event-bento event-card cursor-pointer group p-6 flex flex-col min-h-[220px]"
                                 data-status="pending" 
                                 data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                 data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                 data-desc="<?php echo htmlspecialchars($event['description'] ?? ''); ?>"
                                 data-date="<?php echo date('F j, Y', strtotime($event['start_date'])); ?>" data-time="<?php echo $formattedTime; ?>"
                                 data-end-date="<?php echo date('F j, Y', strtotime($event['end_date'])); ?>" data-end-time="<?php echo $formattedEndTime; ?>"
                                 data-venue="<?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>"
                                 data-participants="<?php echo $jsParticipants; ?>"
                                 onclick="openModal(this)">
                                
                                <div class="flex items-start justify-between w-full shrink-0 mb-3">
                                    <div class="w-10 h-10 rounded-xl <?php echo $color['bg']; ?> border <?php echo $color['border']; ?> flex items-center justify-center shrink-0">
                                        <i class="fa-solid <?php echo $color['icon']; ?> <?php echo $color['iconColor']; ?>"></i>
                                    </div>
                                    <span class="bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-100 dark:border-amber-500/20 text-[10px] font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider animate-pulse">Pending</span>
                                </div>
                                
                                <div class="w-full text-center mb-2 shrink-0">
                                    <h3 class="text-[26px] font-black text-slate-800 dark:text-slate-100 group-hover:text-amber-600 dark:group-hover:text-amber-400 transition leading-tight line-clamp-3">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </h3>
                                </div>
                                
                                <div class="flex-auto flex flex-col justify-center items-center py-2">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Date</p>
                                    <p class="text-[26px] font-black text-slate-800 dark:text-white leading-none text-center">
                                        <?php echo $formattedDate; ?>
                                    </p>
                                </div>

                                <div class="mt-auto flex flex-col gap-2 pt-2 border-t border-slate-100 dark:border-slate-800/50">
                                    <div class="flex items-center justify-center text-xs text-slate-500 dark:text-slate-400 font-bold bg-slate-50 dark:bg-slate-900/50 py-2 rounded-lg border border-slate-100 dark:border-slate-800">
                                        <i class="fa-regular fa-clock mr-1.5 opacity-70"></i> <?php echo $formattedTime; ?>
                                    </div>

                                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                                        <div class="flex justify-center gap-2 mt-1">
                                            <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=approve', 'approve')"
                                                class="w-full h-8 rounded-lg bg-white dark:bg-slate-800 hover:bg-emerald-50 dark:hover:bg-emerald-500/20 text-slate-500 dark:text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 hover:border-emerald-200 dark:hover:border-emerald-500/30 gap-1.5 text-xs font-bold shadow-sm">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                            <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=reject', 'reject')"
                                                class="w-full h-8 rounded-lg bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-500/20 text-slate-500 dark:text-slate-400 hover:text-red-600 dark:hover:text-red-400 flex items-center justify-center transition border border-slate-200 dark:border-slate-700 hover:border-red-200 dark:hover:border-red-500/30 gap-1.5 text-xs font-bold shadow-sm">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php foreach ($holidayEvents as $event): ?>
                        <?php
                        $color = getCategoryColor($event['category_name']);
                        $formattedDate = date('M j, Y', strtotime($event['start_date']));
                        $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                        $formattedEndDate = date('M j, Y', strtotime($event['end_date']));
                        $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                        
                        $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                        $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                        ?>
                        
                        <div class="bento-card event-bento event-card cursor-pointer group p-6 flex flex-col min-h-[220px]"
                             data-status="holiday"
                             data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                             data-title="<?php echo htmlspecialchars($event['title']); ?>"
                             data-desc="<?php echo htmlspecialchars($event['description'] ?? ''); ?>"
                             data-date="<?php echo date('F j, Y', strtotime($event['start_date'])); ?>" data-time="<?php echo $formattedTime; ?>"
                             data-end-date="<?php echo date('F j, Y', strtotime($event['end_date'])); ?>" data-end-time="<?php echo $formattedEndTime; ?>"
                             data-venue="<?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>"
                             data-participants="<?php echo $jsParticipants; ?>"
                             onclick="openModal(this)">
                            
                            <div class="flex items-start justify-between w-full shrink-0 mb-3">
                                <div class="w-10 h-10 rounded-xl <?php echo $color['bg']; ?> border <?php echo $color['border']; ?> flex items-center justify-center shrink-0">
                                    <i class="fa-solid <?php echo $color['icon']; ?> <?php echo $color['iconColor']; ?>"></i>
                                </div>
                                <span class="bg-yellow-50 dark:bg-yellow-500/10 text-yellow-600 dark:text-yellow-500 border border-yellow-100 dark:border-yellow-500/20 text-[10px] font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider">Holiday</span>
                            </div>
                            
                            <div class="w-full text-center mb-2 shrink-0">
                                <h3 class="text-[26px] font-black text-slate-800 dark:text-slate-100 group-hover:text-yellow-500 dark:group-hover:text-yellow-400 transition leading-tight line-clamp-3">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </h3>
                            </div>
                            
                            <div class="flex-auto flex flex-col justify-center items-center py-2">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Date</p>
                                <p class="text-[26px] font-black text-slate-800 dark:text-white leading-none text-center">
                                    <?php echo $formattedDate; ?>
                                </p>
                            </div>

                            <div class="mt-auto flex flex-col gap-2 pt-2 border-t border-slate-100 dark:border-slate-800/50">
                                <div class="flex items-center justify-center text-xs text-slate-500 dark:text-slate-400 font-bold bg-slate-50 dark:bg-slate-900/50 py-2 rounded-lg border border-slate-100 dark:border-slate-800">
                                    <i class="fa-regular fa-clock mr-1.5 opacity-70"></i> <?php echo $formattedTime; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>


                    <?php foreach ($scheduledEvents as $event): ?>
                        <?php
                        $color = getCategoryColor($event['category_name']);
                        $formattedDate = date('M j, Y', strtotime($event['start_date']));
                        $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                        $formattedEndDate = date('M j, Y', strtotime($event['end_date']));
                        $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                        
                        $participants_array = $event['publish_id'] ? ($event_participants_map[$event['publish_id']] ?? []) : [];
                        $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                        ?>
                        
                        <div class="bento-card event-bento event-card cursor-pointer group p-6 flex flex-col min-h-[220px]"
                             data-status="scheduled"
                             data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                             data-title="<?php echo htmlspecialchars($event['title']); ?>"
                             data-desc="<?php echo htmlspecialchars($event['description'] ?? ''); ?>"
                             data-date="<?php echo date('F j, Y', strtotime($event['start_date'])); ?>" data-time="<?php echo $formattedTime; ?>"
                             data-end-date="<?php echo date('F j, Y', strtotime($event['end_date'])); ?>" data-end-time="<?php echo $formattedEndTime; ?>"
                             data-venue="<?php echo htmlspecialchars($event['venue_name'] ?? 'Not specified'); ?>"
                             data-participants="<?php echo $jsParticipants; ?>"
                             onclick="openModal(this)">
                            
                            <div class="flex items-start justify-between w-full shrink-0 mb-3">
                                <div class="w-10 h-10 rounded-xl <?php echo $color['bg']; ?> border <?php echo $color['border']; ?> flex items-center justify-center shrink-0">
                                    <i class="fa-solid <?php echo $color['icon']; ?> <?php echo $color['iconColor']; ?>"></i>
                                </div>
                                
                                <?php if ($event['publish_id'] === null): ?>
                                    <span class="shrink-0 bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 text-[10px] font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider">Auto</span>
                                <?php elseif ($event['status'] === 'Approved'): ?>
                                    <span class="shrink-0 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-500/30 text-[10px] font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider">Approved</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="w-full text-center mb-2 shrink-0">
                                <h3 class="text-[26px] font-black text-slate-800 dark:text-slate-100 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition leading-tight line-clamp-3">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </h3>
                            </div>
                            
                            <div class="flex-auto flex flex-col justify-center items-center py-2">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Date</p>
                                <p class="text-[26px] font-black text-slate-800 dark:text-white leading-none text-center">
                                    <?php echo $formattedDate; ?>
                                </p>
                            </div>

                            <div class="mt-auto flex flex-col gap-2 pt-2 border-t border-slate-100 dark:border-slate-800/50">
                                <div class="flex items-center justify-center text-xs text-slate-500 dark:text-slate-400 font-bold bg-slate-50 dark:bg-slate-900/50 py-2 rounded-lg border border-slate-100 dark:border-slate-800">
                                    <i class="fa-regular fa-clock mr-1.5 opacity-70"></i> <?php echo $formattedTime; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div> </div>
    </main>

    <div id="eventModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity p-4">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-lg overflow-hidden border border-slate-100 dark:border-slate-700 transform transition-all scale-95 opacity-0" id="modalContent">
            
            <div class="bg-slate-50 dark:bg-slate-900 p-6 flex justify-between items-start border-b border-slate-100 dark:border-slate-800">
                <h2 id="modalTitle" class="text-xl font-extrabold text-slate-800 dark:text-slate-100 leading-tight pr-4">Event Title</h2>
                <button onclick="closeModal()" class="text-slate-400 hover:text-red-500 transition bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-6 max-h-[75vh] overflow-y-auto custom-scrollbar">
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
                    <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Participants</h3>
                    <div id="modalParticipants" class="bg-slate-50 dark:bg-slate-900 p-4 rounded-xl border border-slate-100 dark:border-slate-800 min-h-[60px] flex flex-col gap-3">
                        <span class="text-slate-400 dark:text-slate-500 italic text-sm">Loading participants...</span>
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

    <div id="categoryModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[60] backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-sm overflow-hidden border border-slate-100 dark:border-slate-700">
            <div class="bg-slate-50 dark:bg-slate-900 p-5 flex justify-between items-center border-b border-slate-100 dark:border-slate-800">
                <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-100">Filter Categories</h2>
                <button onclick="document.getElementById('categoryModal').classList.add('hidden'); document.getElementById('categoryModal').classList.remove('flex')" class="text-slate-400 hover:text-red-500 transition bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
            
            <div class="p-6 space-y-2 max-h-[50vh] overflow-y-auto">
                <?php foreach ($categories as $cat): ?>
                    <?php $color = getCategoryColor($cat['category_name']); ?>
                    <label class="flex items-center space-x-3 cursor-pointer p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 border border-transparent hover:border-slate-100 dark:hover:border-slate-700 transition">
                        <input type="checkbox" checked
                            value="<?php echo htmlspecialchars($cat['category_name']); ?>"
                            class="category-filter w-5 h-5 rounded <?php echo $color['checkbox']; ?> bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-600 focus:ring-offset-0 <?php echo $color['ring']; ?>">
                        <span class="text-slate-700 dark:text-slate-200 font-bold text-sm"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="bg-white dark:bg-[#0b1120] px-6 py-4 border-t border-slate-100 dark:border-slate-800">
                <button onclick="document.getElementById('categoryModal').classList.add('hidden'); document.getElementById('categoryModal').classList.remove('flex')" class="w-full bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 text-white font-bold py-3 px-6 rounded-xl transition shadow-sm text-sm">
                    Apply Filters
                </button>
            </div>
        </div>
    </div>

</body>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="assets/js/event_modal.js"></script>
<script src="assets/js/pdf_modal.js"></script>

<script>
    // --- DARK MODE TOGGLE LOGIC ---
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

    // --- STATE MANAGEMENT & FILTER LOGIC ---
    const dashSearchBar = document.getElementById('search-bar');
    const dashEventCards = document.querySelectorAll('.event-card');
    const dashEmptyMessage = document.getElementById('empty-state-message');
    const viewToggles = document.querySelectorAll('.view-toggle');
    const categoryCheckboxes = document.querySelectorAll('.category-filter');
    
    const unloadedState = document.getElementById('events-unloaded-state');
    const errorState = document.getElementById('events-error-state');
    const loadedState = document.getElementById('events-loaded-state');

    let currentTab = 'all';
    let isDataLoaded = false;

    // Trigger state to show actual data grid
    function loadEvents() {
        isDataLoaded = true;
        unloadedState.classList.add('hidden');
        errorState.classList.add('hidden');
        
        loadedState.classList.remove('hidden');
        setTimeout(() => {
            loadedState.classList.remove('opacity-0', 'translate-y-4');
        }, 10);
        
        applyCustomFilters(); 
    }

    viewToggles.forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            viewToggles.forEach(t => {
                t.classList.remove('bg-sjsfi-green', 'dark:bg-emerald-600', 'text-white', 'shadow-md');
                t.classList.add('text-slate-500', 'dark:text-slate-400', 'hover:bg-slate-200', 'dark:hover:bg-slate-800');
            });
            e.currentTarget.classList.remove('text-slate-500', 'dark:text-slate-400', 'hover:bg-slate-200', 'dark:hover:bg-slate-800');
            e.currentTarget.classList.add('bg-sjsfi-green', 'dark:bg-emerald-600', 'text-white', 'shadow-md');
            
            currentTab = e.currentTarget.getAttribute('data-view');
            
            if (isDataLoaded) applyCustomFilters();
        });
    });

    function applyCustomFilters() {
        // 1. Grab the raw search input
        const rawSearch = dashSearchBar ? dashSearchBar.value.toLowerCase() : '';
        
        // 2. Clean the search term: remove commas, normalize spaces, and change "01" to "1"
        const searchTerm = rawSearch.replace(/,/g, '')
                                    .replace(/\s+/g, ' ')
                                    .replace(/\b0([1-9])\b/g, '$1')
                                    .trim();

        const activeCategories = Array.from(categoryCheckboxes)
                                      .filter(cb => cb.checked)
                                      .map(cb => cb.value);
        let visibleCount = 0;

        dashEventCards.forEach(card => {
            const title = (card.getAttribute('data-title') || '').toLowerCase();
            const category = card.getAttribute('data-category') || '';
            const status = card.getAttribute('data-status') || '';
            const desc = (card.getAttribute('data-desc') || '').toLowerCase();
            
            // 3. Clean the card's date exactly the same way so they match perfectly!
            const date = (card.getAttribute('data-date') || '').toLowerCase()
                                                               .replace(/,/g, '')
                                                               .replace(/\s+/g, ' '); 
                                                               
            const time = (card.getAttribute('data-time') || '').toLowerCase();

            // 4. Check for matches
            const matchesSearch = searchTerm === '' || 
                                  title.includes(searchTerm) || 
                                  category.toLowerCase().includes(searchTerm) || 
                                  desc.includes(searchTerm) || 
                                  date.includes(searchTerm) || 
                                  time.includes(searchTerm);

            const matchesTab = (currentTab === 'all') || (status === currentTab);
            const matchesCategory = activeCategories.includes(category);

            // 5. Show or hide the card
            if (matchesSearch && matchesTab && matchesCategory) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // 6. Show the ghost icon if no results match
        if (dashEmptyMessage) {
            if (visibleCount === 0) {
                dashEmptyMessage.classList.remove('hidden');
                dashEmptyMessage.classList.add('flex');
            } else {
                dashEmptyMessage.classList.add('hidden');
                dashEmptyMessage.classList.remove('flex');
            }
        }
    }

    if (dashSearchBar) {
        dashSearchBar.addEventListener('input', () => {
            if (!isDataLoaded && dashSearchBar.value.trim() !== '') {
                loadEvents();
            }
            if (isDataLoaded) {
                applyCustomFilters();
            }
        });
    }
    
    categoryCheckboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            if (isDataLoaded) applyCustomFilters();
        });
    });
</script>
</html>