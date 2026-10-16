<?php
// UnitSync: i-Cadet AI Engine (Claude API + Built-in Rule Engine Fallback)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/../helpers.php';

class AiCadetEngine {

    /**
     * Generate response for the given user, role, page, message, and history
     */
    public static function respond(array $user, string $currentPage, string $message, array $history = []): array {
        $role = $user['role'] ?? '';
        $firstName = $user['first_name'] ?? 'User';
        $cfg = ai_get_config();

        // 1. If Claude API is configured, attempt Claude call
        if (!empty($cfg['api_key'])) {
            try {
                $response = self::callClaude($cfg, $user, $currentPage, $message, $history);
                if ($response && !empty($response['reply'])) {
                    return $response;
                }
            } catch (Throwable $e) {
                error_log("i-Cadet Claude API Exception: " . $e->getMessage());
                // Graceful fallback to rule-based engine
            }
        }

        // 2. Built-in rule engine
        $ruleResult = ai_rule_based_reply($role, $currentPage, $message);
        $ruleResult['engine'] = 'rule_based';
        return $ruleResult;
    }

    /**
     * Call Claude Messages API
     */
    private static function callClaude(array $cfg, array $user, string $currentPage, string $message, array $history): ?array {
        $role = $user['role'] ?? '';
        $firstName = $user['first_name'] ?? 'User';
        $roleKnowledge = ai_get_role_knowledge($role);

        // System prompt establishing i-Cadet persona & role-filtered knowledge
        $systemPrompt = "You are i-Cadet, a friendly, courteous, and knowledgeable AI cadet guide for UnitSync, an ROTC attendance management system.\n" .
                        "You are speaking to {$firstName}, whose role in the system is: " . format_role_name($role) . ".\n" .
                        "Current page the user is on: {$currentPage}.\n\n" .
                        "GUIDELINES & GUARDRAILS:\n" .
                        "1. Tone: Friendly, respectful, cheerful military courtesy (e.g. 'Snappy salute!', 'Sir/Ma'am').\n" .
                        "2. Answer strictly and solely based on the UnitSync knowledge provided below.\n" .
                        "3. If the user asks something not covered in the knowledge or impossible in the system, reply: 'I'm not sure about that, Sir/Ma'am. Please consult your S1 Officer or System Administrator.'\n" .
                        "4. Never invent features, policies, or workflows not in the text.\n" .
                        "5. You are read-only: you cannot directly execute actions or submit records for them. Explain the exact steps they need to take.\n" .
                        "6. Under RA 10173 (Data Privacy), never reveal, ask for, or process private cadet personal details (birthdays, contact numbers, emails).\n" .
                        "7. Keep responses concise, clear, and well-structured using markdown bullets.\n\n" .
                        "SYSTEM & ROLE KNOWLEDGE BASE:\n" .
                        "------------------------------------\n" .
                        $roleKnowledge . "\n" .
                        "------------------------------------\n";

        // Build messages payload
        $messages = [];
        // Include last 6 messages from history
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $msg) {
            $sender = ($msg['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = [
                'role' => $sender,
                'content' => (string)$msg['content']
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];

        $payload = [
            'model' => $cfg['model'],
            'max_tokens' => 800,
            'temperature' => 0.2,
            'system' => $systemPrompt,
            'messages' => $messages
        ];

        $url = $cfg['base_url'] . '/messages';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $cfg['api_key'],
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $cfg['timeout'] ?? 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
            error_log("i-Cadet Claude API error (HTTP $httpCode): " . ($curlErr ?: $res));
            return null;
        }

        $json = json_decode($res, true);
        $replyText = '';
        if (isset($json['content']) && is_array($json['content'])) {
            foreach ($json['content'] as $block) {
                if (isset($block['text'])) {
                    $replyText .= $block['text'];
                }
            }
        }

        if (empty($replyText)) {
            return null;
        }

        // Check if unanswerable
        $isUnanswered = (stripos($replyText, "not sure") !== false && stripos($replyText, "consult") !== false);

        // Generate contextual action links based on mention
        $actions = [];
        if (stripos($replyText, 'attendance.php') !== false || stripos($replyText, 'Take Attendance') !== false) {
            if ($role === 'platoon_leader') {
                $actions[] = ['label' => 'Open Take Attendance', 'url' => BASE_URL . '/leader/attendance.php'];
            }
        }
        if (stripos($replyText, 'review_attendance.php') !== false || stripos($replyText, 'Review Attendance') !== false) {
            if ($role === 'battalion_s1' || $role === 'brigade_s1') {
                $actions[] = ['label' => 'Open Review Attendance', 'url' => BASE_URL . '/s1/review_attendance.php'];
            }
        }
        if (stripos($replyText, 'import_cadets.php') !== false) {
            if ($role === 'battalion_s1' || $role === 'brigade_s1') {
                $actions[] = ['label' => 'Open Import Cadets', 'url' => BASE_URL . '/s1/import_cadets.php'];
            }
        }

        return [
            'reply' => $replyText,
            'actions' => $actions,
            'suggestions' => ai_get_page_suggestions($role, $currentPage),
            'unanswered' => $isUnanswered,
            'engine' => 'claude'
        ];
    }
}
