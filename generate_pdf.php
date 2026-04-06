<?php
// generate_pdf.php
session_start();

require_once 'vendor/autoload.php';
require_once 'functions/database.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Get the array of months selected from the modal
$selectedMonths = $_GET['months'] ?? [];
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Safety Check: Make sure they selected at least one month
if (empty($selectedMonths)) {
    die("<h2 style='font-family:sans-serif; color:red;'>Error: Please select at least one month.</h2> <a href='javascript:history.back()'>Go Back</a>");
}

// 2. Sort the months numerically so they appear in chronological order (Jan -> Dec)
$selectedMonths = array_map('intval', $selectedMonths);
sort($selectedMonths);

// 3. Set up the basic CSS and HTML structure
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>School Schedule - ' . $year . '</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e293b; padding-bottom: 10px; }
        .header h1 { margin: 0; color: #1e293b; font-size: 24px; }
        .header p { margin: 5px 0 0 0; color: #64748b; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;}
        
        /* THIS IS THE MAGIC CSS FOR PAGE BREAKS */
        .page-break { page-break-after: always; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
        th { background-color: #f1f5f9; color: #334155; font-weight: bold; text-align: left; padding: 10px; border: 1px solid #cbd5e1; }
        td { padding: 10px; border: 1px solid #cbd5e1; vertical-align: top; }
        .date-col { width: 15%; font-weight: bold; }
        .time-col { width: 15%; color: #64748b; }
        .title-col { width: 45%; }
        .category-col { width: 25%; }
        .no-events { text-align: center; padding: 30px; color: #64748b; font-style: italic; background-color: #f8fafc; border: 1px dashed #cbd5e1; margin-top: 20px;}
    </style>
</head>
<body>';

// 4. Loop through each selected month and build its page
$totalMonths = count($selectedMonths);
$currentIndex = 0;

// Prepare the statement outside the loop for better performance
$stmt = $pdo->prepare("
    SELECT e.*, c.category_name 
    FROM events e
    JOIN event_categories c ON e.category_id = c.category_id
    WHERE MONTH(e.start_date) = :month AND YEAR(e.start_date) = :year
    ORDER BY e.start_date ASC, e.start_time ASC
");

foreach ($selectedMonths as $month) {
    // Get full month name (e.g., "March")
    $monthName = date('F', mktime(0, 0, 0, $month, 10));
    
    // Execute query for this specific month
    $stmt->execute([':month' => $month, ':year' => $year]);
    $events = $stmt->fetchAll();

    // Add the Header for this page
    $html .= '<div class="header">
                <h1>St. Joseph School Foundation</h1>
                <p>Events Schedule - ' . $monthName . ' ' . $year . '</p>
              </div>';

    // Build the table if events exist
    if (count($events) > 0) {
        $html .= '<table>
                    <thead>
                        <tr>
                            <th class="date-col">Date</th>
                            <th class="time-col">Time</th>
                            <th class="title-col">Event Title</th>
                            <th class="category-col">Category</th>
                        </tr>
                    </thead>
                    <tbody>';
                    
        foreach ($events as $event) {
            $formattedDate = date('M d', strtotime($event['start_date']));
            $formattedTime = ($event['start_time'] == '00:00:00') ? 'All Day' : date('g:i A', strtotime($event['start_time']));
            
            $html .= '<tr>
                        <td class="date-col">' . $formattedDate . '</td>
                        <td class="time-col">' . $formattedTime . '</td>
                        <td class="title-col"><strong>' . htmlspecialchars($event['title']) . '</strong></td>
                        <td class="category-col">' . htmlspecialchars($event['category_name']) . '</td>
                      </tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="no-events">No events scheduled for ' . $monthName . '.</div>';
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
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Automatically name the file based on how many months were selected
$fileName = ($totalMonths > 1) ? "Events_Multiple_Months_{$year}.pdf" : "Events_" . date('F', mktime(0,0,0,$selectedMonths[0],10)) . "_{$year}.pdf";

$dompdf->stream($fileName, ["Attachment" => true]);
exit();
?>