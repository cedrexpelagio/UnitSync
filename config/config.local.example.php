<?php
// UnitSync Local Configuration Example
// Copy this file to config.local.php and fill in your local settings.

return [
    // Database credentials
    'db_host' => '127.0.0.1',
    'db_name' => 'unitsync',
    'db_user' => 'root',
    'db_pass' => '',
    'db_port' => '3306',

    // Initial admin credentials for setup_admin.php
    'admin_username' => '',
    'admin_password' => '',
    'admin_first_name' => 'System',
    'admin_last_name' => 'Administrator',
    'admin_student_number' => 'ADMIN-0001',
    'admin_email' => 'admin@unitsync.local',

    // i-Cadet AI Assistant Configuration (Optional)
    // If empty, i-Cadet will run in built-in offline knowledge mode.
    'ai_provider' => 'claude', // 'claude' or 'openai'
    'ai_api_key'  => '',       // e.g. 'sk-ant-api03-...'
    'ai_model'    => 'claude-3-5-haiku-20241022',
    'ai_base_url' => 'https://api.anthropic.com/v1',
];
