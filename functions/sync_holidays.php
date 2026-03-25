<?php
// sync_holidays.php

// 1. Include dependencies
require_once 'database.php';
require_once 'holiday_api.php';

// 2. Configuration
$myApiKey = 'AIzaSyBQ7lCu6H5vVlGwXuxQ68BY9VIdfujErR4';
$holidayCategoryId = 5; // Make sure this is your actual Holiday category ID!

// 3. Run the sync
$result = syncHolidaysThreeYears($pdo, $myApiKey, $holidayCategoryId);

// 4. URL Encode the message so it's safe to pass in the web address
$message = urlencode($result['message']);
$status = $result['success'] ? 'success' : 'error';

// 5. Redirect back to index.php with the message attached to the URL
header("Location: ../index.php?sync_status=$status&sync_msg=$message");
exit();
?>