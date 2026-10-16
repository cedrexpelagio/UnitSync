<?php
// UnitSync: i-Cadet Chat Endpoint
// Complies with RULES.md, FEATURE.md, and AI_IMPLEMENTATION.md
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai/config.php';
require_once __DIR__ . '/../includes/ai/engine.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Guard: Authentication
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please log in again.', 'data' => null]);
    exit;
}

$user = current_user();

// 2. Guard: Feature Enabled
if (!ai_is_enabled($pdo)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'The i-Cadet assistant is currently disabled.', 'data' => null]);
    exit;
}

// 3. Guard: Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.', 'data' => null]);
    exit;
}

// Read body (JSON or form-urlencoded)
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

// 4. Guard: CSRF Verification
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if (!verify_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid or expired CSRF token.', 'data' => null]);
    exit;
}

// 5. Guard: Message Validation
$message = trim($input['message'] ?? '');
if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Message cannot be empty.', 'data' => null]);
    exit;
}
if (mb_strlen($message) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Message is too long (maximum 1,000 characters).', 'data' => null]);
    exit;
}

$currentPage = trim($input['current_page'] ?? 'dashboard.php');
$userId = (int)$user['id'];

// 6. Rate Limiting (20 requests per 10 minutes)
$window = date('Y-m-d H:') . floor((int)date('i') / 10);
try {
    $rateStmt = $pdo->prepare("
        INSERT INTO ai_rate_limits (user_id, window_minute, request_count)
        VALUES (:uid, :win, 1)
        ON DUPLICATE KEY UPDATE request_count = request_count + 1
    ");
    $rateStmt->execute(['uid' => $userId, 'win' => $window]);

    $checkStmt = $pdo->prepare("SELECT request_count FROM ai_rate_limits WHERE user_id = ? AND window_minute = ?");
    $checkStmt->execute([$userId, $window]);
    $reqCount = (int)$checkStmt->fetchColumn();

    if ($reqCount > 25) {
        http_response_code(429);
        echo json_encode([
            'ok' => false, 
            'message' => 'You are sending questions too quickly. Please pause for a moment, Sir/Ma\'am.',
            'data' => null
        ]);
        exit;
    }
} catch (Throwable $e) {
    // Fail open on rate-limit table failure to avoid breaking user experience
}

// 7. Load Recent Conversation History (Last 6 messages)
$history = [];
try {
    $histStmt = $pdo->prepare("
        SELECT role, content 
        FROM chat_messages 
        WHERE user_id = ? AND is_cleared = 0
        ORDER BY id DESC 
        LIMIT 6
    ");
    $histStmt->execute([$userId]);
    $history = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $history = [];
}

// 8. CRUCIAL: Close PHP session before executing AI engine call
// Prevents PHP session lock from freezing other tabs during processing!
session_write_close();

// 9. Generate Response
$response = AiCadetEngine::respond($user, $currentPage, $message, $history);

// 10. Persist Messages to Database
$assistantMsgId = null;
try {
    // Log User Message
    $uStmt = $pdo->prepare("
        INSERT INTO chat_messages (user_id, role, content, page_context, is_unanswered, is_cleared, created_at)
        VALUES (?, 'user', ?, ?, 0, 0, NOW())
    ");
    $uStmt->execute([$userId, $message, $currentPage]);

    // Log Assistant Response
    $aStmt = $pdo->prepare("
        INSERT INTO chat_messages (user_id, role, content, page_context, is_unanswered, is_cleared, created_at)
        VALUES (?, 'assistant', ?, ?, ?, 0, NOW())
    ");
    $isUnanswered = !empty($response['unanswered']) ? 1 : 0;
    $aStmt->execute([$userId, $response['reply'], $currentPage, $isUnanswered]);
    $assistantMsgId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log("Failed to log chat message: " . $e->getMessage());
}

// 11. Return JSON Response
echo json_encode([
    'ok' => true,
    'message' => 'Success',
    'data' => [
        'reply'       => $response['reply'],
        'actions'     => $response['actions'] ?? [],
        'suggestions' => $response['suggestions'] ?? [],
        'message_id'  => $assistantMsgId,
        'engine'      => $response['engine'] ?? 'rule_based',
        'created_at'  => date('g:i A')
    ]
]);
