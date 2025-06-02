<?php

// Load configuration
$config_file = __DIR__ . '/config/config.php';
if (!file_exists($config_file)) {
    die('Configuration file not found. Copy config.example.php to config.php');
}
$config = require $config_file;

// Database connection
$db_host = $config['database']['host'];
$db_user = $config['database']['user'];
$db_pass = $config['database']['pass'];
$db_name = $config['database']['dbname'];


$pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
