<?php
require_once __DIR__ . "/inc.php";

class ThunderstoreAPI {
    private const BASE_URL = "https://thunderstore.io/c/valheim/api/v1/package/";
    private const CACHE_DURATION = 3600; // 1 hour in seconds

    private static function getCachedData() {
        if (isset($_SESSION['packages_data']) &&
            isset($_SESSION['last_fetch']) &&
            (time() - $_SESSION['last_fetch']) < self::CACHE_DURATION) {
            return $_SESSION['packages_data'];
        }
        return null;
    }

    private static function setCachedData($data) {
        $_SESSION['packages_data'] = $data;
        $_SESSION['last_fetch'] = time();
    }

    public static function getAllModsFromThunderstore() {
        $cachedData = self::getCachedData();
        if ($cachedData !== null) {
            return $cachedData;
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'ThunderstoreStats-WebApp/1.0'
                ]
            ]);

            $jsonData = file_get_contents(self::BASE_URL, false, $context);
            if ($jsonData === false) {
                throw new Exception("Failed to fetch data from Thunderstore API");
            }

            $packages = json_decode($jsonData, true);
            if ($packages === null) {
                throw new Exception("Failed to parse JSON data");
            }

            self::setCachedData($packages);
            return $packages;
        } catch (Exception $e) {
            error_log("ThunderstoreAPI Error: " . $e->getMessage());
            return [];
        }
    }

    public static function getModInfo($modName) {
        $packages = self::getAllModsFromThunderstore();
        $modName = strtolower(trim($modName));

        foreach ($packages as $package) {
            if (strtolower($package['name']) === $modName ||
                strtolower($package['full_name']) === $modName) {
                return $package;
            }
        }
        return null;
    }

    public static function searchMods($query) {
        $packages = self::getAllModsFromThunderstore();
        $query = strtolower(trim($query));
        $results = [];

        foreach ($packages as $package) {
            if (strpos(strtolower($package['name']), $query) !== false ||
                strpos(strtolower($package['full_name']), $query) !== false) {
                $results[] = $package;
            }
        }

        return array_slice($results, 0, 10); // Limit to 10 results
    }
}

class ThunderstoreStats {
    private static function filterMods($packages) {
        return array_filter($packages, function($mod) {
            return !$mod['is_pinned'] && !$mod['is_deprecated']  &&
                !stripos($mod['name'], 'modpack') &&
                !in_array('Modpacks', $mod['categories'] ?? []);
        });
    }

    public static function getTopMods($limit = 10) {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);

        usort($filtered, function($a, $b) {
            return $b['rating_score'] - $a['rating_score'];
        });

