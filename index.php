<?php

require_once __DIR__ . "/inc.php";

// Handle comment submission
if (hasPermission("addcomments")) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid && isset($_POST['submit_comment'])) {
        $mod_author = $_POST['mod_author'] ?? '';
        $mod_name = $_POST['mod_name'] ?? '';
        $comment = trim($_POST['comment'] ?? '');
        $comment_author = $_SESSION["discord_user"]['username'] ?? $_SESSION['user'] ?? 'Anonymous';
        $comment_author_id = $_SESSION["discord_user"]['id'] ?? $_SESSION['user'] ?? 'Anonymous';

        // New incompatibility fields
        $incompatibility = $_POST['incompatibility'] ?? null;
        $incompatible_mod_author = trim($_POST['incompatible_mod_author'] ?? '');
        $incompatible_mod_name = trim($_POST['incompatible_mod_name'] ?? '');

        // Validate incompatibility data
        if ($incompatibility && (!$incompatible_mod_author || !$incompatible_mod_name)) {
            $error_message = "Please specify both author and mod name for incompatibility.";
        } elseif (!$incompatibility && ($incompatible_mod_author || $incompatible_mod_name)) {
            $error_message = "Please select incompatibility type if specifying incompatible mod.";
        } elseif (!empty($mod_author) && !empty($mod_name) && !empty($comment)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO comments (mod_author, mod_name, comment_author, comment_author_id, comment, incompatible_mod_author, incompatible_mod_name, incompatibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $mod_author,
                    $mod_name,
                    $comment_author,
                    $comment_author_id,
                    $comment,
                    $incompatibility ? $incompatible_mod_author : null,
                    $incompatibility ? $incompatible_mod_name : null,
                    $incompatibility
                ]);
                header("Location: index.php?commented=success");
            }
            catch (PDOException $e) {
                $error_message = "Error submitting comment: " . $e->getMessage();
            }
        }
        else {
            $error_message = "Please fill in all required fields.";
        }
    }
}

// Handle comment moderation actions
if (hasPermission("modcomments")) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $xsrfValid) {
        if (isset($_POST['approve_comment'])) {
            $comment_id = $_POST['comment_id'];
            try {
                $stmt = $pdo->prepare("UPDATE comments SET approved = 1 WHERE id = ?");
                $stmt->execute([$comment_id]);
                $success_message = "Comment approved successfully.";
            }
            catch (PDOException $e) {
                $error_message = "Error approving comment: " . $e->getMessage();
            }
        }

        if (isset($_POST['reject_comment'])) {
            $comment_id = $_POST['comment_id'];
            try {
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->execute([$comment_id]);
                $success_message = "Comment rejected and deleted.";
            }
            catch (PDOException $e) {
                $error_message = "Error rejecting comment: " . $e->getMessage();
            }
        }

        if (isset($_POST['ban_author'])) {
            $comment_author_id = $_POST['comment_author_id'];
            $comment_id = $_POST['comment_id'];
            try {
                // Add to banned users table
                $stmt = $pdo->prepare("INSERT INTO bans (id, banned_by) VALUES (?, ?) ");
                $stmt->execute([$comment_author_id, $_SESSION["discord_user"]["id"]]);

                // Delete the comment
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->execute([$comment_id]);

                $success_message = "User banned and comment deleted.";
            }
            catch (PDOException $e) {
                $error_message = "Error banning user: " . $e->getMessage();
            }
        }

        if (isset($_POST['delete_comment'])) {
            $comment_id = $_POST['comment_id'];
            try {
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->execute([$comment_id]);
                $success_message = "Comment deleted successfully.";
            }
            catch (PDOException $e) {
                $error_message = "Error deleting comment: " . $e->getMessage();
            }
        }

    }
}

