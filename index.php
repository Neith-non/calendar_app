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
    
    // Returns an array: [Text Color, Background Color, Border Color, Focus Ring]
    if (strpos($name, 'curricular') !== false && strpos($name, 'extra') === false) 
        return ['text' => 'text-blue-700', 'bg' => 'bg-blue-100', 'border' => 'border-blue-200', 'ring' => 'focus:ring-blue-500', 'checkbox' => 'text-blue-500'];
        
    if (strpos($name, 'extra-curricular') !== false || strpos($name, 'sports') !== false) 
        return ['text' => 'text-green-700', 'bg' => 'bg-green-100', 'border' => 'border-green-200', 'ring' => 'focus:ring-green-500', 'checkbox' => 'text-green-500'];
        
    if (strpos($name, 'mass') !== false) 
        return ['text' => 'text-purple-700', 'bg' => 'bg-purple-100', 'border' => 'border-purple-200', 'ring' => 'focus:ring-purple-500', 'checkbox' => 'text-purple-500'];
        
    if (strpos($name, 'meeting') !== false || strpos($name, 'staff') !== false) 
        return ['text' => 'text-orange-700', 'bg' => 'bg-orange-100', 'border' => 'border-orange-200', 'ring' => 'focus:ring-orange-500', 'checkbox' => 'text-orange-500'];
        
    if (strpos($name, 'holiday') !== false) 
        return ['text' => 'text-yellow-700', 'bg' => 'bg-yellow-100', 'border' => 'border-yellow-200', 'ring' => 'focus:ring-yellow-500', 'checkbox' => 'text-yellow-500'];
        
    // Default fallback (Slate)
    return ['text' => 'text-slate-700', 'bg' => 'bg-slate-100', 'border' => 'border-slate-200', 'ring' => 'focus:ring-slate-500', 'checkbox' => 'text-slate-500'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>St. Joseph School Foundation - Event List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex overflow-hidden font-sans">

    <aside class="w-72 bg-slate-800 text-slate-200 flex flex-col flex-shrink-0 shadow-lg z-10">
        <div class="p-8 text-center border-b border-slate-700">
            <div class="w-20 h-20 mx-auto bg-slate-300 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-slate-600">
                <i class="fa-solid fa-user text-3xl text-slate-500"></i>
            </div>
            <h2 class="text-xl font-bold text-white">
            </p><h2 class="text-xl font-bold text-white">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Ma\'am Reyes'); ?>
            </h2>
            <p class="text-sm text-slate-400 capitalize">
            <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
            </p>
        </div>

        <div class="p-6 flex-1 overflow-y-auto">
            <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-4">Event Categories</h3>
            <div class="space-y-3">
                <?php foreach($categories as $cat): ?>
                    <?php $color = getCategoryColor($cat['category_name']); ?>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                    <input type="checkbox" checked value="<?php echo htmlspecialchars($cat['category_name']); ?>" class="category-filter w-5 h-5 rounded <?php echo $color['checkbox']; ?> bg-slate-700 border-slate-600 <?php echo $color['ring']; ?>">
                <span class="group-hover:text-white transition-colors"><?php echo htmlspecialchars($cat['category_name']); ?></span>
             </label>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="p-6 border-t border-slate-700 space-y-3">
            <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
            <a href="add_event.php" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                <i class="fa-solid fa-plus"></i> Add New Event
            </a>
            <a href="functions/sync_holidays.php" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
            </a>
            <a href="calendar.php" class="w-full bg-slate-700 hover:bg-slate-600 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-slate-600">
                <i class="fa-regular fa-calendar-days"></i> View Calendar
            </a>

            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 mt-auto text-red-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors font-medium">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto p-8">
        
        <?php if (isset($_GET['sync_msg'])): ?>
            <?php 
                $isSuccess = $_GET['sync_status'] === 'success';
                $bgColor = $isSuccess ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700';
                $icon = $isSuccess ? 'fa-circle-check' : 'fa-triangle-exclamation';
            ?>
            <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $bgColor; ?> flex items-center gap-3 shadow-sm">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <p class="font-medium"><?php echo htmlspecialchars($_GET['sync_msg']); ?></p>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-6 border-b border-slate-100 pb-4">
                <h1 class="text-2xl font-bold text-slate-800">All Scheduled Events</h1>
                <span id="event-counter" class="bg-slate-100 text-slate-600 py-1 px-3 rounded-full text-sm font-semibold">
                    Total: <?php echo count($events); ?>
                </span>
            </div>

            <div class="space-y-4">
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

                                <div class="event-card cursor-pointer flex items-center justify-between p-4 rounded-lg border border-slate-200 hover:border-blue-300 hover:shadow-md transition bg-slate-50 group" 
                                    data-category="<?php echo htmlspecialchars($event['category_name']); ?>"
                                    data-title="<?php echo htmlspecialchars($event['title']); ?>"
                                    data-desc="<?php echo htmlspecialchars($event['description'] ?? 'No description provided.'); ?>"
                                    data-date="<?php echo $formattedDate; ?>"
                                    data-time="<?php echo $formattedTime; ?>"
                                    data-end-date="<?php echo $formattedEndDate; ?>"  
                                    data-end-time="<?php echo $formattedEndTime; ?>"  
                                    onclick="openModal(this)">
                            <div class="flex items-center gap-4">
                                <div class="bg-white border border-slate-200 rounded-md text-center p-2 min-w-[80px] shadow-sm">
                                    <span class="block text-xs font-bold text-slate-400 uppercase"><?php echo date('M', strtotime($event['start_date'])); ?></span>
                                    <span class="block text-2xl font-black text-slate-700 leading-none"><?php echo date('d', strtotime($event['start_date'])); ?></span>
                                </div>
                                
                                <div>
                                    <h3 class="text-lg font-bold text-slate-800 group-hover:text-blue-600 transition"><?php echo htmlspecialchars($event['title']); ?></h3>
                                    <div class="flex items-center gap-3 mt-1 text-sm text-slate-500">
                                        <span><i class="fa-regular fa-clock mr-1"></i> <?php echo $formattedTime; ?></span>
                                        <span class="text-slate-300">|</span>
                                        <span class="<?php echo $color['bg']; ?> <?php echo $color['text']; ?> px-2 py-0.5 rounded text-xs font-semibold">
                                            <?php echo htmlspecialchars($event['category_name']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-right flex flex-col items-end gap-2">
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
                    <div class="text-center py-12 text-slate-500">
                        <i class="fa-regular fa-calendar-xmark text-4xl mb-3 text-slate-300"></i>
                        <p class="text-lg font-medium">No events found in the database.</p>
                        <p class="text-sm">Click "Sync Holidays" or add a new event to get started.</p>
                    </div>
                <?php endif; ?>
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