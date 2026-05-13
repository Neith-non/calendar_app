<?php
// generate_pdf.php
session_start();

require_once 'vendor/autoload.php';
require_once 'functions/database.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Get the arrays of selected data from the modal
$selectedMonths = $_GET['months'] ?? [];
$selectedCategories = $_GET['categories'] ?? [];
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');

// Display Column Toggles
$showDate = isset($_GET['show_date']);
$showDetails = isset($_GET['show_details']);
$showVenue = isset($_GET['show_venue']);
$showParticipants = isset($_GET['show_participants']);

// Safety Checks
if (empty($selectedMonths)) {
    die("<h2 style='font-family:sans-serif; color:red;'>Error: Please select at least one month.</h2> <a href='javascript:history.back()'>Go Back</a>");
}
if (empty($selectedCategories)) {
    die("<h2 style='font-family:sans-serif; color:red;'>Error: Please select at least one category to print.</h2> <a href='javascript:history.back()'>Go Back</a>");
}

// Sanitize inputs
$selectedMonths = array_map('intval', $selectedMonths);
sort($selectedMonths);

$selectedCategories = array_map('intval', $selectedCategories);

// Create the dynamic "?, ?, ?" placeholders based on how many categories were checked
$catPlaceholders = implode(',', array_fill(0, count($selectedCategories), '?'));

