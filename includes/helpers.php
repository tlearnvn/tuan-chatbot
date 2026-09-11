<?php
/**
 * Hàm tiện ích dùng chung.
 */

/** Escape HTML an toàn. */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Trả JSON rồi dừng. */
function json_out($data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Trả lỗi JSON rồi dừng. */
function json_error($message, $status = 400, $extra = [])
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

/** Đọc body JSON của request. */
function json_input()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}

/** Chuỗi ngẫu nhiên an toàn. */
function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

/** Thời gian hiện tại theo giờ Việt Nam, dạng MySQL DATETIME. */
function now_vn()
{
    return (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d H:i:s');
}

/** Định dạng ngày giờ kiểu Việt Nam. */
function fmt_datetime($value, $format = 'H:i:s d/m/Y')
{
    if (!$value) {
        return '';
    }
    try {
        $dt = new DateTime($value, new DateTimeZone(APP_TZ));
        return $dt->format($format);
    } catch (Exception $ex) {
        return (string)$value;
    }
}

/** "3 phút trước", "hôm qua", ... */
function fmt_relative($value)
{
    if (!$value) {
        return '';
    }
    try {
        $then = new DateTime($value, new DateTimeZone(APP_TZ));
    } catch (Exception $ex) {
        return (string)$value;
    }
    $now  = new DateTime('now', new DateTimeZone(APP_TZ));
    $diff = $now->getTimestamp() - $then->getTimestamp();

    if ($diff < 0)      return $then->format('H:i d/m/Y');
    if ($diff < 60)     return 'vừa xong';
    if ($diff < 3600)   return floor($diff / 60) . ' phút trước';
    if ($diff < 86400)  return floor($diff / 3600) . ' giờ trước';
    if ($diff < 172800) return 'hôm qua lúc ' . $then->format('H:i');
    if ($diff < 604800) return floor($diff / 86400) . ' ngày trước';

    return $then->format('H:i d/m/Y');
}

/** Định dạng dung lượng file. */
function fmt_bytes($bytes)
{
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (int)$bytes : number_format($bytes, 1, ',', '.')) . ' ' . $units[$i];
}

/** Cắt chuỗi giữ nguyên UTF-8. */
function str_limit($text, $limit = 80, $end = '…')
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . $end;
}

/** IP của client (có xét proxy phổ biến trên shared hosting). */
function client_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/** Chuyển hướng rồi dừng. */
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

/** Lấy URL gốc của ứng dụng (hữu ích khi đặt trong thư mục con). */
function base_url()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir    = rtrim(dirname($script), '/');
    // Nếu đang ở /admin hoặc /api thì lùi một cấp.
    if (preg_match('#/(admin|api|tools)$#', $dir)) {
        $dir = dirname($dir);
    }
    $base = ($dir === '/' || $dir === '.') ? '' : $dir;
    return $base;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/** Lấy (hoặc tạo) token CSRF của phiên. */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_token(24);
    }
    return $_SESSION['csrf_token'];
}

/** Thẻ input ẩn chứa token CSRF. */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Kiểm tra token CSRF từ form hoặc header. */
function csrf_check($token = null)
{
    if ($token === null) {
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? (json_input()['csrf_token'] ?? '');
    }
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Bắt buộc CSRF hợp lệ, nếu không thì dừng. */
function csrf_require($json = false)
{
    if (!csrf_check()) {
        if ($json) {
            json_error('Phiên làm việc đã hết hạn, vui lòng tải lại trang.', 419);
        }
        http_response_code(419);
        exit('Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.');
    }
}

// ---------------------------------------------------------------------------
// Flash message
// ---------------------------------------------------------------------------

/** Ghi thông báo hiển thị ở lần tải trang kế tiếp. */
function flash($type, $message)
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Lấy và xoá toàn bộ thông báo đang chờ. */
function flash_pull()
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Hiển thị thông báo dưới dạng HTML. */
function flash_render()
{
    $items = flash_pull();
    if (!$items) {
        return '';
    }
    $icons = ['success' => '🎉', 'error' => '😿', 'info' => '💡', 'warning' => '⚠️'];
    $html = '<div class="flash-stack">';
    foreach ($items as $item) {
        $icon = $icons[$item['type']] ?? '💬';
        $html .= '<div class="flash flash-' . e($item['type']) . '">'
              . '<span class="flash-icon">' . $icon . '</span>'
              . '<span>' . e($item['message']) . '</span>'
              . '<button type="button" class="flash-close" aria-label="Đóng">&times;</button>'
              . '</div>';
    }
    return $html . '</div>';
}

/** Tạo slug an toàn từ tên file. */
function safe_filename($name)
{
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$name);
    $name = str_replace(['/', '\\', "\0"], '-', $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'tep-tin';
    }
    return mb_substr($name, 0, 180);
}
