<?php
require_once __DIR__ . "/inc.php";

try {
    // Check database default collation
    $db_info = $pdo->query("SELECT @@collation_database as db_collation")->fetch();
    echo "Database collation: " . $db_info['db_collation'] . "\n";

    // Check table collations
    $tables = $pdo->query("
        SELECT TABLE_NAME, TABLE_COLLATION 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('mods', 'mod_compatibility', 'valheim_updates', 'comments')
    ")->fetchAll();

    echo "\nTable collations:\n";
    foreach ($tables as $table) {
        echo "{$table['TABLE_NAME']}: {$table['TABLE_COLLATION']}\n";
    }

    // Check column collations for problematic columns
    $columns = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('mods', 'mod_compatibility') 
        AND COLUMN_NAME IN ('author', 'name', 'mod_author', 'mod_name')
        ORDER BY TABLE_NAME, COLUMN_NAME
    ")->fetchAll();

    echo "\nColumn collations:\n";
    foreach ($columns as $col) {
        echo "{$col['TABLE_NAME']}.{$col['COLUMN_NAME']}: {$col['COLLATION_NAME']}\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>