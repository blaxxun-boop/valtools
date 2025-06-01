<?php
// Simple cache clearing script
$cacheDir = __DIR__ . '/cache/wiki/';
if (is_dir($cacheDir)) {
    $files = glob($cacheDir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    echo "Wiki cache cleared!";
} else {
    echo "Cache directory not found.";
}
?>