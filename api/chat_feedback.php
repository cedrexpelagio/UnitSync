<?php
// UnitSync: i-Cadet Feedback Endpoint (Thumbs Up / Down)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.', 'data' => null]);
    exit;
}

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

$messageId = (int)($input['message_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0); // 1 = helpful, -1 = not helpful
$userId = (int)current_user()['id'];

if ($messageId <= 0 || ($rating !== 1 && $rating !== -1)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid message ID or rating.', 'data' => null]);
    exit;
}

try {
    // Record feedback
    $stmt = $pdo->prepare("
        INSERT INTO chat_feedback (message_id, user_id, rating, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$messageId, $userId, $rating]);

    echo json_encode(['ok' => true, 'message' => 'Thank you for your feedback, Sir/Ma\'am!', 'data' => null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Unable to record feedback.', 'data' => null]);
}
