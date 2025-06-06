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

$processed = 0;
$skipped = 0;

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
        $stmt = $pdo->prepare("INSERT INTO mods (author, name, deprecated, packageurl, author_modpage, version, icon, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE version = VALUES(version), updated = VALUES(updated), packageurl = VALUES(packageurl), author_modpage = VALUES(author_modpage), deprecated = VALUES(deprecated), icon = VALUES(icon)");

        $result = $stmt->execute([$author, $name, $isDeprecated, $packageUrl, $authorModpage, $version, $icon, $last_updated]);

        if ($result) {
            ++$processed;
        }
    } catch (PDOException $e) {
        echo "Error inserting mod {$author}-{$name}: " . $e->getMessage() . "<br>";
    }
}

echo "Process completed!<br>";
echo "Processed: {$processed} mods<br>";
echo "Skipped: {$skipped} mods<br>";
?>