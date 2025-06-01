<?php
session_start();
require_once __DIR__ . "/inc.php";

$test_results = [];
$api_endpoints = [
    'Discord OAuth' => 'Test Discord authentication flow',
    'Database Connection' => 'Test database connectivity',
    'Mod Data Fetch' => 'Test mod data retrieval',
    'Comment System' => 'Test comment functionality',
    'User Permissions' => 'Test permission system'
];

// Run tests if requested
if ($_POST['run_tests'] ?? false) {
    // Test 1: Database Connection
    try {
        $pdo->query("SELECT 1");
        $test_results['Database Connection'] = ['status' => 'pass', 'message' => 'Database connection successful'];
    } catch (PDOException $e) {
        $test_results['Database Connection'] = ['status' => 'fail', 'message' => 'Database connection failed: ' . $e->getMessage()];
    }

    // Test 2: Mod Data Fetch
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM mods LIMIT 1");
        $count = $stmt->fetchColumn();
        $test_results['Mod Data Fetch'] = ['status' => 'pass', 'message' => "Successfully fetched mod count: $count mods in database"];
    } catch (PDOException $e) {
        $test_results['Mod Data Fetch'] = ['status' => 'fail', 'message' => 'Mod data fetch failed: ' . $e->getMessage()];
    }

    // Test 3: Comment System
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM comments WHERE approved = 1 LIMIT 1");
        $approved_count = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM comments WHERE approved = 0 LIMIT 1");
        $pending_count = $stmt->fetchColumn();
        $test_results['Comment System'] = ['status' => 'pass', 'message' => "Comment system functional. Approved: $approved_count, Pending: $pending_count"];
    } catch (PDOException $e) {
        $test_results['Comment System'] = ['status' => 'fail', 'message' => 'Comment system test failed: ' . $e->getMessage()];
    }

    // Test 4: User Permissions
    try {
        $current_user_id = $_SESSION["discord_user"]["id"] ?? null;
        if ($current_user_id) {
            $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
            $stmt->execute([$current_user_id]);
            $role = $stmt->fetch();
            $permissions = getPermissions();
            $test_results['User Permissions'] = ['status' => 'pass', 'message' => 'User: ' . ($_SESSION["discord_user"]["username"] ?? 'Guest') . ', Role: ' . ($role['role'] ?? 'None') . ', Permissions: ' . implode(', ', $permissions)];
        } else {
            $test_results['User Permissions'] = ['status' => 'warning', 'message' => 'Not logged in - cannot test user permissions'];
        }
    } catch (PDOException $e) {
        $test_results['User Permissions'] = ['status' => 'fail', 'message' => 'Permission test failed: ' . $e->getMessage()];
    }

    // Test 5: Discord OAuth
    try {
        if (isset($_SESSION["discord_user"])) {
            $user = $_SESSION["discord_user"];
            $avatar_url = "https://cdn.discordapp.com/avatars/{$user['id']}/{$user['avatar']}.png";

            // Test if avatar URL is accessible
            $headers = @get_headers($avatar_url);
            $avatar_accessible = $headers && strpos($headers[0], '200') !== false;

            $test_results['Discord OAuth'] = [
                'status' => 'pass',
                'message' => "Discord OAuth working. User: {$user['username']}, Avatar accessible: " . ($avatar_accessible ? 'Yes' : 'No')
            ];
        } else {
            $test_results['Discord OAuth'] = ['status' => 'warning', 'message' => 'Not logged in via Discord - OAuth cannot be fully tested'];
        }
    } catch (Exception $e) {
        $test_results['Discord OAuth'] = ['status' => 'fail', 'message' => 'Discord OAuth test failed: ' . $e->getMessage()];
    }
}

