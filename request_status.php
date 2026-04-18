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

// Fetch all participants linked to events so we can group them
// NEW: Fetch all participants linked to events so we can group them
$part_stmt = $pdo->query("
    SELECT ep.publish_id, p.name, p.department, p.strand 
    FROM event_participants ep
    JOIN participants p ON ep.participant_id = p.participant_id
");
$event_participants_map = [];
while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
    // Automatically append the strand so the modals don't need JS updates!
    $displayName = $row['name'];
    if (!empty($row['strand'])) {
        $displayName .= ' (' . $row['strand'] . ')';
    }
    
    $event_participants_map[$row['publish_id']][] = [
        'name' => $displayName,
        'department' => $row['department']
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Status - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
            <div
                class="w-20 h-20 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white/20">
                <i class="fa-solid fa-user text-3xl text-white/50"></i>
            </div>
            <h2 class="text-xl font-bold text-white">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-sm text-yellow-400 capitalize">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="p-6 border-b border-white/10">
                <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Traversal</h3>
                <div class="space-y-2">
                    <a href="index.php"
                        class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-solid fa-list w-5 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="calendar.php"
                        class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                        <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                        <span>View Calendar</span>
                    </a>
                    <?php if ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler'): ?>
                        <a href="request_status.php"
                            class="w-full bg-white/20 text-white font-semibold py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors border border-white/30">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php"
                            class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
                    <button onclick="openPdfModal()"
                        class="w-full bg-slate-600 hover:bg-slate-500 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm mt-3 border border-slate-500 block text-center">
                        <i class="fa-solid fa-print text-slate-300"></i> Print Schedule
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                <div class="p-6 border-b border-white/10">
                    <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
                    <div class="space-y-3">
                        <a href="add_event.php"
                            class="w-full bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                            <i class="fa-solid fa-plus"></i> Add New Event
                        </a>
                        <a href="functions/sync_holidays.php"
                            class="w-full bg-white/10 hover:bg-white/20 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-white/20">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-6 mt-auto border-t border-white/10">
            <a href="logout.php"
                class="flex items-center gap-3 px-4 py-3 text-red-400 hover:text-red-300 hover:bg-red-500/20 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8">
        <div class="w-full max-w-6xl mx-auto flex flex-col gap-6">

            <?php 
            // This catches both ?sync_msg=... and ?msg=... depending on what approve_event.php sends
            $displayMsg = $_GET['sync_msg'] ?? $_GET['msg'] ?? null;
            if ($displayMsg): 
                $isSuccess = (!isset($_GET['sync_status']) || $_GET['sync_status'] === 'success' || $_GET['msg'] === 'approved' || $_GET['msg'] === 'rejected');
                $bgColor = $isSuccess ? 'bg-emerald-500/20 border-emerald-500/50 text-emerald-300' : 'bg-red-500/20 border-red-500/50 text-red-300';
                $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
                
                // Format simple msg parameters to readable text just in case
                if ($displayMsg === 'approved') $displayMsg = 'Event successfully approved and added to the calendar!';
                if ($displayMsg === 'rejected') $displayMsg = 'Event has been rejected.';
            ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $bgColor; ?> flex items-center gap-3 shadow-lg">
                    <i class="fa-solid <?php echo $icon; ?> text-xl"></i>
                    <p class="font-medium text-sm"><?php echo htmlspecialchars($displayMsg); ?></p>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-between glass-container p-6 rounded-xl border border-white/10">
                <div>
                    <h1 class="text-3xl font-bold text-white"><i
                            class="fa-solid fa-clipboard-list text-yellow-400 mr-3"></i> Event Status History</h1>
                    <p class="text-slate-300 text-sm mt-1">Track the status and details of all event submissions.</p>
                </div>
                <a href="javascript:history.back()"
                    class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2.5 px-5 rounded-lg transition-colors border border-white/20 flex items-center gap-2">
                    <i class="fa-solid fa-arrow-left"></i> Back
                </a>
            </div>

            <div>
                <?php if (count($requests) > 0): ?>
                    <?php
                    $currentMonth = ''; // Track the month we are currently displaying
                
                    foreach ($requests as $req):
                        // Determine the month text for the current event
                        if (empty($req['start_date'])) {
                            $eventMonth = 'UNSCHEDULED / NO DATE';
                        } else {
                            // Example format: JANUARY 2024
                            $eventMonth = strtoupper(date('F Y', strtotime($req['start_date'])));
                        }

                        // Check if we need to print a new month header
                        if ($eventMonth !== $currentMonth) {
                            // If it's not the very first month, close the previous grid
                            if ($currentMonth !== '') {
                                echo '</div>'; // Close grid div
                            }

                            // Print the new Month Header
                            echo '
                            <div class="mt-8 mb-4 border-b border-white/10 pb-2 flex items-center gap-3">
                                <i class="fa-regular fa-calendar-check text-yellow-400 text-xl"></i>
                                <h2 class="text-xl font-bold text-white tracking-widest">' . htmlspecialchars($eventMonth) . '</h2>
                            </div>';

                            // Open a new grid for this month's events
                            echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';

                            // Update our tracker
                            $currentMonth = $eventMonth;
                        }

                        // Prepare Card Data (Colors, Icons, Statuses)
                        if ($req['status'] === 'Approved') {
                            $statusStyle = 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30';
                            $icon = 'fa-check-circle';
                        } elseif ($req['status'] === 'Rejected') {
                            $statusStyle = 'bg-red-500/20 text-red-400 border-red-500/30';
                            $icon = 'fa-circle-xmark';
                        } else {
                            $statusStyle = 'bg-amber-500/20 text-amber-400 border-amber-500/30 animate-pulse';
                            $icon = 'fa-clock';
                        }

                        // Format Dates and Times for the Modal
                        $startDate = $req['start_date'] ? date('M j, Y', strtotime($req['start_date'])) : 'N/A';
                        $endDate = $req['end_date'] ? date('M j, Y', strtotime($req['end_date'])) : 'N/A';
                        $startTime = $req['start_time'] ? date('g:i A', strtotime($req['start_time'])) : 'N/A';
                        $endTime = $req['end_time'] ? date('g:i A', strtotime($req['end_time'])) : 'N/A';

                        // Sanitize data for passing into Javascript
                        $jsTitle = htmlspecialchars($req['title'], ENT_QUOTES);
                        $jsDesc = htmlspecialchars($req['description'] ?: 'No description provided.', ENT_QUOTES);
                        $jsCategory = htmlspecialchars($req['category_name'] ?: 'Not categorized', ENT_QUOTES);
                        $jsVenue = htmlspecialchars($req['venue_name'] ?: 'Unknown Venue', ENT_QUOTES);
                        $jsStatus = $req['status'];
                        
                        // Retrieve and encode the participants for this specific event
                        $participants_array = $event_participants_map[$req['id']] ?? [];
                        $jsParticipants = htmlspecialchars(json_encode($participants_array), ENT_QUOTES, 'UTF-8');
                        ?>

                        <div onclick="openModal('<?php echo $jsTitle; ?>', '<?php echo $jsStatus; ?>', '<?php echo $jsCategory; ?>', '<?php echo $jsVenue; ?>', '<?php echo $startDate; ?>', '<?php echo $endDate; ?>', '<?php echo $startTime; ?>', '<?php echo $endTime; ?>', '<?php echo $jsDesc; ?>', '<?php echo $jsParticipants; ?>')"
                            class="glass-container p-5 rounded-xl border border-white/10 flex flex-col hover:bg-white/10 transition duration-300 cursor-pointer shadow-md hover:shadow-lg">

                            <div class="flex justify-between items-start mb-3">
                                <h3 class="text-lg font-bold text-white leading-tight truncate pr-2">
                                    <?php echo htmlspecialchars($req['title']); ?>
                                </h3>
                                <span
                                    class="px-2.5 py-1 rounded text-xs font-bold border flex items-center gap-1.5 whitespace-nowrap <?php echo $statusStyle; ?>">
                                    <i class="fa-solid <?php echo $icon; ?>"></i>
                                    <?php echo htmlspecialchars($req['status']); ?>
                                </span>
                            </div>

                            <div class="space-y-2 mb-4 flex-1">
                                <p class="text-sm text-slate-300 flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-tag text-slate-500 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['category_name'] ?? 'Not categorized'); ?>
                                </p>
                                <p class="text-sm text-slate-300 flex items-center gap-2 truncate">
                                    <i class="fa-solid fa-location-dot text-slate-500 w-4 text-center"></i>
                                    <?php echo htmlspecialchars($req['venue_name'] ?? 'Unknown Venue'); ?>
                                </p>
                                <?php if ($req['start_date']): ?>
                                    <p class="text-sm text-slate-300 flex items-center gap-2 truncate">
                                        <i class="fa-regular fa-calendar text-slate-500 w-4 text-center"></i>
                                        <?php echo $startDate; ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div
                                class="text-xs font-semibold border-t border-white/10 pt-3 flex justify-between items-center w-full">
                                <div class="text-blue-400 flex items-center gap-1">
                                    Click for details <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                                </div>

                                <div class="flex gap-2">
                                    <?php if ($req['status'] === 'Pending'): ?>
                                        <a href="javascript:void(0);"
                                            onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=approve', 'approve');"
                                            class="text-emerald-400 hover:text-emerald-300 bg-emerald-500/10 hover:bg-emerald-500/30 px-2.5 py-1.5 rounded transition-colors flex items-center gap-1 z-10 relative">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </a>
                                        <a href="javascript:void(0);"
                                            onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $req['id']; ?>&action=reject', 'reject');"
                                            class="text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/30 px-2.5 py-1.5 rounded transition-colors flex items-center gap-1 z-10 relative">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($req['status'] === 'Rejected'): ?>
                                        <a href="javascript:void(0);"
                                            onclick="event.stopPropagation(); confirmAction('request_status.php?delete_id=<?php echo $req['id']; ?>', 'delete');"
                                            class="text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/30 px-2.5 py-1.5 rounded transition-colors flex items-center gap-1 z-10 relative">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </a>
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
                    <div class="text-center py-12 glass-container rounded-xl border border-white/10 mt-6">
                        <i class="fa-solid fa-inbox text-5xl mb-4 text-slate-500"></i>
                        <p class="text-lg font-medium text-slate-300">No requests found in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="detailsModal"
        class="fixed inset-0 z-50 hidden bg-black/60 backdrop-blur-sm flex justify-center items-center p-4">
        <div class="glass-container w-full max-w-lg rounded-2xl border border-white/20 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]"
            id="modalContent">

            <div class="bg-black/30 p-5 flex justify-between items-start border-b border-white/10 flex-shrink-0">
                <div class="pr-4">
                    <div id="modalStatus" class="inline-block px-2 py-1 rounded text-xs font-bold border mb-2"></div>
                    <h2 id="modalTitle" class="text-2xl font-bold text-white">Event Title</h2>
                </div>
                <button onclick="closeModal()"
                    class="text-slate-400 hover:text-white transition-colors bg-white/5 hover:bg-white/10 rounded-lg p-2">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar">

                <div class="grid grid-cols-2 gap-4 bg-black/20 p-4 rounded-lg border border-white/5">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Starts</p>
                        <p class="text-sm text-white font-medium" id="modalStart"></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Ends</p>
                        <p class="text-sm text-white font-medium" id="modalEnd"></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Category</p>
                        <div
                            class="bg-black/20 p-3 rounded-lg border border-white/5 flex items-center gap-3 text-sm text-white font-medium">
                            <i class="fa-solid fa-tag text-purple-400"></i>
                            <span id="modalCategory">Not categorized</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Venue</p>
                        <div
                            class="bg-black/20 p-3 rounded-lg border border-white/5 flex items-center gap-3 text-sm text-white font-medium">
                            <i class="fa-solid fa-location-dot text-sky-400"></i>
                            <span id="modalVenue">Not specified</span>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Participants</p>
                    <div class="bg-black/20 p-4 rounded-lg border border-white/5 min-h-[60px]" id="modalParticipants">
                        </div>
                </div>

                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Description</p>
                    <div class="bg-black/20 p-4 rounded-lg border border-white/5 text-sm text-slate-300 leading-relaxed min-h-[80px]"
                        id="modalDesc">
                        Description goes here.
                    </div>
                </div>
            </div>

            <div class="p-4 bg-black/30 border-t border-white/10 flex justify-end flex-shrink-0">
                <button onclick="closeModal()"
                    class="bg-white/10 hover:bg-white/20 text-white font-semibold py-2 px-6 rounded-lg transition-colors border border-white/20">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // --- SweetAlert Confirm Action ---
        function confirmAction(url, action) {
            let titleText, detailText, btnColor, btnText;

            if (action === 'approve') {
                titleText = 'Approve this event?';
                detailText = 'It will be officially added to the calendar.';
                btnColor = '#10b981'; // Emerald
                btnText = 'Yes, approve it!';
            } else if (action === 'reject') {
                titleText = 'Reject this event?';
                detailText = 'The event will be marked as rejected.';
                btnColor = '#f59e0b'; // Amber
                btnText = 'Yes, reject it!';
            } else if (action === 'delete') {
                titleText = 'Delete permanently?';
                detailText = 'It will be removed from the system permanently.';
                btnColor = '#ef4444'; // Red
                btnText = 'Yes, delete it!';
            }

            Swal.fire({
                title: titleText,
                text: detailText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: btnColor,
                cancelButtonColor: '#64748b', // Slate
                confirmButtonText: btnText
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url; // Redirect if confirmed
                }
            });
        }

        // --- Details Modal Logic ---
        function openModal(title, status, category, venue, startDate, endDate, startTime, endTime, desc, partsJson) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalCategory').innerText = category;
            document.getElementById('modalVenue').innerText = venue;
            document.getElementById('modalDesc').innerText = desc;

            document.getElementById('modalStart').innerText = `${startDate} at ${startTime}`;
            document.getElementById('modalEnd').innerText = `${endDate} at ${endTime}`;

            // Parse and render Participants
            const partsDiv = document.getElementById('modalParticipants');
            partsDiv.innerHTML = ''; // Clear out old data
            
            let participants = [];
            try {
                if(partsJson) {
                    participants = JSON.parse(partsJson);
                }
            } catch (e) {
                console.error("Error parsing participants json", e);
            }

            if (participants && participants.length > 0) {
                // Group by department
                const grouped = {};
                participants.forEach(p => {
                    if (!grouped[p.department]) grouped[p.department] = [];
                    grouped[p.department].push(p.name);
                });

                // Generate badges
                for (const [dept, names] of Object.entries(grouped)) {
                    const badge = document.createElement('div');
                    badge.className = "bg-white/10 border border-white/20 rounded px-3 py-1.5 text-sm mb-2 mr-2 inline-block";
                    badge.innerHTML = `<span class="text-yellow-400 font-bold mr-2">${dept}:</span><span class="text-slate-200">${names.join(', ')}</span>`;
                    partsDiv.appendChild(badge);
                }
            } else {
                partsDiv.innerHTML = '<span class="text-white/50 italic text-sm">No participants specified.</span>';
            }

            const statusBadge = document.getElementById('modalStatus');
            statusBadge.innerText = status;

            statusBadge.className = 'inline-block px-2 py-1 rounded text-xs font-bold border mb-2';

            if (status === 'Approved') {
                statusBadge.classList.add('bg-emerald-500/20', 'text-emerald-400', 'border-emerald-500/30');
            } else if (status === 'Rejected') {
                statusBadge.classList.add('bg-red-500/20', 'text-red-400', 'border-red-500/30');
            } else {
                statusBadge.classList.add('bg-amber-500/20', 'text-amber-400', 'border-amber-500/30');
            }

            const modal = document.getElementById('detailsModal');
            const content = document.getElementById('modalContent');
            modal.classList.remove('hidden');

            setTimeout(() => {
                content.classList.remove('scale-95');
                content.classList.add('scale-100');
            }, 10);
        }

        function closeModal() {
            const modal = document.getElementById('detailsModal');
            const content = document.getElementById('modalContent');

            content.classList.remove('scale-100');
            content.classList.add('scale-95');

            setTimeout(() => {
                modal.classList.add('hidden');
            }, 200);
        }

        document.getElementById('detailsModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
    <script src="assets/js/pdf_modal.js"></script>
</body>

</html>