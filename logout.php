<?php
// 1. Find the current session
session_start();

// 2. Unset all session variables (take back the data)
session_unset();

// 3. Destroy the session entirely (shred the VIP wristband)
session_destroy();

// 4. Kick them back to the login screen
header("Location: login.php");
exit;