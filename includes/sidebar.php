<?php
// includes/sidebar.php

// 1. Figure out what page we are currently on to highlight the active menu item
$current_page = basename($_SERVER['PHP_SELF']);

// 2. Figure out if we are inside the admin folder or the main folder to fix link paths
$in_admin_folder = (basename(dirname($_SERVER['PHP_SELF'])) === 'admin');
$path_prefix = $in_admin_folder ? '../' : '';

// 3. Define the CSS classes for active vs inactive buttons
$active_class = "bg-white/20 text-white font-semibold border-white/30 border";
$inactive_class = "hover:bg-white/10 text-slate-300 hover:text-white font-medium border-transparent border";
?>

<div class="flex-1 overflow-y-auto">
    <div class="p-6 border-b border-white/10">
        <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Traversal</h3>
        <div class="space-y-2">
            
            <a href="<?php echo $path_prefix; ?>index.php" class="w-full py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors <?php echo ($current_page == 'index.php') ? $active_class : $inactive_class; ?>">
                <i class="fa-solid fa-list w-5 text-center"></i> <span>All Schedule Events</span>
            </a>
            
            <a href="<?php echo $path_prefix; ?>calendar.php" class="w-full py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors <?php echo ($current_page == 'calendar.php') ? $active_class : $inactive_class; ?>">
                <i class="fa-regular fa-calendar-days w-5 text-center"></i> <span>View Calendar</span>
            </a>
            
            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                <a href="<?php echo $path_prefix; ?>request_status.php" class="w-full py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors <?php echo ($current_page == 'request_status.php') ? $active_class : $inactive_class; ?>">
                    <i class="fa-solid fa-clipboard-list w-5 text-center"></i> <span>Event Status</span>
                    <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                        <span class="ml-auto relative flex h-3 w-3" title="<?php echo $pendingCount; ?> Pending Requests">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                <a href="<?php echo $path_prefix; ?>admin/admin_manage.php" class="w-full py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors <?php echo ($current_page == 'admin_manage.php') ? $active_class : $inactive_class; ?>">
                    <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i> <span>Admin Panel</span>
                </a>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                <button onclick="openPdfModal()" class="w-full bg-slate-600 hover:bg-slate-500 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm mt-3 border border-slate-500 block text-center">
                    <i class="fa-solid fa-print text-slate-300"></i> Print Schedule
                </button>
            <?php endif; ?>
            
        </div>
    </div>

    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
        <div class="p-6 border-b border-white/10">
            <h3 class="text-sm uppercase tracking-wider text-slate-400 font-semibold mb-3">Quick Actions</h3>
            <div class="space-y-3">
                <a href="<?php echo $path_prefix; ?>add_event.php" class="w-full bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center">
                    <i class="fa-solid fa-plus"></i> Add New Event
                </a>
                <a href="<?php echo $path_prefix; ?>functions/sync_holidays.php" class="w-full bg-white/10 hover:bg-white/20 text-white font-medium py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm block text-center border border-white/20">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>