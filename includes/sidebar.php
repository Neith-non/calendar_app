<?php
// includes/sidebar.php

$current_page = basename($_SERVER['PHP_SELF']);
$in_admin_folder = (basename(dirname($_SERVER['PHP_SELF'])) === 'admin');
$path_prefix = $in_admin_folder ? '../' : '';
?>

<div x-show="sidebarOpen" 
     @click="sidebarOpen = false" 
     x-transition.opacity.duration.300ms
     class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm lg:hidden" style="display: none;">
</div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" 
       class="fixed inset-y-0 left-0 z-50 w-72 transform bg-white dark:bg-[#0b1120] border-r border-slate-200 dark:border-slate-800 transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-auto flex flex-col flex-shrink-0 h-full shadow-2xl lg:shadow-none">
    
    <div class="p-8 text-center border-b border-slate-100 dark:border-slate-800/50 relative">
        <button @click="sidebarOpen = false" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 lg:hidden">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="w-16 h-16 mx-auto bg-white dark:bg-slate-900 rounded-full flex items-center justify-center mb-4 shadow-sm border border-slate-100 dark:border-slate-700">
            <img src="<?php echo $path_prefix; ?>assets/img/sjsfi_schoologo.png" alt="SJSFI Logo" 
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

    <div class="flex-1 overflow-y-auto custom-scrollbar">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800/50">
            <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Traversal</h3>
            <div class="space-y-2">

                <a href="<?php echo $path_prefix; ?>index.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-table-cells-large w-5 text-center"></i>
                    <span>Dashboard Hub</span>
                </a>

                <a href="<?php echo $path_prefix; ?>calendar.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm <?php echo ($current_page == 'calendar.php') ? 'active' : ''; ?>">
                    <i class="fa-regular fa-calendar-days w-5 text-center"></i>
                    <span>View Calendar</span>
                </a>

                <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                    <a href="<?php echo $path_prefix; ?>request_status.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm <?php echo ($current_page == 'request_status.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                        <span>Event Status</span>
                        <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                            <span class="ml-auto relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                    <a href="<?php echo $path_prefix; ?>admin/admin_manage.php" class="nav-item w-full py-3 px-4 rounded-xl flex items-center gap-3 font-semibold text-sm <?php echo ($current_page == 'admin_manage.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                        <span>Admin Panel</span>
                    </a>
                <?php endif; ?>

                <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
                    <button onclick="openPdfModal()" class="w-full mt-4 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 text-sm shadow-sm">
                        <i class="fa-solid fa-print text-slate-400 dark:text-slate-500"></i> Print Schedule
                    </button>
                <?php endif; ?>

            </div>
        </div>

        <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Head Scheduler' || $_SESSION['role_name'] === 'Admin')): ?>
            <div class="p-6">
                <h3 class="text-xs uppercase tracking-widest text-slate-400 dark:text-slate-500 font-bold mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="<?php echo $path_prefix; ?>add_event.php" class="bg-sjsfi-green dark:bg-emerald-600 text-white hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 text-sm shadow-md transition-colors">
                        <i class="fa-solid fa-plus"></i> Add New Event
                    </a>
                    <a href="<?php echo $path_prefix; ?>functions/sync_holidays.php" class="w-full bg-slate-800 dark:bg-slate-700 hover:bg-slate-900 dark:hover:bg-slate-600 text-white font-bold py-3 px-4 rounded-xl transition flex items-center justify-center gap-2 shadow-sm text-sm">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Sync Holidays
                    </a>
                </div>
            </div>
        <?php endif; ?>
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
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
                </p>
                <p class="text-[11px] font-bold uppercase tracking-wider text-sjsfi-green dark:text-emerald-500 truncate mt-0.5">
                    <?php echo htmlspecialchars($_SESSION['role_name'] ?? ''); ?>
                </p>
            </div>
        </div>

        <a href="<?php echo $path_prefix; ?>logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition font-bold text-sm border border-transparent hover:border-red-100 dark:hover:border-red-500/30">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Secure Logout</span>
        </a>
    </div>
</aside>