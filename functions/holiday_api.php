<?php
// functions/holiday_api.php

/**
 * Fetches Philippine holidays for the CURRENT YEAR from Google Calendar 
 * and inserts them into the database without creating duplicates.
 * * @param PDO $pdo Your database connection object
 * @param string $apiKey Your Google API Key
 * @param int $holidayCategoryId The ID of the "Holidays" category in your database
 * @return array Returns an array with a 'success' boolean and a 'message'
 */
function syncHolidaysThisYear($pdo, $apiKey, $holidayCategoryId) {
    // 1. Get the current year dynamically
    $currentYear = date('Y');
    
    // 2. Google's official Philippine Holidays Calendar ID (URL Encoded)
    $calendarId = urlencode('en.philippines#holiday@group.v.calendar.google.com');
    
    // 3. Set the date boundaries for THIS year (RFC3339 format)
    $timeMin = $currentYear . '-01-01T00:00:00Z';
    $timeMax = $currentYear . '-12-31T23:59:59Z';
    
    // 4. Build the API URL
    $url = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events"
         . "?key={$apiKey}"
         . "&timeMin={$timeMin}"
         . "&timeMax={$timeMax}"
         . "&singleEvents=true"
         . "&orderBy=startTime";

    // 5. Initialize cURL to fetch the data
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fine for local XAMPP testing
    
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

    // 6. Prepare SQL statements
    // Check if event exists
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE title = :title AND start_date = :start_date");
    
    // Insert new event (publish_id is NULL for standalone events)
    $insertStmt = $pdo->prepare("
        INSERT INTO events (publish_id, category_id, title, start_date, start_time) 
        VALUES (NULL, :category_id, :title, :start_date, '00:00:00')
    ");

    $insertedCount = 0;
    $skippedCount = 0;

    // 7. Loop through Google's data and insert
    foreach ($data['items'] as $item) {
        $title = $item['summary'];
        
        // Extract the date correctly
        if (isset($item['start']['date'])) {
            $startDate = $item['start']['date'];
        } else if (isset($item['start']['dateTime'])) {
            $startDate = substr($item['start']['dateTime'], 0, 10); 
        } else {
            continue; 
        }

        // Check for duplicates before inserting
        $checkStmt->execute([':title' => $title, ':start_date' => $startDate]);
        $exists = $checkStmt->fetchColumn();

        if ($exists > 0) {
            $skippedCount++; // Already in database, skip it
        } else {
            // It's a new holiday, insert it!
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
        'message' => "Sync complete for $currentYear! Added $insertedCount new holidays. Skipped $skippedCount duplicates."
    ];
}
?>