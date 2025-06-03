<?php
require __DIR__ . '/inc.php';
require __DIR__ . '/vendor/parsedown/Parsedown.php';
require __DIR__ . '/vendor/parsedown/ParsedownExtra.php';
require __DIR__ . '/vendor/parsedown/ParsedownExtended.php';
require __DIR__ . '/vendor/autoload.php';

class ValheimWikiRenderer {
    private $owner = 'Valheim-Modding';
    private $repo = 'Wiki';
    private $token;
    private $cacheDir;
    private $cacheTime = 7200; // 2 hours cache
    private $parsedown;

    public function __construct($token = null) {
        $this->token = $token;
        $this->cacheDir = __DIR__ . '/cache/wiki/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Initialize ParsedownExtended if available
        if (class_exists('ParsedownExtended')) {
            $this->parsedown = new ParsedownExtended();
        } else {
            $this->parsedown = new ParsedownExtra();
        }

        $this->parsedown->setSafeMode(false); // Allow HTML for better rendering
        $this->parsedown->setBreaksEnabled(true);
        $this->parsedown->setMarkupEscaped(false); // Allow HTML tags
        $this->parsedown->setUrlsLinked(true); // Auto-link URLs
    }

    public function getWikiPage($pageName) {
        // Check cache first
        $cacheFile = $this->cacheDir . md5($pageName) . '.md';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTime) {
            return file_get_contents($cacheFile);
        }

        // Try raw GitHub first (faster)
        $content = $this->fetchRawContent($pageName);
        if ($content !== null) {
            file_put_contents($cacheFile, $content);
            return $content;
        }

