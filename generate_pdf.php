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

// Safety Check
if (empty($selectedMonths)) {
    die("<h2 style='font-family:sans-serif; color:red;'>Error: Please select at least one month.</h2> <a href='javascript:history.back()'>Go Back</a>");
}

$selectedMonths = array_map('intval', $selectedMonths);
sort($selectedMonths);

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
        
        /* Header Image Styles */
        .header { text-align: center; margin-bottom: 10px; }
        .header img { max-width: 100%; max-height: 140px; height: auto; margin-bottom: 5px; }
        .header h1 { margin: 0; color: #000; font-size: 18px; text-transform: uppercase; }
        
        /* First Page Yearly Title */
        .yearly-title { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 15px; text-transform: uppercase; }
        
        /* Month Title */
        .month-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        
        .page-break { page-break-after: always; }
        
        /* Formal Bordered Table Styles */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 10px; vertical-align: middle; }
        
        /* Table Headers (Beige) */
        th { background-color: #F5F5DC; font-weight: bold; text-align: center; text-transform: uppercase; font-size: 13px; color: #000; }
        
        /* Column Sizing and Alignment */
        .date-col { width: 12%; text-align: center; font-size: 18px; font-weight: bold; }
        .activity-col { width: 88%; text-align: left; padding-left: 15px; }
        
        /* Event Text Formatting */
        .ev-title { font-weight: bold; font-size: 14px; margin-bottom: 3px; }
        .ev-desc { font-size: 13px; line-height: 1.4; }
        
        .no-events { text-align: center; padding: 40px; font-style: italic; border: 1px solid #000; }
    </style>
</head>
<body>';

$totalMonths = count($selectedMonths);
$currentIndex = 0;
$exludedCategories = "Personal";
$stmt = $pdo->prepare("
    SELECT e.*, c.category_name, p.status 
        FROM events e
        JOIN event_categories c ON e.category_id = c.category_id
        LEFT JOIN event_publish p ON e.publish_id = p.id
        WHERE MONTH(e.start_date) = :month
        AND YEAR(e.start_date) = :year 
        AND c.category_name != :exlude
        AND (p.status = 'Approved' OR e.publish_id IS NULL)
        ORDER BY e.start_date ASC, e.start_time ASC;
");

// 4. Loop through each selected month and build its page
foreach ($selectedMonths as $month) {
    $monthName = date('F', mktime(0, 0, 0, $month, 10));
    
    $stmt->execute([':month' => $month, ':year' => $year, ':exlude' => $exludedCategories]);
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
                            <th class="date-col">Date</th>
                            <th class="activity-col">Activity / Events</th>
                        </tr>
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
            
            $html .= '  </td>
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

// CHANGED: Paper size is now 'letter' instead of 'A4'
$dompdf->setPaper('letter', 'portrait');

$dompdf->render();

$fileName = ($totalMonths > 1) ? "Events_Multiple_Months_{$year}.pdf" : "Events_" . date('F', mktime(0,0,0,$selectedMonths[0],10)) . "_{$year}.pdf";

$dompdf->stream($fileName, ["Attachment" => true]);
exit();
?>