<?php
// General Helper Functions
require_once __DIR__ . '/../config/config.php';

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        header("Location: " . $path);
    } else {
        $url = BASE_URL . '/' . ltrim($path, '/');
        header("Location: " . $url);
    }
    exit;
}

function log_mail_sim(string $to_email, string $subject, string $body): void {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $entry = "[" . date('Y-m-d H:i:s') . "] TO: {$to_email} | SUBJECT: {$subject}\nBODY:\n{$body}\n" . str_repeat('-', 50) . "\n";
    file_put_contents($log_dir . '/mail.log', $entry, FILE_APPEND);
}

function format_role_name(string $role): string {
    $roles = [
        'admin' => 'Admin',
        'class_president' => 'Class President',
        'platoon_leader' => 'Platoon Leader',
        'battalion_s1' => 'Battalion S1',
        'brigade_s1' => 'Brigade S1',
    ];
    return $roles[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function log_audit(PDO $pdo, ?int $actorId, string $action, string $entity, ?int $entityId = null, ?string $details = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
            VALUES (:actor_id, :action, :entity, :entity_id, :details, NOW())
        ");
        $stmt->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => $details
        ]);
    } catch (Throwable $e) {
        error_log("Failed to write to audit_log: " . $e->getMessage());
    }
}

