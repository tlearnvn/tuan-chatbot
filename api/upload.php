<?php
/**
 * API tải tệp lên. Nhận nhiều tệp cùng lúc qua trường `files[]`.
 * Tệp được lưu vào thư mục uploads/YYYY/MM, chưa gắn với tin nhắn nào cho tới khi gửi.
 */
require_once __DIR__ . '/_init.php';

$user = require_login(true);
api_require_method('POST');
csrf_require(true);

if (empty($_FILES['files'])) {
    json_error('Không nhận được tệp nào. Có thể tệp vượt quá giới hạn upload_max_filesize của máy chủ.');
}

$maxBytes   = setting_int('max_upload_mb', 25) * 1024 * 1024;
$maxFiles   = setting_int('max_files_per_msg', 10);
$allowedExt = allowed_extensions();

$files = $_FILES['files'];
$count = is_array($files['name']) ? count($files['name']) : 1;
if ($count > $maxFiles) {
    json_error('Mỗi lần chỉ gửi tối đa ' . $maxFiles . ' tệp.');
}

$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'Tệp vượt quá giới hạn của máy chủ (upload_max_filesize).',
    UPLOAD_ERR_FORM_SIZE  => 'Tệp vượt quá giới hạn cho phép.',
    UPLOAD_ERR_PARTIAL    => 'Tệp chỉ được tải lên một phần, vui lòng thử lại.',
    UPLOAD_ERR_NO_FILE    => 'Không có tệp nào được chọn.',
    UPLOAD_ERR_NO_TMP_DIR => 'Máy chủ thiếu thư mục tạm.',
    UPLOAD_ERR_CANT_WRITE => 'Máy chủ không ghi được tệp xuống đĩa.',
    UPLOAD_ERR_EXTENSION  => 'Một phần mở rộng PHP đã chặn việc tải tệp lên.',
];

// Các phần mở rộng tuyệt đối không cho phép, dù cấu hình có mở rộng thế nào.
$blockedExt = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
               'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'sh', 'bash', 'exe', 'dll', 'so',
               'htaccess', 'htpasswd'];

$results = [];
$errors  = [];

for ($i = 0; $i < $count; $i++) {
    $name    = is_array($files['name']) ? $files['name'][$i] : $files['name'];
    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
    $error   = is_array($files['error']) ? $files['error'][$i] : $files['error'];
    $size    = (int)(is_array($files['size']) ? $files['size'][$i] : $files['size']);

    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = $name . ': ' . ($uploadErrors[$error] ?? 'Lỗi tải lên không xác định.');
        continue;
    }
    if (!is_uploaded_file($tmpName)) {
        $errors[] = $name . ': Nguồn tệp không hợp lệ.';
        continue;
    }
    if ($size <= 0) {
        $errors[] = $name . ': Tệp rỗng.';
        continue;
    }
    if ($size > $maxBytes) {
        $errors[] = $name . ': Vượt quá ' . setting_int('max_upload_mb', 25) . 'MB.';
        continue;
    }

    $safeName = safe_filename($name);
    $ext      = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

    if ($ext === '' || in_array($ext, $blockedExt, true)) {
        $errors[] = $name . ': Định dạng tệp này không được phép vì lý do an toàn.';
        continue;
    }
    if ($allowedExt && !in_array($ext, $allowedExt, true)) {
        $errors[] = $name . ': Định dạng ".' . $ext . '" chưa được cho phép.';
        continue;
    }

    try {
        list($month, $dir) = upload_ensure_dir();
    } catch (Exception $ex) {
        json_error('Không tạo được thư mục lưu tệp trên máy chủ. Hãy cấp quyền ghi cho thư mục uploads/.', 500);
    }

    $mime   = detect_mime($tmpName, mime_from_ext($ext));
    $kind   = file_kind($mime, $ext);
    $stored = $month . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);

    if (!@move_uploaded_file($tmpName, upload_path($stored))) {
        $errors[] = $name . ': Không lưu được tệp lên máy chủ.';
        continue;
    }
    @chmod(upload_path($stored), 0644);

    // SVG có thể chứa script — xử lý như tệp văn bản thuần để tránh XSS khi xem lại.
    if ($ext === 'svg') {
        $mime = 'image/svg+xml';
        $kind = 'text';
    }

    $extracted = '';
    try {
        $extracted = extract_text_from_file(upload_path($stored), $kind, $ext);
    } catch (Exception $ex) {
        $extracted = '';
    }

    $id = db_insert('attachments', [
        'user_id'         => (int)$user['id'],
        'conversation_id' => null,
        'message_id'      => null,
        'direction'       => 'in',
        'original_name'   => $safeName,
        'stored_name'     => $stored,
        'mime'            => mb_substr($mime, 0, 160),
        'size'            => $size,
        'kind'            => $kind,
        'extracted_text'  => $extracted !== '' ? $extracted : null,
        'created_at'      => now_vn(),
    ]);

    $results[] = [
        'id'            => $id,
        'name'          => $safeName,
        'mime'          => $mime,
        'size'          => $size,
        'sizeText'      => fmt_bytes($size),
        'kind'          => $kind,
        'icon'          => file_icon($kind, $ext),
        'url'           => 'api/download.php?id=' . $id,
        'hasText'       => $extracted !== '',
        'textLength'    => mb_strlen($extracted),
    ];
}

if ($results) {
    log_activity('upload', count($results) . ' tệp: ' . implode(', ', array_column($results, 'name')));
}

if (!$results && $errors) {
    json_error(implode(' · ', $errors), 400);
}

json_out(['ok' => true, 'files' => $results, 'errors' => $errors]);
