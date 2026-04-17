<?php
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
                $stmt_pub = $pdo->prepare("INSERT INTO event_publish (venue_id, title, description, status) VALUES (?, ?, ?, 'Pending')");
                $stmt_pub->execute([$venue_id, $title, $description]);
                $publish_id = $pdo->lastInsertId();

                // Step B: Create Calendar Block 
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
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-zinc-950 text-zinc-200 font-sans min-h-screen flex justify-center items-center p-4 sm:p-6 md:p-8 relative">

    <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-emerald-500 rounded-full mix-blend-screen filter blur-[120px] opacity-10 pointer-events-none z-0"></div>

    <div class="bg-zinc-900/80 backdrop-blur-xl border border-zinc-700 rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden relative z-10">
        
        <div class="bg-zinc-950/60 p-8 sm:px-10 border-b border-zinc-700 flex justify-between items-center">
            <div>
                <h2 class="text-3xl font-extrabold text-zinc-100 tracking-tight">
                    <i class="fa-solid fa-calendar-plus mr-3 text-emerald-500"></i>Request New Event
                </h2>
                <p class="text-zinc-400 text-lg mt-2 font-medium">Submit a schedule block for admin approval.</p>
            </div>
            <i class="fa-solid fa-clock text-5xl text-zinc-800 hidden sm:block"></i>
        </div>

        <div class="p-8 sm:p-10 max-h-[80vh] overflow-y-auto no-scrollbar">
            
            <?php if ($message): ?>
                <div class="mb-8 px-6 py-4 rounded-xl border bg-red-500/10 border-red-500/30 text-red-400 flex items-start gap-4 shadow-inner">
                    <i class="fa-solid fa-triangle-exclamation text-2xl mt-0.5"></i>
                    <p class="font-bold text-lg"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <form action="add_event.php" method="POST" class="space-y-8">
                
                <div>
                    <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Event Title</label>
                    <input type="text" name="title" required placeholder="e.g., Grade 10 Math Olympiad" 
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        class="w-full px-5 py-4 bg-zinc-950/80 border-2 border-zinc-700 text-zinc-100 text-lg font-medium rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all placeholder-zinc-600 shadow-inner">
                </div>

                <div>
                    <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Event Description</label>
                    <textarea name="description" rows="3" placeholder="Optional details, instructions, or agenda..." 
                            class="w-full px-5 py-4 bg-zinc-950/80 border-2 border-zinc-700 text-zinc-100 text-lg font-medium rounded-xl resize-none focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all placeholder-zinc-600 shadow-inner"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-zinc-950/40 p-6 rounded-2xl border border-zinc-700">
                    <div>
                        <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Category</label>
                        <select name="category_id" required class="w-full px-5 py-4 bg-zinc-950/80 border-2 border-zinc-700 text-zinc-100 text-lg font-medium rounded-xl appearance-none focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all shadow-inner bg-no-repeat cursor-pointer" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%23a1a1aa\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 1rem center; background-size: 1.5em;">
                            <option value="" class="text-zinc-500">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['category_id']) ? 'selected' : ''; ?> class="text-zinc-100 bg-zinc-900"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Venue Location</label>
                        <select name="venue_id" required class="w-full px-5 py-4 bg-zinc-950/80 border-2 border-zinc-700 text-zinc-100 text-lg font-medium rounded-xl appearance-none focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all shadow-inner bg-no-repeat cursor-pointer" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%23a1a1aa\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 1rem center; background-size: 1.5em;">
                            <option value="" class="text-zinc-500">-- Select Venue --</option>
                            <?php foreach ($venues as $venue): ?>
                                <option value="<?php echo $venue['venue_id']; ?>" <?php echo (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['venue_id']) ? 'selected' : ''; ?> class="text-zinc-100 bg-zinc-900">
                                    <?php echo htmlspecialchars($venue['venue_name']); ?> 
                                    <?php if ($venue['is_off_campus']): ?> (Off-Campus)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div class="bg-zinc-950/50 p-6 rounded-2xl border border-zinc-700 space-y-6 shadow-sm">
                        <h3 class="font-extrabold text-zinc-300 uppercase tracking-widest text-base border-b border-zinc-800 pb-3 flex items-center">
                            <i class="fa-solid fa-play text-emerald-400 mr-3 text-lg"></i> Starts
                        </h3>
                        
                        <div>
                            <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Start Date</label>
                            <input type="date" name="start_date" required value="<?php echo $_POST['start_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="w-full px-5 py-4 bg-zinc-900 border-2 border-zinc-700 text-zinc-100 text-lg font-bold rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all cursor-pointer [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">Start Time</label>
                            <input type="time" name="start_time" required value="<?php echo $_POST['start_time'] ?? ''; ?>"
                                   class="w-full px-5 py-4 bg-zinc-900 border-2 border-zinc-700 text-zinc-100 text-lg font-bold rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all cursor-pointer [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100">
                        </div>
                    </div>

                    <div class="bg-zinc-950/50 p-6 rounded-2xl border border-zinc-700 space-y-6 shadow-sm">
                        <h3 class="font-extrabold text-zinc-300 uppercase tracking-widest text-base border-b border-zinc-800 pb-3 flex items-center">
                            <i class="fa-solid fa-stop text-red-400 mr-3 text-lg"></i> Ends
                        </h3>
                        
                        <div>
                            <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">End Date</label>
                            <input type="date" name="end_date" required value="<?php echo $_POST['end_date'] ?? $_GET['date'] ?? ''; ?>"
                                   class="w-full px-5 py-4 bg-zinc-900 border-2 border-zinc-700 text-zinc-100 text-lg font-bold rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all cursor-pointer [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-extrabold text-zinc-400 uppercase tracking-widest mb-3">End Time</label>
                            <input type="time" name="end_time" required value="<?php echo $_POST['end_time'] ?? ''; ?>"
                                   class="w-full px-5 py-4 bg-zinc-900 border-2 border-zinc-700 text-zinc-100 text-lg font-bold rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20 transition-all cursor-pointer [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100">
                        </div>
                    </div>

                </div>

                <div class="pt-8 mt-4 border-t border-zinc-700 flex flex-col-reverse md:flex-row gap-4">
                    <a href="index.php" class="w-full md:w-1/3 text-center bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white font-bold py-4 rounded-xl transition-colors border-2 border-zinc-700 text-lg shadow-sm">
                        Cancel
                    </a>
                    <button type="submit" class="w-full md:w-2/3 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold py-4 px-6 rounded-xl transition-all shadow-lg shadow-emerald-900/30 text-xl flex justify-center items-center gap-3 hover:-translate-y-0.5 active:translate-y-0">
                        <i class="fa-solid fa-paper-plane"></i> Submit Request
                    </button>
                </div>

            </form>
        </div>
    </div>

</body>
</html>