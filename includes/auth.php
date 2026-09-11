<?php
/**
 * Xác thực & phân quyền người dùng.
 */

/** Người dùng đang đăng nhập (mảng) hoặc null. */
function current_user($refresh = false)
{
    static $user = null;
    if ($user !== null && !$refresh) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        // Thử khôi phục từ cookie "ghi nhớ đăng nhập".
        if (!auth_try_remember()) {
            return null;
        }
    }
    $row = db_one('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [(int)$_SESSION['user_id']]);
    if (!$row || $row['status'] !== 'active') {
        auth_logout();
        return null;
    }
    $user = $row;
    return $user;
}

/** Đã đăng nhập chưa? */
function is_logged_in()
{
    return current_user() !== null;
}

/** Có phải quản trị viên không? */
function is_admin()
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

/** Bắt buộc đăng nhập, nếu chưa thì chuyển sang trang đăng nhập. */
function require_login($jsonMode = false)
{
    if (is_logged_in()) {
        return current_user();
    }
    if ($jsonMode) {
        json_error('Bạn cần đăng nhập để tiếp tục.', 401);
    }
    $target = $_SERVER['REQUEST_URI'] ?? '';
    redirect(base_url() . '/login.php?next=' . urlencode($target));
}

/** Bắt buộc quyền quản trị. */
function require_admin($jsonMode = false)
{
    $user = require_login($jsonMode);
    if ($user['role'] !== 'admin') {
        if ($jsonMode) {
            json_error('Bạn không có quyền thực hiện thao tác này.', 403);
        }
        http_response_code(403);
        exit('Bạn không có quyền truy cập khu vực quản trị.');
    }
    return $user;
}

/** Đăng nhập một user id vào session. */
function auth_login_user($userId, $remember = false)
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$userId;
    $_SESSION['login_time'] = time();

    db_run('UPDATE `users` SET `last_login_at` = ?, `last_login_ip` = ? WHERE `id` = ?',
        [now_vn(), client_ip(), (int)$userId]);

    if ($remember) {
        auth_set_remember_cookie((int)$userId);
    }
    log_activity('login', 'Đăng nhập thành công', (int)$userId);
}

/** Tạo cookie ghi nhớ đăng nhập (30 ngày). */
function auth_set_remember_cookie($userId)
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = (new DateTime('+30 days', new DateTimeZone(APP_TZ)));

    db_insert('remember_tokens', [
        'user_id'    => $userId,
        'selector'   => $selector,
        'hashed_val' => hash('sha256', $validator),
        'expires_at' => $expires->format('Y-m-d H:i:s'),
        'created_at' => now_vn(),
    ]);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('tchat_remember', $selector . ':' . $validator, [
        'expires'  => $expires->getTimestamp(),
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Thử khôi phục phiên từ cookie ghi nhớ. */
function auth_try_remember()
{
    if (empty($_COOKIE['tchat_remember'])) {
        return false;
    }
    $parts = explode(':', $_COOKIE['tchat_remember'], 2);
    if (count($parts) !== 2) {
        return false;
    }
    list($selector, $validator) = $parts;

    $row = db_one('SELECT * FROM `remember_tokens` WHERE `selector` = ? LIMIT 1', [$selector]);
    if (!$row) {
        return false;
    }
    if (strtotime($row['expires_at']) < time()) {
        db_run('DELETE FROM `remember_tokens` WHERE `id` = ?', [$row['id']]);
        return false;
    }
    if (!hash_equals($row['hashed_val'], hash('sha256', $validator))) {
        // Có dấu hiệu bị đánh cắp token → huỷ toàn bộ token của user.
        db_run('DELETE FROM `remember_tokens` WHERE `user_id` = ?', [$row['user_id']]);
        return false;
    }
    $_SESSION['user_id'] = (int)$row['user_id'];
    return true;
}

/** Đăng xuất. */
function auth_logout()
{
    if (!empty($_COOKIE['tchat_remember'])) {
        $parts = explode(':', $_COOKIE['tchat_remember'], 2);
        if (count($parts) === 2) {
            db_run('DELETE FROM `remember_tokens` WHERE `selector` = ?', [$parts[0]]);
        }
        setcookie('tchat_remember', '', ['expires' => time() - 3600, 'path' => '/']);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Kiểm tra độ mạnh của mật khẩu. Trả về mảng lỗi (rỗng = hợp lệ). */
function validate_password($password)
{
    $errors = [];
    if (mb_strlen($password) < 8) {
        $errors[] = 'Mật khẩu phải có ít nhất 8 ký tự.';
    }
    if (!preg_match('/[a-zA-Z]/', $password)) {
        $errors[] = 'Mật khẩu cần có ít nhất một chữ cái.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Mật khẩu cần có ít nhất một chữ số.';
    }
    return $errors;
}

/** Kiểm tra tên đăng nhập. */
function validate_username($username)
{
    $errors = [];
    if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
        $errors[] = 'Tên đăng nhập chỉ gồm chữ, số, dấu chấm, gạch dưới và dài 3–50 ký tự.';
    }
    return $errors;
}

/** Ghi nhật ký hoạt động. */
function log_activity($action, $detail = '', $userId = null)
{
    try {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        db_insert('activity_log', [
            'user_id'    => $userId ? (int)$userId : null,
            'action'     => mb_substr($action, 0, 60),
            'detail'     => mb_substr((string)$detail, 0, 2000),
            'ip'         => client_ip(),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at' => now_vn(),
        ]);
    } catch (Exception $ex) {
        // Không để lỗi ghi log làm hỏng request.
    }
}

/** Chống dò mật khẩu đơn giản dựa trên session + bảng log. */
function login_throttle_check($username)
{
    $since = (new DateTime('-15 minutes', new DateTimeZone(APP_TZ)))->format('Y-m-d H:i:s');
    try {
        $fails = (int)db_value(
            'SELECT COUNT(*) FROM `activity_log` WHERE `action` = ? AND `ip` = ? AND `created_at` > ?',
            ['login_failed', client_ip(), $since], 0
        );
    } catch (Exception $ex) {
        return true;
    }
    return $fails < 10;
}

/** Avatar mặc định gợi ý cho người dùng mới. */
function random_avatar_emoji()
{
    $pool = ['🐣', '🦊', '🐼', '🐧', '🦄', '🐳', '🌻', '🍀', '🚀', '⭐', '🎈', '🍩', '🐝', '🦋', '🌈'];
    return $pool[random_int(0, count($pool) - 1)];
}
