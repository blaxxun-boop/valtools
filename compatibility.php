<?php
declare(strict_types=1);

require __DIR__ . '/inc.php';

// Get all incompatibility relationships
$incompatibilities = $pdo->query("
    SELECT 
        mod_author, mod_name,
        incompatible_mod_author, incompatible_mod_name,
        incompatibility,
        COUNT(*) as report_count
    FROM comments 
    WHERE incompatibility IS NOT NULL 
    AND incompatible_mod_author IS NOT NULL 
    AND incompatible_mod_name IS NOT NULL
    GROUP BY mod_author, mod_name, incompatible_mod_author, incompatible_mod_name, incompatibility
    ORDER BY report_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get mods with most compatibility issues
$problematic_mods = $pdo->query("
    SELECT 
        mod_author, mod_name,
        COUNT(*) as issue_count,
        GROUP_CONCAT(DISTINCT incompatibility) as issue_types
    FROM comments 
    WHERE incompatibility IS NOT NULL
    GROUP BY mod_author, mod_name
    ORDER BY issue_count DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Search functionality
$search_results = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            mod_author, mod_name,
            incompatible_mod_author, incompatible_mod_name,
            incompatibility,
            COUNT(*) as report_count
        FROM comments 
        WHERE approved = 1 
        AND incompatibility IS NOT NULL
        AND (
            CONCAT(mod_author, ' - ', mod_name) LIKE ? OR
            CONCAT(incompatible_mod_author, ' - ', incompatible_mod_name) LIKE ?
        )
        GROUP BY mod_author, mod_name, incompatible_mod_author, incompatible_mod_name, incompatibility
        ORDER BY report_count DESC
    ");
    $stmt->execute([$search, $search]);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <title>Mod Compatibility Matrix – Valtools</title>
    <style>
        :root {
            --color-text: #333;
            --color-text-secondary: #666;
            --color-bg: #fff;
            --color-border: #ddd;
            --color-accent: #007bff;
            --border-radius: 8px;
        }

        .compatibility-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2em;
            margin-bottom: 2em;
        }

        .compatibility-card {
            background: var(--color-panel);
            padding: 1.5em;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .search-box {
            margin-bottom: 2em;
            padding: 1em;
            background: var(--color-panel);
            border-radius: var(--border-radius);
        }

        .search-box input {
            width: 100%;
            padding: 0.5em;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            background: var(--color-bg);
            color: var(--color-text);
        }

        .incompatibility-item {
            padding: 1em;
            margin: 0.5em 0;
            border-left: 4px solid var(--color-accent);
            background: rgba(255, 0, 0, 0.1);
            border-radius: 4px;
        }

        .incompatibility-type {
            display: inline-block;
            padding: 0.2em 0.5em;
            background: var(--color-accent);
            color: white;
            border-radius: 3px;
            font-size: 0.8em;
            margin-right: 0.5em;
        }

        .report-count {
            float: right;
            color: var(--color-text-secondary);
            font-size: 0.9em;
        }

        .mod-name {
            font-weight: bold;
            color: var(--color-accent);
        }

        .problematic-mod {
            padding: 0.8em;
            margin: 0.5em 0;
            background: rgba(255, 165, 0, 0.1);
            border-radius: 4px;
            border-left: 4px solid orange;
        }

        @media (max-width: 768px) {
            .compatibility-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="table-view">
<?php require __DIR__ . '/topnav.php'; ?>

<main>
    <div id="mod-filters" style="margin-bottom:1em;">
        <h1>Mod Compatibility</h1>
        <p>Track mod incompatibilities and conflicts reported by the community.</p>
    </div>

    <!-- Search -->
    <div class="search-box">
        <form method="GET">
            <input type="text" name="search" placeholder="Search for mod incompatibilities..."
                   value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <button type="submit" style="margin-left: 0.5em;">Search</button>
        </form>
    </div>

    <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
        <div class="compatibility-card">
            <h3>Search Results for "<?= htmlspecialchars($_GET['search']) ?>"</h3>
            <?php if (empty($search_results)): ?>
                <p>No compatibility issues found for this search term.</p>
            <?php else: ?>
                <?php foreach ($search_results as $issue): ?>
                    <div class="incompatibility-item">
                        <span class="incompatibility-type"><?= htmlspecialchars($issue['incompatibility']) ?></span>
                        <span class="report-count"><?= $issue['report_count'] ?> report(s)</span>
                        <div>
                            <span class="mod-name"><?= htmlspecialchars($issue['mod_author'] . ' - ' . $issue['mod_name']) ?></span>
                            conflicts with
                            <span class="mod-name"><?= htmlspecialchars($issue['incompatible_mod_author'] . ' - ' . $issue['incompatible_mod_name']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="compatibility-grid">
        <!-- Most Problematic Mods -->
        <div class="compatibility-card">
            <div style="max-height: 500px; overflow-y: auto;">
                <?php if (empty($problematic_mods)): ?>
                    <p>No compatibility issues reported yet.</p>
                <?php else: ?>
                    <?php foreach ($problematic_mods as $mod): ?>
                        <div class="problematic-mod">
                            <div class="mod-name"><?= htmlspecialchars($mod['mod_author'] . ' - ' . $mod['mod_name']) ?></div>
                            <div style="margin-top: 0.5em;">
                                <span style="color: var(--color-text-secondary);"><?= $mod['issue_count'] ?> issues:</span>
                                <?php
                                $types = explode(',', $mod['issue_types']);
                                foreach ($types as $type):
                                    ?>
                                    <span class="incompatibility-type"><?= htmlspecialchars(trim($type)) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- All Incompatibilities -->
        <div class="compatibility-card">
            <h3>All Reported Incompatibilities</h3>
            <div style="max-height: 500px; overflow-y: auto;">
                <?php if (empty($incompatibilities)): ?>
                    <p>No incompatibilities reported yet.</p>
                <?php else: ?>
                    <?php foreach ($incompatibilities as $issue): ?>
                        <div class="incompatibility-item">
                            <span class="incompatibility-type"><?= htmlspecialchars($issue['incompatibility']) ?></span>
                            <span class="report-count"><?= $issue['report_count'] ?> report(s)</span>
                            <div>
                                <span class="mod-name"><?= htmlspecialchars($issue['mod_author'] . ' - ' . $issue['mod_name']) ?></span>
                                conflicts with
                                <span class="mod-name"><?= htmlspecialchars($issue['incompatible_mod_author'] . ' - ' . $issue['incompatible_mod_name']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>