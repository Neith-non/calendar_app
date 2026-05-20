<?php
// generate_pdf.php
session_start();

// --- 1. FORCE BROWSER TO NEVER CACHE THIS PDF ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'vendor/autoload.php';
require_once 'functions/database.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// 2. Get the arrays of selected data from the modal
$selectedMonths = $_GET['months'] ?? [];
$selectedCategories = $_GET['categories'] ?? [];
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');

// Capture the Display Options toggles
$showCat = isset($_GET['show_cat']) && $_GET['show_cat'] == '1';
$showVen = isset($_GET['show_ven']) && $_GET['show_ven'] == '1';
$showPart = isset($_GET['show_part']) && $_GET['show_part'] == '1';
$showCust = isset($_GET['show_cust']) && $_GET['show_cust'] == '1';

// Capture Paper Size
$paperSize = $_GET['paper_size'] ?? 'letter';
$validSizes = ['letter', 'legal', 'a4'];
if (!in_array(strtolower($paperSize), $validSizes)) {
    $paperSize = 'letter'; 
}

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
$catPlaceholders = implode(',', array_fill(0, count($selectedCategories), '?'));

// Load the Header Image securely using Base64 encoding
$imagePath = 'assets/img/sjsf_header.png';
$base64Image = '';
if (file_exists($imagePath)) {
    $type = pathinfo($imagePath, PATHINFO_EXTENSION);
    $imageData = base64_encode(file_get_contents($imagePath));
    $base64Image = 'data:image/' . $type . ';base64,' . $imageData;
}

// --- STRICT HTML PERCENTAGES TO FORCE MAX WIDTH ---
$dateWidth = 10; 
$catWidth = $showCat ? 14 : 0;
$venWidth = $showVen ? 14 : 0;
$partWidth = $showPart ? 24 : 0; 
$titleWidth = 100 - ($dateWidth + $catWidth + $venWidth + $partWidth);

// 3. Set up the CSS and HTML structure
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>School Schedule - ' . $year . '</title>
    <style>
        /* NARROW MARGINS */
        @page { margin: 0.35in 0.4in; }
        
        body { font-family: "Helvetica", "Arial", sans-serif; color: #000; font-size: 13px; line-height: 1.3; }
        
        .header { text-align: center; margin-bottom: 10px; }
        .header img { max-width: 100%; max-height: 100px; height: auto; margin-bottom: 5px; }
        .header h1 { margin: 0; color: #000; font-size: 18px; text-transform: uppercase; }
        
        .yearly-title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; }
        .month-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }
        
        .page-break { page-break-after: always; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid #000; padding: 6px; vertical-align: top; word-wrap: break-word; }
        th { background-color: #F5F5DC; font-weight: bold; text-align: center; text-transform: uppercase; font-size: 11px; color: #000; }
        
        .date-col { text-align: center; font-size: 16px; font-weight: bold; }
        .day-name { font-size: 11px; font-weight: normal; display: block; margin-bottom: 2px; text-transform: uppercase; }
        
        .ev-title { font-weight: bold; font-size: 13px; margin-bottom: 2px; }
        .time-text { font-size: 11px; font-weight: bold; color: #444; margin-bottom: 5px; }
        .ev-desc { font-size: 11px; line-height: 1.4; color: #222; }
        
        .center-text { text-align: center; font-size: 11px; }
        .part-cell { font-size: 11px; line-height: 1.5; color: #222; text-align: left; }
        
        .no-events { text-align: center; padding: 40px; font-style: italic; border: 1px solid #000; }
    </style>
</head>
<body>';

$totalMonths = count($selectedMonths);
$currentIndex = 0;

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

function formatTimeDisplay($t) {
    if (!$t || $t === '00:00:00' || $t === '23:59:59') return 'All Day';
    return date('g:i A', strtotime($t));
}

// 4. Loop through each selected month and build its page
foreach ($selectedMonths as $month) {
    $monthName = date('F', mktime(0, 0, 0, $month, 10));

    $params = array_merge([$month, $year], $selectedCategories);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    $html .= '<div class="header">';
    if ($base64Image !== '') {
        $html .= '<img src="' . $base64Image . '" alt="St. Joseph School Foundation Header">';
    } else {
        $html .= '<h1>St. Joseph School Foundation</h1>';
    }
    $html .= '</div>';

    if ($currentIndex === 0) {
        $nextYear = $year + 1;
        $html .= '<div class="yearly-title">Monthly School Calendar of Activities for School Year ' . $year . '-' . $nextYear . '</div>';
    }

    $html .= '<div class="month-title">' . $monthName . ' ' . $year . '</div>';

    if (count($events) > 0) {
        $html .= '<table width="100%">
                    <thead>
                        <tr>
                            <th width="' . $dateWidth . '%">Date</th>
                            <th width="' . $titleWidth . '%">Event Details</th>';
                            
        if ($showCat) $html .= '<th width="' . $catWidth . '%">Category</th>';
        if ($showVen) $html .= '<th width="' . $venWidth . '%">Venue</th>';
        if ($showPart) $html .= '<th width="' . $partWidth . '%">Participants</th>';
                            
        $html .= '      </tr>
                    </thead>
                    <tbody>';

        foreach ($events as $event) {
            $dayNum = date('j', strtotime($event['start_date']));
            $descText = !empty($event['description']) ? nl2br(htmlspecialchars($event['description'])) : '';

            $html .= '<tr>
                        <td class="date-col">' . $dayNum . '</td>
                        <td class="activity-col">
                            <div class="ev-title">' . htmlspecialchars($event['title']) . '</div>';

            if ($descText !== '') {
                $html .= '<div class="ev-desc">' . $descText . '</div>';
            }

            // Print the custom schedules cleanly below the description
            if ($showCust && !empty($customSchedules)) {
                $html .= '<div style="margin-top: 8px; font-size: 10px; color: #333; line-height: 1.4;">';
                $html .= '<span style="font-style: italic;">* Custom Schedules:</span><br>';
                $html .= implode('<br>', $customSchedules);
                $html .= '</div>';
            }

            $html .= '</td>';

            // COLUMN 3: Category
            if ($showCat) {
                $catText = !empty($event['category_name']) ? htmlspecialchars($event['category_name']) : 'N/A';
                $html .= '<td class="center-text">' . $catText . '</td>';
            }

            // COLUMN 4: Venue
            if ($showVen) {
                $venText = !empty($event['venue_name']) ? htmlspecialchars($event['venue_name']) : 'N/A';
                $html .= '<td class="center-text">' . $venText . '</td>';
            }

            // COLUMN 5: Participants (Now just a tight, comma-separated list of names)
            if ($showPart) {
                if (!empty($partNames)) {
                    $html .= '<td class="part-cell">' . implode(', ', $partNames) . '</td>';
                } else {
                    $html .= '<td class="center-text" style="color:#777;"><i>N/A</i></td>';
                }
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="no-events">No events scheduled matching your criteria for ' . $monthName . '.</div>';
    }

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

$dompdf->setPaper($paperSize, 'portrait');

$dompdf->render();

$fileName = ($totalMonths > 1) ? "Events_Multiple_Months_{$year}.pdf" : "Events_" . date('F', mktime(0, 0, 0, $selectedMonths[0], 10)) . "_{$year}.pdf";

$dompdf->stream($fileName, ["Attachment" => false]);
exit();
?>