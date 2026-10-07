<?php
// UnitSync Authentication Guard & Helpers
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/flash.php';

// Inactivity timeout check
function check_session_timeout(): void {
    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
            // Expired session
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            session_start();
            set_flash('warning', 'Session expired due to inactivity. Please log in again.');
            redirect('auth/login.php');
        }
        $_SESSION['last_activity'] = time();
    }
}
check_session_timeout();

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'must_change_password' => !empty($_SESSION['must_change_password']),
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    // Force password change check if required
    if (!empty($_SESSION['must_change_password'])) {
        $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($current_script !== 'change_password.php' && $current_script !== 'logout.php') {
            redirect('auth/change_password.php');
        }
    }
}

function require_role(string ...$allowed_roles): void {
    require_login();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $allowed_roles, true)) {
        set_flash('error', 'You do not have access to this page.');
        redirect_dashboard_by_role($role);
    }
}

function redirect_dashboard_by_role(?string $role = null): void {
    if ($role === null) {
        $role = $_SESSION['role'] ?? '';
    }
    switch ($role) {
        case 'admin':
            redirect('admin/dashboard.php');
            break;
        case 'platoon_leader':
            redirect('leader/dashboard.php');
            break;
        case 'class_president':
            redirect('president/dashboard.php');
            break;
        case 'battalion_s1':
        case 'brigade_s1':
            redirect('s1/dashboard.php');
            break;
        default:
            redirect('auth/login.php');
            break;
    }
}
