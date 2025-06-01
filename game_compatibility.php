<?php
require_once __DIR__ . "/inc.php";

// Handle status updates if user has permissions
if (hasPermission("modcompatibility")) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid && isset($_POST['update_status'])) {
        $mod_author = $_POST['mod_author'];
        $mod_name = $_POST['mod_name'];
        $game_version = $_POST['game_version'];
        $new_status = $_POST['status'];
        $notes = trim($_POST['notes'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE mod_compatibility 
                SET status = ?, notes = ? 
                WHERE mod_author = ? AND mod_name = ? AND game_version = ?
            ");
            $stmt->execute([$new_status, $notes ?: null, $mod_author, $mod_name, $game_version]);
            $success_message = "Compatibility status updated successfully.";
        } catch (PDOException $e) {
            $error_message = "Error updating status: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_version = $_GET['version'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_author = $_GET['author'] ?? '';
$search_mod = $_GET['search'] ?? '';

// Build the query with filters
$where_conditions = [];
$params = [];

if (!empty($filter_version)) {
    $where_conditions[] = "mc.game_version = ?";
    $params[] = $filter_version;
}

if (!empty($filter_status)) {
    $where_conditions[] = "mc.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_author)) {
    $where_conditions[] = "mc.mod_author = ?";
    $params[] = $filter_author;
}

if (!empty($search_mod)) {
    $where_conditions[] = "(mc.mod_name LIKE ? OR mc.mod_author LIKE ?)";
    $params[] = "%$search_mod%";
    $params[] = "%$search_mod%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get compatibility data
try {
    $stmt = $pdo->prepare("
        SELECT 
            mc.mod_author,
            mc.mod_name,
            mc.game_version,
            mc.status,
            mc.notes,
            m.version as mod_version,
            m.updated as mod_updated
        FROM mod_compatibility mc
        LEFT JOIN mods m ON mc.mod_author = m.author AND mc.mod_name = m.name
        $where_clause
        ORDER BY mc.mod_author, mc.mod_name, mc.game_version DESC
    ");
    $stmt->execute($params);
    $compatibility_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching compatibility data: " . $e->getMessage();
    $compatibility_data = [];
}

// Get available game versions for filter
try {
    $stmt = $pdo->prepare("SELECT DISTINCT version FROM valheim_updates WHERE version IS NOT NULL ORDER BY version DESC");
    $stmt->execute();
    $game_versions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $game_versions = [];
}

// Get available authors for filter
try {
    $stmt = $pdo->prepare("SELECT DISTINCT mod_author FROM mod_compatibility ORDER BY mod_author");
    $stmt->execute();
    $authors = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $authors = [];
}

// Get status options (assuming ENUM)
$status_options = ['compatible', 'incompatible', 'partial', 'untested', 'pending'];

?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Valtools - Game Compatibility</title>
    <style>
        .compatibility-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 1em;
            margin-bottom: 2em;
            padding: 1.5em;
            background: var(--color-background-secondary, #2a2a2a);
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
        }

        .compatibility-filters > div {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 0.5em;
        }

        .compatibility-filters label {
            color: var(--color-text-light, #fff);
            font-weight: 500;
            font-size: 0.9em;
        }

        .compatibility-filters select,
        .compatibility-filters input {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--color-border, #555);
            background: var(--color-background, #1e1e1e);
            color: var(--color-text, #fff);
            font-size: 0.9em;
        }

        .compatibility-filters select:focus,
        .compatibility-filters input:focus {
            outline: none;
            border-color: var(--color-accent, #007acc);
            box-shadow: 0 0 0 2px rgba(0, 122, 204, 0.2);
        }

        .filter-buttons {
            display: flex;
            align-items: end;
            gap: 0.5em;
            flex-shrink: 0;
        }

        .compatibility-table-container {
            background: var(--color-background-secondary, #2a2a2a);
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
            overflow: hidden;
            margin-top: 1em;
        }

        .compatibility-table {
            width: 100%;
            border-collapse: collapse;
            background: transparent;
        }

        .compatibility-table th,
        .compatibility-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--color-border, #444);
            color: var(--color-text, #fff);
        }

        .compatibility-table th {
            background: var(--color-background, #1e1e1e);
            font-weight: 600;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-text-light, #fff);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .compatibility-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .compatibility-table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-compatible {
            background: #28a745;
            color: white;
        }

        .status-incompatible {
            background: #dc3545;
            color: white;
        }

        .status-partial {
            background: #ffc107;
            color: #000;
        }

        .status-untested {
            background: #6c757d;
            color: white;
        }

        .status-pending {
            background: #17a2b8;
            color: white;
        }

        .notes-cell {
            max-width: 250px;
            word-wrap: break-word;
            font-size: 0.9em;
            line-height: 1.4;
        }

        .edit-form {
            display: none;
            background: var(--color-background, #1e1e1e);
            padding: 1.5em;
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
            margin-top: 1em;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            min-width: 400px;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        .edit-form.active {
            display: block;
        }

        .edit-form label {
            display: block;
            margin-bottom: 0.5em;
            color: var(--color-text-light, #fff);
            font-weight: 500;
        }

        .edit-form select,
        .edit-form textarea {
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--color-border, #555);
            background: var(--color-background-secondary, #2a2a2a);
            color: var(--color-text, #fff);
            font-family: inherit;
            resize: vertical;
        }

        .edit-form-buttons {
            display: flex;
            gap: 1em;
            margin-top: 1.5em;
        }

        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }

        .overlay.active {
            display: block;
        }

        .version-badge {
            background: var(--color-accent, #007acc);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5em;
            margin-bottom: 2em;
        }

        .stat-card {
            background: var(--color-background-secondary, #2a2a2a);
            padding: 1.5em;
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
            text-align: center;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--color-accent, #007acc);
            margin-bottom: 0.25em;
        }

        .stat-label {
            color: var(--color-text-muted, #ccc);
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .mod-name {
            font-weight: 600;
            color: var(--color-text-light, #fff);
        }

        .mod-author {
            color: var(--color-text-muted, #ccc);
            font-size: 0.9em;
        }

        .mod-version {
            font-size: 0.85em;
            color: var(--color-text-muted, #ccc);
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

        .success-message {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 1em;
            border-radius: 6px;
            margin-bottom: 1em;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 1em;
            border-radius: 6px;
            margin-bottom: 1em;
        }

        .no-data {
            text-align: center;
            padding: 3em;
            color: var(--color-text-muted, #ccc);
            background: var(--color-background-secondary, #2a2a2a);
            border-radius: 8px;
            border: 1px solid var(--color-border, #444);
        }

        @media (max-width: 768px) {
            .compatibility-filters {
                flex-direction: column;
            }

            .compatibility-filters > div {
                min-width: 100%;
            }

            .stats-summary {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 1em;
            }

            .compatibility-table-container {
                overflow-x: auto;
            }

            .edit-form {
                position: fixed;
                top: 10px;
                left: 10px;
                right: 10px;
                transform: none;
                min-width: auto;
                max-width: none;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . "/topnav.php"; ?>

<main style="padding: 2em; max-width: 1400px; margin: 0 auto;">
    <h1 style="color: var(--color-text-light, #fff); margin-bottom: 1em;">Game Compatibility</h1>

    <?php if (isset($error_message)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <!-- Statistics Summary -->
    <div class="stats-summary">
        <?php
        $stats = [];
        foreach ($compatibility_data as $item) {
            $stats['total'] = ($stats['total'] ?? 0) + 1;
            $stats[$item['status']] = ($stats[$item['status']] ?? 0) + 1;
        }
        ?>
        <div class="stat-card">
            <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Total Entries</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $stats['compatible'] ?? 0 ?></div>
            <div class="stat-label">Compatible</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $stats['incompatible'] ?? 0 ?></div>
            <div class="stat-label">Incompatible</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $stats['untested'] ?? 0 ?></div>
            <div class="stat-label">Untested</div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="compatibility-filters">
        <div>
            <label for="version">Game Version:</label>
            <select name="version" id="version">
                <option value="">All Versions</option>
                <?php foreach ($game_versions as $version): ?>
                    <option value="<?= htmlspecialchars($version) ?>" <?= $filter_version === $version ? 'selected' : '' ?>>
                        <?= htmlspecialchars($version) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="status">Status:</label>
            <select name="status" id="status">
                <option value="">All Statuses</option>
                <?php foreach ($status_options as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                        <?= ucfirst($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="author">Author:</label>
            <select name="author" id="author">
                <option value="">All Authors</option>
                <?php foreach ($authors as $author): ?>
                    <option value="<?= htmlspecialchars($author) ?>" <?= $filter_author === $author ? 'selected' : '' ?>>
                        <?= htmlspecialchars($author) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="search">Search Mods:</label>
            <input type="text" name="search" id="search" value="<?= htmlspecialchars($search_mod) ?>"
                   placeholder="Search mod name or author...">
        </div>

        <div class="filter-buttons">
            <button type="submit" class="button">Filter</button>
            <a href="game_compatibility.php" class="button secondary">Clear</a>
        </div>
    </form>

    <!-- Results -->
    <?php if (!empty($compatibility_data)): ?>
        <div class="compatibility-table-container">
            <table class="compatibility-table">
                <thead>
                    <tr>
                        <th>Author</th>
                        <th>Mod Name</th>
                        <th>Game Version</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Mod Version</th>
	                    <?php if (hasPermission("modcompatibility")): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($compatibility_data as $index => $item): ?>
                        <tr>
                            <td class="mod-author"><?= htmlspecialchars($item['mod_author']) ?></td>
                            <td class="mod-name"><?= htmlspecialchars($item['mod_name']) ?></td>
                            <td>
                                <span class="version-badge"><?= htmlspecialchars($item['game_version']) ?></span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($item['status']) ?>">
                                    <?= htmlspecialchars($item['status']) ?>
                                </span>
                            </td>
                            <td class="notes-cell">
                                <?= $item['notes'] ? htmlspecialchars($item['notes']) : '<em style="color: var(--color-text-muted, #999);">No notes</em>' ?>
                            </td>
                            <td class="mod-version">
                                <?= $item['mod_version'] ? htmlspecialchars($item['mod_version']) : 'N/A' ?>
                                <?php if ($item['mod_updated']): ?>
                                    <br><small style="color: var(--color-text-muted, #999);"><?= date('Y-m-d', $item['mod_updated']) ?></small>
                                <?php endif; ?>
                            </td>
	                        <?php if (hasPermission("modcompatibility")): ?>
                                <td>
                                    <button onclick="toggleEditForm(<?= $index ?>)" class="button" style="font-size: 0.8em;">
                                        Edit
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Forms (Modal Style) -->
	    <?php if (hasPermission("modcompatibility")): ?>
            <div class="overlay" id="overlay" onclick="closeAllEditForms()"></div>
            <?php foreach ($compatibility_data as $index => $item): ?>
                <div id="edit-form-<?= $index ?>" class="edit-form">
                    <h3 style="color: var(--color-text-light, #fff); margin-top: 0;">Edit Compatibility Status</h3>
                    <p style="color: var(--color-text-muted, #ccc); margin-bottom: 1.5em;">
                        <strong><?= htmlspecialchars($item['mod_author']) ?></strong> -
                        <?= htmlspecialchars($item['mod_name']) ?>
                        (<?= htmlspecialchars($item['game_version']) ?>)
                    </p>

                    <form method="POST">
                        <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                        <input type="hidden" name="mod_author" value="<?= htmlspecialchars($item['mod_author']) ?>">
                        <input type="hidden" name="mod_name" value="<?= htmlspecialchars($item['mod_name']) ?>">
                        <input type="hidden" name="game_version" value="<?= htmlspecialchars($item['game_version']) ?>">

                        <div style="margin-bottom: 1em;">
                            <label>Status:</label>
                            <select name="status" required>
                                <?php foreach ($status_options as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>"
                                            <?= $item['status'] === $status ? 'selected' : '' ?>>
                                        <?= ucfirst($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="margin-bottom: 1em;">
                            <label>Notes:</label>
                            <textarea name="notes" rows="4" placeholder="Add any compatibility notes..."><?= htmlspecialchars($item['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="edit-form-buttons">
                            <button type="submit" name="update_status" class="button">Update</button>
                            <button type="button" onclick="toggleEditForm(<?= $index ?>)" class="button secondary">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-data">
            <h3 style="margin-top: 0; color: var(--color-text-muted, #999);">No compatibility data found</h3>
            <p>Try adjusting your filters or check back later for updates.</p>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleEditForm(index) {
    const form = document.getElementById('edit-form-' + index);
    const overlay = document.getElementById('overlay');

    if (form.classList.contains('active')) {
        form.classList.remove('active');
        overlay.classList.remove('active');
    } else {
        // Close any other open forms
        closeAllEditForms();
        // Open this form
        form.classList.add('active');
        overlay.classList.add('active');
    }
}

function closeAllEditForms() {
    const forms = document.querySelectorAll('.edit-form');
    const overlay = document.getElementById('overlay');

    forms.forEach(form => form.classList.remove('active'));
    overlay.classList.remove('active');
}

// Close form on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllEditForms();
    }
});
</script>

</body>
<?php require __DIR__ . '/footer.php'; ?>
</html>