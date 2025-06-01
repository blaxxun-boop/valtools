<?php
require_once __DIR__ . "/../inc.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['author']) || empty($input['author'])) {
    echo json_encode(['error' => 'Author parameter required']);
    exit;
}

$author = trim($input['author']);

try {
    // Get mods from both the mods table and compatibility matrix
    $stmt = $pdo->prepare("
        SELECT DISTINCT name 
        FROM mods 
        WHERE author = ? AND name IS NOT NULL 
        UNION 
        SELECT DISTINCT mod_name as name 
        FROM mod_compatibility 
        WHERE mod_author = ? AND mod_name IS NOT NULL 
        ORDER BY name
    ");
    $stmt->execute([$author, $author]);
    $mods = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['mods' => $mods]);
} catch (PDOException $e) {
    error_log("Error fetching mods by author: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>