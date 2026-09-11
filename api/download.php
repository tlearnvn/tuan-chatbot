<?php
/**
 * Trả tệp đính kèm về trình duyệt (xem trực tiếp hoặc tải xuống).
 * Chỉ chủ sở hữu tệp hoặc quản trị viên mới xem được.
 */
require_once __DIR__ . '/_init.php';

$user = require_login();
$id   = (int)($_GET['id'] ?? 0);
$down = !empty($_GET['dl']);

$att = attachment_for_user($id, $user);
if (!$att) {
    http_response_code(404);
    exit('Không tìm thấy tệp.');
}

$path = attachment_abs_path($att);
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Tệp không còn tồn tại trên máy chủ.');
}

$size = filesize($path);
$mime = $att['mime'] ?: 'application/octet-stream';

// Chỉ hiển thị trực tiếp các định dạng an toàn; còn lại buộc tải xuống.
$inlineSafe = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
               'application/pdf', 'text/plain', 'audio/mpeg', 'audio/wav', 'audio/ogg',
               'audio/mp4', 'video/mp4', 'video/webm'];
$disposition = ($down || !in_array(strtolower($mime), $inlineSafe, true)) ? 'attachment' : 'inline';
if ($disposition === 'attachment') {
    // Ngăn trình duyệt tự đoán kiểu nội dung với tệp tải xuống.
    $mime = 'application/octet-stream';
}

$fileName = $att['original_name'];
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $fileName);
$asciiName = str_replace(['"', '\\'], '_', $asciiName);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: ' . $disposition
    . '; filename="' . $asciiName . '"'
    . "; filename*=UTF-8''" . rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; media-src \'self\'; style-src \'unsafe-inline\'; sandbox');
header('Cache-Control: private, max-age=86400');
header('Accept-Ranges: none');

$fp = fopen($path, 'rb');
if ($fp) {
    fpassthru($fp);
    fclose($fp);
}
exit;