        return null;
    }

    private function fetchRawContent($pageName) {
        $url = "https://raw.githubusercontent.com/wiki/{$this->owner}/{$this->repo}/{$pageName}.md";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Valtools-Wiki-Reader/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $response : null;
    }

    public function getWikiPagesList() {
        $cacheFile = $this->cacheDir . 'pages_list.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTime) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        // Get all known pages and validate they exist
        $pages = $this->getAllKnownPages();
        $validPages = [];

        foreach ($pages as $page) {
            // Test if page exists by trying to fetch it
            if ($this->pageExists($page['name'])) {
                $validPages[] = $page;
            }
        }

        // Keep the original order - don't sort alphabetically
        // The pages are already in logical order in getAllKnownPages()

        // Cache the pages list
        file_put_contents($cacheFile, json_encode($validPages));

        return $validPages;
    }

    private function pageExists($pageName) {
        $url = "https://raw.githubusercontent.com/wiki/{$this->owner}/{$this->repo}/{$pageName}.md";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Valtools-Wiki-Reader/1.0');

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function getAllKnownPages(): array {
        return $this->fetchAllPagesDynamically();
    }

    private function fetchAllPagesDynamically(): array {
        $url = "https://github.com/{$this->owner}/{$this->repo}/wiki/_pages";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Valtools-Wiki-Reader/1.0');
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return []; // Fail gracefully
        }

        // Match links to individual wiki pages
        preg_match_all('/href="\/' . preg_quote($this->owner) . '\/' . preg_quote($this->repo) . '\/wiki\/([^"]+)"/', $html, $matches);

        $uniquePages = array_unique($matches[1] ?? []);
        $pages = [];

        foreach ($uniquePages as $page) {
            $pageName = htmlspecialchars_decode($page);
            $title = $this->prettifyTitleFromSlug($pageName);
            $pages[] = [
                'name' => $pageName,
                'title' => $title,
            ];
        }

        return $pages;
    }

    public function fetchSidebarSections(): array {
        $cacheFile = $this->cacheDir . 'sidebar_sections.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTime) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        $url = "https://raw.githubusercontent.com/wiki/{$this->owner}/{$this->repo}/_Sidebar.md";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Valtools-Wiki-Reader/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $markdown = curl_exec($ch);
        curl_close($ch);

        if (!$markdown) return [];

        $sections = $this->parseSidebarMarkdown($markdown);

        file_put_contents($cacheFile, json_encode($sections));

        return $sections;
    }


    private function parseSidebarMarkdown(string $markdown): array {
        $lines = explode("\n", $markdown);
        $sections = [];
        $currentSection = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^#+\s*(.+)$/', $line, $matches)) {
                $currentSection = $matches[1];
                $sections[$currentSection] = [];
            } elseif (preg_match('/\*\s*\[(.+?)\]\((.+?)\)/', $line, $matches)) {
                $title = $matches[1];
                $url = $matches[2];

                // Extract the page name from the GitHub wiki link
                if (preg_match('#/wiki/([^/]+)$#', $url, $urlMatch)) {
                    $pageName = $urlMatch[1];
                    if ($currentSection) {
                        $sections[$currentSection][] = [
                            'name' => $pageName,
                            'title' => $title,
                        ];
                    }
                }
            }
        }

        return $sections;
    }

    public function getCombinedSidebarSections(): array {
        $sidebar = $this->fetchSidebarSections(); // structured by section
        $allPages = $this->getWikiPagesList();    // flat list of all valid pages

        $usedNames = [];
        foreach ($sidebar as $section) {
            foreach ($section as $page) {
                $usedNames[] = $page['name'];
            }
        }

        $uncategorized = [];
        foreach ($allPages as $page) {
            if (!in_array($page['name'], $usedNames)) {
                $uncategorized[] = $page;
            }
        }

        if (!empty($uncategorized)) {
            $sidebar['Uncategorized'] = $uncategorized;
        }

        return $sidebar;
    }



    private function prettifyTitleFromSlug(string $slug): string {
        $title = str_replace(['-', '_'], ' ', $slug);
        return ucwords($title);
    }

    public function renderMarkdown($markdown) {
        if (empty($markdown)) {
            return '<p>Content not available.</p>';
        }

        // Pre-process markdown for better rendering
        $markdown = $this->preprocessMarkdown($markdown);

        // Use Parsedown to convert to HTML
        $html = $this->parsedown->text($markdown);

        // Post-process HTML
        $html = $this->postprocessHTML($html);

        return $html;
    }

    private function preprocessMarkdown($markdown) {
        // Handle emoji shortcodes
        $emojiMap = [
            ':information_source:' => 'ℹ️',
            ':warning:' => '⚠️',
            ':exclamation:' => '❗',
            ':question:' => '❓',
            ':bulb:' => '💡',
            ':gear:' => '⚙️',
            ':wrench:' => '🔧',
            ':hammer:' => '🔨',
            ':computer:' => '💻',
            ':file_folder:' => '📁',
            ':page_facing_up:' => '📄',
            ':heavy_check_mark:' => '✅',
            ':x:' => '❌',
            ':arrow_right:' => '➡️',
            ':arrow_left:' => '⬅️',
            ':point_right:' => '👉',
        ];

        foreach ($emojiMap as $shortcode => $emoji) {
            $markdown = str_replace($shortcode, $emoji, $markdown);
        }

        // Handle HTML tables better - convert to markdown tables where possible
        $markdown = preg_replace('/<table[^>]*align="center"[^>]*>/', "\n", $markdown);
        $markdown = preg_replace('/<table[^>]*>/', "\n", $markdown);
        $markdown = preg_replace('/<\/table>/', "\n", $markdown);
        $markdown = preg_replace('/<tr[^>]*>/', "", $markdown);
        $markdown = preg_replace('/<\/tr>/', "\n", $markdown);
        $markdown = preg_replace('/<td[^>]*>/', "\n\n", $markdown);
        $markdown = preg_replace('/<\/td>/', "\n\n", $markdown);
        $markdown = preg_replace('/<th[^>]*>/', "**", $markdown);
        $markdown = preg_replace('/<\/th>/', "**\n", $markdown);

        // Handle definition lists better
        $markdown = preg_replace('/<dl[^>]*>/', "\n", $markdown);
        $markdown = preg_replace('/<\/dl>/', "\n", $markdown);
        $markdown = preg_replace('/<dt[^>]*>/', "\n\n**", $markdown);
        $markdown = preg_replace('/<\/dt>/', "**\n\n", $markdown);
        $markdown = preg_replace('/<dd[^>]*>/', "", $markdown);
        $markdown = preg_replace('/<\/dd>/', "\n\n", $markdown);

        // Handle kbd tags
        $markdown = preg_replace('/<kbd[^>]*>/', '`', $markdown);
        $markdown = preg_replace('/<\/kbd>/', '`', $markdown);

        // Handle ins tags (underline/insert)
        $markdown = preg_replace('/<ins[^>]*>/', '**', $markdown);
        $markdown = preg_replace('/<\/ins>/', '**', $markdown);

        // Handle br tags
        $markdown = preg_replace('/<br\s*\/?>/i', "\n", $markdown);

        // Clean up excessive whitespace
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        return $markdown;
    }

    private function postprocessHTML($html) {
        // Fix internal wiki links
        $html = preg_replace_callback('/href="([^"]+)"/', function($matches) {
            $url = $matches[1];

            // If it's a relative link or wiki link without http/https, make it internal
            if (!preg_match('/^https?:\/\//', $url) && !preg_match('/^mailto:/', $url) && !preg_match('/^#/', $url)) {
                // Handle wiki-style links
                if (strpos($url, '/') === false && !str_contains($url, '.')) {
                    return 'href="?page=' . urlencode($url) . '"';
                }
            }

            // External links get target="_blank"
            if (preg_match('/^https?:\/\//', $url)) {
                return 'href="' . $url . '" target="_blank"';
            }

            return $matches[0];
        }, $html);

        // Fix GitHub image URLs - convert blob URLs to raw URLs
        $html = preg_replace_callback('/src="([^"]+)"/', function($matches) {
            $url = $matches[1];

            // Convert GitHub blob URLs to raw URLs
            if (preg_match('/github\.com\/([^\/]+)\/([^\/]+)\/blob\/(.+)/', $url, $urlMatches)) {
                $owner = $urlMatches[1];
                $repo = $urlMatches[2];
                $path = $urlMatches[3];
                return 'src="https://raw.githubusercontent.com/' . $owner . '/' . $repo . '/' . $path . '"';
            }

            return $matches[0];
        }, $html);

        // Add classes to elements for styling
        $html = str_replace('<table>', '<table class="wiki-table">', $html);
        $html = str_replace('<blockquote>', '<blockquote class="wiki-note">', $html);

        // Handle note boxes better - look for emoji + bold patterns
        $html = preg_replace('/(<p>)?(ℹ️|⚠️|❗|❓|💡|✅|❌)\s*<strong>([^<]+)<\/strong>/', '<div class="wiki-callout wiki-callout-info"><div class="wiki-callout-title">$2 $3</div><div class="wiki-callout-content">', $html);

        // Close callout boxes at next paragraph or end
        $html = preg_replace('/(<div class="wiki-callout-content">.*?)<\/p>\s*<p>/', '$1</div></div><p>', $html);

        return $html;
    }
}

