<?php
require_once __DIR__ . "/inc.php";

try {
    // First, let's check what the status column accepts
    $stmt = $pdo->prepare("SHOW CREATE TABLE mod_compatibility");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "Table structure: " . $result['Create Table'] . "\n\n";

    $stmt = $pdo->prepare("
        INSERT INTO mod_compatibility (mod_author, mod_name, game_version, status, notes)
SELECT
    m.author COLLATE utf8mb4_general_ci as mod_author,
    m.name COLLATE utf8mb4_general_ci as mod_name,
    v.version COLLATE utf8mb4_general_ci as game_version,
    'unknown' as status,
    NULL as notes
FROM mods m
         CROSS JOIN valheim_updates v
WHERE v.version IS NOT NULL
  AND v.version != ''
  AND NOT EXISTS (
    SELECT 1
    FROM mod_compatibility mc
    WHERE mc.mod_author = m.author COLLATE utf8mb4_general_ci
      AND mc.mod_name = m.name COLLATE utf8mb4_general_ci
      AND mc.game_version = v.version COLLATE utf8mb4_general_ci
        )
    ");

    $stmt->execute();
    $rowCount = $stmt->rowCount();

    echo "Successfully inserted {$rowCount} mod compatibility records.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>