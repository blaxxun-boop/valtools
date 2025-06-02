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

// Check if we should show only pending comments
$show_pending_only = isset($_GET['pending_only']) && $_GET['pending_only'] === '1' && hasPermission("modcomments");

// Fetch mods data with latest approved comment or pending comments
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
			m.author_modpage,
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
		WHERE c.approved = 0
		GROUP BY m.author, m.name, m.version, m.updated
		ORDER BY MAX(c.comment_time) DESC
	");
    }
    else {
        // All mods with their comments grouped
        $stmt = $pdo->prepare("
		SELECT 
			m.author, 
			m.name, 
			m.version, 
			m.updated,
			m.deprecated,
			m.packageurl,
			m.author_modpage,
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
		GROUP BY m.author, m.name, m.version, m.updated
		ORDER BY m.updated DESC
	");
    }
    $stmt->execute();
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
            'author_modpage' => $row['author_modpage'],
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
</head>
<body class="table-view">
<?php require __DIR__ . "/topnav.php"; ?>
<script>
    function toggleCommentForm(previous) {
        const form = document.comment_form;
        if (form.style.display === 'none' || form.style.display === '' || form.previousElementSibling != previous) {
            form.style.display = 'block';
        } else {
            form.style.display = 'none';
        }
        form.mod_author.value = previous.parentNode.parentNode.getElementsByClassName("mod_author")[0].innerText;
        form.mod_name.value = previous.parentNode.parentNode.getElementsByClassName("mod_name")[0].innerText;
        previous.after(form);
    }

    function toggleView() {
        const body = document.body;
        const table = document.getElementById('tableView');
        //const cards = document.getElementById('cardView');
        const button = document.getElementById('viewLabel');

        const isTable = body.classList.toggle('table-view');
        body.classList.toggle('card-view', !isTable);

        button.textContent = isTable ? 'Switch to Card View' : 'Switch to Table View';
    }

    (function () {
        const toggle = document.createElement('button');
        toggle.textContent = 'Switch to Card View';
        toggle.id = 'viewLabel';
        toggle.className = 'view-toggle-button';
        toggle.onclick = toggleView;

        const nav = document.querySelector("nav");
        nav.lastElementChild.previousElementSibling.after(toggle);
    })();

    function filterMods() {
        const query = document.getElementById("modSearch").value.toLowerCase();
        const status = document.getElementById("filterCompatibility").value;
        const author = document.getElementById("filterAuthor").value;
        const showDeprecated = document.getElementById("showDeprecatedFilter").checked;

        const rows = document.querySelectorAll("#tableView tbody tr");

        function matches(modName, modAuthor, modStatus, isDeprecated) {
            return (
                (!query || modName.toLowerCase().includes(query)) &&
                (!status || modStatus === status) &&
                (!author || modAuthor === author) &&
                (showDeprecated || !isDeprecated)
            );
        }

        rows.forEach(row => {
            const modName = row.children[1]?.innerText || '';
            const modAuthor = row.children[0]?.innerText || '';
            const statusSpan = row.querySelector(".comment-approved, .comment-pending");
            const modStatus = row.querySelector(".status")?.classList.contains('compatible') ? 'compatible' :
                row.querySelector(".status")?.classList.contains('incompatible') ? 'incompatible' : 'unknown';
            const deprecatedBadge = row.querySelector(".incompatibility-badge");
            const isDeprecated = deprecatedBadge && deprecatedBadge.textContent.trim() === "Deprecated";

            row.style.display = matches(modName, modAuthor, modStatus, isDeprecated) ? '' : 'none';
        });
    }


    document.addEventListener("DOMContentLoaded", () => {
        toggleDeprecatedFilter(false);
        document.getElementById("modSearch").addEventListener("input", filterMods);
        document.getElementById("filterCompatibility").addEventListener("change", filterMods);
        document.getElementById("filterAuthor").addEventListener("change", filterMods);
    });

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
        const rows = document.querySelectorAll("#tableView tbody tr");
        const cards = document.querySelectorAll("#cardView .card");

        rows.forEach(row => {
            const deprecatedBadge = row.querySelector(".incompatibility-badge");
            const isDeprecated = deprecatedBadge && deprecatedBadge.textContent.trim() === "Deprecated";

            if (isDeprecated && !checked) {
                row.style.display = 'none';
            } else if (isDeprecated && checked) {
                // Just show it, don't call filterMods for each row
                row.style.display = '';
            }
        });

        cards.forEach(card => {
            const deprecatedBadge = card.querySelector(".incompatibility-badge");
            const isDeprecated = deprecatedBadge && deprecatedBadge.textContent.trim() === "Deprecated";

            if (isDeprecated && !checked) {
                card.style.display = 'none';
            } else if (isDeprecated && checked) {
                // Just show it, don't call filterMods for each card
                card.style.display = 'block';
            }
        });

        // Call filterMods once at the end to apply all other filters
        if (checked) {
            filterMods();
        }
    }


    // Autocomplete functionality
    function setupAutocomplete(inputId) {
        const input = document.getElementById(inputId);
        const container = input.parentElement;
        let suggestionsDiv = container.querySelector('.autocomplete-suggestions');

        if (!suggestionsDiv) {
            suggestionsDiv = document.createElement('div');
            suggestionsDiv.className = 'autocomplete-suggestions';
            container.appendChild(suggestionsDiv);
        }

        let selectedIndex = -1;

        input.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length < 2) {
                suggestionsDiv.style.display = 'none';
                return;
            }

            fetch(`?api=mods&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    suggestionsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach((mod, index) => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-suggestion';
                            div.textContent = `${mod.author} - ${mod.name}`;
                            div.addEventListener('click', function() {
                                selectMod(mod);
                                suggestionsDiv.style.display = 'none';
                            });
                            suggestionsDiv.appendChild(div);
                        });
                        suggestionsDiv.style.display = 'block';
                        selectedIndex = -1;
                    } else {
                        suggestionsDiv.style.display = 'none';
                    }
                });
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
                s.classList.toggle('selected', i === selectedIndex);
            });
        }

        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                suggestionsDiv.style.display = 'none';
            }
        });
    }

    function selectMod(mod) {
        document.getElementById(`incompatible_mod_search`).value = `${mod.author} - ${mod.name}`;
        document.getElementById(`incompatible_mod_author`).value = mod.author;
        document.getElementById(`incompatible_mod_name`).value = mod.name;
    }

    function toggleIncompatibilitySection() {
        const checkbox = document.getElementById(`has_incompatibility`);
        const section = document.getElementById(`incompatibility_section`);
        section.style.display = checkbox.checked ? 'block' : 'none';

        if (!checkbox.checked) {
            // Clear incompatibility fields
            const form = section.closest('form');
            let incompatibility_box = form.querySelector(`input[name="incompatibility"]:checked`);
            if (incompatibility_box) {
                incompatibility_box.checked = false;
            }
            form.querySelector(`input[name="incompatible_mod_author"]`).value = '';
            form.querySelector(`input[name="incompatible_mod_name"]`).value = '';
            form.querySelector(`input[id*="incompatible_mod_search"]`).value = '';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const avatars = document.querySelectorAll('img[data-src]');

        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    observer.unobserve(img);
                }
            });
        });

        avatars.forEach(img => imageObserver.observe(img));
    });

    document.addEventListener("DOMContentLoaded", () => {
        document.getElementById("modSearch").addEventListener("input", filterMods);
        document.getElementById("filterCompatibility").addEventListener("change", filterMods);

        // Author filter with search functionality
        const authorSearch = document.getElementById("authorSearch");
        const authorDropdown = document.getElementById("authorDropdown");
        const filterAuthor = document.getElementById("filterAuthor");
        let selectedAuthor = "";

        authorSearch.addEventListener("input", function() {
            const query = this.value.toLowerCase();
            const options = authorDropdown.querySelectorAll(".author-option");
            let hasVisibleOptions = false;

            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                const matches = text.includes(query);
                option.style.display = matches ? "block" : "none";
                if (matches) hasVisibleOptions = true;
            });

            authorDropdown.style.display = hasVisibleOptions ? "block" : "none";
        });

        authorSearch.addEventListener("focus", function() {
            authorDropdown.style.display = "block";
        });

        authorDropdown.addEventListener("click", function(e) {
            if (e.target.classList.contains("author-option")) {
                const value = e.target.dataset.value;
                const text = e.target.textContent;

                selectedAuthor = value;
                authorSearch.value = value ? text : "";
                authorSearch.placeholder = value ? text : "Search authors...";
                filterAuthor.value = value;
                authorDropdown.style.display = "none";

                filterMods();
            }
        });

        document.addEventListener("click", function(e) {
            if (!e.target.closest(".author-filter-container")) {
                authorDropdown.style.display = "none";
            }
        });

        // Update filterMods function to use the hidden select
        window.filterMods = function() {
            const query = document.getElementById("modSearch").value.toLowerCase();
            const status = document.getElementById("filterCompatibility").value;
            const author = document.getElementById("filterAuthor").value;

            const rows = document.querySelectorAll("#tableView tbody tr");
            const cards = document.querySelectorAll("#cardView .card");

            function matches(modName, modAuthor, modStatus) {
                return (
                    (!query || modName.toLowerCase().includes(query)) &&
                    (!status || modStatus === status) &&
                    (!author || modAuthor === author)
                );
            }

            rows.forEach(row => {
                const modName = row.children[1]?.textContent || '';
                const modAuthor = row.children[0]?.textContent || '';
                const statusSpan = row.querySelector(".comment-approved, .comment-pending");
                const modStatus = row.querySelector(".status")?.classList.contains('compatible') ? 'compatible' :
                    row.querySelector(".status")?.classList.contains('incompatible') ? 'incompatible' : 'unknown';

                row.style.display = matches(modName, modAuthor, modStatus) ? '' : 'none';
            });

            cards.forEach(card => {
                const modName = card.querySelector("h2")?.textContent || '';
                const modAuthor = card.querySelector(".author")?.textContent || '';
                const statusDiv = card.querySelector(".status");
                const modStatus = statusDiv?.classList.contains("compatible") ? "compatible" :
                    statusDiv?.classList.contains("incompatible") ? "incompatible" : "unknown";

                card.style.display = matches(modName, modAuthor, modStatus) ? 'block' : 'none';
            });
        };
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
                content: "No compatibility issues";
                font-style: italic;
            }
            .card-view #tableView td:nth-child(5)::before {
                display: block;
                content: "Incompatible Mods";
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
                    <th>Compatibility</th>
                    <th>Comments</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mods as $index => $mod): ?>
                    <tr>
                        <td class="mod_author"><a href="<?=  $mod['author_modpage'] ?>" target="_blank"><?= htmlspecialchars($mod['author']) ?></a></td>
                        <!--<td class="mod_name"><?php /*= htmlspecialchars($mod['name']) */?></td>-->
                        <td class="mod_name"><a href="<?= $mod['packageurl'] ?>" target="_blank"><?= htmlspecialchars($mod['name']) ?></a>
                            <?php if ($mod['deprecated'] == 1): ?>
                                <span class="incompatibility-badge incompatibility-full">Deprecated</span>
                            <?php endif; ?>
                        </td>
                        <!--<td class="mod_name">
                            <a href="modshowcase.php?author=<?php /*= urlencode($mod['author']) */?>&name=<?php /*= urlencode($mod['name']) */?>">
                                <?php /*= htmlspecialchars($mod['name']) */?>
                            </a>
                        </td>-->
                        <td><?= htmlspecialchars($mod['version'] ?? 'N/A') ?></td>
                        <td><?= $mod['updated'] ? date('Y-m-d H:i', $mod['updated']) : 'N/A' ?></td>
                        <td><!-- TODO: compatibility overview --></td>
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
                                <?php endforeach; ?></div>
                            <?php if (hasPermission("addcomments")): ?>
                                <button class="comment-toggle" onclick="toggleCommentForm(this)">Add Comment</button>

                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            </table>
        </div>

        <?php /*
        <!-- Card View -->
        <div id="cardView" class="container" style="display:none;">
			<?php foreach ($mods as $index => $mod): ?>
                <div class="card">
                    <!-- HEADER: MOD NAME, AUTHOR, STATUS -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h2 style="margin-bottom: 0.25em;">
								<?= htmlspecialchars($mod['name']) ?>
								<?= isset($mod['version']) ? '<small style="opacity:0.7;"> (v' . htmlspecialchars($mod['version']) . ')</small>' : '' ?>
                            </h2>
                            <div class="author" style="margin-bottom: 0.5em;">
                                by <?= htmlspecialchars($mod['author']) ?>
                            </div>
                        </div>
                        <div class="status <?= $mod['approved'] ? 'compatible' : 'incompatible' ?>">
							<?= $mod['approved'] ? 'COMPATIBLE' : 'INCOMPATIBLE' ?>
							<?= isset($mod['game_version']) ? '<br><small>(v' . htmlspecialchars($mod['game_version']) . ')</small>' : '' ?>
                        </div>
                    </div>

                    <!-- INCOMPATIBLE MODS -->
                    <section>
                        <h3>Incompatible Mods</h3>
                        <div class="tags">
							<?php
							// Example stubbed data, not sure how I want do do this shit yet
							$incompatibles = ['EpicLoot', 'ValheimPlus', 'Any Shit Mod'];
							foreach ($incompatibles as $tag): ?>
                                <span class="tag"><?= htmlspecialchars($tag) ?></span>
							<?php endforeach; ?>
                        </div>
                    </section>

                    <!-- NOTES -->
                    <section>
                        <h3>Notes</h3>
                        <p style="font-style: italic;">
                            “<?= htmlspecialchars($mod['comment'] ?? 'No known issues reported.') ?>”</p>
                    </section>

                    <!-- COMMENTS -->
					<?php if (!empty($mod['comment_author'])): ?>
                        <section>
                            <h3>COMMENTS</h3>
                            <div style="display: flex; align-items: flex-start; gap: 1em; margin-top: 0.5em;">
                                <img src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($mod['comment_author_id']) ?>/<?= htmlspecialchars($mod['comment_author_avatar']) ?>.png"
                                     alt="avatar"
                                     style="width:40px; height:40px; border-radius:50%; object-fit: cover;">
                                <div style="flex: 1;">
                                    <div style="font-weight: bold;">
										<?= htmlspecialchars($mod['comment_author']) ?>
                                        <small style="display: block; color: var(--color-text-muted);">
											<?= $mod['comment_date'] ? date('F j, Y', $mod['comment_date']) : 'Unknown date' ?>
                                        </small>
                                    </div>
                                    <p style="margin: 0.5em 0 0 0;"><?= htmlspecialchars($mod['comment']) ?></p>
                                </div>
                            </div>
                        </section>
					<?php endif; ?>

                    <!-- COMMENT FORM BUTTON -->
                    <div style="margin-top: auto; text-align: right;">
                        <button class="comment-toggle" onclick="toggleCommentForm('card', <?= $index ?>)">Add Comment
                        </button>
                    </div>

                    <!-- COMMENT FORM -->
                    <div id="comment-form-card-<?= $index ?>" class="comment-form">
                        <form method="POST">
                            <input type="hidden" name="mod_author" value="<?= htmlspecialchars($mod['author']) ?>">
                            <input type="hidden" name="mod_name" value="<?= htmlspecialchars($mod['name']) ?>">
                            <textarea name="comment" placeholder="Enter your comment for this mod..." required></textarea>

                            <div style="margin-top: 10px;">
                                <label>
                                    <input type="checkbox" id="has_incompatibility_<?= $index ?>"
                                           onchange="toggleIncompatibilitySection('card', <?= $index ?>)">
                                    Report incompatibility with another mod
                                </label>
                            </div>

                            <div id="incompatibility_section_<?= $index ?>" style="display: none; margin-top: 10px; padding: 10px; background: #2a2a2a; border-radius: 5px;">
                                <div style="margin-bottom: 10px;">
                                    <label>Incompatibility type:</label><br>
                                    <label><input type="radio" name="incompatibility" value="Full"> Full incompatibility</label>
                                    <label><input type="radio" name="incompatibility" value="Partial"> Partial incompatibility</label>
                                </div>

                                <div class="autocomplete-container">
                                    <input type="text" id="incompatible_mod_search_<?= $index ?>"
                                           placeholder="Search for incompatible mod..."
                                           style="width: 100%; padding: 6px; background: #1e1e1e; color: #fff; border: 1px solid #555; border-radius: 3px;"
                                           onfocus="setupAutocomplete('incompatible_mod_search_<?= $index ?>', <?= $index ?>)">
                                </div>

                                <input type="hidden" name="incompatible_mod_author" id="incompatible_mod_author_<?= $index ?>">
                                <input type="hidden" name="incompatible_mod_name" id="incompatible_mod_name_<?= $index ?>">
                            </div>

                            <div style="margin-top: 10px;">
                                <button type="submit" name="submit_comment">Submit Comment</button>
                                <button type="button" onclick="toggleCommentForm('card', <?= $index ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
			<?php endforeach; ?>
        </div>
 */ ?>

    <?php else: ?>
        <div class="bigcard">
            <p>No mods found in the database.</p>
        </div>
    <?php endif; ?>

</main>
<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>
