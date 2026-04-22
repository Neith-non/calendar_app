<?php
// functions/holiday_api.php

/**
 * Fetches Philippine holidays for Last Year, Current Year, and Next Year.
 * Cleans up holidays older than Last Year to save database memory.
 * * @param PDO $pdo Your database connection object
 * @param string $apiKey Your Google API Key
 * @param int $holidayCategoryId The ID of the "Holidays" category in your database
 * @return array Returns an array with a 'success' boolean and a 'message'
 */
function syncHolidaysThreeYears($pdo, $apiKey, $holidayCategoryId)
{
    // 1. Calculate the years
    $currentYear = (int) date('Y');
    $lastYear = $currentYear - 1;
    $nextYear = $currentYear + 1;

    // ==========================================
    // STEP 1: MEMORY MANAGEMENT (CLEANUP)
    // ==========================================
    // Delete holidays that are strictly older than $lastYear.
    // Important: We ONLY delete events belonging to the holiday category!
    $deleteStmt = $pdo->prepare("
        DELETE FROM events 
        WHERE category_id = :category_id 
        AND YEAR(start_date) < :last_year
    ");
    $deleteStmt->execute([
        ':category_id' => $holidayCategoryId,
        ':last_year' => $lastYear
    ]);
    $deletedCount = $deleteStmt->rowCount();

    // ==========================================
    // STEP 2: FETCH FROM GOOGLE API
    // ==========================================
    $calendarId = urlencode('en.philippines#holiday@group.v.calendar.google.com');

    // Set boundaries from Jan 1st of Last Year to Dec 31st of Next Year
    $timeMin = $lastYear . '-01-01T00:00:00Z';
    $timeMax = $nextYear . '-12-31T23:59:59Z';

    $url = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events"
        . "?key={$apiKey}"
        . "&timeMin={$timeMin}"
        . "&timeMax={$timeMax}"
        . "&singleEvents=true"
        . "&orderBy=startTime";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'message' => "Google API Error. HTTP Code: $httpCode"];
    }

    $data = json_decode($response, true);

    if (!isset($data['items'])) {
        return ['success' => false, 'message' => "No holidays found in the API response."];
    }

    // ==========================================
    // STEP 3: INSERT INTO DATABASE
    // ==========================================
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE title = :title AND start_date = :start_date AND category_id = :category_id");

    $insertStmt = $pdo->prepare("
        INSERT INTO events (publish_id, category_id, title, start_date, start_time) 
        VALUES (NULL, :category_id, :title, :start_date, '00:00:00')
    ");

    $insertedCount = 0;
    $skippedCount = 0;

    foreach ($data['items'] as $item) {
        $title = $item['summary'];

        if (isset($item['start']['date'])) {
            $startDate = $item['start']['date'];
        } else if (isset($item['start']['dateTime'])) {
            $startDate = substr($item['start']['dateTime'], 0, 10);
        } else {
            continue;
        }

        // Check for duplicates before inserting
        $checkStmt->execute([
            ':title' => $title,
            ':start_date' => $startDate,
            ':category_id' => $holidayCategoryId
        ]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $skippedCount++;
        } else {
            $insertStmt->execute([
                ':category_id' => $holidayCategoryId,
                ':title' => $title,
                ':start_date' => $startDate
            ]);
            $insertedCount++;
        }
    }

    return [
        'success' => true,
        'message' => "Sync Complete! Deleted $deletedCount old holidays. Added $insertedCount new holidays for $lastYear - $nextYear. (Skipped $skippedCount duplicates)."
    ];
}
?>