<?php
session_start();

// 1. Check Permissions
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: index.php?error=unauthorized");
    exit();
}

require_once 'functions/database.php';
// Required to fetch the pending count for the sidebar notification dot
require_once 'functions/get_pending_count.php'; 

// --- Handle Deletion of Rejected Requests ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int) $_GET['delete_id'];
    $stmt_del_event = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
    $stmt_del_event->execute([$delete_id]);
    $stmt_del_pub = $pdo->prepare("DELETE FROM event_publish WHERE id = ? AND status = 'Rejected'");
    $stmt_del_pub->execute([$delete_id]);
    header("Location: request_status.php");
    exit();
}

// Fetch requests
$stmt = $pdo->query("
    SELECT p.id, p.title, p.description, p.status, 
           v.venue_name, c.category_name,
           e.start_date, e.start_time, e.end_date, e.end_time
    FROM event_publish p
    LEFT JOIN venues v ON p.venue_id = v.venue_id
    LEFT JOIN events e ON p.id = e.publish_id
    LEFT JOIN event_categories c ON e.category_id = c.category_id
    ORDER BY 
        CASE WHEN e.start_date IS NULL THEN 1 ELSE 0 END, 
        e.start_date ASC, 
        e.start_time ASC
");
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Status - St. Joseph School</title>
    
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
    
    <link rel="stylesheet" href="assets/css/request_status.css?v=<?php echo time(); ?>">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'], chinese: ['Noto Sans TC', 'sans-serif'] },
                    colors: { sjsfi: { green: '#004731', greenHover: '#003323', light: '#f8faf9', yellow: '#ffbb00' } }
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

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800/50">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
                <div class="space-y-2">

                    <a href="index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-solid fa-table-cells-large w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>Dashboard Hub</span>
                    </a>

                    <a href="calendar.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                        <i class="fa-regular fa-calendar-days w-5 text-center text-slate-400 dark:text-slate-500"></i>
                        <span>View Calendar</span>
                    </a>

                    <?php if ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler'): ?>
                        <a href="request_status.php" class="nav-item active w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                                <span class="ml-auto relative flex h-3 w-3" title="<?php echo $pendingCount; ?> Pending Requests">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                            <?php endif; ?>
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

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-6 md:p-8 lg:p-10 relative">

        <div class="bento-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 p-6 md:p-8 rounded-2xl flex flex-col md:flex-row justify-between items-start md:items-center shadow-sm mb-8 gap-4 relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-48 h-48 bg-amber-500/5 dark:bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="relative z-10">
                <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-1">
                    <i class="fa-solid fa-clipboard-list text-amber-500 dark:text-amber-400 mr-2"></i> Event Status History
                </h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Track the status and details of all event submissions.</p>
            </div>
            <a href="javascript:history.back()" class="relative z-10 bg-slate-50 dark:bg-[#0b1120] hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold py-2.5 px-6 rounded-xl transition-colors border border-slate-200 dark:border-slate-700 flex items-center gap-2 shadow-sm text-sm">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>

        <div>
            <?php if (count($requests) > 0): ?>
                <?php
                $currentMonth = ''; 
                foreach ($requests as $req):
                    if (empty($req['start_date'])) {
                        $eventMonth = 'UNSCHEDULED / NO DATE';
                    } else {
                        $eventMonth = strtoupper(date('F Y', strtotime($req['start_date'])));
                    }

                    if ($eventMonth !== $currentMonth) {
                        if ($currentMonth !== '') echo '</div>'; 
                        echo '<div class="mt-8 mb-6 border-b border-slate-200 dark:border-slate-800 pb-3 flex items-center gap-3">
                                <i class="fa-regular fa-calendar-check text-amber-500 dark:text-amber-400 text-xl"></i>
                                <h2 class="text-lg font-extrabold text-slate-800 dark:text-slate-200 tracking-widest uppercase">' . htmlspecialchars($eventMonth) . '</h2>
                              </div>';
                        echo '<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">';
                        $currentMonth = $eventMonth;
                    }

                    if ($req['status'] === 'Approved') {
                        $statusStyle = 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/30';
                        $icon = 'fa-check-circle';
                    } elseif ($req['status'] === 'Rejected') {
                        $statusStyle = 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 border-red-200 dark:border-red-500/30';
                        $icon = 'fa-circle-xmark';
                    } else {
                        $statusStyle = 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-500/30 animate-pulse';
                        $icon = 'fa-clock';
                    }

                    $startDate = $req['start_date'] ? date('M j, Y', strtotime($req['start_date'])) : 'N/A';
                    $endDate = $req['end_date'] ? date('M j, Y', strtotime($req['end_date'])) : 'N/A';
                    $startTime = $req['start_time'] ? date('g:i A', strtotime($req['start_time'])) : 'N/A';
                    $endTime = $req['end_time'] ? date('g:i A', strtotime($req['end_time'])) : 'N/A';

                    $jsTitle = htmlspecialchars($req['title'], ENT_QUOTES);
                    $jsDesc = htmlspecialchars($req['description'] ?: 'No description provided.', ENT_QUOTES);
                    $jsCategory = htmlspecialchars($req['category_name'] ?: 'Not categorized', ENT_QUOTES);
                    $jsVenue = htmlspecialchars($req['venue_name'] ?: 'Unknown Venue', ENT_QUOTES);
                    $jsStatus = $req['status'];
                    ?>

                    <div onclick="openModal('<?php echo $jsTitle; ?>', '<?php echo $jsStatus; ?>', '<?php echo $jsCategory; ?>', '<?php echo $jsVenue; ?>', '<?php echo $startDate; ?>', '<?php echo $endDate; ?>', '<?php echo $startTime; ?>', '<?php echo $endTime; ?>', '<?php echo $jsDesc; ?>')"
                        class="bento-card status-card bg-white dark:bg-[#111827] border border-slate-200 dark:border-slate-800 p-6 rounded-2xl flex flex-col justify-between hover:shadow-sm cursor-pointer group">

                        <div>
                            <div class="flex justify-between items-start mb-4 gap-2">
                                <h3 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 leading-tight line-clamp-2 group-hover:text-amber-600 dark:group-hover:text-amber-400 transition-colors">
                                    <?php echo htmlspecialchars($req['title']); ?>
                               </h3>
                                <span class="px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase tracking-wider border flex items-center gap-1.5 shrink-0 <?php echo $statusStyle; ?>">
                                    <i class="fa-solid <?php echo $icon; ?>"></i>
                                    <?php echo htmlspecialchars($req['status']); ?>
                                </span>
                            </div>

                            <div class="space-y-2 mb-5">
                                <p class="text-sm text-slate-500 dark:text-slate-400 font-medium flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-tag text-slate-400 dark:text-slate-500 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['category_name'] ?? 'Not categorized'); ?>
                                </p>
                                <p class="text-sm text-slate-500 dark:text-slate-400 font-medium flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-location-dot text-slate-400 dark:text-slate-500 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['venue_name'] ?? 'Unknown Venue'); ?>
                                </p>
                                <?php if ($req['start_date']): ?>
                                    <p class="text-sm text-slate-500 dark:text-slate-400 font-medium flex items-center gap-2 truncate">
                                        <i class="fa-regular fa-calendar text-slate-400 dark:text-slate-500 w-4 text-center"></i>
                                        <?php echo $startDate; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center w-full">
                            <div class="text-xs font-bold text-sky-600 dark:text-sky-400 flex items-center gap-1 group-hover:underline">
                                Details <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                            </div>

                            <div class="flex gap-1.5">
                                <?php if ($req['status'] === 'Pending'): ?>
                                    <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=approve', 'approve');"
                                        class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center transition border border-emerald-200 dark:border-emerald-500/30 relative z-10" title="Approve">
                                        <i class="fa-solid fa-check text-xs"></i>
                                    </button>
                                    <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=reject', 'reject');"
                                        class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-500/10 hover:bg-amber-100 dark:hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center transition border border-amber-200 dark:border-amber-500/30 relative z-10" title="Reject">
                                        <i class="fa-solid fa-xmark text-xs"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($req['status'] === 'Rejected'): ?>
                                    <button onclick="event.stopPropagation(); confirmAction('request_status.php?delete_id=<?php echo $req['id']; ?>', 'delete');"
                                        class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-500/10 hover:bg-red-100 dark:hover:bg-red-500/20 text-red-600 dark:text-red-400 flex items-center justify-center transition border border-red-200 dark:border-red-500/30 relative z-10" title="Delete Permanent">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
                <?php if ($currentMonth !== '') echo '</div>'; ?>

            <?php else: ?>
                <div class="bg-white dark:bg-[#111827] rounded-3xl p-16 flex flex-col items-center justify-center text-center border border-slate-200 dark:border-slate-800 shadow-sm mt-4">
                    <div class="w-20 h-20 bg-slate-50 dark:bg-slate-800/50 rounded-full flex items-center justify-center mb-5 border border-slate-100 dark:border-slate-700">
                        <i class="fa-solid fa-inbox text-4xl text-slate-300 dark:text-slate-600"></i>
                    </div>
                    <h3 class="text-xl font-extrabold text-slate-800 dark:text-slate-200 mb-2">No requests found</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">There are no pending or history requests in the system.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="detailsModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm items-center justify-center p-4 transition-opacity">
        <div class="bg-white dark:bg-[#0b1120] w-full max-w-lg rounded-[2rem] border border-slate-100 dark:border-slate-700 shadow-2xl overflow-hidden transform scale-95 opacity-0 transition-all" id="modalContent">
            <div class="bg-slate-50 dark:bg-slate-900 p-6 flex justify-between items-start border-b border-slate-100 dark:border-slate-800">
                <div class="pr-4">
                    <div id="modalStatus" class="inline-block px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase tracking-wider border mb-2"></div>
                    <h2 id="modalTitle" class="text-xl font-extrabold text-slate-800 dark:text-slate-100 leading-tight">Event Title</h2>
                </div>
                <button onclick="closeModal()" class="text-slate-400 hover:text-red-500 transition bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
            <div class="p-6 space-y-6">
                <div class="bg-white dark:bg-[#111827] p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center justify-between text-slate-700 dark:text-slate-300 font-semibold text-sm">
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Starts</span>
                        <span id="modalStart" class="bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700"></span>
                    </div>
                    <div class="flex items-center justify-between text-slate-700 dark:text-slate-300 font-semibold text-sm border-t border-slate-100 dark:border-slate-800/50 pt-4">
                        <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Ends</span>
                        <span id="modalEnd" class="bg-slate-50 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-700"></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Category</h3>
                        <p class="text-slate-700 dark:text-slate-200 font-bold bg-slate-50 dark:bg-slate-900 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-tag text-slate-400"></i><span id="modalCategory" class="truncate">Not categorized</span>
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Venue</h3>
                        <p class="text-slate-700 dark:text-slate-200 font-bold bg-slate-50 dark:bg-slate-900 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 flex items-center gap-2 text-sm">
                            <i class="fa-solid fa-location-dot text-slate-400"></i><span id="modalVenue" class="truncate">Not specified</span>
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

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
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
            if (document.documentElement.classList.contains('dark')) localStorage.setItem('color-theme', 'dark');
            else localStorage.setItem('color-theme', 'light');
            updateToggleUI();
        });

        function confirmAction(url, action) {
            let titleText, detailText, btnColor, btnText;
            if (action === 'approve') { titleText = 'Approve this event?'; detailText = 'It will be officially added to the calendar.'; btnColor = '#10b981'; btnText = 'Yes, approve it!'; }
            else if (action === 'reject') { titleText = 'Reject this event?'; detailText = 'The event will be marked as rejected.'; btnColor = '#f59e0b'; btnText = 'Yes, reject it!'; }
            else if (action === 'delete') { titleText = 'Delete permanently?'; detailText = 'It will be removed from the system permanently.'; btnColor = '#ef4444'; btnText = 'Yes, delete it!'; }

            Swal.fire({
                title: titleText, text: detailText, icon: 'warning', showCancelButton: true, confirmButtonColor: btnColor, cancelButtonColor: '#64748b', confirmButtonText: btnText
            }).then((result) => { if (result.isConfirmed) window.location.href = url; });
        }

        function openModal(title, status, category, venue, startDate, endDate, startTime, endTime, desc) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalCategory').innerText = category;
            document.getElementById('modalVenue').innerText = venue;
            document.getElementById('modalDesc').innerText = desc;
            document.getElementById('modalStart').innerText = `${startDate} at ${startTime}`;
            document.getElementById('modalEnd').innerText = `${endDate} at ${endTime}`;

            const statusBadge = document.getElementById('modalStatus');
            statusBadge.innerText = status;
            statusBadge.className = 'inline-block px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase tracking-wider border mb-2 flex items-center gap-1.5 w-max';

            if (status === 'Approved') {
                statusBadge.classList.add('bg-emerald-50', 'dark:bg-emerald-500/10', 'text-emerald-600', 'dark:text-emerald-400', 'border-emerald-200', 'dark:border-emerald-500/30');
                statusBadge.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + status;
            } else if (status === 'Rejected') {
                statusBadge.classList.add('bg-red-50', 'dark:bg-red-500/10', 'text-red-600', 'dark:text-red-400', 'border-red-200', 'dark:border-red-500/30');
                statusBadge.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + status;
            } else {
                statusBadge.classList.add('bg-amber-50', 'dark:bg-amber-500/10', 'text-amber-600', 'dark:text-amber-400', 'border-amber-200', 'dark:border-amber-500/30', 'animate-pulse');
                statusBadge.innerHTML = '<i class="fa-solid fa-clock"></i> ' + status;
            }

            const modal = document.getElementById('detailsModal');
            const content = document.getElementById('modalContent');
            modal.classList.remove('hidden'); modal.classList.add('flex');
            setTimeout(() => { modal.classList.remove('opacity-0'); content.classList.remove('scale-95', 'opacity-0'); content.classList.add('scale-100', 'opacity-100'); }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('detailsModal');
            const content = document.getElementById('modalContent');
            modal.classList.add('opacity-0'); content.classList.remove('scale-100', 'opacity-100'); content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 300);
        }
        document.getElementById('detailsModal').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
    </script>
    <script src="assets/js/pdf_modal.js"></script>
</body>
</html>