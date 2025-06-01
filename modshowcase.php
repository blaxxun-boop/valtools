<?php
declare(strict_types=1);

// Enable error display for debugging (remove in production)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/inc.php';
// inc.php already calls session_start(), so do not call it again

// First, let's check what columns exist in the mods table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM mods");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Debug: uncomment this line to see what columns exist
    // echo "Mods table columns: " . implode(', ', $columns) . "<br>";

    // Determine the correct column names
    $author_col = 'author';
    $name_col = 'name';

    if (in_array('mod_author', $columns)) {
        $author_col = 'mod_author';
    }
    if (in_array('mod_name', $columns)) {
        $name_col = 'mod_name';
    }

} catch (PDOException $e) {
    die("Error checking table structure: " . htmlspecialchars($e->getMessage()));
}

// Ensure database tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `mod_showcases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mod_author` VARCHAR(255) NOT NULL,
    `mod_name` VARCHAR(255) NOT NULL,
    `author_id` VARCHAR(64) NOT NULL,
    `description_md` TEXT,
    `features_md` TEXT,
    `installation_md` TEXT,
    `compatibility_md` TEXT,
    `changelog_md` TEXT,
    `additional_info_md` TEXT,
    `thunderstore_url` VARCHAR(500),
    `github_url` VARCHAR(500),
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_mod` (`mod_author`, `mod_name`)
) ENGINE=InnoDB CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `showcase_comments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `showcase_id` INT NOT NULL,
        `user_id` VARCHAR(64) NOT NULL,
        `comment` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`showcase_id`) REFERENCES `mod_showcases`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4;");

    // Fix collation mismatch - get the collation of the mods table and apply it to mod_showcases
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mods'");
    $mods_collation = $stmt->fetchColumn();

    if ($mods_collation) {
        // Update mod_showcases table to use the same collation as mods table
        $pdo->exec("ALTER TABLE mod_showcases CONVERT TO CHARACTER SET utf8mb4 COLLATE $mods_collation");
        $pdo->exec("ALTER TABLE showcase_comments CONVERT TO CHARACTER SET utf8mb4 COLLATE $mods_collation");
    }
} catch (PDOException $e) {
    die("Database setup error: " . htmlspecialchars($e->getMessage()));
}

// Load Parsedown if available
$parsedownPath = __DIR__ . '/vendor/parsedown/Parsedown.php';
$useParsedown = false;
if (file_exists($parsedownPath)) {
    require $parsedownPath;
    require __DIR__ . '/vendor/parsedown/ParsedownExtra.php';
    require __DIR__ . '/vendor/parsedown/ParsedownExtended.php';
    $Parsedown = new Parsedown();
    $useParsedown = true;
}

// Get mod info from URL parameters
$mod_author = $_GET['author'] ?? null;
$mod_name = $_GET['name'] ?? null;
$showcase_id = $_GET['id'] ?? null;