// API endpoint for mod autocomplete
if (isset($_GET['api']) && $_GET['api'] === 'mods' && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $query = '%' . $_GET['q'] . '%';
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT author, name FROM mods WHERE CONCAT(author, ' - ', name) LIKE ? ORDER BY author, name LIMIT 20");
        $stmt->execute([$query]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

if (($_GET["commented"] ?? "") == "success") {
    $success_message = "Comment submitted successfully and is pending approval.";
}

// Get the latest game version for compatibility checking
$latest_game_version = null;
try {
    $stmt = $pdo->prepare("SELECT version FROM valheim_updates WHERE version IS NOT NULL AND version != '' ORDER BY published_at DESC LIMIT 1");
    $stmt->execute();
    $latest_game_version = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Fallback - continue without compatibility info
}

// Check if we should show only pending comments
$show_pending_only = isset($_GET['pending_only']) && $_GET['pending_only'] === '1' && hasPermission("modcomments");

// Fetch mods data with latest approved comment or pending comments AND compatibility info
$mods = [];
try {
    if ($show_pending_only) {
        // Only show mods with pending comments, but group them
        $stmt = $pdo->prepare("
		SELECT 
			m.author, 
			m.name, 
			m.version, 
			m.updated,
			m.deprecated,
			m.packageurl,
			m.icon,
			m.author_modpage,
			mc.status as compatibility_status,
			mc.notes as compatibility_notes,
			mc.tested_date as compatibility_tested_date,
			GROUP_CONCAT(
				CONCAT(
					c.id, '\t',
					COALESCE(c.comment, ''), '\t',
					COALESCE(c.comment_time, ''), '\t',
					c.approved, '\t',
					COALESCE(c.comment_author, ''), '\t',
					COALESCE(c.comment_author_id, ''), '\t',
					COALESCE(c.comment_author_avatar, ''), '\t',
					COALESCE(c.incompatible_mod_author, ''), '\t',
					COALESCE(c.incompatible_mod_name, ''), '\t',
					COALESCE(c.incompatibility, '')
				) 
				ORDER BY c.comment_time DESC 
				SEPARATOR '\x7F'
			) as comments_data
		FROM mods m
		INNER JOIN comments c ON m.author = c.mod_author AND m.name = c.mod_name
		LEFT JOIN mod_compatibility mc ON m.author = mc.mod_author 
		    AND m.name = mc.mod_name 
		    AND mc.game_version = ?
		WHERE c.approved = 0
		GROUP BY m.author, m.name, m.version, m.updated, mc.status, mc.notes, mc.tested_date
		ORDER BY MAX(c.comment_time) DESC
	");
        $stmt->execute([$latest_game_version]);
    }
    else {
        // All mods with their comments grouped AND compatibility info
        $stmt = $pdo->prepare("
		SELECT 
			m.author, 
			m.name, 
			m.version, 
			m.updated,
			m.deprecated,
			m.packageurl,
			m.icon,
			m.author_modpage,
			mc.status as compatibility_status,
			mc.notes as compatibility_notes,
			mc.tested_date as compatibility_tested_date,
			GROUP_CONCAT(
				CONCAT(
					COALESCE(c.id, ''), '\t',
					COALESCE(c.comment_time, ''), '\t',
					COALESCE(c.approved, '0'), '\t',
					COALESCE(c.comment_author, ''), '\t',
					COALESCE(c.comment_author_id, ''), '\t',
					COALESCE(c.comment_author_avatar, ''), '\t',
					COALESCE(c.comment, ''), '\t',
					COALESCE(c.incompatible_mod_author, ''), '\t',
					COALESCE(c.incompatible_mod_name, ''), '\t',
					COALESCE(c.incompatibility, '')
				) 
				ORDER BY c.comment_time DESC 
				SEPARATOR '\x7F'
			) as comments_data
		FROM mods m
		LEFT JOIN comments c ON m.author = c.mod_author AND m.name = c.mod_name
		LEFT JOIN mod_compatibility mc ON m.author = mc.mod_author 
		    AND m.name = mc.mod_name 
		    AND mc.game_version = ?
		GROUP BY m.author, m.name, m.version, m.updated, mc.status, mc.notes, mc.tested_date
		ORDER BY m.updated DESC
	");
        $stmt->execute([$latest_game_version]);
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process the grouped comments
    $mods = [];
    foreach ($results as $row) {
        $mod = [
            'author' => $row['author'],
            'name' => $row['name'],
            'version' => $row['version'],
            'updated' => $row['updated'],
            'comments' => [],
            'deprecated' => $row['deprecated'],
            'packageurl' => $row['packageurl'],
            'icon' => $row['icon'],
            'author_modpage' => $row['author_modpage'],
            'compatibility_status' => $row['compatibility_status'],
            'compatibility_notes' => $row['compatibility_notes'],
            'compatibility_tested_date' => $row['compatibility_tested_date'],
        ];

        if (!empty($row['comments_data'])) {
            $comments_groups = explode("\x7F", $row['comments_data']);
            foreach ($comments_groups as $comment_data) {
                $parts = explode("\t", $comment_data, 10);
                if (!empty($parts[0])) {
                    $mod['comments'][] = [
                        'id' => $parts[0],
                        'comment_time' => $parts[1] ? (int) $parts[1] : null,
                        'approved' => (bool) $parts[2],
                        'comment_author' => $parts[3],
                        'comment_author_id' => $parts[4],
                        'comment_author_avatar' => $parts[5],
                        'comment' => $parts[6],
                        'incompatible_mod_author' => $parts[7] ?? '',
                        'incompatible_mod_name' => $parts[8] ?? '',
                        'incompatibility' => $parts[9] ?? '',
                    ];
                }
            }
        }

        $mods[] = $mod;
    }
}
catch (PDOException $e) {
    $error_message = "Error fetching mods: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Valtools</title>

    <!-- Preload first few mod icons for faster initial render -->
    <?php
    $firstMods = array_slice($mods, 0, 6); // Preload first 6 mod icons
    foreach ($firstMods as $mod):
        if (!empty($mod['icon'])): ?>
            <link rel="preload" as="image" href="<?= htmlspecialchars($mod['icon']) ?>">
        <?php endif;
    endforeach; ?>

    <style>
        .compatibility-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
            text-align: center;
            min-width: 80px;
        }

        .compatibility-compatible {
            background-color: #28a745;
        }

        .compatibility-incompatible {
            background-color: #dc3545;
        }

        .compatibility-partial {
            background-color: #ffc107;
            color: #000;
        }

        .compatibility-untested {
            background-color: #6c757d;
        }

        .compatibility-pending {
            background-color: #17a2b8;
        }

        .compatibility-unknown {
            background-color: #6c757d;
        }

        .compatibility-info {
            font-size: 0.7em;
            color: #999;
            margin-top: 2px;
            display: block;
        }

        .compatibility-notes {
            font-size: 0.7em;
            color: #ccc;
            font-style: italic;
            margin-top: 2px;
            max-width: 200px;
            word-wrap: break-word;
        }

        .icon {
            background: #333;
            border-radius: 3px;
            transition: opacity 0.3s ease;
        }

        .icon[data-src] {
            opacity: 0.7;
            background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50"><rect width="50" height="50" fill="%23333"/><text x="25" y="30" text-anchor="middle" fill="%23666" font-size="12">📦</text></svg>');
            background-size: cover;
        }

        .icon.loaded {
            opacity: 1;
        }
    </style>
</head>
<body class="table-view">
<?php require __DIR__ . "/topnav.php"; ?>
<script>
    // Global variables for performance
    let modsCache = [];
    let isInitialized = false;

    function toggleCommentForm(previous) {
        const form = document.comment_form;
        if (form.style.display === 'none' || form.style.display === '' || form.previousElementSibling != previous) {
            form.style.display = 'block';
        } else {
            form.style.display = 'none';
        }

        // Get the table row
        const row = previous.closest('tr');

        // Extract author from first cell - handle the link
        const authorCell = row.children[0];
        const authorLink = authorCell.querySelector('a');
        const authorText = authorLink ? authorLink.textContent.trim() : authorCell.textContent.trim();

        // Extract mod name from second cell - handle the complex structure
        const modNameCell = row.children[1];
        const modNameLink = modNameCell.querySelector('a');
        let modNameText = '';

        if (modNameLink) {
            // Get the text content and clean it up
            const fullText = modNameLink.textContent.trim();
            // The mod name should be the text content after removing extra whitespace
            modNameText = fullText.replace(/\s+/g, ' ').trim();
        } else {
            modNameText = modNameCell.textContent.trim();
        }

        form.mod_author.value = authorText;
        form.mod_name.value = modNameText;
        previous.after(form);
    }

    function toggleView() {
        const body = document.body;
        const button = document.getElementById('viewLabel');

        const isTable = body.classList.toggle('table-view');
        body.classList.toggle('card-view', !isTable);

        button.textContent = isTable ? 'Switch to Card View' : 'Switch to Table View';
    }

    // Initialize view toggle button
    (function () {
        const toggle = document.createElement('button');
        toggle.textContent = 'Switch to Card View';
        toggle.id = 'viewLabel';
        toggle.className = 'view-toggle-button';
        toggle.onclick = toggleView;

        const nav = document.querySelector("nav");
        if (nav && nav.lastElementChild && nav.lastElementChild.previousElementSibling) {
            nav.lastElementChild.previousElementSibling.after(toggle);
        }
    })();

    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Initialize mod data cache
    function initializeModsCache() {
        if (isInitialized) return;

        const rows = document.querySelectorAll("#tableView tbody tr");
        modsCache = Array.from(rows).map(row => {
            const modNameCell = row.children[1];
            const modAuthorCell = row.children[0];
            const compatibilityCell = row.children[4];

            // Extract text content properly
            const modName = modNameCell ? modNameCell.textContent.trim().toLowerCase() : '';
            const modAuthor = modAuthorCell ? modAuthorCell.textContent.trim().toLowerCase() : '';

            // Get compatibility status
            let modStatus = 'unknown';
            if (compatibilityCell) {
                const statusElement = compatibilityCell.querySelector('.compatibility-status');
                if (statusElement) {
                    if (statusElement.classList.contains('compatibility-compatible')) modStatus = 'compatible';
                    else if (statusElement.classList.contains('compatibility-incompatible')) modStatus = 'incompatible';
                    else if (statusElement.classList.contains('compatibility-partial')) modStatus = 'partial';
                    else if (statusElement.classList.contains('compatibility-untested')) modStatus = 'untested';
                    else if (statusElement.classList.contains('compatibility-pending')) modStatus = 'pending';
                }
            }

            // Check if deprecated
            const deprecatedBadge = row.querySelector(".incompatibility-badge");
            const isDeprecated = deprecatedBadge && deprecatedBadge.textContent.trim() === "Deprecated";

            return {
                row: row,
                modName: modName,
                modAuthor: modAuthor,
                modStatus: modStatus,
                isDeprecated: isDeprecated
            };
        });

        isInitialized = true;
    }

    // Main filter function
    function filterMods() {
        if (!isInitialized) {
            initializeModsCache();
        }

        const searchInput = document.getElementById("modSearch");
        const statusSelect = document.getElementById("filterCompatibility");
        const authorSelect = document.getElementById("filterAuthor");
        const deprecatedCheckbox = document.getElementById("showDeprecatedFilter");

        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const status = statusSelect ? statusSelect.value : '';
        const author = authorSelect ? authorSelect.value.toLowerCase() : '';
        const showDeprecated = deprecatedCheckbox ? deprecatedCheckbox.checked : false;

        modsCache.forEach(mod => {
            const matchesSearch = !query || mod.modName.includes(query) || mod.modAuthor.includes(query);
            const matchesStatus = !status || mod.modStatus === status;
            const matchesAuthor = !author || mod.modAuthor.includes(author);
            const matchesDeprecated = showDeprecated || !mod.isDeprecated;

            const shouldShow = matchesSearch && matchesStatus && matchesAuthor && matchesDeprecated;
            mod.row.style.display = shouldShow ? '' : 'none';
        });
    }

    // Debounced version for search input
    const debouncedFilter = debounce(filterMods, 200);

    function togglePendingFilter(checked) {
        const url = new URL(window.location);
        if (checked) {
            url.searchParams.set('pending_only', '1');
        } else {
            url.searchParams.delete('pending_only');
        }
        window.location.href = url.toString();
    }

    function toggleDeprecatedFilter(checked) {
        filterMods(); // Just re-run the main filter
    }

    // Autocomplete functionality
    function setupAutocomplete(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;

        const container = input.parentElement;
        let suggestionsDiv = container.querySelector('.autocomplete-suggestions');

        if (!suggestionsDiv) {
            suggestionsDiv = document.createElement('div');
            suggestionsDiv.className = 'autocomplete-suggestions';
            suggestionsDiv.style.cssText = `
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #1e1e1e;
                border: 1px solid #555;
                border-radius: 3px;
                max-height: 200px;
                overflow-y: auto;
                z-index: 1000;
                display: none;
            `;
            container.style.position = 'relative';
            container.appendChild(suggestionsDiv);
        }

        let selectedIndex = -1;

        const debouncedAutocomplete = debounce(function(query) {
            if (query.length < 2) {
                suggestionsDiv.style.display = 'none';
                return;
            }

            fetch(`?api=mods&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    suggestionsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach((mod) => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-suggestion';
                            div.style.cssText = `
                                padding: 8px 12px;
                                cursor: pointer;
                                color: #fff;
                                border-bottom: 1px solid #333;
                            `;
                            div.textContent = `${mod.author} - ${mod.name}`;
                            div.addEventListener('click', function() {
                                selectMod(mod);
                                suggestionsDiv.style.display = 'none';
                            });
                            div.addEventListener('mouseenter', function() {
                                this.style.backgroundColor = '#333';
                            });
                            div.addEventListener('mouseleave', function() {
                                this.style.backgroundColor = '';
                            });
                            suggestionsDiv.appendChild(div);
                        });
                        suggestionsDiv.style.display = 'block';
                        selectedIndex = -1;
                    } else {
                        suggestionsDiv.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Autocomplete error:', error);
                    suggestionsDiv.style.display = 'none';
                });
        }, 300);

        input.addEventListener('input', function() {
            debouncedAutocomplete(this.value.trim());
        });

        input.addEventListener('keydown', function(e) {
            const suggestions = suggestionsDiv.querySelectorAll('.autocomplete-suggestion');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateSelection(suggestions);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(suggestions);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                suggestions[selectedIndex].click();
            } else if (e.key === 'Escape') {
                suggestionsDiv.style.display = 'none';
                selectedIndex = -1;
            }
        });

        function updateSelection(suggestions) {
            suggestions.forEach((s, i) => {
                if (i === selectedIndex) {
                    s.style.backgroundColor = '#007acc';
                } else {
                    s.style.backgroundColor = '';
                }
            });
        }

        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                suggestionsDiv.style.display = 'none';
            }
        });
    }

    function selectMod(mod) {
        const searchInput = document.getElementById('incompatible_mod_search');
        const authorInput = document.getElementById('incompatible_mod_author');
        const nameInput = document.getElementById('incompatible_mod_name');

        if (searchInput) searchInput.value = `${mod.author} - ${mod.name}`;
        if (authorInput) authorInput.value = mod.author;
        if (nameInput) nameInput.value = mod.name;
    }

    function toggleIncompatibilitySection() {
        const checkbox = document.getElementById('has_incompatibility');
        const section = document.getElementById('incompatibility_section');

        if (!checkbox || !section) return;

        section.style.display = checkbox.checked ? 'block' : 'none';

        if (!checkbox.checked) {
            const form = section.closest('form');
            if (form) {
                const incompatibilityRadios = form.querySelectorAll('input[name="incompatibility"]');
                incompatibilityRadios.forEach(radio => radio.checked = false);

                const authorField = form.querySelector('input[name="incompatible_mod_author"]');
                const nameField = form.querySelector('input[name="incompatible_mod_name"]');
                const searchField = form.querySelector('input[id*="incompatible_mod_search"]');

                if (authorField) authorField.value = '';
                if (nameField) nameField.value = '';
                if (searchField) searchField.value = '';
            }
        }
    }

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize the mod cache
        initializeModsCache();

        // Set up search input
        const modSearch = document.getElementById("modSearch");
        if (modSearch) {
            modSearch.addEventListener("input", debouncedFilter);
        }

        // Set up compatibility filter
        const filterCompatibility = document.getElementById("filterCompatibility");
        if (filterCompatibility) {
            filterCompatibility.addEventListener("change", filterMods);
        }

        // Set up author filter
        const filterAuthor = document.getElementById("filterAuthor");
        if (filterAuthor) {
            filterAuthor.addEventListener("change", filterMods);
        }

        // Set up deprecated filter
        const showDeprecatedFilter = document.getElementById("showDeprecatedFilter");
        if (showDeprecatedFilter) {
            showDeprecatedFilter.addEventListener("change", function() {
                toggleDeprecatedFilter(this.checked);
            });
            // Initialize with deprecated hidden
            toggleDeprecatedFilter(false);
        }

        // Author search dropdown functionality
        const authorSearch = document.getElementById("authorSearch");
        const authorDropdown = document.getElementById("authorDropdown");

        if (authorSearch && authorDropdown) {
            const debouncedAuthorFilter = debounce(function(query) {
                const options = authorDropdown.querySelectorAll(".author-option");
                let hasVisible = false;

                options.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    const matches = text.includes(query.toLowerCase());
                    option.style.display = matches ? "block" : "none";
                    if (matches) hasVisible = true;
                });

                authorDropdown.style.display = hasVisible ? "block" : "none";
            }, 150);

            authorSearch.addEventListener("input", function() {
                const query = this.value.trim();

                // If input is cleared, reset the author filter
                if (query === '') {
                    if (filterAuthor) filterAuthor.value = '';
                    this.placeholder = "Search authors...";
                    authorDropdown.style.display = "none";
                    filterMods(); // Apply the filter change immediately
                } else {
                    debouncedAuthorFilter(query);
                }
            });

            // Handle backspace and delete keys specifically
            authorSearch.addEventListener("keydown", function(e) {
                if ((e.key === 'Backspace' || e.key === 'Delete') && this.value.length <= 1) {
                    // Will be empty after this keystroke
                    setTimeout(() => {
                        if (this.value.trim() === '') {
                            if (filterAuthor) filterAuthor.value = '';
                            this.placeholder = "Search authors...";
                            authorDropdown.style.display = "none";
                            filterMods();
                        }
                    }, 0);
                }
            });

            authorSearch.addEventListener("focus", function() {
                if (this.value.trim() !== '') {
                    authorDropdown.style.display = "block";
                }
            });

            authorDropdown.addEventListener("click", function(e) {
                if (e.target.classList.contains("author-option")) {
                    const value = e.target.dataset.value;
                    const text = e.target.textContent;

                    authorSearch.value = value ? text : "";
                    authorSearch.placeholder = value ? text : "Search authors...";
                    if (filterAuthor) filterAuthor.value = value;
                    authorDropdown.style.display = "none";

                    filterMods();
                }
            });

            // Handle clicking outside to close dropdown
            document.addEventListener("click", function(e) {
                if (!e.target.closest(".author-filter-container")) {
                    authorDropdown.style.display = "none";
                }
            });

            // Handle Escape key to clear and close
            authorSearch.addEventListener("keydown", function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    if (filterAuthor) filterAuthor.value = '';
                    this.placeholder = "Search authors...";
                    authorDropdown.style.display = "none";
                    filterMods();
                    this.blur(); // Remove focus
                }
            });
        }

        // Enhanced lazy loading for avatars and mod icons
        // Load images that are immediately visible without waiting for intersection observer
        document.addEventListener('DOMContentLoaded', function() {
            // Load images in the first few rows immediately
            const immediateImages = document.querySelectorAll('#tableView tbody tr:nth-child(-n+5) img[data-src]');
            immediateImages.forEach(img => {
                const newImg = new Image();
                newImg.onload = function() {
                    img.src = img.dataset.src;
                    img.classList.add('loaded');
                    img.removeAttribute('data-src');
                };
                newImg.src = img.dataset.src;
            });

            // Then set up lazy loading for the rest
            const images = document.querySelectorAll('img[data-src]');
            if (images.length > 0) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;

                            // Create a new image to preload
                            const newImg = new Image();
                            newImg.onload = function() {
                                img.src = img.dataset.src;
                                img.classList.add('loaded');
                                img.removeAttribute('data-src');
                                observer.unobserve(img);
                            };
                            newImg.onerror = function() {
                                // Fallback for broken images
                                img.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50"><rect width="50" height="50" fill="%23333"/><text x="25" y="30" text-anchor="middle" fill="%23666" font-size="12">❌</text></svg>';
                                img.classList.add('loaded');
                                img.removeAttribute('data-src');
                                observer.unobserve(img);
                            };
                            newImg.src = img.dataset.src;
                        }
                    });
                }, {
                    rootMargin: '100px', // Start loading 100px before image comes into view
                    threshold: 0.1
                });

                images.forEach(img => imageObserver.observe(img));
            }
        });
    });
</script>
<main>
    <form method="POST" name="comment_form" class="comment-form">
        <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
        <input type="hidden" name="mod_author" value="">
        <input type="hidden" name="mod_name" value="">
        <textarea name="comment" placeholder="Enter your comment for this mod.
Please only report objective things, not your personal opinion of this mod." required></textarea>

        <div style="margin-top: 10px;">
            <label>
                <input type="checkbox" id="has_incompatibility"
                       onchange="toggleIncompatibilitySection()">
                Report incompatibility with another mod
            </label>
        </div>

        <div id="incompatibility_section" style="display: none; margin-top: 10px; padding: 10px; background: #2a2a2a; border-radius: 5px;">
            <div style="margin-bottom: 10px;">
                <label>Incompatibility type:</label><br>
                <label><input type="radio" name="incompatibility" value="Full"> Full incompatibility</label>
                <label><input type="radio" name="incompatibility" value="Partial"> Partial incompatibility</label>
            </div>

            <div class="autocomplete-container">
                <input type="text" id="incompatible_mod_search"
                       placeholder="Search for incompatible mod..."
                       style="width: 100%; padding: 6px; background: #1e1e1e; color: #fff; border: 1px solid #555; border-radius: 3px;"
                       onfocus="setupAutocomplete('incompatible_mod_search')">
            </div>

            <input type="hidden" name="incompatible_mod_author" id="incompatible_mod_author">
            <input type="hidden" name="incompatible_mod_name" id="incompatible_mod_name">
        </div>

        <div style="margin-top: 10px;">
            <button type="submit" name="submit_comment">Submit Comment</button>
            <button type="button" onclick="document.comment_form.style.display = 'none'">Cancel</button>
        </div>
    </form>
    <div id="mod-filters" style="display: flex; flex-wrap: wrap; gap: 1em; align-items: center; margin-bottom: 1em;">
        <h1>Mods</h1>
        <input type="text" id="modSearch" placeholder="Search mods..."
               style="padding: 6px 10px; border-radius: 6px; border: 1px solid #555; background: #1e1e1e; color: #fff; flex: 1; max-width: 300px;">

        <select id="filterCompatibility"
                style="padding: 6px 10px; border-radius: 6px; border: 1px solid #555; background: #1e1e1e; color: #fff;">
            <option value="">All Statuses</option>
            <option value="compatible">Compatible</option>
            <option value="incompatible">Incompatible</option>
            <option value="partial">Partial/Issues</option>
            <option value="untested">Untested</option>
            <option value="pending">Pending</option>
            <option value="unknown">Unknown</option>
        </select>

        <div class="author-filter-container">
            <input type="text" id="authorSearch" placeholder="Search authors..."
                   style="padding: 6px 10px; border-radius: 6px; border: 1px solid #555; background: #1e1e1e; color: #fff; width: 250px;">
            <select id="filterAuthor"
                    style="padding: 6px 10px; border-radius: 6px; border: 1px solid #555; background: #1e1e1e; color: #fff; display: none;">
                <option value="">All Authors</option>
                <?php
                // Extract unique authors for dropdown
                $authors = array_unique(array_column($mods, 'author'));
                sort($authors, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($authors as $author): ?>
                    <option value="<?= htmlspecialchars($author) ?>"><?= htmlspecialchars($author) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="authorDropdown" class="author-dropdown" style="display: none;">
                <div class="author-option" data-value="">All Authors</div>
                <?php foreach ($authors as $author): ?>
                    <div class="author-option" data-value="<?= htmlspecialchars($author) ?>"><?= htmlspecialchars($author) ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pending Comments Filter -->
        <?php if (hasPermission("modcomments")): ?>
            <label style="display: flex; align-items: center; gap: 0.5em; color: #fff;">
                <input type="checkbox" id="pendingOnlyFilter" <?= $show_pending_only ? 'checked' : '' ?>
                       onchange="togglePendingFilter(this.checked)"
                       style="accent-color: #007acc;">
                Show Pending Comments Only
            </label>
        <?php endif; ?>

        <!-- Show Deprecated Mods Filter -->
        <label style="display: flex; align-items: center; gap: 0.5em; color: #fff;">
            <input type="checkbox" id="showDeprecatedFilter"
                   onchange="toggleDeprecatedFilter(this.checked)"
                   style="accent-color: #007acc;">
            Show Deprecated Mods
        </label>

    </div>

    <?php if (isset($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="success"
             style="color: green; margin: 10px 0; padding: 10px; background: #e8f5e8; border-radius: 5px;">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($mods)): ?>
        <style>
            .card-view #tableView table {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
                gap: 1.5em;
                max-width: 100%;
                padding: 2em;
                box-sizing: border-box;
                overflow-x: hidden;
            }
            .card-view #tableView thead {
                display: none;
            }
            .card-view #tableView tbody {
                display: contents;
            }
            .card-view #tableView tr {
                background-color: var(--color-panel);
                border-radius: var(--border-radius);
                padding: 1.5em;
                box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
                display: flex;
                flex-direction: row;
                justify-content: space-between;
                transition: transform 0.2s ease;
                flex-wrap: wrap;
                align-content: baseline;
            }

            .card-view #tableView td {
                display: block;
                border: none;
                align-self: baseline;
                padding: 0;
            }

            .card-view #tableView td:nth-child(1) {
                order: 3;
                flex-basis: 100%;
                line-height: 0;
                margin-bottom: 2em;
                color: var(--color-text-muted);
            }
            .card-view #tableView td:nth-child(1)::before {
                content: "by ";
            }
            .card-view #tableView td:nth-child(2) {
                order: 1;
                margin-top: 0;
                font-size: 1.4em;
                color: var(--color-text-light);
            }
            .card-view #tableView td:nth-child(3) {
                order: 2;
                font-size: 0.9em;
                color: var(--color-text-muted);
                margin-bottom: 0.5em;
                flex-grow: 1;
                margin-left: 0.3em;
            }
            .card-view #tableView td:nth-child(3)::before {
                content: "(v";
            }
            .card-view #tableView td:nth-child(3)::after {
                content: ")";
            }
            .card-view #tableView td:nth-child(4) {
                display: none;
            }
            .card-view #tableView td:nth-child(5) {
                order: 5;
                flex-basis: 100%;
            }
            .card-view #tableView td:nth-child(5):empty::after {
                content: "No compatibility information";
                font-style: italic;
            }
            .card-view #tableView td:nth-child(5)::before {
                display: block;
                content: "Compatibility Status";
                font-size: 1.2em;
                font-weight: bold;
            }
            .card-view #tableView td:nth-child(6) {
                order: 6;
                flex-basis: 100%;
            }
            .card-view .comment-toggle {
                margin-left: auto;
                display: block;
            }
            .card-view .all-comments:empty::after {
                content: "No known issues reported.";
                font-style: italic;
            }
            .card-view .all-comments::before {
                display: block;
                content: "Notes";
                font-size: 1.2em;
                font-weight: bold;
            }
            .card-view table tr:nth-of-type(2n) td {
                background-color: inherit;
            }
            .hidden-mod {
                transform: scale(0);
                opacity: 0;
                pointer-events: none;
            }
        </style>

        <!-- Table View -->
        <div id="tableView">
            <table>
                <thead>
                <tr>
                    <th>
                        <a href="#" class="sortlink">Author</a>
                    </th>
                    <th>
                        <a href="#" class="sortlink">Mod Name</a>
                    </th>
                    <th>
                        <a href="#" class="sortlink">Version</a>
                    </th>
                    <th>
                        <a href="#" class="sortlink">Last Updated</a>
                    </th>
                    <th>Compatibility <?= $latest_game_version ? '(' . htmlspecialchars($latest_game_version) . ')' : '' ?></th>
                    <th>Comments</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mods as $index => $mod): ?>
                    <tr>
                        <td class="mod_author"><a href="<?=  $mod['author_modpage'] ?>" target="_blank"><?= htmlspecialchars($mod['author']) ?></a></td>
                        <td class="mod_name">
                            <a href="<?= $mod['packageurl'] ?>" target="_blank"><img src="<?= $mod['icon'] ?>" alt="Icon" class="icon" width="50" height="50"> <br><?= htmlspecialchars($mod['name']) ?></a>
                            <?php if ($mod['deprecated'] == 1): ?>
                                <span class="incompatibility-badge incompatibility-full">Deprecated</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($mod['version'] ?? 'N/A') ?></td>
                        <td><?= $mod['updated'] ? date('Y-m-d H:i', $mod['updated']) : 'N/A' ?></td>
                        <td>
                            <?php if ($mod['compatibility_status']): ?>
                                <span class="compatibility-status compatibility-<?= htmlspecialchars($mod['compatibility_status']) ?>"
                                      title="<?= htmlspecialchars($mod['compatibility_status']) ?><?= $mod['compatibility_notes'] ? ': ' . htmlspecialchars($mod['compatibility_notes']) : '' ?>">
                                    <?= ucfirst(htmlspecialchars($mod['compatibility_status'])) ?>
                                </span>
                                <?php if ($mod['compatibility_tested_date']): ?>
                                    <span class="compatibility-info">
                                        Tested: <?= date('M j, Y', strtotime($mod['compatibility_tested_date'])) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($mod['compatibility_notes'] && !str_contains($mod['compatibility_notes'], 'Auto-populated')): ?>
                                    <div class="compatibility-notes">
                                        <?= htmlspecialchars($mod['compatibility_notes']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="compatibility-status compatibility-unknown" title="No compatibility data available">
                                    Unknown
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="all-comments"><?php foreach ($mod['comments'] as $comment): ?>
                                    <div class="<?= $comment['approved'] ? 'comment-approved' : 'comment-pending' ?>">
                            <span class="comment-text">
                                <?= htmlspecialchars(substr($comment['comment'], 0, 100)) ?>
                                <?= !$comment['approved'] ? '<em>(Pending)</em>' : '' ?>
                            </span>
                                        <?php if (hasPermission("modcomments")): ?>
                                            <form method="POST" style="display: inline; margin-left: 10px;">
                                                <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                                <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                <button type="submit" name="delete_comment"
                                                        style="background: none; border: none; color: #dc3545; cursor: pointer; font-size: 16px; padding: 0;"
                                                        onclick="return confirm('Are you sure you want to delete this comment?')"
                                                        title="Delete comment">×
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($comment['incompatibility']): ?>
                                            <div class="incompatibility-info">
                                    <span class="incompatibility-badge incompatibility-<?= strtolower($comment['incompatibility']) ?>">
                                        <?= htmlspecialchars($comment['incompatibility']) ?> Incompatibility
                                    </span>
                                                <br>
                                                <small>with <?= htmlspecialchars($comment['incompatible_mod_author']) ?> - <?= htmlspecialchars($comment['incompatible_mod_name']) ?></small>
                                            </div>
                                        <?php endif; ?>

                                        <?= $comment['comment_time'] ? date('Y-m-d H:i', $comment['comment_time']) : 'N/A' ?>
                                        <?php if ($comment['comment_author']): ?>
                                            <br>
                                            <small>by <?= htmlspecialchars($comment['comment_author']) ?></small>
                                            <?php if ($comment['comment_author_avatar']): ?>
                                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20'%3E%3C/svg%3E"
                                                     data-src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($comment['comment_author_id']) ?>/<?= htmlspecialchars($comment['comment_author_avatar']) ?>.png"
                                                     alt="avatar"
                                                     loading="lazy"
                                                     style="width:20px; height:20px; border-radius:50%; object-fit: cover;">
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($show_pending_only && !$comment['approved']): ?>
                                            <!-- Moderation actions for pending comments -->
                                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" name="approve_comment"
                                                            style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;">
                                                        Approve
                                                    </button>
                                                </form>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" name="reject_comment"
                                                            style="background: #dc3545; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;"
                                                            onclick="return confirm('Are you sure you want to reject this comment?')">
                                                        Reject
                                                    </button>
                                                </form>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="xsrf" value="<?= $_SESSION["xsrf"] ?>">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <input type="hidden" name="comment_author_id" value="<?= $comment['comment_author_id'] ?>">
                                                    <button type="submit" name="ban_author"
                                                            style="background: #6c757d; color: white; border: none; padding: 4px 8px; border-radius: 3px; cursor: pointer;"
                                                            onclick="return confirm('Are you sure you want to ban this user and delete their comment?')">
                                                        Ban User
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (hasPermission("addcomments")): ?>
                                <button class="comment-toggle" onclick="toggleCommentForm(this)">Add Comment</button>

                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    <?php else: ?>
        <div class="bigcard">
            <p>No mods found in the database.</p>
        </div>
    <?php endif; ?>

</main>
<?php require __DIR__ . "/footer.php"; ?>
</body>echo $imageData;
?></html>
