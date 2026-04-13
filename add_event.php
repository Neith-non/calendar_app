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

$message = '';
$msgType = 'error'; // Can be 'error' or 'success'

// 1. Fetch Categories and Venues
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = (int)$_POST['category_id'];
    $venue_id = (int)$_POST['venue_id'];
    
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
    } 
    else {
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
        } 
        else {
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
    <!-- Frontend Change: Added Google Font for a more modern typeface -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Frontend Change: Linked our new global stylesheet -->
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<!-- Frontend Change: Added 'dashboard-body' class for the new background and centered layout -->
<body class="dashboard-body flex justify-center items-center min-h-screen p-4 sm:p-6 md:p-8">

    <!-- Frontend Change: Main form container with the glassmorphism effect -->
    <div class="glass-container rounded-2xl shadow-lg w-full max-w-3xl overflow-hidden">
        
        <!-- Form Header -->
        <div class="bg-black/20 p-6 border-b border-white/10 flex justify-between items-center">
            <div>
                <h2 class="text-2xl font-bold text-white"><i class="fa-solid fa-calendar-plus mr-3 text-yellow-400"></i> Request New Event</h2>
                <p class="text-slate-300 text-sm mt-1">Submit a schedule for admin approval.</p>
            </div>
            <i class="fa-solid fa-clock text-4xl text-white/20"></i>
        </div>

        <!-- Form Body -->
        <div class="p-6 sm:p-8 max-h-[80vh] overflow-y-auto">
            <!-- Backend Note: This PHP block displays an error message. We only styled the container. -->
            <?php if ($message): ?>
                <!-- Frontend Change: Styled error message container -->
                <div class="mb-6 px-4 py-3 rounded-lg border bg-red-500/20 border-red-500/50 text-red-300 flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                    <p class="font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Backend Note: This is the main form. We only styled the inputs and buttons. -->
            <form action="add_event.php" method="POST" class="space-y-7">
                
                <!-- Event Title Input Container -->
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-2">Event Title</label>
                    <!-- Frontend Change: Applied 'form-input-glass' class -->
                    <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad" 
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                </div>

                <!-- Event Description Input Container -->
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-2">Event Description</label>
                    <!-- Frontend Change: Applied 'form-input-glass' class -->
                    <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..." 
                            class="form-input-glass w-full px-4 py-2.5 rounded-lg resize-none"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <!-- Category & Venue Selection Container -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-4 bg-black/20 rounded-lg border border-white/10">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Category</label>
                        <!-- Frontend Change: Applied 'form-input-glass' and custom dropdown arrow -->
                        <select name="category_id" required class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Venue Location</label>
                        <!-- Frontend Change: Applied 'form-input-glass' and custom dropdown arrow -->
                        <select name="venue_id" required class="form-input-glass w-full px-4 py-2.5 rounded-lg appearance-none bg-no-repeat bg-right-4" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%239ca3af\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-size: 1.25em;">
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

                <!-- Date & Time Inputs Container -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                    <!-- "Starts" Column -->
                    <div class="space-y-4">
                        <h3 class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2"><i class="fa-solid fa-play text-emerald-400 mr-2"></i> Starts</h3>
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Start Date</label>
                            <!-- Frontend Change: Applied 'form-input-glass' class -->
                            <input type="date" name="start_date" required value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">Start Time</label>
                            <!-- Frontend Change: Applied 'form-input-glass' class -->
                            <input type="time" name="start_time" required value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                   class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                        </div>
                    </div>

                    <!-- "Ends" Column -->
                    <div class="space-y-4">
                        <h3 class="font-bold text-slate-400 uppercase tracking-wider text-xs border-b border-white/10 pb-2"><i class="fa-solid fa-stop text-red-400 mr-2"></i> Ends</h3>
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">End Date</label>
                            <!-- Frontend Change: Applied 'form-input-glass' class -->
                            <input type="date" name="end_date" required value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-2">End Time</label>
                            <!-- Frontend Change: Applied 'form-input-glass' class -->
                            <input type="time" name="end_time" required value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                   class="form-input-glass w-full px-4 py-2.5 rounded-lg">
                        </div>
                    </div>
                </div>

                <!-- Form Actions/Buttons Container -->
                <div class="pt-6 mt-4 border-t border-white/10 flex flex-col-reverse sm:flex-row gap-4">
                    <!-- Frontend Change: Styled "Cancel" button -->
                    <a href="javascript:history.back()" class="text-center bg-white/10 hover:bg-white/20 text-white font-bold py-3 rounded-lg transition-colors border border-white/20">
                        Cancel
                    </a>
                    <!-- Frontend Change: Styled "Submit" button -->
                    <button type="submit" class="flex-1 bg-yellow-500 hover:bg-yellow-600 text-dark-green font-bold py-3 rounded-lg transition-colors shadow-lg flex justify-center items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Submit Request
                    </button>
                </div>

            </form>
        </div>
    </div>

</body>
</html>