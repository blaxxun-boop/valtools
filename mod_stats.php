<?php
session_start();
require_once __DIR__ . "/inc.php";

try {
    // Get mod statistics
    $total_mods = $pdo->query("SELECT COUNT(*) FROM mods")->fetchColumn();
    $total_comments = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
    $pending_comments = $pdo->query("SELECT COUNT(*) FROM comments WHERE approved = 0")->fetchColumn();
    $approved_comments = $pdo->query("SELECT COUNT(*) FROM comments WHERE approved = 1")->fetchColumn();

    // Most active mod authors
    $top_authors = $pdo->query("
        SELECT author, COUNT(*) as mod_count 
        FROM mods 
        GROUP BY author 
        ORDER BY mod_count DESC 
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Most commented mods
    $most_commented = $pdo->query("
        SELECT m.author, m.name, COUNT(c.id) as comment_count
        FROM mods m
        LEFT JOIN comments c ON m.author = c.mod_author AND m.name = c.mod_name
        GROUP BY m.author, m.name
        ORDER BY comment_count DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent activity
    $recent_mods = $pdo->query("
        SELECT author, name, updated 
        FROM mods 
        ORDER BY updated DESC 
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Mod Statistics - Valtools</title>
    <style>
        .stats-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .stats-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--color-panel);
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--color-border, #333);
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
            color: var(--color-text-muted, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0;
            line-height: 1;
        }

        .stat-value.primary { color: #007bff; }
        .stat-value.success { color: #28a745; }
        .stat-value.warning { color: #ffc107; }
        .stat-value.info { color: #17a2b8; }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .content-section {
            background: var(--color-panel);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--color-border, #333);
        }

        .section-header {
            background: var(--color-header-bg_tsblue, #2a2a2a);
            padding: 1rem 1.5rem;
            margin: 0;
            font-size: 1.1rem;
            border-bottom: 1px solid var(--color-border, #333);
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }

        .stats-table th,
        .stats-table td {
            padding: 0.75rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid var(--color-border, #333);
        }

        .stats-table th {
            background: var(--color-table-header-bg_tsblue, #2a2a2a);
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-text-muted, #888);
        }

        .stats-table tbody tr:hover {
            background: var(--color-header-bg_tsblue, rgba(255, 255, 255, 0.05));
        }

        .stats-table tbody tr:last-child td {
            border-bottom: none;
        }

        .full-width-section {
            background: var(--color-panel);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--color-border, #333);
        }

        .mod-link {
            color: var(--color-text);
            text-decoration: none;
        }

        .author-name {
            font-weight: 600;
            color: var(--color-text-light, #007bff);
        }

        .count-badge {
            background: var(--color-primary, #007bff);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .stat-value {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . "/topnav.php"; ?>

    <div class="stats-container">
        <div class="stats-header">
            <h1>Mod Statistics</h1>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php else: ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Mods</h3>
                    <p class="stat-value primary"><?= number_format($total_mods) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Comments</h3>
                    <p class="stat-value warning" style="color: #4caf50"><?= number_format($total_comments) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Pending Comments</h3>
                    <p class="stat-value warning"><?= number_format($pending_comments) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Approved Comments</h3>
                    <p class="stat-value info"><?= number_format($approved_comments) ?></p>
                </div>
            </div>

            <!-- Two Column Content -->
            <div class="content-grid">
                <div class="content-section">
                    <h2 class="section-header">Top Mod Authors</h2>
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Author</th>
                                <th>Mods</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_authors as $author): ?>
                                <tr>
                                    <td class="author-name"><?= htmlspecialchars($author['author']) ?></td>
                                    <td><span class="count-badge"><?= number_format($author['mod_count']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="content-section">
                    <h2 class="section-header">Most Commented Mods</h2>
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th>Mod</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($most_commented as $mod): ?>
                                <tr>
                                    <td>
                                        <div class="mod-link">
                                            <span class="author-name"><?= htmlspecialchars($mod['author']) ?></span>
                                            <br>
                                            <small><?= htmlspecialchars($mod['name']) ?></small>
                                        </div>
                                    </td>
                                    <td><span class="count-badge"><?= number_format($mod['comment_count']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Full Width Section -->
            <div class="full-width-section">
                <h2 class="section-header">Recently Updated Mods</h2>
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Author</th>
                            <th>Mod Name</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_mods as $mod): ?>
                            <tr>
                                <td class="author-name"><?= htmlspecialchars($mod['author']) ?></td>
                                <td><?= htmlspecialchars($mod['name']) ?></td>
                                <td><?= date('M j, Y \a\t g:i A', $mod['updated']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</body>
<?php require __DIR__ . '/footer.php'; ?>
</html>