<?php
declare(strict_types=1);

// ─── Bootstrap your app & get $pdo ───────────────────────────────────────────
require __DIR__ . '/inc.php';

// ─── CONFIG ─────────────────────────────────────────────────────────────────
const STEAM_API_KEY = 'YOUR_STEAM_API_KEY';
const VALHEIM_APPID = 892970;
const NEWS_COUNT = 2000;

// ─── ENSURE SCHEMA ────────────────────────────────────────────────────────────
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `valheim_updates` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `version`      VARCHAR(64)    NULL,
  `title`        TEXT           NOT NULL,
  `url`          TEXT           NOT NULL,
  `published_at` DATETIME       NOT NULL,
  UNIQUE KEY `uniq_news` (`title`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
);

// ─── FETCH STEAM NEWS ─────────────────────────────────────────────────────────
$apiUrl = sprintf(
    'https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/'
    . '?appid=%d&count=%d&maxlength=300&format=json&key=%s',
    VALHEIM_APPID, NEWS_COUNT, STEAM_API_KEY
);
$json = file_get_contents($apiUrl);
if ($json === false) {
    error_log("Failed to fetch Steam API data for Valheim updates");
    $items = [];
} else {
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON response from Steam API: " . json_last_error_msg());
        $items = [];
    } else {
        $items = $data['appnews']['newsitems'] ?? [];
    }
}

// ─── UPSERT ───────────────────────────────────────────────────────────────────
$insert = $pdo->prepare(<<<'SQL'
INSERT IGNORE INTO `valheim_updates`
  (`version`,`title`,`url`,`published_at`)
VALUES
  (:version, :title, :url, :published_at)
SQL
);

try {
    $pdo->beginTransaction();
    foreach ($items as $n) {


        $title = trim($n['title'] ?? '');
        $url = trim($n['url'] ?? '');
        $dt = date('Y-m-d H:i:s', intval($n['date'] ?? 0));

    // ─── Smart version parsing ────────────────────────────────────────────
    // 1) Look for “Patch” or “Update”, optionally followed by “–” or “:”, then version numbers.
    // 2) If that fails, fall back to any standalone x.y or x.y.z at start.
        if (preg_match(
            '/\b(?:Patch|Update)\b[\s\-–:]*([\d]+(?:\.[\d]+)+)/i',
            $title,
            $m
        )) {
            $version = $m[1];
        } elseif (preg_match(
            '/^([\d]+(?:\.[\d]+)+)\b/',
            $title,
            $m
        )) {
            $version = $m[1];
        } else {
            $version = null;
        }


        $insert->execute([
            ':version' => $version,
            ':title' => $title,
            ':url' => $url,
            ':published_at' => $dt,
        ]);

    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollback();
    error_log("Failed to update Valheim news: " . $e->getMessage());
}

// ─── LOAD ALL UPDATES FOR RENDERING ──────────────────────────────────────────
$updates = $pdo
    ->query("SELECT version, title, url, published_at
           FROM valheim_updates
           ORDER BY published_at DESC")
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <title>Valheim Updates – Valtools</title>
</head>
<body class="table-view">
<?php require __DIR__ . '/topnav.php'; ?>

<main>
    <div id="mod-filters" style="margin-bottom:1em;">
        <h1>Valheim Patch Notes</h1>
    </div>

    <div id="tableView">
        <table>
            <thead>
            <tr>
                <!--<th><a href="#" class="sortlink">Version</a></th>-->
                <th><a href="#" class="sortlink">Title</a></th>
                <th><a href="#" class="sortlink">Published At</a></th>
                <th>Link</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($updates) === 0): ?>
                <tr>
                    <td colspan="4" style="text-align:center; opacity:0.7;">
                        No patch notes available.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($updates as $upd): ?>
                    <tr>
                        <!--<td><?php /*= htmlspecialchars($upd['version'] ?? '—') */?></td>-->
                        <td><?= htmlspecialchars($upd['title']) ?></td>
                        <td><?= htmlspecialchars($upd['published_at']) ?></td>
                        <td>
                            <a class="redirect-link" href="<?= htmlspecialchars($upd['url']) ?>" target="_blank">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
