<?php
declare(strict_types=1);

require __DIR__ . '/inc.php';

// On POST, handle uploaded logfile
$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logfile']) && is_uploaded_file($_FILES['logfile']['tmp_name'])) {
    $text = file_get_contents($_FILES['logfile']['tmp_name']);
    $results = parse_bepinex_log($text);
}

/**
 * Parse BepInEx log text and extract metadata
 */
function parse_bepinex_log(string $text): array
{
    $lines = preg_split('/\r?\n/', $text);
    $parsed = [
        'valheim_version' => null,
        'bepinex_version' => null,
        'bepinex_pack_version' => null,
        'unity_version' => null,
        'mods' => [],
        'patchers' => [],
        'errors' => [],
        'warnings' => [],
        'outdated_mods' => [],
    ];

    foreach ($lines as $line) {
        // Versions
        if (!$parsed['valheim_version'] && strpos($line, ': Valheim version: ') !== false) {
            $parsed['valheim_version'] = trim(substr($line, strpos($line, ': Valheim version: ') + 19));
        }
        if (!$parsed['bepinex_version'] && preg_match('/\[Message:\s*BepInEx\] BepInEx (\S+)/', $line, $m)) {
            $parsed['bepinex_version'] = $m[1];
        }
        if (!$parsed['bepinex_pack_version'] && preg_match('/\[Message:\s*BepInEx\] User is running BepInExPack Valheim version (\S+)/', $line, $m)) {
            $parsed['bepinex_pack_version'] = $m[1];
        }
        if (!$parsed['unity_version'] && preg_match('/\[Info\s*:\s*BepInEx\]\s*Running under Unity v([\d\.]+)/', $line, $m)) {
            $parsed['unity_version'] = $m[1];
        }
        // Mods
        if (preg_match('/\[Info\s*:\s*BepInEx\]\s*Loading\s*\[([^\]]+)\]/', $line, $m)) {
            list($name, $ver) = parse_name_version($m[1]);
            $parsed['mods'][] = ['name' => $name, 'version' => $ver];
        }
        // Patchers
        if (preg_match('/\[Info\s*:\s*BepInEx\]\s*Loaded\s*\d+\s*patcher method[s]?.*?\[([^\]]+)\]/i', $line, $m)) {
            list($name, $ver) = parse_name_version($m[1]);
            $parsed['patchers'][] = ['name' => $name, 'version' => $ver];
        }
    }

    // Extract errors and warnings using improved method
    $error_data = fetch_errors_improved($text);
    $parsed['errors'] = merge_duplicate_errors($error_data['errors']);
    $parsed['warnings'] = merge_duplicate_errors($error_data['warnings']);

    // Sort mods and patchers alphabetically by name
    usort($parsed['mods'], function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    usort($parsed['patchers'], function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Check for outdated mods (basic implementation)
    $parsed['outdated_mods'] = check_outdated_mods($parsed['mods']);

    return $parsed;
}

/**
 * Improved error and warning extraction with context lines
 */
function fetch_errors_improved(string $text): array
{
    $lines = preg_split('/\r?\n/', $text);
    $errors = [];
    $warnings = [];
    $current_error = '';
    $current_warning = '';
    $in_error_stack = false;
    $in_warning_stack = false;
    $error_start_line = -1;
    $warning_start_line = -1;
    $context_before = 3;
    $context_after = 3;

    foreach ($lines as $line_num => $line) {
        // Handle Warnings & Errors with grouping
        $is_warning = preg_match('/^\[Warning/i', $line);
        $is_error = preg_match('/^\[(Error|Fatal)/i', $line) || strpos($line, 'Exception in ZRpc::HandlePackage:') !== false || preg_match('/Exception:/i', $line);
        $is_stack_trace = preg_match('/^\s+at\s+/', $line) || preg_match('/^\s+\w+\.\w+/', $line) || preg_match('/Stack trace:/', $line);

        // Check for info/debug/message lines that should reset error state
        $is_reset_line = preg_match('/^\[(Info|Debug|Message)/', $line);

        if ($is_warning) {
            // Finish previous error if we were in one
            if ($in_error_stack && !empty($current_error)) {
                $errors[] = create_message_with_context($current_error, $error_start_line, $lines, $context_before, $context_after);
                $current_error = '';
                $in_error_stack = false;
            }

            // Finish previous warning if we were in one
            if ($in_warning_stack && !empty($current_warning)) {
                $warnings[] = create_message_with_context($current_warning, $warning_start_line, $lines, $context_before, $context_after);
            }

            $current_warning = $line;
            $warning_start_line = $line_num;
            $in_warning_stack = true;
            $in_error_stack = false;
        } elseif ($is_error) {
            // Finish previous warning if we were in one
            if ($in_warning_stack && !empty($current_warning)) {
                $warnings[] = create_message_with_context($current_warning, $warning_start_line, $lines, $context_before, $context_after);
                $current_warning = '';
                $in_warning_stack = false;
            }

            // Finish previous error if we were in one
            if ($in_error_stack && !empty($current_error)) {
                $errors[] = create_message_with_context($current_error, $error_start_line, $lines, $context_before, $context_after);
            }

            $current_error = $line;
            $error_start_line = $line_num;
            $in_error_stack = true;
            $in_warning_stack = false;
        } elseif ($is_stack_trace) {
            // Continue building the current error or warning
            if ($in_error_stack) {
                $current_error .= "\n" . $line;
            } elseif ($in_warning_stack) {
                $current_warning .= "\n" . $line;
            }
        } elseif ($is_reset_line || empty(trim($line))) {
            // Reset on info/debug/message lines or empty lines
            if ($in_error_stack && !empty($current_error)) {
                $errors[] = create_message_with_context($current_error, $error_start_line, $lines, $context_before, $context_after);
                $current_error = '';
                $in_error_stack = false;
            }
            if ($in_warning_stack && !empty($current_warning)) {
                $warnings[] = create_message_with_context($current_warning, $warning_start_line, $lines, $context_before, $context_after);
                $current_warning = '';
                $in_warning_stack = false;
            }
        } else {
            // For any other line, if we're in an error/warning context, continue building it
            if ($in_error_stack) {
                $current_error .= "\n" . $line;
            } elseif ($in_warning_stack) {
                $current_warning .= "\n" . $line;
            }
        }
    }

    // Don't forget to add the last error/warning if we ended on one
    if ($in_error_stack && !empty($current_error)) {
        $errors[] = create_message_with_context($current_error, $error_start_line, $lines, $context_before, $context_after);
    }
    if ($in_warning_stack && !empty($current_warning)) {
        $warnings[] = create_message_with_context($current_warning, $warning_start_line, $lines, $context_before, $context_after);
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Create a message with context lines
 */
function create_message_with_context(string $main_message, int $start_line, array $all_lines, int $context_before, int $context_after): array
{
    $message_lines = explode("\n", $main_message);
    $end_line = $start_line + count($message_lines) - 1;

    $formatted_lines = [];

    // Add "before" context (muted)
    $before_start = max(0, $start_line - $context_before);
    for ($i = $before_start; $i < $start_line; ++$i) {
        $line_num = sprintf("%4d", $i + 1);
        $formatted_lines[] = "<span class=\"context-line\">  {$line_num} │ {$all_lines[$i]}</span>";
    }

    // Add the main error/warning lines (normal color)
    foreach ($message_lines as $index => $line) {
        $line_num = sprintf("%4d", $start_line + $index + 1);
        $formatted_lines[] = "► {$line_num} │ {$line}";
    }

    // Add "after" context (muted)
    $after_end = min(count($all_lines), $end_line + 1 + $context_after);
    for ($i = $end_line + 1; $i < $after_end; ++$i) {
        $line_num = sprintf("%4d", $i + 1);
        $formatted_lines[] = "<span class=\"context-line\">  {$line_num} │ {$all_lines[$i]}</span>";
    }

    return [
        'message' => $main_message,
        'formatted_message' => implode("\n", $formatted_lines),
        'line_number' => $start_line + 1
    ];
}

/**
 * Merge duplicate errors with occurrence counting (updated for context)
 */
function merge_duplicate_errors(array $messages): array
{
    $merged = [];
    $normalized_map = [];

    foreach ($messages as $msg_data) {
        $message = $msg_data['message'];
        $line_number = $msg_data['line_number'];
        $formatted_message = $msg_data['formatted_message'];

        // Normalize the message for comparison
        $normalized = normalize_error_message($message);

        if (isset($normalized_map[$normalized])) {
            // Found duplicate, increment count
            $merged[$normalized_map[$normalized]]['count']++;
        } else {
            // New message
            $index = count($merged);
            $normalized_map[$normalized] = $index;
            $merged[] = [
                'message' => $message,
                'formatted_message' => $formatted_message,
                'count' => 1,
                'line_number' => $line_number
            ];
        }
    }

    return $merged;
}

/**
 * Normalize error message for comparison
 */
function normalize_error_message(string $message): string
{
    // Remove timestamps and variable data
    $normalized = preg_replace('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', 'XX/XX/XXXX XX:XX:XX', $message);
    $normalized = preg_replace('/\[\d{2}:\d{2}:\d{2}\.\d{3}\]/', '[XX:XX:XX.XXX]', $normalized);
    $normalized = preg_replace('/line \d+/', 'line XXX', $normalized);
    $normalized = preg_replace('/\(\d+\)/', '(XXX)', $normalized); // Remove object instance numbers
    $normalized = preg_replace('/0x[0-9a-fA-F]+/', '0xXXXXXXXX', $normalized); // Memory addresses
    $normalized = preg_replace('/\d+\.\d+\.\d+\.\d+/', 'XXX.XXX.XXX.XXX', $normalized); // IP addresses

    return $normalized;
}

/**
 * Basic mod version checking (placeholder - would need external mod database)
 */
function check_outdated_mods(array $mods): array
{
    $outdated = [];

    // This is a simplified example - in reality you'd check against Thunderstore API
    $known_versions = [
        'Jotunn' => '2.20.0',
        'HookGenPatcher' => '0.0.5.56',
        'ServerSync' => '1.13.0',
        // Add more known latest versions here
    ];

    foreach ($mods as $mod) {
        $mod_name = $mod['name'];
        if (isset($known_versions[$mod_name])) {
            $current_version = $mod['version'];
            $latest_version = $known_versions[$mod_name];

            if (version_compare($current_version, $latest_version, '<')) {
                $outdated[] = [
                    'name' => $mod_name,
                    'current_version' => $current_version,
                    'latest_version' => $latest_version,
                    'status' => 'outdated'
                ];
            }
        }
    }

    return $outdated;
}

/**
 * Add a message to the array only if it's not already present, or increment count
 * (Kept for backward compatibility but now using merge_duplicate_errors)
 */
function add_unique_message(array &$messages, string $message): void
{
    // Normalize the message for comparison (remove timestamps and line numbers that might vary)
    $normalized = preg_replace('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', 'XX/XX/XXXX XX:XX:XX', $message);
    $normalized = preg_replace('/\[\d{2}:\d{2}:\d{2}\.\d{3}\]/', '[XX:XX:XX.XXX]', $normalized);
    $normalized = preg_replace('/line \d+/', 'line XXX', $normalized);
    $normalized = preg_replace('/\(\d+\)/', '(XXX)', $normalized); // Remove object instance numbers

    foreach ($messages as &$existing) {
        $existing_normalized = preg_replace('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', 'XX/XX/XXXX XX:XX:XX', $existing['message']);
        $existing_normalized = preg_replace('/\[\d{2}:\d{2}:\d{2}\.\d{3}\]/', '[XX:XX:XX.XXX]', $existing_normalized);
        $existing_normalized = preg_replace('/line \d+/', 'line XXX', $existing_normalized);
        $existing_normalized = preg_replace('/\(\d+\)/', '(XXX)', $existing_normalized); // Remove object instance numbers

        if ($existing_normalized === $normalized) {
            // Found duplicate, increment count
            $existing['count']++;
            return;
        }
    }

    // Not found, add new message
    $messages[] = ['message' => $message, 'count' => 1];
}

/**
 * Format messages with count indicators and context
 */
function format_messages_with_counts(array $messages): string
{
    $formatted = [];
    foreach ($messages as $msg) {
        $header = '';
        if ($msg['count'] > 1) {
            $header = "🔄 " . $msg['count'] . "x occurrences (first at line " . $msg['line_number'] . "):\n\n";
        } else {
            $header = "📍 Line " . $msg['line_number'] . ":\n\n";
        }

        $formatted[] = $header . $msg['formatted_message'];
    }
    return implode("\n\n────────────────────────────────────────\n\n", $formatted);
}

/**
 * Split a string like "Name Version" into [name, version]
 */
function parse_name_version(string $inside): array
{
    $parts = preg_split('/\s+/', $inside);
    $ver = array_pop($parts);
    $name = implode(' ', $parts);
    return [$name, $ver];
}

?>
<!DOCTYPE html>
<html id="website">
<head>
    <?php require __DIR__ . '/head.php'; ?>
    <title>Log Parser – Valtools</title>
    <style>
        .two-column-table {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .two-column-table table {
            margin: 0;
        }

        @media (max-width: 768px) {
            .two-column-table {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        .collapsible-section {
            margin-bottom: 2rem;
        }

        .collapsible-header {
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.2s;
            position: sticky;
            top: 63px;
            z-index: 10;
        }

        .collapsible-header:hover {
            background: #333;
        }

        .collapsible-header h2 {
            margin: 0;
            color: #4a9eff;
        }

        .collapsible-toggle {
            font-size: 1.2em;
            color: #888;
            transition: transform 0.2s;
        }

        .collapsible-content {
            margin-top: 1rem;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .collapsible-content.collapsed {
            max-height: 0;
            margin-top: 0;
        }

        .collapsible-content.expanded {
            max-height: none;
            overflow: visible;
        }

        .collapsible-header.expanded .collapsible-toggle {
            transform: rotate(180deg);
        }

        .outdated-mod {
            background: #3a2a1a;
            border-left: 4px solid #ff8800;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 4px;
        }

        .context-line {
            opacity: 0.4;
            color: #888;
        }

        .error-pre {
            background: #1a1a1a;
            color: #ff6b6b;
            padding: 1.5rem;
            border-radius: 8px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9em;
            line-height: 1.4;
            border-left: 4px solid #ff4444;
            overflow-x: auto;
        }

        .warning-pre {
            background: #1a1a1a;
            color: #ffd93d;
            padding: 1.5rem;
            border-radius: 8px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9em;
            line-height: 1.4;
            border-left: 4px solid #ffcc00;
            overflow-x: auto;
        }
    </style>
</head>
<body class="table-view">
<?php require __DIR__ . '/topnav.php'; ?>
<main>
    <h1>Upload BepInEx LogOutput.log</h1>
    <form method="POST" enctype="multipart/form-data" id="logForm">
        <div ondrop="handleDrop(event)" ondragover="event.preventDefault()"
             style="padding:1em;border:2px dashed #555;margin-bottom:1em;text-align:center;color:#aaa;position:relative;">
            Drag & drop your LogOutput.log here<br>or click Choose File<br><br>
            <input type="file" name="logfile" id="logfile" style="position:relative;inset:0;cursor:pointer;color:white;"
                   value="Browse">
        </div>
        <!--        <button type="submit">Parse Log</button>-->
    </form>

    <?php if ($results): ?>
        <section>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <div>
                    <h3 style="margin-top: 0; color: #4a9eff;">System Information</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 0.5rem;"><strong>Valheim
                                Version:</strong> <?= htmlspecialchars($results['valheim_version'] ?? 'Unknown') ?></li>
                        <li style="margin-bottom: 0.5rem;"><strong>BepInEx
                                Version:</strong> <?= htmlspecialchars($results['bepinex_version'] ?? 'Unknown') ?></li>
                        <?php if ($results['bepinex_pack_version']): ?>
                            <li style="margin-bottom: 0.5rem;"><strong>BepInExPack
                                    Version:</strong> <?= htmlspecialchars($results['bepinex_pack_version']) ?></li>
                        <?php endif; ?>
                        <li style="margin-bottom: 0.5rem;"><strong>Unity
                                Version:</strong> <?= htmlspecialchars($results['unity_version'] ?? 'Unknown') ?></li>
                    </ul>
                </div>
                <div>
                    <h3 style="margin-top: 0; color: #4a9eff;">Mod Statistics</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 0.5rem;"><strong>Mods Loaded:</strong> <?= count($results['mods']) ?>
                        </li>
                        <li style="margin-bottom: 0.5rem;"><strong>Patchers
                                Loaded:</strong> <?= count($results['patchers']) ?></li>
                        <li style="margin-bottom: 0.5rem; color: #ffcc00;">
                            <strong>Warnings:</strong> <?= count($results['warnings']) ?></li>
                        <li style="margin-bottom: 0.5rem; color: #ff4444;">
                            <strong>Errors:</strong> <?= count($results['errors']) ?></li>
                        <?php if (!empty($results['outdated_mods'])): ?>
                            <li style="margin-bottom: 0.5rem; color: #ff8800;">
                                <strong>Outdated Mods:</strong> <?= count($results['outdated_mods']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <?php if (!empty($results['outdated_mods'])): ?>
                <div class="collapsible-section">
                    <div class="collapsible-header" onclick="toggleSection('outdated')">
                        <h2 style="color: #ff8800;">Outdated Mods (<?= count($results['outdated_mods']) ?>)</h2>
                        <span class="collapsible-toggle">▼</span>
                    </div>
                    <div class="collapsible-content collapsed" id="outdated-content">
                        <?php foreach ($results['outdated_mods'] as $mod): ?>
                            <div class="outdated-mod">
                                <strong><?= htmlspecialchars($mod['name']) ?></strong><br>
                                Current: <?= htmlspecialchars($mod['current_version']) ?> →
                                Latest: <?= htmlspecialchars($mod['latest_version']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="collapsible-section">
                <div class="collapsible-header" onclick="toggleSection('mods')">
                    <h2>Loaded Mods (<?= count($results['mods']) ?>)</h2>
                    <span class="collapsible-toggle">▼</span>
                </div>
                <div class="collapsible-content collapsed" id="mods-content">
                    <div class="two-column-table">
                        <?php
                        $mod_chunks = array_chunk($results['mods'], (int)ceil(count($results['mods']) / 2));
                        foreach ($mod_chunks as $chunk): ?>
                            <table>
                                <thead>
                                <tr>
                                    <th><a href="#" class="sortlink">Mod Name</a></th>
                                    <th><a href="#" class="sortlink">Version</a></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($chunk as $mod): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($mod['name']) ?></td>
                                        <td><?= htmlspecialchars($mod['version']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="collapsible-section">
                <div class="collapsible-header" onclick="toggleSection('patchers')">
                    <h2>Loaded Patchers (<?= count($results['patchers']) ?>)</h2>
                    <span class="collapsible-toggle">▼</span>
                </div>
                <div class="collapsible-content collapsed" id="patchers-content">
                    <div class="two-column-table">
                        <?php
                        $patcher_chunks = array_chunk($results['patchers'], (int)ceil(count($results['patchers']) / 2));
                        foreach ($patcher_chunks as $chunk): ?>
                            <table>
                                <thead>
                                <tr>
                                    <th><a href="#" class="sortlink">Patcher Name</a></th>
                                    <th><a href="#" class="sortlink">Version</a></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($chunk as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['name']) ?></td>
                                        <td><?= htmlspecialchars($p['version']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="collapsible-section">
                <div class="collapsible-header" onclick="toggleSection('warnings')">
                    <h2 style="color:#ffcc00;">Warnings (<?= count($results['warnings']) ?>)</h2>
                    <span class="collapsible-toggle">▼</span>
                </div>
                <div class="collapsible-content collapsed" id="warnings-content">
                    <?php if (empty($results['warnings'])): ?>
                        <p>No warnings found.</p>
                    <?php else: ?>
                        <div class="warning-pre"><?= format_messages_with_counts($results['warnings']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="collapsible-section">
                <div class="collapsible-header" onclick="toggleSection('errors')">
                    <h2 style="color: #ff4444;">Errors (<?= count($results['errors']) ?>)</h2>
                    <span class="collapsible-toggle">▼</span>
                </div>
                <div class="collapsible-content collapsed" id="errors-content">
                    <?php if (empty($results['errors'])): ?>
                        <p>No errors found.</p>
                    <?php else: ?>
                        <div class="error-pre"><?= format_messages_with_counts($results['errors']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?></main><?php require __DIR__ . '/footer.php'; ?>
<script>
    function toggleSection(sectionName) {
        const content = document.getElementById(sectionName + '-content');
        const header = content.previousElementSibling;
        const toggle = header.querySelector('.collapsible-toggle');

        if (content.classList.contains('collapsed')) {
            content.classList.remove('collapsed');
            content.classList.add('expanded');
            header.classList.add('expanded');
        } else {
            content.classList.remove('expanded');
            content.classList.add('collapsed');
            header.classList.remove('expanded');
        }
    }

    document.getElementById('logfile').addEventListener('change', function () {
        if (this.files.length) document.getElementById('logForm').submit();
    });

    function handleDrop(e) {
        e.preventDefault();
        let fi = document.getElementById('logfile');
        fi.files = e.dataTransfer.files;
        if (fi.files.length) document.getElementById('logForm').submit();
    }
</script>
</body>
</html>
