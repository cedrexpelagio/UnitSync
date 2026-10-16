<?php
// UnitSync: AI Assistant Configuration
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Returns configuration settings for i-Cadet
 */
function ai_get_config(): array {
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: (getenv('CLAUDE_API_KEY') ?: (getenv('UNITSYNC_AI_API_KEY') ?: ''));
    $provider = getenv('UNITSYNC_AI_PROVIDER') ?: 'claude';
    $model = getenv('UNITSYNC_AI_MODEL') ?: 'claude-3-5-haiku-20241022';
    $baseUrl = getenv('UNITSYNC_AI_BASE_URL') ?: 'https://api.anthropic.com/v1';

    $localConfigFile = __DIR__ . '/../../config/config.local.php';
    if (file_exists($localConfigFile)) {
        $local = require $localConfigFile;
        if (is_array($local)) {
            if (!empty($local['ai_api_key'])) $apiKey = $local['ai_api_key'];
            if (!empty($local['ai_provider'])) $provider = $local['ai_provider'];
            if (!empty($local['ai_model'])) $model = $local['ai_model'];
            if (!empty($local['ai_base_url'])) $baseUrl = $local['ai_base_url'];
        }
    }

    return [
        'provider' => $provider,
        'api_key'  => $apiKey,
        'model'    => $model,
        'base_url' => rtrim($baseUrl, '/'),
        'timeout'  => 15,
    ];
}

/**
 * Returns true if an external AI API key is configured
 */
function ai_is_configured(): bool {
    $cfg = ai_get_config();
    return !empty($cfg['api_key']);
}

/**
 * Checks whether the AI assistant is enabled in settings
 */
function ai_is_enabled(PDO $pdo): bool {
    $val = get_setting($pdo, 'ai_chat_enabled', '1');
    return $val === '1' || strtolower($val) === 'true';
}

/**
 * Gets assistant name (defaults to 'i-Cadet')
 */
function ai_get_name(PDO $pdo): string {
    return get_setting($pdo, 'ai_name', 'i-Cadet');
}
