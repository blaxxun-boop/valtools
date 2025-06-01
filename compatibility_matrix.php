<?php
require_once __DIR__ . "/inc.php";

// Ensure mod_compatibility table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mod_compatibility (
            id INT PRIMARY KEY AUTO_INCREMENT,
            mod_author VARCHAR(255) NOT NULL,
            mod_name VARCHAR(255) NOT NULL,
            mod_version VARCHAR(50) NOT NULL,
            game_version VARCHAR(50) NOT NULL,
            status ENUM('compatible', 'incompatible', 'partial', 'untested', 'pending') DEFAULT 'untested',
            notes TEXT,
            tested_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            tested_by VARCHAR(255),
            UNIQUE KEY unique_compatibility (mod_author, mod_name, mod_version, game_version),
            INDEX idx_mod_lookup (mod_author, mod_name),
            INDEX idx_game_version (game_version),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    error_log("Could not create mod_compatibility table: " . $e->getMessage());
}


// Handle compatibility updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid) {
    if (isset($_POST['update_compatibility'])) {
        $mod_author = trim($_POST['mod_author']);
        $mod_name = trim($_POST['mod_name']);
        $mod_version = trim($_POST['mod_version']);
        $game_version = trim($_POST['game_version']);
        $status = $_POST['status'];
        $notes = trim($_POST['notes'] ?? '');

        // Get user ID from Discord session
        $user_id = $_SESSION['discord_user']['id'] ?? 'system';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO mod_compatibility (mod_author, mod_name, mod_version, game_version, status, notes, tested_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                notes = VALUES(notes), 
                tested_date = CURRENT_TIMESTAMP,
                tested_by = VALUES(tested_by)
            ");
            $stmt->execute([$mod_author, $mod_name, $mod_version, $game_version, $status, $notes ?: null, $user_id]);
            $success_message = "Compatibility updated successfully.";
        } catch (PDOException $e) {
            $error_message = "Error updating compatibility: " . $e->getMessage();
        }
    }
}

