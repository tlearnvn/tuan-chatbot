<?php
/**
 * Bootstrap — nạp cấu hình, thiết lập múi giờ, session, tự động nạp thư viện.
 * Mọi file khác chỉ cần require file này.
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

define('APP_ROOT', dirname(__DIR__));
define('APP_INC', APP_ROOT . '/includes');

require_once APP_INC . '/version.php';

mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------------
// Cấu hình
// ---------------------------------------------------------------------------
$configFile = APP_ROOT . '/config/config.php';

if (!file_exists($configFile)) {
    // Chưa cài đặt → đẩy sang trình cài đặt (trừ khi đang ở chính trình cài đặt).
    $self = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    if ($self !== 'install.php') {
        $prefix = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false
                   || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) ? '../' : '';
        header('Location: ' . $prefix . 'install.php');
        exit;
    }
    $GLOBALS['APP_CONFIG'] = [
        'timezone'   => 'Asia/Ho_Chi_Minh',
        'upload_dir' => 'uploads',
        'debug'      => false,
        'app_key'    => 'installer-temp-key',
    ];
} else {
    $GLOBALS['APP_CONFIG'] = require $configFile;
}

/**
 * Đọc một giá trị cấu hình.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function cfg($key, $default = null)
{
    return isset($GLOBALS['APP_CONFIG'][$key]) ? $GLOBALS['APP_CONFIG'][$key] : $default;
}

// ---------------------------------------------------------------------------
// Múi giờ — mặc định giờ Việt Nam (UTC+7)
// ---------------------------------------------------------------------------
define('APP_TZ', cfg('timezone', 'Asia/Ho_Chi_Minh'));
date_default_timezone_set(APP_TZ);

// ---------------------------------------------------------------------------
// Báo lỗi
// ---------------------------------------------------------------------------
if (cfg('debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', '0');
}

// ---------------------------------------------------------------------------
// Nạp các thành phần cần trước khi mở session
// ---------------------------------------------------------------------------
require_once APP_INC . '/helpers.php';
require_once APP_INC . '/crypto.php';
require_once APP_INC . '/db.php';
require_once APP_INC . '/session_db.php';

// ---------------------------------------------------------------------------
// Session — lưu trong cơ sở dữ liệu để không sinh file trên đĩa (đỡ tốn inode).
// Nếu chưa cài đặt xong (chưa có bảng `sessions`) thì tạm dùng cơ chế mặc định.
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_name('TCHATSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');

    // Phải đăng ký bộ xử lý TRƯỚC session_start().
    session_use_database();
    session_start();
}

// ---------------------------------------------------------------------------
// Nạp phần còn lại
// ---------------------------------------------------------------------------
require_once APP_INC . '/settings.php';
require_once APP_INC . '/auth.php';
require_once APP_INC . '/storage.php';
require_once APP_INC . '/files.php';
