<?php

// 1. Database Credentials
// Change these to match your local setup (e.g., XAMPP, WAMP, or MAMP)
$host = 'localhost';
$dbname = 'calendar_of_event'; // The name of the database you created in phpMyAdmin
$username = 'root';          // Default XAMPP/WAMP username is usually 'root'
$password = '';              // Default XAMPP/WAMP password is usually empty

// 2. Data Source Name (DSN)
// Specifies the host, database name, and character set
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// 4. Create the Connection
try {
    $pdo = new PDO($dsn, $username, $password, $options);

} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>