// Get compatibility matrix data - Fixed query with correct column names
try {
    $stmt = $pdo->prepare("
        SELECT 
            mc.*,
            m.version as latest_mod_version,
            m.updated as mod_last_updated,
            vu.published_at as game_published_at
        FROM mod_compatibility mc
        LEFT JOIN mods m ON mc.mod_author COLLATE utf8mb4_general_ci = m.author COLLATE utf8mb4_general_ci 
                         AND mc.mod_name COLLATE utf8mb4_general_ci = m.name COLLATE utf8mb4_general_ci
        LEFT JOIN valheim_updates vu ON mc.game_version COLLATE utf8mb4_general_ci = vu.version COLLATE utf8mb4_general_ci
        ORDER BY mc.mod_author, mc.mod_name, mc.mod_version DESC, mc.game_version DESC
    ");
    $stmt->execute();
    $compatibility_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching compatibility data: " . $e->getMessage();
    $compatibility_data = [];
}

// Organize data into a matrix structure
$compatibility_matrix = [];
foreach ($compatibility_data as $row) {
    $mod_key = $row['mod_author'] . '/' . $row['mod_name'];
    $compatibility_matrix[$mod_key]['info'] = [
        'author' => $row['mod_author'],
        'name' => $row['mod_name'],
        'latest_version' => $row['latest_mod_version'],
        'last_updated' => $row['mod_last_updated']
    ];
    $compatibility_matrix[$mod_key]['versions'][$row['mod_version']][$row['game_version']] = [
        'status' => $row['status'],
        'notes' => $row['notes'],
        'tested_date' => $row['tested_date'],
        'tested_by' => $row['tested_by']
    ];
}

// Get available game versions - Fixed query with correct column name
try {
    $stmt = $pdo->prepare("SELECT DISTINCT version FROM valheim_updates WHERE version IS NOT NULL AND version != '' ORDER BY published_at DESC");
    $stmt->execute();
    $game_versions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $game_versions = [];
    $error_message = "Could not fetch game versions: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Valtools - Compatibility Matrix</title>
    <style>
        .matrix-container {
            overflow-x: auto;
            margin: 2em 0;
        }

        .compatibility-matrix {
            min-width: 800px;
            border-collapse: collapse;
            background: var(--color-panel, #2a2a2a);
            border-radius: 8px;
            overflow: auto;
        }

        .compatibility-matrix th,
        .compatibility-matrix td {
            padding: 8px 12px;
            border: 1px solid var(--color-border, #444);
            text-align: center;
            font-size: 0.9em;
        }

        .compatibility-matrix th {
            background: var(--color-table-header-bg_tsblue, #1e1e1e);
            color: var(--color-text-light, #fff);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .mod-info-cell {
            text-align: left !important;
            min-width: 200px;
            position: sticky;
            left: 0;
            background: var(--color-border, #2a2a2a);
            z-index: 5;
        }

        .mod-name {
            font-weight: 600;
            color: var(--color-text-light, #fff);
        }

        .mod-author {
            font-size: 0.8em;
            color: var(--color-text-muted, #ccc);
        }

        .mod-version-header {
            background: var(--color-accent, #007acc);
            color: white;
            font-weight: 600;
        }

        .compatibility-cell {
            cursor: pointer;
            transition: background-color 0.2s;
            min-width: 80px;
        }

        .compatibility-cell:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .status-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin: 2px;
        }

        .status-compatible {
            background: #28a745;
        }

        .status-incompatible {
            background: #dc3545;
        }

        .status-partial {
            background: #ffc107;
        }

        .status-untested {
            background: #6c757d;
        }

        .status-pending {
            background: #17a2b8;
        }

        .version-selector {
            margin: 1em 0;
            padding: 1em;
            background: var(--color-background-secondary, #2a2a2a);
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
        }

        .legend {
            display: flex;
            gap: 1em;
            margin: 1em 0;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5em;
            font-size: 0.9em;
        }

        .quick-add-form {
            background: var(--color-background-secondary, #2a2a2a);
            padding: 1.5em;
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
            margin: 2em 0;
        }

        .form-row {
            display: flex;
            gap: 1em;
            margin-bottom: 1em;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5em;
            color: var(--color-text-light, #fff);
            font-weight: 500;
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--color-border, #555);
            background: var(--color-background, #1e1e1e);
            color: var(--color-text, #fff);
        }

        .auto-detect-btn {
            background: var(--color-accent, #007acc);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
            margin-left: 0.5em;
        }

        .compatibility-tooltip {
            position: absolute;
            background: var(--color-background, #1e1e1e);
            border: 1px solid var(--color-border, #444);
            border-radius: 6px;
            padding: 1em;
            z-index: 1000;
            max-width: 300px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            display: none;
        }

        .bulk-actions {
            margin: 1em 0;
            padding: 1em;
            background: var(--color-background-secondary, #2a2a2a);
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
        }

        .button {
            background: var(--color-accent, #007acc);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .button:hover {
            background: var(--color-accent-hover, #0056b3);
        }

        .button.secondary {
            background: var(--color-background, #1e1e1e);
            border: 1px solid var(--color-border, #555);
        }

        .button.secondary:hover {
            background: var(--color-background-secondary, #2a2a2a);
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }

            .legend {
                justify-content: center;
            }
        }

        .dropdown {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: var(--color-background, #1e1e1e);
            min-width: 100%;
            /*max-height: 200px;*/
            overflow-y: auto;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            border: 1px solid var(--color-border, #444);
            border-radius: 6px;
        }

        .dropdown-content a {
            color: var(--color-text, #fff);
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            cursor: pointer;
        }

        .dropdown-content a:hover {
            background-color: var(--color-background-secondary, #2a2a2a);
        }

        .show {
            display: block;
        }
    </style>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>

<main style="padding: 2em; max-width: 1600px; margin: 0 auto;">
    <h1 style="color: var(--color-text-light, #fff); margin-bottom: 1em;">Mod Compatibility Matrix</h1>

    <?php if (isset($error_message)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item">
            <span class="status-icon status-compatible"></span>
            <span>Compatible</span>
        </div>
        <div class="legend-item">
            <span class="status-icon status-incompatible"></span>
            <span>Incompatible</span>
        </div>
        <div class="legend-item">
            <span class="status-icon status-partial"></span>
            <span>Partial/Issues</span>
        </div>
        <div class="legend-item">
            <span class="status-icon status-untested"></span>
            <span>Untested</span>
        </div>
        <div class="legend-item">
            <span class="status-icon status-pending"></span>
            <span>Pending Test</span>
        </div>
    </div>

    <!-- Quick Add Form -->
    <?php if (true): ?>
        <div class="quick-add-form">
            <h3 style="margin-top: 0; color: var(--color-text-light, #fff);">Add/Update Compatibility</h3>
            <form method="POST">
                <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Mod Author:</label>
                        <div class="dropdown">
                            <input type="text" name="mod_author" id="mod_author" required
                                   placeholder="Search authors..."
                                   autocomplete="off"
                                   onkeyup="filterAuthors(this.value)"
                                   onfocus="showAuthorDropdown()"
                                   onclick="showAuthorDropdown()">
                            <div id="authorDropdown" class="dropdown-content">
                                <?php
                                $authors = [];
                                foreach ($compatibility_data as $row) {
                                    if (!empty($row['mod_author'])) {
                                        $authors[] = $row['mod_author'];
                                    }
                                }
                                try {
                                    $stmt = $pdo->prepare("SELECT DISTINCT author FROM mods WHERE author IS NOT NULL ORDER BY author");
                                    $stmt->execute();
                                    $mod_authors = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    $authors = array_merge($authors, $mod_authors);
                                } catch (PDOException $e) {}
                                $authors = array_unique($authors);
                                sort($authors);

                                foreach ($authors as $author): ?>
                                    <a href="#" class="dropdown-item" onclick="selectAuthor('<?= htmlspecialchars($author, ENT_QUOTES) ?>')"><?= htmlspecialchars($author) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Mod Name:</label>
                        <div class="dropdown">
                            <input type="text" name="mod_name" id="mod_name" required
                                   placeholder="Search mods..."
                                   autocomplete="off"
                                   onkeyup="filterMods(this.value)"
                                   onfocus="showModDropdown()"
                                   onclick="showModDropdown()">
                            <div id="modDropdown" class="dropdown-content">
                                <?php
                                $mod_names = [];
                                foreach ($compatibility_data as $row) {
                                    if (!empty($row['mod_name'])) {
                                        $mod_names[] = $row['mod_name'];
                                    }
                                }
                                try {
                                    $stmt = $pdo->prepare("SELECT DISTINCT name FROM mods WHERE name IS NOT NULL ORDER BY name");
                                    $stmt->execute();
                                    $mod_db_names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    $mod_names = array_merge($mod_names, $mod_db_names);
                                } catch (PDOException $e) {}
                                $mod_names = array_unique($mod_names);
                                sort($mod_names);

                                foreach ($mod_names as $name): ?>
                                    <a href="#" class="dropdown-item" onclick="selectMod('<?= htmlspecialchars($name, ENT_QUOTES) ?>')"><?= htmlspecialchars($name) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <span><button type="button" class="auto-detect-btn" onclick="autoDetectLatestVersion()">Auto-detect</button></span>
                    </div>
                    <div class="form-group">
                        <label>Mod Version:</label>
                        <input type="text" name="mod_version" required placeholder="e.g., 1.2.3">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Game Version:</label>
                        <select name="game_version" required>
                            <option value="">Select Game Version</option>
                            <?php foreach ($game_versions as $version): ?>
                                <option value="<?= htmlspecialchars($version) ?>"><?= htmlspecialchars($version) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status" required>
                            <option value="compatible">Compatible</option>
                            <option value="incompatible">Incompatible</option>
                            <option value="partial">Partial/Issues</option>
                            <option value="untested">Untested</option>
                            <option value="pending">Pending Test</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Notes (optional):</label>
                        <textarea name="notes" rows="2" placeholder="Any additional notes about compatibility..."></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <button type="submit" name="update_compatibility" class="button">Add/Update Compatibility</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Compatibility Matrix -->
    <div class="matrix-container">
        <table class="compatibility-matrix">
            <thead>
            <tr>
                <th class="mod-info-cell">Mod</th>
                <?php foreach ($game_versions as $game_version): ?>
                    <th class="mod-version-header"><?= htmlspecialchars($game_version) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($compatibility_matrix as $mod_key => $mod_data): ?>
                <?php foreach ($mod_data['versions'] as $mod_version => $game_compatibility): ?>
                    <tr>
                        <td class="mod-info-cell">
                            <div class="mod-name"><?= htmlspecialchars($mod_data['info']['name']) ?></div>
                            <div class="mod-author">by <?= htmlspecialchars($mod_data['info']['author']) ?></div>
                            <div style="font-size: 0.8em; color: #007acc; margin-top: 4px;">
                                v<?= htmlspecialchars($mod_version) ?>
                                <?php if ($mod_version === $mod_data['info']['latest_version']): ?>
                                    <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.7em; margin-left: 4px;">LATEST</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php foreach ($game_versions as $game_version): ?>
                            <td class="compatibility-cell"
                                onclick="showCompatibilityDetails('<?= htmlspecialchars($mod_data['info']['author']) ?>', '<?= htmlspecialchars($mod_data['info']['name']) ?>', '<?= htmlspecialchars($mod_version) ?>', '<?= htmlspecialchars($game_version) ?>')"
                                data-mod-author="<?= htmlspecialchars($mod_data['info']['author']) ?>"
                                data-mod-name="<?= htmlspecialchars($mod_data['info']['name']) ?>"
                                data-mod-version="<?= htmlspecialchars($mod_version) ?>"
                                data-game-version="<?= htmlspecialchars($game_version) ?>">
                                <?php if (isset($game_compatibility[$game_version])): ?>
                                    <?php $compat = $game_compatibility[$game_version]; ?>
                                    <span class="status-icon status-<?= htmlspecialchars($compat['status']) ?>"
                                          title="<?= htmlspecialchars($compat['status']) ?><?= $compat['notes'] ? ': ' . htmlspecialchars($compat['notes']) : '' ?>"></span>
                                <?php else: ?>
                                    <span class="status-icon status-untested" title="Untested"></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (empty($compatibility_matrix)): ?>
        <div style="text-align: center; padding: 3em; color: #ccc; background: #2a2a2a; border-radius: 8px; border: 1px solid #444;">
            <h3 style="margin-top: 0; color: #999;">No compatibility data found</h3>
            <p>Start by adding some compatibility information using the form above.</p>
        </div>
    <?php endif; ?>

    <!-- Bulk Actions -->
    <?php if (hasPermission("modcompatibility")): ?>
        <div class="bulk-actions">
            <h3 style="margin-top: 0; color: #fff;">Bulk Actions</h3>
            <div style="display: flex; gap: 1em; flex-wrap: wrap;">
                <button onclick="markAllAsCompatible()" class="button">Mark Selected as Compatible</button>
                <button onclick="markAllAsIncompatible()" class="button">Mark Selected as Incompatible</button>
                <button onclick="exportCompatibilityData()" class="button secondary">Export Data</button>
                <button onclick="importCompatibilityData()" class="button secondary">Import Data</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Compatibility Details Tooltip -->
    <div id="compatibility-tooltip" class="compatibility-tooltip">
        <div id="tooltip-content"></div>
    </div>

    <!-- Statistics Summary -->
    <div style="margin-top: 3em; padding: 2em; background: #2a2a2a; border-radius: 8px; border: 1px solid #444;">
        <h3 style="margin-top: 0; color: #fff;">Compatibility Statistics</h3>
        <?php
        $stats = [
            'total_mods' => count($compatibility_matrix),
            'total_entries' => count($compatibility_data),
            'compatible' => 0,
            'incompatible' => 0,
            'partial' => 0,
            'untested' => 0,
            'pending' => 0
        ];

        foreach ($compatibility_data as $entry) {
            $stats[$entry['status']]++;
        }
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1em; margin-top: 1em;">
            <div style="text-align: center;">
                <div style="font-size: 2em; font-weight: bold; color: #007acc;"><?= $stats['total_mods'] ?></div>
                <div style="color: #ccc;">Total Mods</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?= $stats['compatible'] ?></div>
                <div style="color: #ccc;">Compatible</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; font-weight: bold; color: #dc3545;"><?= $stats['incompatible'] ?></div>
                <div style="color: #ccc;">Incompatible</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; font-weight: bold; color: #ffc107;"><?= $stats['partial'] ?></div>
                <div style="color: #ccc;">Partial</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; font-weight: bold; color: #6c757d;"><?= $stats['untested'] ?></div>
                <div style="color: #ccc;">Untested</div>
            </div>
        </div>
    </div>
</main>

<script>
    let selectedCells = new Set();

    function showCompatibilityDetails(author, name, modVersion, gameVersion) {
        const tooltip = document.getElementById('compatibility-tooltip');
        const content = document.getElementById('tooltip-content');

        // Find the compatibility data for this combination
        const compatData = <?= json_encode($compatibility_data) ?>;
        const entry = compatData.find(item =>
            item.mod_author === author &&
            item.mod_name === name &&
            item.mod_version === modVersion &&
            item.game_version === gameVersion
        );

        if (entry) {
            content.innerHTML = `
            <h4 style="margin: 0 0 0.5em 0; color: #fff;">${name} v${modVersion}</h4>
            <p style="margin: 0 0 0.5em 0; color: #ccc;">by ${author}</p>
            <p style="margin: 0 0 0.5em 0;"><strong>Game Version:</strong> ${gameVersion}</p>
            <p style="margin: 0 0 0.5em 0;"><strong>Status:</strong> <span class="status-badge status-${entry.status}">${entry.status}</span></p>
            ${entry.notes ? `<p style="margin: 0 0 0.5em 0;"><strong>Notes:</strong> ${entry.notes}</p>` : ''}
            <p style="margin: 0; font-size: 0.8em; color: #999;">
                Tested: ${new Date(entry.tested_date).toLocaleDateString()}
                ${entry.tested_by ? ` by ${entry.tested_by}` : ''}
            </p>
            <?php if (hasPermission("modcompatibility")): ?>
            <div style="margin-top: 1em; padding-top: 1em; border-top: 1px solid #444;">
                <button onclick="editCompatibility('${author}', '${name}', '${modVersion}', '${gameVersion}')" class="button" style="font-size: 0.8em;">
                    Edit
                </button>
            </div>
            <?php endif; ?>
        `;
        } else {
            content.innerHTML = `
            <h4 style="margin: 0 0 0.5em 0; color: #fff;">${name} v${modVersion}</h4>
            <p style="margin: 0 0 0.5em 0; color: #ccc;">by ${author}</p>
            <p style="margin: 0 0 0.5em 0;"><strong>Game Version:</strong> ${gameVersion}</p>
            <p style="margin: 0 0 0.5em 0;"><strong>Status:</strong> <span class="status-badge status-untested">Untested</span></p>
            <p style="margin: 0; color: #999;">No compatibility data available for this combination.</p>
            <?php if (hasPermission("modcompatibility")): ?>
            <div style="margin-top: 1em; padding-top: 1em; border-top: 1px solid #444;">
                <button onclick="addCompatibility('${author}', '${name}', '${modVersion}', '${gameVersion}')" class="button" style="font-size: 0.8em;">
                    Add Compatibility Data
                </button>
            </div>
            <?php endif; ?>
        `;
        }

        tooltip.style.display = 'block';

        // Position tooltip near mouse
        document.addEventListener('mousemove', positionTooltip);
    }

    function positionTooltip(e) {
        const tooltip = document.getElementById('compatibility-tooltip');
        const rect = tooltip.getBoundingClientRect();

        let x = e.clientX + 10;
        let y = e.clientY + 10;

        // Keep tooltip within viewport
        if (x + rect.width > window.innerWidth) {
            x = e.clientX - rect.width - 10;
        }
        if (y + rect.height > window.innerHeight) {
            y = e.clientY - rect.height - 10;
        }

        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    function hideTooltip() {
        const tooltip = document.getElementById('compatibility-tooltip');
        tooltip.style.display = 'none';
        document.removeEventListener('mousemove', positionTooltip);
    }

    // Hide tooltip when clicking elsewhere
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.compatibility-cell') && !e.target.closest('.compatibility-tooltip')) {
            hideTooltip();
        }
    });

    function autoDetectLatestVersion() {
        const authorInput = document.getElementById('mod_author');
        const nameInput = document.getElementById('mod_name');
        const versionInput = document.querySelector('input[name="mod_version"]');

        if (!authorInput.value || !nameInput.value) {
            alert('Please select mod author and name first.');
            return;
        }

        fetch('api/get_mod_version.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                author: authorInput.value,
                name: nameInput.value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.version) {
                versionInput.value = data.version;
            } else {
                alert('Could not auto-detect version: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error auto-detecting version. Please enter manually.');
        });
    }

    function editCompatibility(author, name, modVersion, gameVersion) {
        // Pre-fill the form with existing data
        document.querySelector('input[name="mod_author"]').value = author;
        document.querySelector('input[name="mod_name"]').value = name;
        document.querySelector('input[name="mod_version"]').value = modVersion;
        document.querySelector('select[name="game_version"]').value = gameVersion;

        // Scroll to form
        document.querySelector('.quick-add-form').scrollIntoView({behavior: 'smooth'});
        hideTooltip();
    }

    function addCompatibility(author, name, modVersion, gameVersion) {
        editCompatibility(author, name, modVersion, gameVersion);
    }

    function markAllAsCompatible() {
        // Implementation for bulk marking as compatible
        if (selectedCells.size === 0) {
            alert('Please select some cells first by clicking on them while holding Ctrl.');
            return;
        }

        if (confirm(`Mark ${selectedCells.size} entries as compatible?`)) {
            // Implement bulk update logic
            console.log('Marking as compatible:', selectedCells);
        }
    }

    function markAllAsIncompatible() {
        // Implementation for bulk marking as incompatible
        if (selectedCells.size === 0) {
            alert('Please select some cells first by clicking on them while holding Ctrl.');
            return;
        }

        if (confirm(`Mark ${selectedCells.size} entries as incompatible?`)) {
            // Implement bulk update logic
            console.log('Marking as incompatible:', selectedCells);
        }
    }

    function exportCompatibilityData() {
        // Export compatibility data as CSV
        const compatData = <?= json_encode($compatibility_data) ?>;

        if (compatData.length === 0) {
            alert('No data to export.');
            return;
        }

        // Create CSV content
        const headers = ['Mod Author', 'Mod Name', 'Mod Version', 'Game Version', 'Status', 'Notes', 'Tested Date', 'Tested By'];
        const csvContent = [
            headers.join(','),
            ...compatData.map(row => [
                `"${row.mod_author}"`,
                `"${row.mod_name}"`,
                `"${row.mod_version}"`,
                `"${row.game_version}"`,
                `"${row.status}"`,
                `"${row.notes || ''}"`,
                `"${row.tested_date}"`,
                `"${row.tested_by || ''}"`
            ].join(','))
        ].join('\n');

        // Download CSV
        const blob = new Blob([csvContent], {type: 'text/csv'}
    }

    function toggleDropdown(dropdownId) {
        const dropdown = document.getElementById(dropdownId);
        dropdown.classList.toggle('show');

        // Close other dropdowns
        const allDropdowns = document.querySelectorAll('.dropdown-content');
        allDropdowns.forEach(dd => {
            if (dd.id !== dropdownId) {
                dd.classList.remove('show');
            }
        });
    }

    function filterDropdown(dropdownId, searchTerm) {
        const dropdown = document.getElementById(dropdownId);
        const items = dropdown.querySelectorAll('.dropdown-item');

        if (!dropdown.classList.contains('show')) {
            dropdown.classList.add('show');
        }

        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const search = searchTerm.toLowerCase();

            if (text.includes(search)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function selectDropdownItem(inputId, value, dropdownId) {
        document.getElementById(inputId).value = value;
        document.getElementById(dropdownId).classList.remove('show');

        // If author is selected, filter mod names for that author
        if (inputId === 'mod_author') {
            filterModsByAuthor(value);
        }
    }

    function filterFunction(dropdownId) {
        if (dropdownId === 'authorDropdown') {
            const input = document.getElementById('mod_author');
            filterAuthors(input.value);
        } else if (dropdownId === 'modDropdown') {
            const input = document.getElementById('mod_name');
            filterMods(input.value);
        }
    }

    function filterAuthors(searchTerm) {
        const dropdown = document.getElementById('authorDropdown');
        const items = dropdown.querySelectorAll('.dropdown-item');

        // Show dropdown when typing
        if (!dropdown.classList.contains('show')) {
            dropdown.classList.add('show');
        }

        let visibleCount = 0;
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const search = searchTerm.toLowerCase();

            if (text.includes(search) || searchTerm === '') {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Hide dropdown if no matches and search term is not empty
        if (visibleCount === 0 && searchTerm !== '') {
            dropdown.classList.remove('show');
        }
    }

    function showAuthorDropdown() {
        const dropdown = document.getElementById('authorDropdown');
        const input = document.getElementById('mod_author');

        dropdown.classList.add('show');

        // Reset all items to visible when opening dropdown
        const items = dropdown.querySelectorAll('.dropdown-item');
        items.forEach(item => {
            item.style.display = 'block';
        });

        // If there's text in input, filter immediately
        if (input.value) {
            filterAuthors(input.value);
        }
    }

    function selectAuthor(author) {
        document.getElementById('mod_author').value = author;
        document.getElementById('authorDropdown').classList.remove('show');

        // Filter mods by selected author
        filterModsByAuthor(author);

        // Clear mod name and version when author changes
        document.getElementById('mod_name').value = '';
        document.querySelector('input[name="mod_version"]').value = '';
    }

    function filterModsByAuthor(author) {
        const modDropdown = document.getElementById('modDropdown');
        const modLinks = modDropdown.getElementsByTagName('a');
        const modItems = modDropdown.querySelectorAll('.dropdown-item');
        // Make an AJAX call to get mods for this author
        fetch('api/get_mods_by_author.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                author: author
            })
        })
        .then(response => response.json())
        .then(data => {
            // Clear existing mod dropdown
            modDropdown.innerHTML = '';

            if (data.mods && data.mods.length > 0) {
                data.mods.forEach(mod => {
                    const link = document.createElement('a');
                    link.href = '#';
                    link.className = 'dropdown-item';
                    link.textContent = mod;
                    link.onclick = function() { selectMod(mod); };
                    modDropdown.appendChild(link);
                });
            } else {
                // Fallback to showing all mods if API fails
                for (let i = 0; i < modLinks.length; i++) {
                    modLinks[i].style.display = "";
                }
            }
        })
        .catch(error => {
            console.error('Error filtering mods:', error);
            // Fallback to showing all mods
            for (let i = 0; i < modLinks.length; i++) {
                modLinks[i].style.display = "";
            }
        });
    }

    function filterMods(searchTerm) {
        const dropdown = document.getElementById('modDropdown');
        const items = dropdown.querySelectorAll('.dropdown-item');

        // Show dropdown when typing
        if (!dropdown.classList.contains('show')) {
            dropdown.classList.add('show');
        }

        let visibleCount = 0;
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const search = searchTerm.toLowerCase();

            if (text.includes(search) || searchTerm === '') {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        // Hide dropdown if no matches and search term is not empty
        if (visibleCount === 0 && searchTerm !== '') {
            dropdown.classList.remove('show');
        }
        });

    // Update the mod name input as well for consistency
    function showModDropdown() {
        const dropdown = document.getElementById('modDropdown');
        const input = document.getElementById('mod_name');

        dropdown.classList.add('show');

        // Reset all items to visible when opening dropdown
        const items = dropdown.querySelectorAll('.dropdown-item');
        items.forEach(item => {
            item.style.display = 'block';
        });

        // If there's text in input, filter immediately
        if (input.value) {
            filterMods(input.value);
        }
    }

    // Close dropdowns when clicking outside
    window.onclick = function(event) {
        if (!event.target.matches('.dropdown input')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }
</script>
</body>
</html>