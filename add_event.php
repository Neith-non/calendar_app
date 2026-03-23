<?php
// add_event.php
require_once 'functions/database.php';

$message = '';

// 1. Fetch Categories for the dropdown (Excluding 'Holidays' since teachers shouldn't plot those)
$stmt_cats = $pdo->query("SELECT * FROM event_categories WHERE category_name != 'Holidays' ORDER BY category_name ASC");
$categories = $stmt_cats->fetchAll();

// 2. Fetch Venues for the dropdown
$stmt_venues = $pdo->query("SELECT * FROM venues ORDER BY venue_name ASC");
$venues = $stmt_venues->fetchAll();

// 3. Process the form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $category_id = (int)$_POST['category_id'];
    $venue_id = (int)$_POST['venue_id'];
    $start_date = $_POST['start_date'];
    $start_time = $_POST['start_time'];

    if (!empty($title) && $category_id > 0 && $venue_id > 0 && !empty($start_date) && !empty($start_time)) {
        try {
            // STEP 1: Insert into event_publish (Creates the Pending Request)
            $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, status) VALUES (?, ?, 'Pending')");
            $stmt_pub->execute([$venue_id, $title]);
            
            // Get the ID of the request we just created
            $publish_id = $pdo->lastInsertId();

            // STEP 2: Insert into events (Creates the actual calendar block tied to the request)
            $stmt_event = $pdo->prepare("INSERT INTO events (publish_id, category_id, title, start_date, start_time) VALUES (?, ?, ?, ?, ?)");
            $stmt_event->execute([$publish_id, $category_id, $title, $start_date, $start_time]);

            // Success! Redirect back to the dashboard
            header("Location: index.php?sync_status=success&sync_msg=" . urlencode("Event '$title' successfully submitted for approval!"));
            exit();

        } catch (PDOException $e) {
            // Catching the UNIQUE index rule if they accidentally submit the exact same event twice
            if ($e->getCode() == 23000) {
                $message = "An event with this title already exists on this date!";
            } else {
                $message = "Database Error: " . $e->getMessage();
            }
        }
    } else {
        $message = "Please fill in all required fields.";
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
<body class="bg-slate-100 text-slate-800 h-screen flex justify-center items-center font-sans">

    <div class="bg-white rounded-xl shadow-lg border border-slate-200 w-full max-w-lg overflow-hidden">
        
        <div class="bg-slate-800 text-white p-6 border-b border-slate-700">
            <h2 class="text-2xl font-bold"><i class="fa-solid fa-calendar-plus mr-2"></i> Request New Event</h2>
            <p class="text-slate-400 text-sm mt-1">Submit a new event schedule for approval.</p>
        </div>

        <div class="p-6">
            <?php if ($message): ?>
                <div class="mb-4 px-4 py-3 rounded-lg border bg-red-100 border-red-400 text-red-700 flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p class="font-medium text-sm"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <form action="add_event.php" method="POST" class="space-y-4">
                
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Event Title</label>
                    <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad" 
                           class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Category</label>
                        <select name="category_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Venue</label>
                        <select name="venue_id" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                            <option value="">-- Select --</option>
                            <?php foreach ($venues as $venue): ?>
                                <option value="<?php echo $venue['venue_id']; ?>">
                                    <?php echo htmlspecialchars($venue['venue_name']); ?> 
                                    <?php echo $venue['is_off_campus'] ? '(Off-Campus)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Date</label>
                        <input type="date" name="start_date" required 
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Time</label>
                        <input type="time" name="start_time" required 
                               class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                </div>

                <div class="pt-4 flex gap-3">
                    <a href="index.php" class="flex-1 text-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 rounded-lg transition-colors border border-slate-300">
                        Cancel
                    </a>
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg transition-colors shadow-sm">
                        Submit Request
                    </button>
                </div>

            </form>
        </div>
    </div>

</body>
</html>