<?php
// One-time Admin Setup Script (Run once, then delete)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$configFile = __DIR__ . '/config/config.local.php';
if (!file_exists($configFile)) {
    die("Error: config/config.local.php not found. Please create it first.");
}

$localConfig = require $configFile;
$adminUsername = $localConfig['admin_username'] ?? 'admin';
$adminPassword = $localConfig['admin_password'] ?? '';
$firstName = $localConfig['admin_first_name'] ?? 'System';
$lastName = $localConfig['admin_last_name'] ?? 'Administrator';
$studentNumber = $localConfig['admin_student_number'] ?? 'ADMIN-0001';
$email = $localConfig['admin_email'] ?? 'admin@unitsync.local';

if (empty($adminPassword)) {
    die("Error: admin_password is not set in config/config.local.php");
}

// Check if an admin account already exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$stmt->execute();
if ($stmt->fetch()) {
    die("An Admin account already exists. For security, setup_admin.php cannot be run again. Please delete this file.");
}

// Hash password and insert admin
$passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (
        username, password_hash, first_name, last_name, student_number, email, 
        role, status, must_change_password, created_at
    ) VALUES (
        :username, :password_hash, :first_name, :last_name, :student_number, :email,
        'admin', 'approved', 1, NOW()
    )
");

$stmt->execute([
    'username' => $adminUsername,
    'password_hash' => $passwordHash,
    'first_name' => $firstName,
    'last_name' => $lastName,
    'student_number' => $studentNumber,
    'email' => $email,
]);

echo "<!DOCTYPE html><html><head><title>Admin Setup</title></head><body>";
echo "<h2>Admin Setup Successful</h2>";
echo "<p>Admin account <strong>" . e($adminUsername) . "</strong> has been created with status <em>approved</em>.</p>";
echo "<p><strong>Important:</strong> First login will require a password change.</p>";
echo "<p style='color:red;'><strong>Please delete setup_admin.php immediately for security.</strong></p>";
echo "<p><a href='" . BASE_URL . "/auth/login.php'>Go to Login</a></p>";
echo "</body></html>";
