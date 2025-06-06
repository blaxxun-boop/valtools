<?php
require __DIR__ . "/dbinc.php";

// Function to handle new game version compatibility
function updateCompatibilityForNewGameVersion($pdo, $new_game_version) {
    try {
        // Get the previous game version
        $stmt = $pdo->prepare("
            SELECT version 
            FROM valheim_updates 
            WHERE version != ? AND version IS NOT NULL AND version != '' 
            ORDER BY published_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$new_game_version]);
        $previous_version = $stmt->fetchColumn();

        if (!$previous_version) {
            echo "No previous version found, treating all as new entries.<br>";
            $previous_version = $new_game_version; // Fallback
        }

        // Copy compatibility from previous version to new version for all mods
        // Only if they were compatible in the previous version
        $stmt = $pdo->prepare("
            INSERT INTO mod_compatibility (
                mod_author, 
                mod_name, 
                mod_version, 
                game_version, 
                status, 
                notes, 
                tested_by
            )
            SELECT 
                mc.mod_author,
                mc.mod_name,
                m.version as current_mod_version,
                ? as new_game_version,
                CASE 
                    WHEN m.deprecated = 1 THEN 'incompatible'
                    WHEN mc.status = 'compatible' THEN 'compatible'
                    ELSE 'untested'
                END as status,
                CASE 
                    WHEN m.deprecated = 1 THEN 'Mod marked as deprecated on Thunderstore'
                    WHEN mc.status = 'compatible' THEN CONCAT('Assumed compatible based on ', ?)
                    ELSE 'Needs testing for new game version'
                END as notes,
                'system' as tested_by
            FROM mod_compatibility mc
            INNER JOIN mods m ON mc.mod_author = m.author AND mc.mod_name = m.name
            WHERE mc.game_version = ?
            AND NOT EXISTS (
                SELECT 1 FROM mod_compatibility mc2 
                WHERE mc2.mod_author = mc.mod_author 
                AND mc2.mod_name = mc.mod_name 
                AND mc2.game_version = ?
            )
            GROUP BY mc.mod_author, mc.mod_name
        ");

        $result = $stmt->execute([
            $new_game_version,
            $previous_version,
            $previous_version,
            $new_game_version
        ]);

        $rows_affected = $stmt->rowCount();
        echo "Added compatibility entries for {$rows_affected} mods for game version {$new_game_version}<br>";

        return $rows_affected;

    } catch (PDOException $e) {
        echo "Error updating compatibility for new version: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Example usage - you would call this when a new game version is detected
// updateCompatibilityForNewGameVersion($pdo, '0.218.15');
?>