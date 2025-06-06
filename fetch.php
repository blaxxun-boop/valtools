<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . "/dbinc.php";

echo "Starting fetch process...<br>";

$url = "https://thunderstore.io/c/valheim/api/v1/package/";
$json_data = file_get_contents($url);

if ($json_data === false) {
    die("Failed to fetch data from API");
}

$mods = json_decode($json_data);

if ($mods === null) {
    die("Failed to decode JSON data");
}

echo "Fetched " . count($mods) . " mods from API<br>";

// Get the latest game version for compatibility insertion
try {
    $stmt = $pdo->prepare("SELECT version FROM valheim_updates WHERE version IS NOT NULL AND version != '' ORDER BY published_at DESC LIMIT 1");
    $stmt->execute();
    $latest_game_version = $stmt->fetchColumn();
} catch (PDOException $e) {
    echo "Warning: Could not fetch latest game version: " . $e->getMessage() . "<br>";
    $latest_game_version = null;
}

$processed = 0;
$skipped = 0;
$compatibility_added = 0;

foreach ($mods as $mod) {
    // Skip modpacks and mods with many dependencies
    if (in_array("Modpacks", $mod->categories) ||
        count($mod->versions[0]->dependencies) >= 5 ||
        stripos($mod->name, 'modpack') !== false) {
        ++$skipped;
        continue;
    }

    $author = $mod->owner;
    $name = $mod->name;
    $isDeprecated = $mod->is_deprecated ? 1 : 0;
    $packageUrl = $mod->package_url;
    $authorModpage = 'https://thunderstore.io/c/valheim/p/' . $mod->owner;
    $version = $mod->versions[0]->version_number;
    $icon = $mod->versions[0]->icon;
    $last_updated = strtotime($mod->versions[0]->date_created);

    try {
        // Insert/update mod
        $stmt = $pdo->prepare("INSERT INTO mods (author, name, deprecated, packageurl, author_modpage, version, icon, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE version = VALUES(version), updated = VALUES(updated), packageurl = VALUES(packageurl), author_modpage = VALUES(author_modpage), deprecated = VALUES(deprecated), icon = VALUES(icon)");

        $result = $stmt->execute([$author, $name, $isDeprecated, $packageUrl, $authorModpage, $version, $icon, $last_updated]);

        if ($result) {
            ++$processed;

            // Add compatibility data if we have a game version
            if ($latest_game_version) {
                try {
                    $compatibility_status = $isDeprecated ? 'incompatible' : 'compatible';
                    $compatibility_notes = $isDeprecated ? 'Mod marked as deprecated on Thunderstore' : 'Auto-populated based on mod status';

                    $compat_stmt = $pdo->prepare("
                        INSERT INTO mod_compatibility (
                            mod_author, 
                            mod_name, 
                            mod_version, 
                            game_version, 
                            status, 
                            notes, 
                            tested_by
                        ) VALUES (?, ?, ?, ?, ?, ?, 'system')
                        ON DUPLICATE KEY UPDATE 
                            status = CASE 
                                WHEN VALUES(status) = 'incompatible' AND mod_compatibility.status != 'incompatible' THEN 'incompatible'
                                ELSE mod_compatibility.status
                            END,
                            notes = CASE 
                                WHEN VALUES(status) = 'incompatible' AND mod_compatibility.status != 'incompatible' THEN VALUES(notes)
                                ELSE mod_compatibility.notes
                            END,
                            tested_date = CASE 
                                WHEN VALUES(status) = 'incompatible' AND mod_compatibility.status != 'incompatible' THEN CURRENT_TIMESTAMP
                                ELSE mod_compatibility.tested_date
                            END
                    ");

                    $compat_result = $compat_stmt->execute([
                        $author,
                        $name,
                        $version,
                        $latest_game_version,
                        $compatibility_status,
                        $compatibility_notes
                    ]);

                    if ($compat_result && $compat_stmt->rowCount() > 0) {
                        ++$compatibility_added;
                    }
                } catch (PDOException $e) {
                    echo "Error inserting compatibility for {$author}-{$name}: " . $e->getMessage() . "<br>";
                }
            }
        }
    } catch (PDOException $e) {
        echo "Error inserting mod {$author}-{$name}: " . $e->getMessage() . "<br>";
    }
}

echo "Process completed!<br>";
echo "Processed: {$processed} mods<br>";
echo "Skipped: {$skipped} mods<br>";
echo "Compatibility entries added/updated: {$compatibility_added}<br>";
?>