<?php
session_start();

// Check if user is logged in AND is specifically the Head Scheduler
$allowed_roles = ['Head Scheduler', 'Admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], $allowed_roles)) {
    header("Location: calendar.php?error=unauthorized");
    exit;
}

// add_event.php
require_once 'functions/database.php';
require_once 'functions/get_pending_count.php'; // Added so the sidebar notification works!

$message = '';
$msgType = 'error'; // Can be 'error' or 'success'

// Fetch all holidays to pass to Javascript
$holidayStmt = $pdo->query("SELECT start_date, title FROM events WHERE category_id = 5");
$holidays = [];
while ($row = $holidayStmt->fetch(PDO::FETCH_ASSOC)) {
    $holidays[$row['start_date']] = $row['title'];
}
$holidaysJson = json_encode($holidays);

// 1. Fetch Categories and Venues
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int) $_POST['category_id'];
    $venue_id = (int) $_POST['venue_id'];

    $start_date = $_POST['start_date'];
    $start_time = $_POST['start_time'];
    $end_date = $_POST['end_date'];
    $end_time = $_POST['end_time'];

    // Combine Date and Time for easy math logic
    $start_datetime = $start_date . ' ' . $start_time;
    $end_datetime = $end_date . ' ' . $end_time;

    // RULE 1: End Time must be AFTER Start Time
    if (strtotime($end_datetime) <= strtotime($start_datetime)) {
        $message = "Oops! The End Date/Time must be after the Start Date/Time.";
    } else {
        // RULE 2: Conflict Detection (The Overlap Formula)
        // Two events overlap if: (Existing Start < New End) AND (Existing End > New Start)
        $conflictStmt = $pdo->prepare("
            SELECT e.title, p.status 
            FROM events e
            JOIN event_publish p ON e.publish_id = p.id
            WHERE p.venue_id = ? 
            AND p.status IN ('Approved', 'Pending') 
            AND CONCAT(e.start_date, ' ', e.start_time) < ? 
            AND CONCAT(e.end_date, ' ', e.end_time) > ?
            LIMIT 1
        ");

        $conflictStmt->execute([$venue_id, $end_datetime, $start_datetime]);
        $conflict = $conflictStmt->fetch();

        if ($conflict) {
            $statusText = $conflict['status'] === 'Pending' ? 'is pending approval' : 'is already approved';
            $message = "Venue Conflict! '{$conflict['title']}' {$statusText} at this venue during your selected time.";
        } else {
            // ALL CLEAR! Insert the data.
            try {
                $pdo->beginTransaction();

                // Step A: Create Request (Now with description!)
                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                // Step B: Create Calendar Block (Now with description!)
                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, description, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $description, $start_date, $start_time, $end_date, $end_time]);

                $pdo->commit();

                header("Location: index.php?sync_status=success&sync_msg=" . urlencode("Event '$title' successfully submitted for approval!"));
                exit();

            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = "Database Error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Event - St. Joseph School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>

<body class="dashboard-body h-screen flex overflow-hidden">

    <aside class="w-72 glass-container flex flex-col flex-shrink-0 z-10 transition-all duration-300">
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

                    <?php if (isset($_SESSION['role_name']) && ($_SESSION['role_name'] === 'Admin' || $_SESSION['role_name'] === 'Head Scheduler')): ?>
                        <a href="request_status.php"
                            class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                            <span>Event Status</span>
                            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                                <span class="ml-auto relative flex h-3 w-3"
                                    title="<?php echo $pendingCount; ?> Pending Requests">
                                    <span
                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                        <a href="admin/admin_manage.php"
                            class="w-full hover:bg-white/10 text-slate-300 hover:text-white font-medium py-2.5 px-4 rounded-lg flex items-center gap-3 transition-colors">
                            <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>
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
    <main class="flex-1 flex justify-center items-center overflow-y-auto p-4 sm:p-6 md:p-8">

        <div class="glass-container rounded-2xl shadow-lg w-full max-w-3xl overflow-hidden">

            <div class="bg-black/20 p-6 border-b border-white/10 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-white"><i
                            class="fa-solid fa-calendar-plus mr-3 text-yellow-400"></i>
                        Request New Event</h2>
                    <p class="text-slate-300 text-sm mt-1">Submit a schedule for admin approval.</p>
                </div>
                <i class="fa-solid fa-clock text-4xl text-white/20"></i>
            </div>

            <div class="p-6 sm:p-8 max-h-[80vh] overflow-y-auto">

                <?php if ($message): ?>
                    <div
                        class="mb-6 px-4 py-3 rounded-lg border bg-red-500/20 border-red-500/50 text-red-300 flex items-center gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                        <p class="font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>

                <form action="add_event.php" method="POST" class="space-y-7">

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Event Title</label>
                        <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                            class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Event Description</label>
                        <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..."
                            class="form-input-glass w-full px-4 py-2.5 rounded-lg resize-none"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-4 bg-black/20 rounded-lg border border-white/10">
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Category</label>
                            <select name="category_id" required
                                class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Venue Location</label>
                            <select name="venue_id" required
                                class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4"
                                style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                                <option value="">-- Select Venue --</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?php echo $venue['venue_id']; ?>" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($venue['venue_name']); ?>
                                        <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <p id="holiday-warning" class="hidden text-red-500 text-sm mt-1.5 font-medium animate-pulse">
                        <i class="fa-solid fa-triangle-exclamation"></i> Warning: This date falls on <strong
                            id="holiday-name"></strong>.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">

                        <div class="space-y-4">
                            <h3
                                class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2">
                                <i class="fa-solid fa-play text-emerald-400 mr-2"></i> Starts
                            </h3>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Start Date</label>
                                <input type="date" name="start_date" required
                                    value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Start Time</label>
                                <input type="time" name="start_time" required
                                    value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h3
                                class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2">
                                <i class="fa-solid fa-stop text-red-400 mr-2"></i> Ends
                            </h3>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">End Date</label>
                                <input type="date" name="end_date" required
                                    value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">End Time</label>
                                <input type="time" name="end_time" required
                                    value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                    class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                            </div>
                        </div>
                    </div>

                    <div class="pt-6 mt-4 border-t border-white/10 flex flex-col-reverse sm:flex-row gap-4">
                        <a href="javascript:history.back()"
                            class="text-center bg-white/10 hover:bg-white/20 text-white font-bold py-3 px-6 rounded-lg transition-colors border border-white/20">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-3 rounded-lg transition-colors shadow-lg flex justify-center items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Submit Request
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </main>
    <div id="holidayConfirmModal"
        class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden p-6 text-center">

            <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-calendar-xmark text-3xl text-red-500"></i>
            </div>

            <h2 class="text-xl font-bold text-slate-800 mb-2">Holiday Conflict</h2>
            <p class="text-slate-600 mb-6">
                You are trying to schedule an event on <strong id="modalHolidayName" class="text-red-500"></strong>. Are
                you sure you want to proceed?
            </p>

            <div class="flex justify-center gap-3">
                <button type="button" onclick="closeHolidayModal()"
                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg transition">
                    Cancel
                </button>
                <button type="button" onclick="submitFormForce()"
                    class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition shadow-md">
                    Yes, Add Event
                </button>
            </div>
        </div>
    </div>
</body>

<script>
    // Load the holidays from PHP into a Javascript Object
    const holidays = <?php echo $holidaysJson; ?>;

    const dateInput = document.querySelector('input[name="start_date"]');
    const warningText = document.getElementById('holiday-warning');
    const holidayNameSpan = document.getElementById('holiday-name');

    const eventForm = document.querySelector('form'); // Grabs your main form
    const modal = document.getElementById('holidayConfirmModal');
    const modalNameSpan = document.getElementById('modalHolidayName');

    let isHolidayBypassed = false; // Prevents the modal from showing twice

    // 1. Listen for date changes
    if (dateInput) {
        dateInput.addEventListener('change', function () {
            const selectedDate = this.value;
            // If the selected date exists in our holiday list...
            if (holidays[selectedDate]) {
                holidayNameSpan.textContent = holidays[selectedDate];
                warningText.classList.remove('hidden');
            } else {
                warningText.classList.add('hidden');
            }
        });
    }

    // 2. Intercept the form submission
    eventForm.addEventListener('submit', function (e) {
        const selectedDate = dateInput.value;

        // If it's a holiday and they haven't explicitly clicked "Yes" yet...
        if (holidays[selectedDate] && !isHolidayBypassed) {
            e.preventDefault(); // Stop the form from submitting

            // Show the modal
            modalNameSpan.textContent = holidays[selectedDate];
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    });

    // 3. Modal Control Functions
    function closeHolidayModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function submitFormForce() {
        isHolidayBypassed = true; // Tell the script we are forcing it
        eventForm.submit(); // Actually submit the form
    }
</script>

</html>