// Initialize
$wiki = new ValheimWikiRenderer();
$currentPage = $_GET['page'] ?? 'Home';
$pages = $wiki->getWikiPagesList();

// Get current page content
$markdown = $wiki->getWikiPage($currentPage);
$content = $markdown ? $wiki->renderMarkdown($markdown) : '<p>Page not found. <a href="https://github.com/Valheim-Modding/Wiki/wiki/' . urlencode($currentPage) . '" target="_blank">View on GitHub</a></p>';

// Find current page title
$currentPageTitle = $currentPage;
foreach ($pages as $page) {
    if ($page['name'] === $currentPage) {
        $currentPageTitle = $page['title'];
        break;
    }
}
?>

<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <title><?= htmlspecialchars($currentPageTitle) ?> – Wiki – Valtools</title>
    <style>
        .wiki-container {
            display: grid;
            grid-template-columns: minmax(0,0.4fr) minmax(0, 2fr);
            gap: 2rem;
            margin: 0 auto;
        }

        .wiki-sidebar {
            background: #1a1a1a;
            border-radius: 8px;
            padding: 1.5rem;
            height: calc(100vh - 120px); /* Fixed height based on viewport */
            position: sticky;
            top: 80px;
            overflow-y: auto; /* Enable vertical scrolling */
            overflow-x: hidden; /* Hide horizontal scrollbar */
        }

        /* Custom scrollbar styling for the sidebar */
        .wiki-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .wiki-sidebar::-webkit-scrollbar-track {
            background: #2a2a2a;
            border-radius: 3px;
        }

        .wiki-sidebar::-webkit-scrollbar-thumb {
            background: #4a4a4a;
            border-radius: 3px;
        }

        .wiki-sidebar::-webkit-scrollbar-thumb:hover {
            background: #5a5a5a;
        }

        /* For Firefox */
        .wiki-sidebar {
            scrollbar-width: thin;
            scrollbar-color: #4a4a4a #2a2a2a;
        }

        .wiki-sidebar h3 {
            margin: 0 0 1rem 0;
            color: #4a9eff;
            font-size: 1.1rem;
        }

        .wiki-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .wiki-nav li {
            margin-bottom: 0.3rem;
        }

        .wiki-nav .section-header {
            margin-top: 1rem;
            padding-top: 0.5rem;
            border-top: 1px solid #333;
            font-size: 0.75rem;
            color: #888;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .wiki-nav .section-header:first-child {
            margin-top: 0;
            border-top: none;
            padding-top: 0;
        }

        .wiki-nav a {
            color: #ccc;
            text-decoration: none;
            padding: 0.4rem 0.6rem;
            display: block;
            border-radius: 4px;
            transition: background-color 0.2s;
            font-size: 0.85rem;
        }

        .wiki-nav a:hover {
            background: #2a2a2a;
            color: var(--color-accent-hover2,#4a9eff);
        }

        .wiki-nav a.active {
            background: var(--color-accent-hover,#4a9eff);
            color: white;
        }

        .wiki-content {
            background: #1a1a1a;
            border-radius: 8px;
            padding: 2rem;
            line-height: 1.6;
            min-height: 500px;
        }

        .wiki-content h1 {
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #333;
            padding-bottom: 0.5rem;
        }

        .wiki-content h2 {
            font-size: 20px;
            line-height: 66px;
            color: #8597a3;
            font-family: 'Roboto', sans-serif;
            font-weight: 200;
            margin-top: 2rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid #333;
            padding-bottom: 0.3rem;
        }

        .wiki-content h3, .wiki-content h4 {
            font-size: 18px;
            line-height: 66px;
            color: #8597a3;
            font-family: 'Roboto', sans-serif;
            font-weight: 200;
            border-bottom: 1px solid #333;
            padding-bottom: 0.3rem;
            margin-top: 1.5rem;
            margin-bottom: 0.8rem;
        }

        .wiki-content pre {
            background: #0d1117;
            border: 1px solid #333;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            margin: 1rem 0;
        }

        .wiki-content code {
            background: #2a2a2a;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 0.9em;
        }

        .wiki-content pre code {
            background: none;
            padding: 0;
        }

        .wiki-content blockquote {
            border-left: 4px solid #4a9eff;
            margin: 1rem 0;
            padding: 0.5rem 1rem;
            background: #1f2937;
            border-radius: 0 4px 4px 0;
        }

        .wiki-content ul, .wiki-content ol {
            margin: 1rem 0;
            padding-left: 2rem;
        }

        .wiki-content li {
            margin-bottom: 0.5rem;
        }

        .wiki-content hr {
            border: none;
            border-top: 1px solid #333;
            margin: 2rem 0;
        }

        .wiki-breadcrumb {
            background: #2a2a2a;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        /* Special callout boxes for notes, warnings, etc. */
        .wiki-callout {
            margin: 1rem 0;
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid #4a9eff;
        }

        .wiki-callout-info {
            background: #1a2332;
            border-left-color: #4a9eff;
        }

        .wiki-callout-warning {
            background: #332a1a;
            border-left-color: #ffcc00;
        }

        .wiki-callout-title {
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #4a9eff;
        }

        .wiki-callout-content {
            margin: 0;
        }

        /* Better handling of definition lists */
        .wiki-content dt {
            font-weight: bold;
            color: #4a9eff;
            margin-top: 1rem;
        }

        .wiki-content dd {
            margin-left: 1rem;
            margin-bottom: 0.5rem;
        }

        /* Keyboard key styling */
        .wiki-content kbd {
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 3px;
            padding: 0.1rem 0.3rem;
            font-family: monospace;
            font-size: 0.9em;
            box-shadow: 0 1px 1px rgba(0,0,0,0.2);
        }

        /* Image styling */
        .wiki-content img {
            max-width: 100%;
            height: auto;
            border-radius: 4px;
            margin: 1rem 0;
        }

        @media (max-width: 768px) {
            .wiki-container {
                grid-template-columns: 1fr;
                padding: 1rem;
            }

            .wiki-sidebar {
                position: static !important;
                height: auto !important;
                max-height: none !important;
                margin-bottom: 1rem;
                overflow-y: visible !important;
                top: auto !important;
            }

            .wiki-sidebar h3 {
                cursor: pointer;
                position: relative;
            }

            .wiki-sidebar h3::after {
                content: "▶";
                float: right;
            }

            .wiki-nav {
                display: none;
            }

            .wiki-sidebar.expanded .wiki-nav {
                display: block;
            }

            .wiki-sidebar.expanded h3::after {
                content: "▼";
            }
        }

        .wiki-mirror-notice {
            background: linear-gradient(135deg, #1a2332 0%, #1f2937 100%);
            border: 1px solid #374151;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .mirror-notice-content {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            gap: 0.75rem;
        }

        .mirror-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .mirror-text {
            font-size: 0.9rem;
            color: #d1d5db;
            line-height: 1.4;
        }

        .mirror-text strong {
            color: #4a9eff;
        }

        @media (max-width: 768px) {
            .mirror-notice-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .mirror-text {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body class="table-view">
<?php require __DIR__ . '/topnav.php'; ?>
<main>
    <div class="wiki-container">
        <aside class="wiki-sidebar">
            <h3>📚 Wiki Pages</h3>
            <ul class="wiki-nav">
                <?php
                $sections = $wiki->getCombinedSidebarSections();

                foreach ($sections as $sectionName => $sectionPages) {
                    echo '<li class="section-header">' . htmlspecialchars($sectionName) . '</li>';

                    foreach ($sectionPages as $page) {
                        $active = ($page['name'] === $currentPage) ? 'active' : '';
                        echo '<li><a href="?page=' . urlencode($page['name']) . '" class="' . $active . '">' . htmlspecialchars($page['title']) . '</a></li>';
                    }
                }

                ?>
            </ul>

            <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #333;">
                <p style="font-size: 0.8rem; color: #888; margin: 0;">
                    📖 Content from<br>
                    <a href="https://github.com/Valheim-Modding/Wiki" target="_blank" style="color: #4a9eff;">
                        Valheim-Modding Wiki
                    </a>
                </p>
            </div>
        </aside>
        <!-- State where this content is mirrored from with a link/disclaimer text -->


        <div class="wiki-content">
            <div class="wiki-breadcrumb">
                <a href="/">Home</a> › <a href="?page=Home">Wiki</a> › <?= htmlspecialchars($currentPageTitle) ?>
            </div>

            <!-- Add the mirror notice here -->
            <div class="wiki-mirror-notice">
                <div class="mirror-notice-content">
                    <span class="mirror-icon">🔗</span>
                    <div class="mirror-text">
                        <strong>Mirror Notice:</strong> This is a cached copy (refreshed every 2 hours) of the original wiki page.
                        <a href="https://github.com/Valheim-Modding/Wiki/wiki/<?= urlencode($currentPage) ?>" target="_blank" rel="noopener">
                            View the latest version on GitHub
                        </a>
                    </div>
                </div>
            </div>

            <?= $content ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>

