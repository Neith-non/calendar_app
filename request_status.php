<?php
session_start();

// 1. Check Permissions
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: index.php?error=unauthorized");
    exit();
}

require_once 'functions/database.php';

// --- Handle Deletion of Rejected Requests ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int) $_GET['delete_id'];

    // Delete from events table first
    $stmt_del_event = $pdo->prepare("DELETE FROM events WHERE publish_id = ?");
    $stmt_del_event->execute([$delete_id]);

    // Delete from publish table (only if it's actually rejected, as a safety measure)
    $stmt_del_pub = $pdo->prepare("DELETE FROM event_publish WHERE id = ? AND status = 'Rejected'");
    $stmt_del_pub->execute([$delete_id]);

    // Refresh the page and show a success message!
    header("Location: request_status.php?sync_status=success&sync_msg=" . urlencode("Event permanently deleted."));
    exit();
}
// -------------------------------------------------

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

// Fetch requests and JOIN with events and categories to get all needed data
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
    <title>Event Status - SJSFI</title>
    
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
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(16, 185, 129, 0.4); }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="h-screen flex overflow-hidden bg-[#f4fcf7] dark:bg-[#04120a] transition-colors duration-300">

    <?php include 'includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8 relative custom-scrollbar">
        
        <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-[#d1f0e0] dark:border-[#123f29] w-full">
            <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
            <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#0a1a12] rounded-xl border border-[#d1f0e0] dark:border-[#123f29] text-emerald-700 dark:text-emerald-400 flex items-center justify-center shadow-sm hover:bg-emerald-50 dark:hover:bg-[#103322] transition-colors">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="w-full max-w-6xl mx-auto flex flex-col gap-6">

            <?php 
            $displayMsg = $_GET['sync_msg'] ?? $_GET['msg'] ?? null;
            if ($displayMsg): 
                $isSuccess = (!isset($_GET['sync_status']) || $_GET['sync_status'] === 'success' || $_GET['msg'] === 'approved' || $_GET['msg'] === 'rejected');
                $bgColor = $isSuccess ? 'bg-[#f0fcf5] dark:bg-[#0a1a12] border border-emerald-200 dark:border-emerald-900/50 text-emerald-700 dark:text-emerald-400' : 'bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 text-red-700 dark:text-red-400';
                $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
                
                if ($displayMsg === 'approved') $displayMsg = 'Event successfully approved and added to the calendar!';
                if ($displayMsg === 'rejected') $displayMsg = 'Event has been rejected.';
            ?>
                <div class="px-5 py-4 rounded-2xl <?php echo $bgColor; ?> flex items-center gap-3 shadow-sm">
                    <i class="fa-solid <?php echo $icon; ?> text-xl"></i>
                    <p class="font-bold text-sm"><?php echo htmlspecialchars($displayMsg); ?></p>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-between bento-card p-6 sm:p-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-[#e0f5ea] dark:bg-[#103322] border border-[#bbf2d1] dark:border-[#1a4d33] flex items-center justify-center shadow-sm">
                        <i class="fa-solid fa-clipboard-list text-xl text-emerald-600 dark:text-emerald-400"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">Event Status History</h1>
                        <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mt-1">Track the status and details of all event submissions.</p>
                    </div>
                </div>
                <a href="javascript:history.back()" class="hidden sm:flex bg-white dark:bg-[#0a1a12] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] text-emerald-700 dark:text-emerald-400 font-bold py-2.5 px-5 rounded-xl transition-colors border border-[#d1f0e0] dark:border-[#123f29] shadow-sm items-center gap-2 text-sm">
                    <i class="fa-solid fa-arrow-left"></i> Back
                </a>
            </div>

            <div>
                <?php if (count($requests) > 0): ?>
                    <?php
                    $currentMonth = '';
                
                    foreach ($requests as $req):
                        // Group by Month/Year
                        if (empty($req['start_date'])) {
                            $eventMonth = 'UNSCHEDULED / NO DATE';
                        } else {
                            $eventMonth = strtoupper(date('F Y', strtotime($req['start_date'])));
                        }

                        // Print Month Header
                        if ($eventMonth !== $currentMonth) {
                            if ($currentMonth !== '') {
                                echo '</div>'; // Close previous grid
                            }

                            echo '
                            <div class="mt-10 mb-5 border-b border-[#d1f0e0] dark:border-[#123f29] pb-3 flex items-center gap-3">
                                <i class="fa-regular fa-calendar-check text-emerald-500 text-lg"></i>
                                <h2 class="text-lg font-extrabold text-emerald-800 dark:text-emerald-400 tracking-widest uppercase">' . htmlspecialchars($eventMonth) . '</h2>
                            </div>';

                            echo '<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">';
                            $currentMonth = $eventMonth;
                        }

                        // Status Styling
                        if ($req['status'] === 'Approved') {
                            $statusStyle = 'bg-[#f0fcf5] dark:bg-[#0a1a12] text-emerald-600 dark:text-emerald-400 border border-[#bbf2d1] dark:border-[#1a4d33]';
                            $icon = 'fa-check-circle';
                        } elseif ($req['status'] === 'Rejected') {
                            $statusStyle = 'bg-red-50 dark:bg-red-900/10 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-900/30';
                            $icon = 'fa-circle-xmark';
                        } else {
                            $statusStyle = 'bg-amber-50 dark:bg-amber-900/10 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-900/30 animate-pulse';
                            $icon = 'fa-clock';
                        }

                        // Formatting for Modal
                        $startDate = $req['start_date'] ? date('F j, Y', strtotime($req['start_date'])) : 'N/A';
                        $endDate = $req['end_date'] ? date('F j, Y', strtotime($req['end_date'])) : 'N/A';
                        $startTime = ($req['start_time'] == '00:00:00' || $req['start_time'] == '23:59:59') ? 'All Day' : ($req['start_time'] ? date('g:i A', strtotime($req['start_time'])) : 'N/A');
                        $endTime = ($req['end_time'] == '00:00:00' || $req['end_time'] == '23:59:59') ? 'All Day' : ($req['end_time'] ? date('g:i A', strtotime($req['end_time'])) : 'N/A');

                        $jsTitle = htmlspecialchars($req['title'], ENT_QUOTES);
                        $jsDesc = htmlspecialchars($req['description'] ?: 'No description provided.', ENT_QUOTES);
                        $jsCategory = htmlspecialchars($req['category_name'] ?: 'Not categorized', ENT_QUOTES);
                        $jsVenue = htmlspecialchars($req['venue_name'] ?: 'Unknown Venue', ENT_QUOTES);
                        $jsStatus = $req['status'];
                        
                        $participants_array = $event_participants_map[$req['id']] ?? [];
                        $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                        ?>

                        <div class="bento-card p-6 flex flex-col hover:shadow-lg hover:-translate-y-1 transition-all duration-300 cursor-pointer relative group"
                             x-data="{ showNoteForm: false }"
                             data-title="<?php echo $jsTitle; ?>"
                             data-desc="<?php echo $jsDesc; ?>"
                             data-category="<?php echo $jsCategory; ?>" 
                             data-venue="<?php echo $jsVenue; ?>"
                             data-date="<?php echo $startDate; ?>"
                             data-time="<?php echo $startTime; ?>"
                             data-end-date="<?php echo $endDate; ?>"
                             data-end-time="<?php echo $endTime; ?>"
                             data-participants="<?php echo $jsParticipants; ?>"
                             onclick="openModal(this)">

                            <button @click.stop="showNoteForm = true" class="absolute top-16 right-6 w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/60 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-700/50 shadow-md flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10 hover:bg-amber-200 dark:hover:bg-amber-800" title="Add Sticky Note">
                                <i class="fa-regular fa-note-sticky text-sm"></i>
                            </button>

                            <div x-show="showNoteForm" style="display: none;" @click.stop x-transition.opacity class="absolute inset-0 bg-white/90 dark:bg-[#07160f]/90 backdrop-blur-sm z-20 rounded-[1.5rem] flex flex-col items-center justify-center p-6 cursor-default">
                                <div class="w-full bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl shadow-lg p-4 transform transition-transform">
                                    <h4 class="text-[10px] font-black text-amber-700 dark:text-amber-500 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                                        <i class="fa-solid fa-thumbtack"></i> Sticky Note
                                    </h4>
                                    <textarea class="w-full bg-white dark:bg-[#04120a] border border-amber-200 dark:border-amber-700/50 rounded-lg p-2.5 text-sm text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:focus:ring-amber-600 resize-none placeholder-slate-400 dark:placeholder-slate-500 mb-3" rows="3" placeholder="Type a note here..."></textarea>
                                    <div class="flex justify-end gap-2">
                                        <button @click="showNoteForm = false" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 transition-colors">Cancel</button>
                                        <button @click="showNoteForm = false" class="px-3 py-1.5 text-xs font-bold bg-amber-400 hover:bg-amber-500 text-amber-900 rounded-lg shadow-sm transition-colors flex items-center gap-1.5"><i class="fa-solid fa-check"></i> Save</button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-between items-start mb-4">
                                <h3 class="text-lg font-black text-slate-800 dark:text-white leading-tight truncate pr-4 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors">
                                    <?php echo htmlspecialchars($req['title']); ?>
                                </h3>
                                <span class="px-2.5 py-1 rounded-md text-[10px] uppercase tracking-wider font-extrabold flex items-center gap-1.5 whitespace-nowrap shadow-sm <?php echo $statusStyle; ?>">
                                    <i class="fa-solid <?php echo $icon; ?>"></i>
                                    <?php echo htmlspecialchars($req['status']); ?>
                                </span>
                            </div>

                            <div class="space-y-2 mb-6 flex-1">
                                <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-tag text-emerald-400 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['category_name'] ?? 'Not categorized'); ?>
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-location-dot text-emerald-400 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['venue_name'] ?? 'Unknown Venue'); ?>
                                </p>
                                <?php if ($req['start_date']): ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold flex items-center gap-2 truncate">
                                        <i class="fa-regular fa-calendar text-emerald-400 w-4 text-center"></i>
                                        <?php echo date('M j, Y', strtotime($req['start_date'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="border-t border-[#d1f0e0] dark:border-[#123f29] pt-4 flex justify-between items-center w-full">
                                <div class="text-[10px] font-bold text-emerald-600 dark:text-emerald-500 uppercase tracking-widest flex items-center gap-1 group-hover:text-emerald-500 transition-colors">
                                    Details <i class="fa-solid fa-arrow-right ml-0.5 transform group-hover:translate-x-1 transition-transform"></i>
                                </div>

                                <div class="flex gap-2">
                                    <?php if ($req['status'] === 'Pending'): ?>
                                        <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=approve', 'approve');"
                                            class="w-8 h-8 rounded-lg bg-[#f0fcf5] dark:bg-[#0a1a12] border border-[#bbf2d1] dark:border-[#1a4d33] hover:bg-emerald-50 dark:hover:bg-[#103322] text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 transition flex items-center justify-center shadow-sm z-10 relative">
                                            <i class="fa-solid fa-check text-sm"></i>
                                        </button>
                                        <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=reject', 'reject');"
                                            class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 hover:bg-red-100 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 hover:text-red-700 transition flex items-center justify-center shadow-sm z-10 relative">
                                            <i class="fa-solid fa-xmark text-sm"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($req['status'] === 'Rejected'): ?>
                                        <button onclick="event.stopPropagation(); confirmActionDelete('request_status.php?delete_id=<?php echo $req['id']; ?>');"
                                            class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 hover:bg-red-100 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 hover:text-red-700 transition flex items-center justify-center shadow-sm z-10 relative">
                                            <i class="fa-solid fa-trash text-sm"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php
                    // Close the very last grid tag if there were events
                    if ($currentMonth !== '') {
                        echo '</div>';
                    }
                    ?>

                <?php else: ?>
                    <div class="text-center py-16 bento-card mt-6">
                        <div class="w-20 h-20 bg-[#f0fcf5] dark:bg-[#0a1a12] rounded-full flex items-center justify-center mx-auto mb-5 border border-[#d1f0e0] dark:border-[#123f29]">
                            <i class="fa-solid fa-inbox text-4xl text-emerald-300 dark:text-emerald-700"></i>
                        </div>
                        <p class="text-xl font-extrabold text-slate-800 dark:text-white mb-2">No requests found</p>
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">There are currently no events pending approval in the system.</p>
                    </div>
                <?php endif; ?>
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
<script src="assets/js/pdf_modal.js"></script>
<script>
    // Theme toggle initialization
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeToggleKnob = document.getElementById('theme-toggle-knob');
    const themeToggleIcon = document.getElementById('theme-toggle-icon');
    const themeToggleText = document.getElementById('theme-toggle-text');

    if(themeToggleBtn) {
        function updateToggleUI() {
            if (document.documentElement.classList.contains('dark')) {
                if(themeToggleKnob) themeToggleKnob.classList.add('translate-x-5');
                if(themeToggleIcon) themeToggleIcon.className = 'fa-solid fa-sun text-yellow-400';
                if(themeToggleText) themeToggleText.innerText = 'Light Mode';
            } else {
                if(themeToggleKnob) themeToggleKnob.classList.remove('translate-x-5');
                if(themeToggleIcon) themeToggleIcon.className = 'fa-solid fa-moon text-slate-400';
                if(themeToggleText) themeToggleText.innerText = 'Dark Mode';
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
    }

    // Specific Delete Confirmation for this page
    function confirmActionDelete(url) {
        Swal.fire({
            title: 'Delete permanently?',
            text: 'It will be removed from the system permanently.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444', 
            cancelButtonColor: '#64748b', 
            confirmButtonText: 'Yes, delete it!',
            customClass: {
                popup: 'dark:bg-[#07160f] dark:border dark:border-[#123f29] dark:text-white',
                title: 'dark:text-white',
                htmlContainer: 'dark:text-slate-400'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    }
</script>
</html>