// Fetch Participants Map if needed
$event_participants_map = [];
if ($showParticipants) {
    $part_stmt = $pdo->query("
        SELECT ps.event_publish_id AS publish_id, d.name AS department
        FROM participant_schedule ps
        JOIN participants p ON ps.participant_id = p.id
        JOIN department d ON p.department_id = d.id
    ");
    while ($row = $part_stmt->fetch(PDO::FETCH_ASSOC)) {
        $event_participants_map[$row['publish_id']][] = $row['department'];
    }
}

// 2. Load the Header Image securely using Base64 encoding
$imagePath = 'assets/img/sjsf_header.png';
$base64Image = '';
if (file_exists($imagePath)) {
    $type = pathinfo($imagePath, PATHINFO_EXTENSION);
    $imageData = base64_encode(file_get_contents($imagePath));
    $base64Image = 'data:image/' . $type . ';base64,' . $imageData;
}

// 3. Set up the CSS and HTML structure
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>School Schedule - ' . $year . '</title>
    <style>
        body { font-family: "Cambria", Georgia, serif; color: #000; font-size: 14px; }
        .header { text-align: center; margin-bottom: 10px; }
        .header img { max-width: 100%; max-height: 140px; height: auto; margin-bottom: 5px; }
        .header h1 { margin: 0; color: #000; font-size: 18px; text-transform: uppercase; }
        .yearly-title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 15px; text-transform: uppercase; }
        .month-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .page-break { page-break-after: always; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 10px; vertical-align: top; }
        th { background-color: #F5F5DC; font-weight: bold; text-align: center; text-transform: uppercase; font-size: 13px; color: #000; }
        .ev-title { font-weight: bold; font-size: 14px; margin-bottom: 3px; }
        .ev-cat { font-size: 11px; color: #444; font-style: italic; margin-top: 3px; }
        .ev-desc { font-size: 13px; line-height: 1.4; }
        .no-events { text-align: center; padding: 40px; font-style: italic; border: 1px solid #000; }
    </style>
</head>
<body>';

$totalMonths = count($selectedMonths);
$currentIndex = 0;

// THE EXACT STRICT QUERY: We use the dynamic $catPlaceholders to lock the query to ONLY the checked categories + Join Venues
$sql = "
    SELECT e.*, c.category_name, p.status, v.venue_name
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    LEFT JOIN event_publish p ON e.publish_id = p.id
    LEFT JOIN venues v ON p.venue_id = v.venue_id
    WHERE MONTH(e.start_date) = ?
    AND YEAR(e.start_date) = ? 
    AND e.category_id IN ($catPlaceholders)
    AND (p.status = 'Approved' OR e.publish_id IS NULL)
    ORDER BY e.start_date ASC, e.start_time ASC
";
$stmt = $pdo->prepare($sql);

// 4. Loop through each selected month and build its page
foreach ($selectedMonths as $month) {
    $monthName = date('F', mktime(0, 0, 0, $month, 10));

    // Combine the current month, year, and the checked category IDs into a single array for the query
    $params = array_merge([$month, $year], $selectedCategories);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    // Document Header & Logo 
    $html .= '<div class="header">';
    if ($base64Image !== '') {
        $html .= '<img src="' . $base64Image . '" alt="St. Joseph School Foundation Header">';
    } else {
        $html .= '<h1>St. Joseph School Foundation</h1>';
    }
    $html .= '</div>';

    // Add the Main Document Title ONLY on the very first page
    if ($currentIndex === 0) {
        $nextYear = $year + 1;
        $html .= '<div class="yearly-title">Monthly School Calendar of Activities for School Year ' . $year . '-' . $nextYear . '</div>';
    }

    // Month Title 
    $html .= '<div class="month-title">' . $monthName . ' ' . $year . '</div>';

    // Build the Bordered Table
    if (count($events) > 0) {
        $html .= '<table>
                    <thead>
                        <tr>
                            <th>Event Name</th>';
        
        if ($showDate) $html .= '<th>Date & Time</th>';
        if ($showDetails) $html .= '<th>Event Details</th>';
        if ($showVenue) $html .= '<th>Venue</th>';
        if ($showParticipants) $html .= '<th>Participants</th>';

        $html .= '      </tr>
                    </thead>
                    <tbody>';

        foreach ($events as $event) {
            $formattedDate = date('M j, Y', strtotime($event['start_date']));
            $formattedTime = ($event['start_time'] == '00:00:00' || $event['start_time'] == '23:59:59') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
            $descText = !empty($event['description']) ? nl2br(htmlspecialchars($event['description'])) : '-';
            $venueText = !empty($event['venue_name']) ? htmlspecialchars($event['venue_name']) : 'Unspecified';

            $html .= '<tr>
                        <td>
                            <div class="ev-title">' . htmlspecialchars($event['title']) . '</div>
                            <div class="ev-cat">' . htmlspecialchars($event['category_name']) . '</div>
                        </td>';

            // Date & Time
            if ($showDate) {
                $html .= '<td style="text-align:center;"><strong>' . $formattedDate . '</strong><br>' . $formattedTime . '</td>';
            }
            
            // Event Details
            if ($showDetails) {
                $html .= '<td><div class="ev-desc">' . $descText . '</div></td>';
            }

            // Venue
            if ($showVenue) {
                $html .= '<td style="text-align:center;">' . $venueText . '</td>';
            }

            // Participants
            if ($showParticipants) {
                $html .= '<td>';
                $pubId = $event['publish_id'];
                if ($pubId && !empty($event_participants_map[$pubId])) {
                    $uniqueDepts = array_unique($event_participants_map[$pubId]);
                    $html .= implode('<br>', array_map('htmlspecialchars', $uniqueDepts));
                } else {
                    $html .= '<span style="color:#666; font-style:italic;">Unspecified</span>';
                }
                $html .= '</td>';
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="no-events">No events scheduled matching your criteria for ' . $monthName . '.</div>';
    }

    // Add a page break if this is NOT the last month in the loop
    $currentIndex++;
    if ($currentIndex < $totalMonths) {
        $html .= '<div class="page-break"></div>';
    }
}

$html .= '</body></html>';

// 5. Generate and Output the PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// Paper size set to letter, kept in portrait as requested
$dompdf->setPaper('letter', 'portrait');

$dompdf->render();

$fileName = ($totalMonths > 1) ? "Events_Multiple_Months_{$year}.pdf" : "Events_" . date('F', mktime(0, 0, 0, $selectedMonths[0], 10)) . "_{$year}.pdf";

$dompdf->stream($fileName, ["Attachment" => true]);
exit();
?>