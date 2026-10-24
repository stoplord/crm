<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'u859404188_Stoplordc');
define('DB_USER', 'u859404188_StopLordc');
define('DB_PASS', 'u859404188_StopLordC');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    // Log error securely, do not output raw error to user
    error_log($e->getMessage());
    die("Database connection failed. Please try again later.");
}
?>