// Handle showcase creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid && isset($_POST['save_showcase']) && !empty($_SESSION['discord_user']['id'])) {
    $author_id = $_SESSION['discord_user']['id'];

    // Validate that user can edit this showcase
    if ($mod_author && $mod_name) {
        // Check if user is the mod author or has admin permissions
        $can_edit = false;

        // Get mod info from main mods table to verify authorship
        $stmt = $pdo->prepare("SELECT $author_col FROM mods WHERE $author_col = ? AND $name_col = ?");
        $stmt->execute([$mod_author, $mod_name]);
        $mod_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mod_info) {
            // For now, allow anyone to create showcases - in production you'd want to verify Discord username matches mod author
            $can_edit = true;
        }

        // Admin override
        if (hasPermission("users")) {
            $can_edit = true;
        }

        if ($can_edit) {
            try {
                $stmt = $pdo->prepare("INSERT INTO mod_showcases 
    (mod_author, mod_name, author_id, description_md, features_md, installation_md, compatibility_md, changelog_md, additional_info_md, thunderstore_url, github_url) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
    description_md = VALUES(description_md),
    features_md = VALUES(features_md),
    installation_md = VALUES(installation_md),
    compatibility_md = VALUES(compatibility_md),
    changelog_md = VALUES(changelog_md),
    additional_info_md = VALUES(additional_info_md),
    thunderstore_url = VALUES(thunderstore_url),
    github_url = VALUES(github_url),
    updated_at = CURRENT_TIMESTAMP");

                $stmt->execute([
                    $mod_author,
                    $mod_name,
                    $author_id,
                    trim($_POST['description_md'] ?? ''),
                    trim($_POST['features_md'] ?? ''),
                    trim($_POST['installation_md'] ?? ''),
                    trim($_POST['compatibility_md'] ?? ''),
                    trim($_POST['changelog_md'] ?? ''),
                    trim($_POST['additional_info_md'] ?? ''),
                    trim($_POST['thunderstore_url'] ?? ''),
                    trim($_POST['github_url'] ?? ''),
                ]);

                header("Location: modshowcase.php?author=" . urlencode($mod_author) . "&name=" . urlencode($mod_name) . "&saved=1");
                exit;
            } catch (PDOException $e) {
                $error_message = 'Error saving showcase: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $error_message = 'You do not have permission to edit this mod showcase.';
        }
    }
}

// Handle new comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid && isset($_POST['comment'], $_POST['showcase_id']) && !empty($_SESSION['discord_user']['id'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO showcase_comments (showcase_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->execute([
            intval($_POST['showcase_id']),
            $_SESSION['discord_user']['id'],
            trim($_POST['comment']),
        ]);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } catch (PDOException $e) {
        $error_message = 'Error saving comment: ' . htmlspecialchars($e->getMessage());
    }
}

