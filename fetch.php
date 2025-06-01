<?php

require __DIR__ . "/dbinc.php";

$mods = json_decode(file_get_contents("https://thunderstore.io/c/valheim/api/v1/package/"));
foreach ($mods as $mod) {
	if (in_array("Modpacks", $mod->categories) || count($mod->versions[0]->dependencies) >= 5 || stripos($mod->name, 'modpack') !== false || $mod->is_deprecated) continue;
	$author = $mod->owner;
	$name = $mod->name;
	$version = $mod->versions[0]->version_number;
	$last_updated = strtotime($mod->versions[0]->date_created);

	$pdo->prepare("INSERT INTO mods (author, name, version, updated) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE version = VALUES(version), updated = VALUES(updated)")->execute([$author, $name, $version, $last_updated]);
}