        return array_slice($filtered, 0, $limit);
    }

    public static function getLatestMods($limit = 10) {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);

        usort($filtered, function($a, $b) {
            return strtotime($b['date_created']) - strtotime($a['date_created']);
        });

        return array_slice($filtered, 0, $limit);
    }

    public static function getTopWeekly($limit = 10) {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);
        $weekAgo = time() - (7 * 24 * 60 * 60);

        $weeklyMods = [];
        foreach ($filtered as $mod) {
            $weeklyDownloads = 0;
            if (isset($mod['versions'])) {
                foreach ($mod['versions'] as $version) {
                    if (strtotime($version['date_created']) > $weekAgo) {
                        $weeklyDownloads += $version['downloads'];
                    }
                }
            }
            if ($weeklyDownloads > 0) {
                $mod['weekly_downloads'] = $weeklyDownloads;
                $weeklyMods[] = $mod;
            }
        }

        usort($weeklyMods, function($a, $b) {
            return $b['weekly_downloads'] - $a['weekly_downloads'];
        });

        return array_slice($weeklyMods, 0, $limit);
    }

    public static function getTopToday($limit = 10) {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);
        $dayAgo = time() - (24 * 60 * 60);

        $todayMods = array_filter($filtered, function($mod) use ($dayAgo) {
            return strtotime($mod['date_updated']) > $dayAgo;
        });

        usort($todayMods, function($a, $b) {
            return strtotime($b['date_updated']) - strtotime($a['date_updated']);
        });

        return array_slice($todayMods, 0, $limit);
    }

    public static function getCountModsThisWeek() {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);
        $weekAgo = time() - (7 * 24 * 60 * 60);

        $countUpdated = 0;
        $countUploaded = 0;

        foreach ($filtered as $mod) {
            if (strtotime($mod['date_updated']) > $weekAgo) {
                $countUpdated++;
            }
            if (strtotime($mod['date_created']) > $weekAgo) {
                $countUploaded++;
            }
        }

        return [
            'updated' => $countUpdated,
            'uploaded' => $countUploaded,
            'total' => $countUpdated + $countUploaded
        ];
    }

    public static function getDownloadGrowthMods($limit = 10) {
        $packages = ThunderstoreAPI::getAllModsFromThunderstore();
        $filtered = self::filterMods($packages);
        $monthAgo = time() - (30 * 24 * 60 * 60);

        $growthMods = [];
        foreach ($filtered as $mod) {
            if (strtotime($mod['date_updated']) > $monthAgo) {
                $monthlyDownloads = 0;
                if (isset($mod['versions'])) {
                    foreach ($mod['versions'] as $version) {
                        if (strtotime($version['date_created']) > $monthAgo) {
                            $monthlyDownloads += $version['downloads'];
                        }
                    }
                }
                if ($monthlyDownloads > 0) {
                    $mod['monthly_downloads'] = $monthlyDownloads;
                    $growthMods[] = $mod;
                }
            }
        }

        usort($growthMods, function($a, $b) {
            return $b['monthly_downloads'] - $a['monthly_downloads'];
        });

        return array_slice($growthMods, 0, $limit);
    }
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'top':
            echo json_encode(ThunderstoreStats::getTopMods());
            break;
        case 'latest':
            echo json_encode(ThunderstoreStats::getLatestMods());
            break;
        case 'topweekly':
            echo json_encode(ThunderstoreStats::getTopWeekly());
            break;
        case 'toptoday':
            echo json_encode(ThunderstoreStats::getTopToday());
            break;
        case 'countweek':
            echo json_encode(ThunderstoreStats::getCountModsThisWeek());
            break;
        case 'growth':
            echo json_encode(ThunderstoreStats::getDownloadGrowthMods());
            break;
        case 'search':
            if (isset($_GET['query'])) {
                echo json_encode(ThunderstoreAPI::searchMods($_GET['query']));
            } else {
                echo json_encode([]);
            }
            break;
        case 'modinfo':
            if (isset($_GET['name'])) {
                $mod = ThunderstoreAPI::getModInfo($_GET['name']);
                echo json_encode($mod ?: []);
            } else {
                echo json_encode([]);
            }
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" id="mainpage">
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>Valtools - Thunderstore Stats</title>
    <style>
        .thunderstore-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .search-section {
            margin-bottom: 30px;
        }

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-box input {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--color-border, #ddd);
            border-radius: 5px;
            font-size: 16px;
            background: var(--input-bg, white);
            color: var(--color-text-light, #333);
        }

        .search-box button {
            padding: 12px 20px;
            background: var(--color-comment-tsblue, #007cba);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }

        .search-box button:hover {
            background: var(--color-button-blue-hover, #005a87);
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 20px;
            background: var(--card-bg, #f0f0f0);
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            color: var(--text-color, #333);
        }

        .tab.active {
            background: var(--color-comment-tsblue, #007cba);
            color: white;
        }

        .tab:hover {
            background: var(--color-button-blue-hover, #005a87);
            color: white;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--color-text-muted, #666);
        }

        .mod-card {
            border-bottom: 1px solid var(--color-border, #eee);
            padding: 20px;
            transition: background-color 0.3s;
        }

/*        .mod-card:hover {
            background-color: var(--color-accent-hover, rgba(0,0,0,0.05));
        }*/

        .mod-card:last-child {
            border-bottom: none;
        }

        .mod-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }

        .mod-title {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--color-text-light, #667);
            margin-bottom: 5px;
        }

        .mod-author {
            color: var(--color-text-muted, #666);
            font-size: 0.9em;
        }

        .mod-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .stat {
            background: var(--color-header-bg_tsblue, #f0f0f0);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85em;
            color: var(--color-text-muted, #555);
        }

        .mod-links {
            margin-top: 10px;
        }

        .mod-links a {
            color: var(--color-accent-hover2, #667eea);
            text-decoration: none;
            margin-right: 15px;
        }

        .mod-links a:hover {
            text-decoration: underline;
        }

        .error {
            text-align: center;
            padding: 40px;
            color: var(--color-error, #e74c3c);
        }

        .stats-summary {
            margin-bottom: 20px;
            text-align: center;
        }

        .stats-summary h3 {
            color: var(--text-color, #333);
            margin-bottom: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .stat-item {
        padding: 15px;
        border-radius: 8px;
            border-left: 4px solid var(--primary-color, #667eea);
        }

        .stat-number {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--primary-color, #667eea);
        }

        .stat-label {
            font-size: 0.9em;
            color: var(--color-text-muted, #666);
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .thunderstore-container {
                padding: 10px;
            }

            .search-box {
                flex-direction: column;
            }

            .tabs {
                justify-content: center;
            }

            .tab {
                font-size: 12px;
                padding: 8px 15px;
            }

            .mod-header {
                flex-direction: column;
                align-items: start;
            }

            .mod-stats {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . "/topnav.php"; ?>

    <main class="thunderstore-container">
        <h1 class="section-title">🔨 Thunderstore Stats</h1>
        <p style="text-align: center; margin-bottom: 2rem; color: var(--text-muted, #666);">
            Valheim Mod Statistics & Search
        </p>

        <div class="bigcard search-section">
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Search for mods..." />
            <button onclick="searchMods()">Search</button>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="loadData('latest')">Latest</button>
            <button class="tab" onclick="loadData('topweekly')">Top Weekly</button>
            <button class="tab" onclick="loadData('toptoday')">Updated Today</button>
            <button class="tab" onclick="loadData('growth')">Growth (30d)</button>
            <button class="tab" onclick="loadWeeklyStats()">Weekly Stats</button>
            <button class="tab" onclick="loadData('top')">Top Rated</button>
        </div>
    </div>

        <div id="statsSection" class="bigcard stats-summary" style="display: none;">
        <h3>Weekly Statistics</h3>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number" id="updatedCount">-</div>
                <div class="stat-label">Mods Updated</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="uploadedCount">-</div>
                <div class="stat-label">Mods Uploaded</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="totalCount">-</div>
                <div class="stat-label">Total Activity</div>
            </div>
        </div>
    </div>

        <div class="bigcard" id="results">
        <div class="loading">Loading top rated mods...</div>
    </div>
    </main>

<script>
    let currentTab = 'latest';

    // Load initial data
    document.addEventListener('DOMContentLoaded', function() {
        loadData('latest');
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchMods();
        }
    });

    function searchMods() {
        const query = document.getElementById('searchInput').value.trim();
        if (query === '') {
            loadData(currentTab);
            return;
        }

        showLoading('Searching for mods...');
        hideStats();

        fetch(`?action=search&query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                displayMods(data, 'Search Results');
            })
            .catch(error => {
                showError('Failed to search mods');
                console.error('Error:', error);
            });
    }

    function loadData(type) {
        currentTab = type;
        updateActiveTab(type);
        hideStats();

        const loadingMessages = {
            'top': 'Loading top rated mods...',
            'latest': 'Loading latest mods...',
            'topweekly': 'Loading top weekly mods...',
            'toptoday': 'Loading mods updated today...',
            'growth': 'Loading growth statistics...'
        };

        showLoading(loadingMessages[type] || 'Loading...');

        fetch(`?action=${type}`)
            .then(response => response.json())
            .then(data => {
                const titles = {
                    'top': 'Top Rated Mods',
                    'latest': 'Latest Mods',
                    'topweekly': 'Top Weekly Downloads',
                    'toptoday': 'Updated Today',
                    'growth': 'Download Growth (30 days)'
                };
                displayMods(data, titles[type]);
            })
            .catch(error => {
                showError('Failed to load data');
                console.error('Error:', error);
            });
    }

    function loadWeeklyStats() {
        currentTab = 'countweek';
        updateActiveTab('countweek');
        showLoading('Loading weekly statistics...');

        fetch('?action=countweek')
            .then(response => response.json())
            .then(data => {
                showStats(data);
                loadData('topweekly'); // Show weekly mods alongside stats
            })
            .catch(error => {
                showError('Failed to load weekly statistics');
                console.error('Error:', error);
            });
    }

    function updateActiveTab(activeType) {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });

        const tabTexts = {
            'top': 'Top Rated',
            'latest': 'Latest',
            'topweekly': 'Top Weekly',
            'toptoday': 'Updated Today',
            'growth': 'Growth (30d)',
            'countweek': 'Weekly Stats'
        };

        document.querySelectorAll('.tab').forEach(tab => {
            if (tab.textContent.trim() === tabTexts[activeType]) {
                tab.classList.add('active');
            }
        });
    }

    function showLoading(message) {
        document.getElementById('results').innerHTML = `<div class="loading">${message}</div>`;
    }

    function showError(message) {
        document.getElementById('results').innerHTML = `<div class="error">${message}</div>`;
    }

    function showStats(data) {
        document.getElementById('updatedCount').textContent = data.updated || 0;
        document.getElementById('uploadedCount').textContent = data.uploaded || 0;
        document.getElementById('totalCount').textContent = data.total || 0;
        document.getElementById('statsSection').style.display = 'block';
    }

    function hideStats() {
        document.getElementById('statsSection').style.display = 'none';
    }

    function displayMods(mods, title) {
        if (!mods || mods.length === 0) {
            document.getElementById('results').innerHTML = `
                    <div class="error">No mods found</div>
                `;
            return;
        }

        let html = '';

        mods.forEach(mod => {
            const totalDownloads = mod.versions ?
                mod.versions.reduce((sum, version) => sum + (version.downloads || 0), 0) : 0;

            const weeklyDownloads = mod.weekly_downloads || 0;
            const monthlyDownloads = mod.monthly_downloads || 0;

            const latestVersion = mod.versions && mod.versions.length > 0 ?
                mod.versions[0] : null;

            html += `
                    <div class="mod-card">
                        <div class="mod-header">
                            <div>
                                <div class="mod-title">${escapeHtml(mod.name || 'Unknown')}</div>
                                <div class="mod-author">by ${escapeHtml(mod.owner || 'Unknown')}</div>
                            </div>
                        </div>

                        <div class="mod-stats">
                            <span class="stat">⭐ Rating: ${mod.rating_score || 0}</span>
                            <span class="stat">📥 Total Downloads: ${totalDownloads.toLocaleString()}</span>
                            ${weeklyDownloads > 0 ? `<span class="stat">📈 Weekly: ${weeklyDownloads.toLocaleString()}</span>` : ''}
                            ${monthlyDownloads > 0 ? `<span class="stat">📊 Monthly: ${monthlyDownloads.toLocaleString()}</span>` : ''}
                            ${latestVersion ? `<span class="stat">🔖 Version: ${escapeHtml(latestVersion.version_number || 'Unknown')}</span>` : ''}
                            <span class="stat">📅 Updated: ${formatDate(mod.date_updated)}</span>
                        </div>

                        <div class="mod-links">
                            ${mod.package_url ? `<a href="${escapeHtml(mod.package_url)}" target="_blank">View on Thunderstore</a>` : ''}
                            ${latestVersion && latestVersion.download_url ? `<a href="${escapeHtml(latestVersion.download_url)}" target="_blank">Download</a>` : ''}
                            ${latestVersion && latestVersion.website_url ? `<a href="${escapeHtml(latestVersion.website_url)}" target="_blank">Website</a>` : ''}
                        </div>
                    </div>
                `;
        });

        document.getElementById('results').innerHTML = html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDate(dateString) {
        if (!dateString) return 'Unknown';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString();
        } catch (e) {
            return 'Unknown';
        }
    }
</script>
</body>
<?php require __DIR__ . '/footer.php'; ?>
</html>