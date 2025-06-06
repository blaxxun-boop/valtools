<?php
require_once __DIR__ . "/../inc.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$author = $input['author'] ?? '';
$name = $input['name'] ?? '';

if (!$author || !$name) {
    echo json_encode(['error' => 'Author and name are required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT version FROM mods WHERE author = ? AND name = ?");
    $stmt->execute([$author, $name]);
    $result = $stmt->fetch();

    if ($result) {
        echo json_encode(['version' => $result['version']]);
    } else {
        echo json_encode(['error' => 'Mod not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>