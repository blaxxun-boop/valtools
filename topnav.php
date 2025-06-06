<header>
    <nav>
        <?php
        $is_admin = hasPermission("users");
        $can_moderate_comments = hasPermission("modcomments");

        // Get pending comment count for notification bell
        $pending_comment_count = 0;
        if ($can_moderate_comments) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE approved = 0");
                $stmt->execute();
                $pending_comment_count = $stmt->fetchColumn();
            } catch (PDOException $e) {
                // Silently fail if there's a database error
                $pending_comment_count = 0;
            }
        }
        ?>

        <a class="nav-link" href="index.php">Home</a>
        <a class="nav-link" href="fetch_valheim_updates.php">Valheim Updates</a>
        <a class="nav-link" href="/wiki.php">Wiki</a>

        <li class="nav-item">
            <div class="dropdown" style="position: relative;">
                <a href="#" role="button" id="debugMenu" class="nav-link navlink-dropdown dropdown-toggle" onclick="toggleDropdown('debugMenuDropdown')">Debug Tools</a>
                <div class="dropdown-content" id="debugMenuDropdown" style="position: absolute; top: 100%; left: 0; z-index: 1000;">
                    <a class="dropdown-item" href="parselog.php">Log Parser</a>
                    <a class="dropdown-item" href="https://valheim-map.world/">Valheim World Generator<br>& Seed Finder</a>
                </div>
            </div>
        </li>

        <?php if ($is_admin): ?>
        <li class="nav-item">
            <div class="dropdown" style="position: relative;">
                <a href="#" role="button" id="testpageMenu" class="nav-link navlink-dropdown dropdown-toggle" onclick="toggleDropdown('testpageMenuDropdown')">Test Pages</a>
                <div class="dropdown-content" id="testpageMenuDropdown" style="position: absolute; top: 100%; left: 0; z-index: 1000;">
                    <a class="dropdown-item" href="compatibility.php">Mod to Mod Compatibility</a>
                    <a class="dropdown-item" href="compatibility_matrix.php">Game Version Compatibility</a>
                    <a class="dropdown-item" href="game_compatibility.php">Game Version Compatibility2</a>
                    <a class="dropdown-item" href="mod_stats.php">Mod Statistics</a>
                    <a class="dropdown-item" href="modshowcase.php">Mod Showcase</a>
                    <a class="dropdown-item" href="discord_bot.php">Thunderstore Stats</a>
                    <a class="dropdown-item" href="api_test.php">API Test</a>
                </div>
            </div>
        </li>

            <li class="nav-item">
                <div class="dropdown" style="position: relative;">
                    <a href="#" role="button" id="adminMenu" class="nav-link navlink-dropdown dropdown-toggle" onclick="toggleDropdown('adminMenuDropdown')">
                        Moderation
                    </a>
                    <div class="dropdown-content" id="adminMenuDropdown" style="position: absolute; top: 100%; left: 0; z-index: 1000;">
                        <a class="dropdown-item notification-item" href="comment_moderation.php">
                            Comment Moderation
                            <?php if ($pending_comment_count > 0): ?>
                                <span class="notification-badge-small"><?= $pending_comment_count > 99 ? '99+' : $pending_comment_count ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="dropdown-item" href="manageusers.php">Manage Users</a>
                        <a class="dropdown-item" href="clear-wiki-cache.php">Clear Wiki Cache</a>
                        <a class="dropdown-item" href="fetch.php">Fetch Mods</a>
                    </div>
                    <?php if ($pending_comment_count > 0): ?>
                        <span class="notification-dot"></span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endif; ?>

        <?php if (isset($_SESSION["discord_user"]["username"])): ?>
            <div class="nav-link-right">
                <div class="profile-dropdown" style="position: relative;">
                    <img src="https://cdn.discordapp.com/avatars/<?= htmlspecialchars($_SESSION["discord_user"]["id"]) ?>/<?= htmlspecialchars($_SESSION["discord_user"]["avatar"]) ?>.png"
                         alt="avatar"
                         class="profile-avatar"
                         onclick="toggleDropdown('profileDropdown')"
                         style="width:50px; height:50px; border-radius:50%; object-fit: cover; cursor: pointer;">

                    <div class="dropdown-content" id="profileDropdown" style="position: absolute; top: 100%; right: 0; z-index: 1000;">
                        <div class="dropdown-header">
                            <span><?= htmlspecialchars($_SESSION["discord_user"]["username"]) ?></span>
                        </div>
                        <a href="profile.php" class="dropdown-item">Profile</a>
                        <a href="settings.php" class="dropdown-item">Settings</a>
                        <a href="logout.php" class="dropdown-item">Logout</a>
                        <!-- Add more menu items here in the future -->
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="nav-link-right">
                <a class="nav-link" href="login.php">Login</a>
            </div>
        <?php endif; ?>
    </nav>
</header>

<style>
/* Notification dot for the Moderation dropdown toggle */
.notification-dot {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background-color: #dc3545;
    border-radius: 50%;
    border: 2px solid white;
    animation: pulse 2s infinite;
}

/* Small notification badge for dropdown items */
.notification-item {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-badge-small {
    background-color: #dc3545;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 0.7em;
    font-weight: bold;
    min-width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    margin-left: 8px;
}

@keyframes pulse {
    0% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.2);
        opacity: 0.7;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

/* Ensure dropdown toggle has relative positioning for the notification dot */
.navlink-dropdown {
    position: relative;
}
</style>

<script>
    function toggleDropdown(id) {
        const dropdown = document.getElementById(id);
        document.querySelectorAll('.dropdown-content.show').forEach(el => el != dropdown && el.classList.remove('show'));
        dropdown.classList.toggle("show");
    }

    // Close dropdowns when clicking outside toggles or avatars
    window.onclick = function (event) {
        const isToggle = event.target.matches('.dropdown-toggle');
        const isAvatar = event.target.matches('.profile-avatar');
        if (isToggle || isAvatar) return;
        document.querySelectorAll('.dropdown-content.show').forEach(el => el.classList.remove('show'));
    }
</script>
