<?php
// UnitSync: i-Cadet Chat History Endpoint (Load & Clear)
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.', 'data' => null]);
    exit;
}

$user = current_user();
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

// Clear history action
if ($method === 'POST' || $method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!verify_csrf_token($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.', 'data' => null]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE chat_messages SET is_cleared = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['ok' => true, 'message' => 'Chat history cleared.', 'data' => null]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to clear chat history.', 'data' => null]);
    }
    exit;
}

// GET history action
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, role, content, page_context, DATE_FORMAT(created_at, '%l:%i %p') AS time_str
            FROM chat_messages
            WHERE user_id = ? AND is_cleared = 0
            ORDER BY id ASC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'message' => 'Success', 'data' => ['messages' => $rows]]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to load history.', 'data' => null]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.', 'data' => null]);