// Fetch data
if ($mod_author && $mod_name) {
    // Get mod info from main mods table
    $stmt = $pdo->prepare("SELECT * FROM mods WHERE $author_col = ? AND $name_col = ?");
    $stmt->execute([$mod_author, $mod_name]);
    $mod_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mod_info) {
        http_response_code(404);
        die('<p>Mod not found.</p>');
    }

    // Get showcase info
    $stmt = $pdo->prepare("SELECT * FROM mod_showcases WHERE mod_author = ? AND mod_name = ?");
    $stmt->execute([$mod_author, $mod_name]);
    $showcase = $stmt->fetch(PDO::FETCH_ASSOC);

    // Add debug to see what's happening
    /*echo "Looking for: author='$mod_author', name='$mod_name'<br>";
    echo "Found showcase: " . ($showcase ? "YES" : "NO") . "<br>";
    if ($showcase) {
        echo "Showcase ID: " . $showcase['id'] . "<br>";
    }*/


    // Get comments if showcase exists
    $comments = [];
    if ($showcase) {
        $cstmt = $pdo->prepare("SELECT * FROM showcase_comments WHERE showcase_id = ? ORDER BY created_at ASC");
        $cstmt->execute([$showcase['id']]);
        $comments = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Check if current user can edit
    $can_edit = false;
    if (!empty($_SESSION['discord_user']['id'])) {
        // Admin override
        if (hasPermission("users")) {
            $can_edit = true;
        } // For now, allow anyone to create/edit showcases - in production you'd verify authorship
        else {
            $can_edit = true;
        }
    }

    // Get mod info
    $stmt = $pdo->prepare("SELECT * FROM mods WHERE $author_col = ? AND $name_col = ?");
    $stmt->execute([$showcase['mod_author'], $showcase['mod_name']]);
    $mod_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Comments
    $cstmt = $pdo->prepare("SELECT * FROM showcase_comments WHERE showcase_id = ? ORDER BY created_at ASC");
    $cstmt->execute([$showcase_id]);
    $comments = $cstmt->fetchAll(PDO::FETCH_ASSOC);

    $mod_author = $showcase['mod_author'];
    $mod_name = $showcase['mod_name'];
} else {
    // List all showcases - fix collation mismatch in JOIN
    $stmt = $pdo->query("SELECT ms.*, m.version, m.updated FROM mod_showcases ms 
                         LEFT JOIN mods m ON ms.mod_author COLLATE utf8mb4_general_ci = m.$author_col COLLATE utf8mb4_general_ci 
                         AND ms.mod_name COLLATE utf8mb4_general_ci = m.$name_col COLLATE utf8mb4_general_ci 
                         ORDER BY ms.updated_at DESC");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$success_message = isset($_GET['saved']) ? 'Showcase saved successfully!' : '';
?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <title><?php if ($mod_author && $mod_name): ?><?= htmlspecialchars($mod_name) ?> by <?= htmlspecialchars($mod_author) ?> – <?php endif; ?>
        Mod Showcase – Valtools</title>
    <style>
        .showcase-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .mod-header {
            background: var(--color-panel);
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .mod-title {
            font-size: 2.5em;
            margin: 0 0 10px 0;
            color: var(--color-text-light);
        }

        .mod-author {
            font-size: 1.2em;
            color: var(--color-text-muted);
            margin-bottom: 20px;
        }

        .mod-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .mod-version, .mod-updated {
            background: var(--color-bg);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9em;
        }

        .mod-links {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .mod-link {
            background: #007acc;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s;
        }

        .mod-link:hover {
            background: #005a9e;
        }

        .showcase-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        .content-section {
            background: var(--color-panel);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .content-section h2 {
            margin-top: 0;
            color: var(--color-text-light);
            border-bottom: 2px solid var(--color-accent);
            padding-bottom: 10px;
        }

        .edit-form {
            background: var(--color-panel);
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 25px;
        }

        .form-section label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: var(--color-text-light);
        }

        .form-section textarea {
            width: 100%;
            min-height: 150px;
            padding: 12px;
            border: 1px solid #555;
            border-radius: 5px;
            background: var(--color-bg);
            color: var(--color-text);
            font-family: 'Consolas', 'Monaco', monospace;
            resize: vertical;
            box-sizing: border-box;
        }

        .form-section input[type="url"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #555;
            border-radius: 5px;
            background: var(--color-bg);
            color: var(--color-text);
            box-sizing: border-box;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #007acc;
            color: white;
        }

        .btn-primary:hover {
            background: #005a9e;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .showcase-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .showcase-card {
            background: var(--color-panel);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .showcase-card h3 {
            margin-top: 0;
        }

        .showcase-card h3 a {
            color: var(--color-text-light);
            text-decoration: none;
        }

        .showcase-card h3 a:hover {
            color: var(--color-accent);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .comments-section {
            margin-top: 30px;
        }

        .comment {
            background: var(--color-bg);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .comment-meta {
            font-size: 0.9em;
            color: var(--color-text-muted);
            margin-bottom: 10px;
        }

        .comment-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 10px;
            border: 1px solid #555;
            border-radius: 5px;
            background: var(--color-bg);
            color: var(--color-text);
            resize: vertical;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .showcase-container {
                padding: 10px;
            }

            .mod-header {
                padding: 20px;
            }

            .mod-title {
                font-size: 2em;
            }

            .mod-meta {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body class="table-view">
<?php require __DIR__ . '/topnav.php'; ?>
<main>
    <?php if (!$mod_author || !$mod_name): ?>
        <!-- List all showcases -->
        <div class="showcase-container">
            <h1>Mod Showcases</h1>
            <p>Detailed information and guides for popular mods, created by their authors.</p>

            <?php if (!empty($list)): ?>
                <div class="showcase-list">
                    <?php foreach ($list as $item): ?>
                        <div class="showcase-card">
                            <h3>
                                <a href="?author=<?= urlencode($item['mod_author']) ?>&name=<?= urlencode($item['mod_name']) ?>"><?= htmlspecialchars($item['mod_name']) ?></a>
                            </h3>
                            <p class="muted">by <?= htmlspecialchars($item['mod_author']) ?></p>
                            <?php if ($item['version']): ?>
                                <p class="muted">Version: <?= htmlspecialchars($item['version']) ?></p>
                            <?php endif; ?>
                            <p class="muted">Updated: <?= $item['updated_at'] ?></p>
                            <?php if ($item['description_md']): ?>
                                <div>
                                    <?php if ($useParsedown): ?>
                                        <?= $Parsedown->text(substr($item['description_md'], 0, 200) . '...') ?>
                                    <?php else: ?>
                                        <?= nl2br(htmlspecialchars(substr($item['description_md'], 0, 200) . '...')) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="content-section">
                    <p>No mod showcases have been created yet. <a href="index.php">Browse mods</a> and click on a mod to
                        create its showcase page.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Individual mod showcase -->
        <div class="showcase-container">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <a href="index.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Mod List</a>

            <!-- Mod Header -->
            <div class="mod-header">
                <h1 class="mod-title"><?= htmlspecialchars($mod_name) ?></h1>
                <div class="mod-author">by <?= htmlspecialchars($mod_author) ?></div>

                <?php if ($mod_info): ?>
                    <div class="mod-meta">
                        <?php if ($mod_info['version']): ?>
                            <span class="mod-version">Version: <?= htmlspecialchars($mod_info['version']) ?></span>
                        <?php endif; ?>
                        <?php if ($mod_info['updated']): ?>
                            <span class="mod-updated">Updated: <?= date('Y-m-d', $mod_info['updated']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($showcase && ($showcase['thunderstore_url'] || $showcase['github_url'])): ?>
                    <div class="mod-links">
                        <?php if ($showcase['thunderstore_url']): ?>
                            <a href="<?= htmlspecialchars($showcase['thunderstore_url']) ?>" target="_blank"
                               class="mod-link">Thunderstore</a>
                        <?php endif; ?>
                        <?php if ($showcase['github_url']): ?>
                            <a href="<?= htmlspecialchars($showcase['github_url']) ?>" target="_blank" class="mod-link">GitHub</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($can_edit): ?>
                    <div style="margin-top: 20px;">
                        <button onclick="toggleEditForm()" class="btn btn-primary" id="editToggle">
                            <?= $showcase ? 'Edit Showcase' : 'Create Showcase' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Edit Form -->
            <?php if ($can_edit): ?>
                <div id="editForm" class="edit-form" style="display: none;">
                    <h2><?= $showcase ? 'Edit' : 'Create' ?> Showcase</h2>
                    <form method="POST">
                        <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                        <input type="hidden" name="save_showcase" value="1">

                        <div class="form-section">
                            <label for="thunderstore_url">Thunderstore URL:</label>
                            <input type="url" id="thunderstore_url" name="thunderstore_url"
                                   value="<?= htmlspecialchars($showcase['thunderstore_url'] ?? '') ?>"
                                   placeholder="https://thunderstore.io/c/valheim/p/...">
                        </div>

                        <div class="form-section">
                            <label for="github_url">GitHub URL:</label>
                            <input type="url" id="github_url" name="github_url"
                                   value="<?= htmlspecialchars($showcase['github_url'] ?? '') ?>"
                                   placeholder="https://github.com/...">
                        </div>

                        <div class="form-section">
                            <label for="description_md">Description (Markdown):</label>
                            <textarea id="description_md" name="description_md"
                                      placeholder="Brief description of your mod..."><?= htmlspecialchars($showcase['description_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="features_md">Features (Markdown):</label>
                            <textarea id="features_md" name="features_md"
                                      placeholder="List the main features of your mod..."><?= htmlspecialchars($showcase['features_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="installation_md">Installation Instructions (Markdown):</label>
                            <textarea id="installation_md" name="installation_md"
                                      placeholder="How to install your mod..."><?= htmlspecialchars($showcase['installation_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="compatibility_md">Compatibility Notes (Markdown):</label>
                            <textarea id="compatibility_md" name="compatibility_md"
                                      placeholder="Compatibility information, known issues, etc..."><?= htmlspecialchars($showcase['compatibility_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="changelog_md">Changelog (Markdown):</label>
                            <textarea id="changelog_md" name="changelog_md"
                                      placeholder="Recent changes and version history..."><?= htmlspecialchars($showcase['changelog_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-section">
                            <label for="additional_info_md">Additional Information (Markdown):</label>
                            <textarea id="additional_info_md" name="additional_info_md"
                                      placeholder="Any other information about your mod..."><?= htmlspecialchars($showcase['additional_info_md'] ?? '') ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Save Showcase</button>
                            <button type="button" onclick="toggleEditForm()" class="btn btn-secondary">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Showcase Content -->
            <?php if ($showcase): ?>
                <div class="showcase-content">
                    <?php if ($showcase['description_md']): ?>
                        <div class="content-section">
                            <h2>Description</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['description_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['description_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showcase['features_md']): ?>
                        <div class="content-section">
                            <h2>Features</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['features_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['features_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showcase['installation_md']): ?>
                        <div class="content-section">
                            <h2>Installation</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['installation_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['installation_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showcase['compatibility_md']): ?>
                        <div class="content-section">
                            <h2>Compatibility</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['compatibility_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['compatibility_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showcase['changelog_md']): ?>
                        <div class="content-section">
                            <h2>Changelog</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['changelog_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['changelog_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showcase['additional_info_md']): ?>
                        <div class="content-section">
                            <h2>Additional Information</h2>
                            <?php if ($useParsedown): ?>
                                <?= $Parsedown->text($showcase['additional_info_md']) ?>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars($showcase['additional_info_md'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Comments Section -->
                <div class="content-section comments-section">
                    <h2>Comments</h2>
                    <?php if (!empty($comments)): ?>
                        <?php foreach ($comments as $c): ?>
                            <div class="comment">
                                <div class="comment-meta">
                                    <?= htmlspecialchars($c['user_id']) ?> • <?= $c['created_at'] ?>
                                </div>
                                <div class="comment-content">
                                    <?= nl2br(htmlspecialchars($c['comment'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No comments yet.</p>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['discord_user']['id'])): ?>
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                            <input type="hidden" name="showcase_id" value="<?= $showcase['id'] ?>"/>
                            <div class="form-section">
                                <label for="comment">Add a comment:</label>
                                <textarea name="comment" id="comment" placeholder="Your comment..." required
                                          class="comment-form"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Post Comment</button>
                        </form>
                    <?php else: ?>
                        <p><a href="login.php?redirect">Login</a> to post comments.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- No showcase exists yet -->
                <div class="content-section">
                    <h2>No Showcase Created</h2>
                    <p>This mod doesn't have a detailed showcase page yet.</p>
                    <?php if ($can_edit): ?>
                        <p>As the mod author, you can create a showcase page with detailed information, installation
                            instructions, and more!</p>
                        <button onclick="toggleEditForm()" class="btn btn-primary">Create Showcase</button>
                    <?php else: ?>
                        <p>The mod author can create a showcase page with detailed information.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
    function toggleEditForm() {
        const form = document.getElementById('editForm');
        const button = document.getElementById('editToggle');

        if (form.style.display === 'none' || form.style.display === '') {
            form.style.display = 'block';
            button.textContent = 'Cancel Edit';
            form.scrollIntoView({behavior: 'smooth'});
        } else {
            form.style.display = 'none';
            button.textContent = <?= $showcase ? "'Edit Showcase'" : "'Create Showcase'" ?>;
        }
    }

    // Auto-resize textareas
    document.addEventListener('DOMContentLoaded', function () {
        const textareas = document.querySelectorAll('textarea');
        textareas.forEach(textarea => {
            textarea.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });
        });
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>