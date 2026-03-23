<?php
// add_event.php
require_once 'database.php';

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

                // Step A: Create Request
                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, status) VALUES (?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title]);
                $publish_id = $pdo->lastInsertId();

                // Step B: Create Calendar Block (Now with End Dates!)
                $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, start_date, start_time, end_date, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_event->execute([$publish_id, $category_id, $title, $start_date, $start_time, $end_date, $end_time]);

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex justify-center items-center font-sans py-8">

    <div class="bg-white rounded-xl shadow-lg border border-slate-200 w-full max-w-2xl overflow-hidden">
        
        <div class="bg-slate-800 text-white p-6 border-b border-slate-700 flex justify-between items-center">
            <div>
                <h2 class="text-2xl font-bold"><i class="fa-solid fa-calendar-plus mr-2"></i> Request New Event</h2>
                <p class="text-slate-400 text-sm mt-1">Submit a schedule for Admin approval.</p>
            </div>
            <i class="fa-solid fa-clock text-4xl text-slate-600 opacity-50"></i>
        </div>

        <div class="p-8">
            <?php if ($message): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border bg-red-100 border-red-400 text-red-700 flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                    <p class="font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <form action="add_event.php" method="POST" class="space-y-6">
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Event Title</label>
                    <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad" 
                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition bg-slate-50 focus:bg-white">
                </div>

                <div class="grid grid-cols-2 gap-6 p-4 bg-slate-50 rounded-lg border border-slate-100">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Category</label>
                        <select name="category_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Venue Location</label>
                        <select name="venue_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                            <option value="">-- Select Venue --</option>
                            <?php foreach ($venues as $venue): ?>
                                <option value="<?php echo $venue['venue_id']; ?>" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($venue['venue_name']); ?> 
                                    <?php echo $venue['is_off_campus'] ? '(Off-Campus)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <h3 class="font-bold text-slate-500 uppercase tracking-wider text-xs border-b pb-1"><i class="fa-solid fa-play text-emerald-500 mr-1"></i> Starts</h3>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">Start Date</label>
                            <input type="date" name="start_date" required value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">Start Time</label>
                            <input type="time" name="start_time" required value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h3 class="font-bold text-slate-500 uppercase tracking-wider text-xs border-b pb-1"><i class="fa-solid fa-stop text-red-500 mr-1"></i> Ends</h3>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">End Date</label>
                            <input type="date" name="end_date" required value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">End Time</label>
                            <input type="time" name="end_time" required value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                    </div>
                </div>

                <div class="pt-6 mt-6 border-t border-slate-200 flex gap-4">
                    <a href="index.php" class="flex-1 text-center bg-white hover:bg-slate-50 text-slate-700 font-bold py-3 rounded-lg transition-colors border-2 border-slate-200">
                        Cancel
                    </a>
                    <button type="submit" class="flex-[2] bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg transition-colors shadow-md flex justify-center items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Submit Request
                    </button>
                </div>

            </form>
        </div>
    </div>

</body>
</html>