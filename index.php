<?php
// index.php
session_start();

// The Bouncer: If they are not logged in, kick them out!
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 1. Include database connection from the functions folder
require_once 'functions/database.php';

// 2. Fetch Categories for the Sidebar
$stmt = $pdo->query("SELECT * FROM event_categories ORDER BY category_id ASC");
$categories = $stmt->fetchAll();

// 3. Fetch ALL Events (With Publish Status)
$stmt = $pdo->query("
    SELECT e.*, c.category_name, p.status 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    ORDER BY e.start_date ASC, e.start_time ASC
");
$events = $stmt->fetchAll();

// Helper function to map Category Names to FULL Tailwind Classes
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
    <title>St. Joseph School Foundation - Event List</title>
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
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-zinc-950 text-zinc-200 font-sans h-screen flex overflow-hidden">

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
                    <a href="index.php" class="w-full bg-emerald-500/10 text-emerald-400 font-bold py-3 px-4 rounded-lg flex items-center gap-4 border border-emerald-500/30 transition-colors text-lg">
                        <i class="fa-solid fa-list w-6 text-center"></i>
                        <span>All Schedule Events</span>
                    </a>
                    <a href="calendar.php" class="w-full hover:bg-zinc-800/50 text-zinc-300 hover:text-white font-semibold py-3 px-4 rounded-lg flex items-center gap-4 transition-colors text-lg">
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
    
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-4 sm:p-6 md:p-8 bg-zinc-950 relative">
        
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-emerald-500 rounded-full mix-blend-screen filter blur-[100px] opacity-5 pointer-events-none z-0"></div>

        <?php if (isset($_GET['sync_msg'])): ?>
            <?php 
                $isSuccess = $_GET['sync_status'] === 'success';
                $bgColor = $isSuccess ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400';
                $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
            ?>
            <div class="mb-8 px-5 py-4 rounded-xl border <?php echo $bgColor; ?> flex items-center gap-4 backdrop-blur-sm relative z-10">
                <i class="fa-solid <?php echo $icon; ?> text-2xl"></i>
                <p class="font-bold text-lg"><?php echo htmlspecialchars($_GET['sync_msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="mb-8 relative z-10 flex items-center justify-between">
            <h1 class="text-4xl font-extrabold text-zinc-100 tracking-tight">All Scheduled Events</h1>
            <span id="event-counter" class="bg-zinc-900 border-2 border-zinc-700 text-zinc-300 py-2 px-5 rounded-full text-sm font-bold uppercase tracking-wider shadow-md">
                Total: <?php echo count($events); ?>
            </span>
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

        <div class="bg-zinc-900/80 backdrop-blur-md border border-zinc-700 rounded-2xl p-6 sm:p-8 flex-1 overflow-y-auto relative z-10 shadow-inner">
            
            <div class="space-y-4">
                <?php if (count($events) > 0): ?>
                    <?php foreach ($events as $event): ?>
                        <?php 
                            $color = getCategoryColor($event['category_name']); 
                            $formattedDate = date('F j, Y', strtotime($event['start_date']));
                            $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
                            $formattedEndDate = date('F j, Y', strtotime($event['end_date']));
                            $formattedEndTime = ($event['end_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['end_time']));
                        ?>

                        <div class="event-card cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between p-5 rounded-xl bg-zinc-950/60 border-2 border-zinc-800 hover:border-emerald-500/50 hover:bg-zinc-800/80 transition-all duration-300 group shadow-md" 
                            data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                            data-title="<?php echo htmlspecialchars($event['title']); ?>"
                            data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                            data-date="<?php echo $formattedDate; ?>"
                            data-time="<?php echo $formattedTime; ?>"
                            data-end-date="<?php echo $formattedEndDate; ?>"  
                            data-end-time="<?php echo $formattedEndTime; ?>"  
                            onclick="openModal(this)">
                            
                            <div class="flex items-center gap-6">
                                <div class="bg-zinc-900 border-2 border-zinc-700 rounded-lg text-center p-3 min-w-[90px] group-hover:border-emerald-500/50 transition-colors shadow-inner">
                                    <span class="block text-sm font-extrabold text-emerald-500 uppercase tracking-widest"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                    <span class="block text-4xl font-black text-zinc-100 leading-none mt-1"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                </div>
                                
                                <div>
                                    <h3 class="text-2xl font-extrabold text-zinc-100 group-hover:text-emerald-400 transition-colors"><?php echo htmlspecialchars($event['title']); ?></h3>
                                    <div class="flex items-center flex-wrap gap-3 mt-2 text-base font-medium">
                                        <span class="text-zinc-300 flex items-center gap-2"><i class="fa-regular fa-clock text-zinc-500"></i> <?php echo $formattedTime; ?></span>
                                        <span class="text-zinc-600 mx-1">|</span>
                                        <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> border <?php echo $color['border']; ?> px-3 py-1 rounded-md text-sm font-bold uppercase tracking-wider">
                                            <?php echo htmlspecialchars($event['category_name']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-right flex flex-col items-end gap-3 mt-5 sm:mt-0 w-full sm:w-auto">
                                <?php if ($event['publish_id'] === null): ?>
                                    <span class="text-sm font-bold text-emerald-400 bg-emerald-500/10 px-3 py-1.5 rounded-md border border-emerald-500/30">
                                        <i class="fa-solid fa-check-circle mr-1.5"></i> Auto-Approved
                                    </span>
                                <?php elseif ($event['status'] === 'Approved'): ?>
                                    <span class="text-sm font-bold text-sky-400 bg-sky-500/10 px-3 py-1.5 rounded-md border border-sky-500/30">
                                        <i class="fa-solid fa-check-double mr-1.5"></i> Approved (ID: <?php echo $event['publish_id']; ?>)
                                    </span>
                                <?php elseif ($event['status'] === 'Pending'): ?>
                                    <span class="text-sm font-bold text-amber-400 bg-amber-500/10 px-3 py-1.5 rounded-md border border-amber-500/30 mb-2">
                                        <i class="fa-solid fa-clock mr-1.5"></i> Pending Approval
                                    </span>
                                    <div class="flex gap-3">
                                        <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=approve', 'approve')" 
                                                class="bg-emerald-500/20 hover:bg-emerald-500 border-2 border-emerald-500/40 text-emerald-400 hover:text-white text-sm font-bold py-2 px-5 rounded-lg transition-all shadow-md">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                        <button onclick="event.stopPropagation(); confirmAction('approve_event.php?id=<?php echo $event['publish_id']; ?>&action=reject', 'reject')" 
                                                class="bg-red-500/20 hover:bg-red-500 border-2 border-red-500/40 text-red-400 hover:text-white text-sm font-bold py-2 px-5 rounded-lg transition-all shadow-md">
                                            <i class="fa-solid fa-xmark"></i> Reject
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-20 px-6 border-2 border-dashed border-zinc-700 rounded-2xl bg-zinc-950/60">
                        <i class="fa-regular fa-calendar-xmark text-6xl mb-5 text-zinc-600"></i>
                        <p class="text-2xl font-bold text-zinc-200">No events found</p>
                        <p class="text-lg text-zinc-400 mt-2">Click "Add New Event" to populate the queue.</p>
                    </div>
                <?php endif; ?>
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
<script src="assets/js/filter.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const checkboxes = document.querySelectorAll('.category-filter');
        const filterButtonText = document.getElementById('filter-button-text');

        function updateFilterButton() {
            if (!filterButtonText) return; 
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (checkedCount === 0) {
                filterButtonText.innerText = 'No Categories';
            } else if (checkedCount === checkboxes.length) {
                filterButtonText.innerText = 'All Categories';
            } else {
                filterButtonText.innerText = `${checkedCount} Selected`;
            }
        }

        checkboxes.forEach(box => {
            box.addEventListener('change', updateFilterButton);
        });

        updateFilterButton();
    });

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