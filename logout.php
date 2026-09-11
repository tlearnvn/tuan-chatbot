<?php
/**
 * Đăng xuất.
 */
require_once __DIR__ . '/includes/bootstrap.php';

// Chấp nhận token từ liên kết (GET) hoặc biểu mẫu (POST).
$token = $_GET['csrf'] ?? ($_POST['csrf_token'] ?? '');
if (!csrf_check($token)) {
    redirect(base_url() . '/index.php');
}

if (is_logged_in()) {
    log_activity('logout', 'Đăng xuất');
}
auth_logout();

session_start();
flash('info', 'Bạn đã đăng xuất. Hẹn gặp lại! 👋');
redirect(base_url() . '/login.php');