// API endpoint simulation
$api_data = null;
if ($_GET['api'] ?? false) {
    header('Content-Type: application/json');

    switch ($_GET['api']) {
        case 'mods':
            try {
                $stmt = $pdo->query("SELECT author, name, version, updated FROM mods ORDER BY updated DESC LIMIT 10");
                $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'data' => $mods]);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'comments':
            try {
                $stmt = $pdo->query("SELECT comment_author, comment, comment_time, mod_author, mod_name FROM comments WHERE approved = 1 ORDER BY comment_time DESC LIMIT 10");
                $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'data' => $comments]);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        case 'stats':
            try {
                $mod_count = $pdo->query("SELECT COUNT(*) FROM mods")->fetchColumn();
                $comment_count = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
                $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'mods' => $mod_count,
                        'comments' => $comment_count,
                        'users' => $user_count
                    ]
                ]);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown API endpoint']);
            exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <?php require __DIR__ . "/head.php"; ?>
    <title>API Test - Valtools</title>
    <style>
        .test-result {
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }
        .test-pass {
            background: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }
        .test-fail {
            background: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }
        .test-warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }
        .api-endpoint {
            background: var(--color-panel);
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
        }
        .response-box {
            background: var(--color-panel);
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . "/topnav.php"; ?>

    <div class="container" style="padding: 20px;">
        <h1>API Test Suite</h1>

        <!-- System Tests -->
        <div class="section" style="margin-bottom: 30px;">
            <h2>System Tests</h2>
            <form method="POST">
                <button type="submit" name="run_tests" value="1" style="background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                    Run All Tests
                </button>
            </form>

            <?php if (!empty($test_results)): ?>
                <div style="margin-top: 20px;">
                    <?php foreach ($test_results as $test_name => $result): ?>
                        <div class="test-result test-<?= $result['status'] ?>">
                            <strong><?= htmlspecialchars($test_name) ?>:</strong> <?= htmlspecialchars($result['message']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- API Endpoints -->
        <div class="section" style="margin-bottom: 30px;">
            <h2>API Endpoints</h2>
            <p>Test the available API endpoints by clicking the buttons below:</p>

            <div class="api-endpoint">
                <h3>GET /api_test.php?api=mods</h3>
                <p>Returns the latest 10 mods with their details</p>
                <button onclick="testAPI('mods')" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    Test Mods API
                </button>
                <div id="mods-response" class="response-box" style="display: none; margin-top: 10px;"></div>
            </div>

            <div class="api-endpoint">
                <h3>GET /api_test.php?api=comments</h3>
                <p>Returns the latest 10 approved comments</p>
                <button onclick="testAPI('comments')" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    Test Comments API
                </button>
                <div id="comments-response" class="response-box" style="display: none; margin-top: 10px;"></div>
            </div>

            <div class="api-endpoint">
                <h3>GET /api_test.php?api=stats</h3>
                <p>Returns general statistics about the database</p>
                <button onclick="testAPI('stats')" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    Test Stats API
                </button>
                <div id="stats-response" class="response-box" style="display: none; margin-top: 10px;"></div>
            </div>
        </div>

        <!-- Manual API Testing -->
        <div class="section">
            <h2>Manual API Testing</h2>
            <p>Test custom API calls:</p>

            <div style="margin-bottom: 15px;">
                <label for="api-url" style="display: block; margin-bottom: 5px;">API URL:</label>
                <input type="text" id="api-url" placeholder="e.g., api_test.php?api=mods"
                       style="width: 300px; padding: 8px; border: 1px solid #dee2e6; border-radius: 4px;">
                <button onclick="testCustomAPI()" style="background: #17a2b8; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-left: 10px;">
                    Test Custom URL
                </button>
            </div>

            <div id="custom-response" class="response-box" style="display: none;"></div>
        </div>

        <!-- Session Information -->
        <div class="section" style="margin-top: 30px;">
            <h2>Session Information</h2>
            <div class="response-box">
Current User: <?= isset($_SESSION["discord_user"]) ? htmlspecialchars($_SESSION["discord_user"]["username"]) : 'Not logged in' ?>

User ID: <?= isset($_SESSION["discord_user"]) ? htmlspecialchars($_SESSION["discord_user"]["id"]) : 'N/A' ?>

Permissions: <?= !empty(getPermissions()) ? implode(', ', getPermissions()) : 'None' ?>

Session ID: <?= session_id() ?>

Server Time: <?= date('Y-m-d H:i:s T') ?>
            </div>
        </div>
    </div>

    <script>
        async function testAPI(endpoint) {
            const responseDiv = document.getElementById(endpoint + '-response');
            responseDiv.style.display = 'block';
            responseDiv.textContent = 'Loading...';

            try {
                const response = await fetch(`api_test.php?api=${endpoint}`);
                const data = await response.text();

                // Try to format as JSON if possible
                try {
                    const jsonData = JSON.parse(data);
                    responseDiv.textContent = JSON.stringify(jsonData, null, 2);
                } catch (e) {
                    responseDiv.textContent = data;
                }
            } catch (error) {
                responseDiv.textContent = 'Error: ' + error.message;
            }
        }

        async function testCustomAPI() {
            const url = document.getElementById('api-url').value;
            const responseDiv = document.getElementById('custom-response');

            if (!url) {
                alert('Please enter a URL to test');
                return;
            }

            responseDiv.style.display = 'block';
            responseDiv.textContent = 'Loading...';

            try {
                const response = await fetch(url);
                const data = await response.text();

                // Try to format as JSON if possible
                try {
                    const jsonData = JSON.parse(data);
                    responseDiv.textContent = JSON.stringify(jsonData, null, 2);
                } catch (e) {
                    responseDiv.textContent = data;
                }
            } catch (error) {
                responseDiv.textContent = 'Error: ' + error.message;
            }
        }
    </script>
</body>
</html>