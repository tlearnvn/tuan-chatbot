<?php
/**
 * Xử lý tệp: phân loại, trích xuất nội dung văn bản, đọc/ghi nội dung tệp.
 *
 * Nội dung tệp được lưu trong cơ sở dữ liệu (xem includes/storage.php) nên ứng
 * dụng KHÔNG ghi tệp nào xuống đĩa — tránh tốn inode của shared hosting.
 * Các hàm *_legacy_* chỉ phục vụ những bản ghi cũ từ trước khi đổi cách lưu.
 */

/**
 * Đường dẫn tới thư mục uploads cũ. Chỉ dùng để đọc lại tệp của bản cũ,
 * ứng dụng không còn ghi gì vào đây nữa.
 */
function upload_path($sub = '')
{
    $dir = APP_ROOT . '/' . trim((string)cfg('upload_dir', 'uploads'), '/');
    if ($sub !== '') {
        $dir .= '/' . trim($sub, '/');
    }
    return $dir;
}

/** Phân loại tệp thành nhóm để giao diện và bộ chuyển đổi API dùng. */
function file_kind($mime, $ext)
{
    $ext  = strtolower($ext);
    $mime = strtolower((string)$mime);

    if (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic'], true)) {
        return 'image';
    }
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return 'pdf';
    }
    if (strpos($mime, 'audio/') === 0 || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) {
        return 'audio';
    }
    if (strpos($mime, 'video/') === 0 || in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) {
        return 'video';
    }
    $textExt = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx',
                'py', 'php', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rb', 'rs', 'sql', 'yml', 'yaml',
                'ini', 'conf', 'log', 'sh', 'bat', 'env', 'srt', 'vtt'];
    if (strpos($mime, 'text/') === 0 || in_array($ext, $textExt, true)
        || in_array($mime, ['application/json', 'application/xml', 'application/javascript'], true)) {
        return 'text';
    }
    return 'file';
}

/** Emoji minh hoạ cho từng loại tệp. */
function file_icon($kind, $ext = '')
{
    switch ($kind) {
        case 'image': return '🖼️';
        case 'pdf':   return '📕';
        case 'audio': return '🎵';
        case 'video': return '🎬';
        case 'text':
            $code = ['php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb', 'rs', 'sql', 'html', 'css'];
            return in_array(strtolower($ext), $code, true) ? '💻' : '📄';
        default:
            if (in_array(strtolower($ext), ['zip', 'rar', '7z', 'tar', 'gz'], true)) return '🗜️';
            if (in_array(strtolower($ext), ['doc', 'docx'], true)) return '📘';
            if (in_array(strtolower($ext), ['xls', 'xlsx', 'csv'], true)) return '📗';
            if (in_array(strtolower($ext), ['ppt', 'pptx'], true)) return '📙';
            return '📎';
    }
}

/** Dò MIME thực tế của tệp trên đĩa. */
function detect_mime($path, $fallback = 'application/octet-stream')
{
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) {
            return $mime;
        }
    }
    return $fallback;
}

/** MIME suy ra từ phần mở rộng (dùng khi trả tệp về trình duyệt). */
function mime_from_ext($ext)
{
    static $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'json' => 'application/json', 'xml' => 'application/xml', 'html' => 'text/html', 'htm' => 'text/html',
        'css' => 'text/css', 'js' => 'text/javascript', 'zip' => 'application/zip',
        'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
    ];
    $ext = strtolower(ltrim((string)$ext, '.'));
    return $map[$ext] ?? 'application/octet-stream';
}

/** Phần mở rộng suy ra từ MIME (dùng cho tệp AI trả về). */
function ext_from_mime($mime)
{
    static $map = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/svg+xml' => 'svg', 'image/bmp' => 'bmp', 'application/pdf' => 'pdf',
        'text/plain' => 'txt', 'text/markdown' => 'md', 'text/csv' => 'csv', 'text/html' => 'html',
        'application/json' => 'json', 'application/xml' => 'xml', 'application/zip' => 'zip',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'video/mp4' => 'mp4',
    ];
    $mime = strtolower(trim((string)$mime));
    if (isset($map[$mime])) {
        return $map[$mime];
    }
    if (preg_match('#/([a-z0-9.+-]+)$#', $mime, $m)) {
        return preg_replace('/[^a-z0-9]/', '', str_replace(['x-', '+xml'], '', $m[1])) ?: 'bin';
    }
    return 'bin';
}

/**
 * Trích xuất văn bản từ tệp để gửi kèm vào ngữ cảnh của AI.
 * Hỗ trợ: text thuần, docx/xlsx/pptx (đọc XML trong zip), pdf (thử giải nén stream).
 */
function extract_text_from_file($path, $kind, $ext, $limit = 120000)
{
    $ext = strtolower($ext);

    if ($kind === 'text') {
        $raw = @file_get_contents($path, false, null, 0, $limit + 1024);
        return $raw === false ? '' : normalize_text($raw, $limit);
    }

    if (in_array($ext, ['docx', 'xlsx', 'pptx'], true) && class_exists('ZipArchive')) {
        return normalize_text(extract_ooxml_text($path, $ext), $limit);
    }

    if ($ext === 'pdf' || $kind === 'pdf') {
        return normalize_text(extract_pdf_text($path), $limit);
    }

    return '';
}

/** Chuẩn hoá văn bản: bỏ ký tự điều khiển, giới hạn độ dài. */
function normalize_text($text, $limit = 120000)
{
    $text = (string)$text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = @iconv('WINDOWS-1258', 'UTF-8//IGNORE', $text);
        if ($converted === false) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
        }
        $text = $converted === false ? '' : $converted;
    }
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    $text = preg_replace("/\n{4,}/", "\n\n\n", $text);
    if (mb_strlen($text) > $limit) {
        $text = mb_substr($text, 0, $limit) . "\n\n[… nội dung đã được cắt bớt do quá dài …]";
    }
    return trim($text);
}

/** Đọc văn bản trong tệp Office Open XML (docx/xlsx/pptx). */
function extract_ooxml_text($path, $ext)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $targets = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($ext === 'docx' && preg_match('#^word/(document|footnotes|endnotes)\d*\.xml$#', $name)) {
            $targets[] = $name;
        } elseif ($ext === 'xlsx' && preg_match('#^xl/(sharedStrings\.xml|worksheets/sheet\d+\.xml)$#', $name)) {
            $targets[] = $name;
        } elseif ($ext === 'pptx' && preg_match('#^ppt/(slides|notesSlides)/[a-zA-Z]+\d+\.xml$#', $name)) {
            $targets[] = $name;
        }
    }
    sort($targets);
    $out = [];
    foreach ($targets as $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false) {
            continue;
        }
        // Giữ ngắt đoạn/dòng trước khi bỏ thẻ.
        $xml = preg_replace('#</(w:p|a:p|w:tr|row)>#', "\n", $xml);
        $xml = preg_replace('#<(w:br|w:tab|a:br)[^>]*/?>#', " ", $xml);
        $xml = preg_replace('#</(w:tc|c)>#', "\t", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        if (trim($text) !== '') {
            $out[] = trim($text);
        }
    }
    $zip->close();
    return implode("\n\n", $out);
}

/** Thử rút văn bản từ PDF (không cần thư viện ngoài — phù hợp shared hosting). */
function extract_pdf_text($path)
{
    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return '';
    }
    $chunks = [];

    // Các stream nén Flate chứa nội dung trang.
    if (preg_match_all('#stream\r?\n?(.*?)endstream#s', $data, $matches)) {
        foreach ($matches[1] as $stream) {
            $raw = @gzuncompress(ltrim($stream, "\r\n"));
            if ($raw === false) {
                $raw = @gzinflate(ltrim($stream, "\r\n"));
            }
            if ($raw === false) {
                $raw = $stream;
            }
            if (strpos($raw, 'Tj') === false && strpos($raw, 'TJ') === false) {
                continue;
            }
            $chunks[] = pdf_stream_to_text($raw);
        }
    }

    $text = trim(implode("\n", array_filter($chunks)));
    if ($text === '') {
        return '';
    }
    return $text;
}

/** Bóc các toán tử hiển thị chữ trong content stream của PDF. */
function pdf_stream_to_text($raw)
{
    $out = '';
    // ( ... ) Tj   và   [ (..) -250 (..) ] TJ
    if (preg_match_all('#\[(.*?)\]\s*TJ|\(((?:\\\\.|[^\\\\()])*)\)\s*Tj#s', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $item) {
            if (!empty($item[1])) {
                if (preg_match_all('#\(((?:\\\\.|[^\\\\()])*)\)#s', $item[1], $inner)) {
                    $out .= implode('', array_map('pdf_unescape', $inner[1]));
                }
                $out .= ' ';
            } elseif (isset($item[2])) {
                $out .= pdf_unescape($item[2]) . ' ';
            }
        }
    }
    $out = preg_replace('/[ ]{2,}/', ' ', $out);
    return trim($out);
}

/** Giải mã chuỗi PDF literal. */
function pdf_unescape($string)
{
    $map = ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\b", '\\f' => "\f",
            '\\(' => '(', '\\)' => ')', '\\\\' => '\\'];
    $string = strtr($string, $map);
    $string = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
        return chr(octdec($m[1]));
    }, $string);
    if (!mb_check_encoding($string, 'UTF-8')) {
        $conv = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $string);
        $string = $conv === false ? '' : $conv;
    }
    return $string;
}

/** Lấy bản ghi tệp đính kèm nếu người dùng có quyền xem. */
function attachment_for_user($attachmentId, $user)
{
    $row = db_one('SELECT * FROM `attachments` WHERE `id` = ? LIMIT 1', [(int)$attachmentId]);
    if (!$row) {
        return null;
    }
    if ($user['role'] !== 'admin' && (int)$row['user_id'] !== (int)$user['id']) {
        return null;
    }
    return $row;
}

/**
 * Đường dẫn trên đĩa của tệp CŨ (bản ghi có storage = 'file').
 * Tệp mới nằm hoàn toàn trong cơ sở dữ liệu nên hàm này trả về null.
 */
function attachment_legacy_path($row)
{
    if (($row['storage'] ?? 'db') !== 'file' || (string)$row['stored_name'] === '') {
        return null;
    }
    $stored = ltrim(str_replace('\\', '/', (string)$row['stored_name']), '/');
    if (strpos($stored, '..') !== false) {
        return null;
    }
    $real = realpath(upload_path($stored));
    $base = realpath(upload_path());
    if ($real === false || $base === false || strpos($real, $base) !== 0) {
        return null;
    }
    return $real;
}

/**
 * Đọc toàn bộ nội dung của một tệp đính kèm.
 *
 * @param array    $row       Bản ghi bảng attachments
 * @param int|null $maxBytes  Vượt mức này thì trả về null (tránh hết bộ nhớ)
 * @return string|null
 */
function attachment_binary($row, $maxBytes = null)
{
    if (($row['storage'] ?? 'db') === 'file') {
        $path = attachment_legacy_path($row);
        if (!$path || !is_file($path)) {
            return null;
        }
        if ($maxBytes !== null && filesize($path) > $maxBytes) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }
    return storage_get($row['id'], $maxBytes);
}

/** Đẩy nội dung tệp ra trình duyệt, dùng chung cho cả tệp mới và tệp cũ. */
function attachment_passthru($row)
{
    if (($row['storage'] ?? 'db') === 'file') {
        $path = attachment_legacy_path($row);
        if (!$path || !is_file($path)) {
            return 0;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return 0;
        }
        $sent = fpassthru($handle);
        fclose($handle);
        return (int)$sent;
    }
    return storage_passthru($row['id']);
}

/** Dung lượng thực tế của tệp đính kèm. */
function attachment_size($row)
{
    if (($row['storage'] ?? 'db') === 'file') {
        $path = attachment_legacy_path($row);
        return ($path && is_file($path)) ? (int)filesize($path) : 0;
    }
    return (int)$row['size'] ?: storage_size($row['id']);
}

/** Nội dung tệp còn tồn tại không? */
function attachment_available($row)
{
    if (($row['storage'] ?? 'db') === 'file') {
        $path = attachment_legacy_path($row);
        return $path !== null && is_file($path);
    }
    return storage_exists($row['id']);
}

/**
 * Lưu dữ liệu nhị phân do AI trả về vào cơ sở dữ liệu, thành tệp tải xuống được.
 *
 * @return array Thông tin tệp vừa tạo (dùng cho sự kiện SSE và giao diện).
 */
function store_output_file($userId, $conversationId, $binary, $mime, $suggestedName = '')
{
    $ext = $suggestedName !== '' ? strtolower(pathinfo($suggestedName, PATHINFO_EXTENSION)) : '';
    if ($ext === '') {
        $ext = ext_from_mime($mime);
    }
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';

    $base = $suggestedName !== ''
        ? pathinfo(safe_filename($suggestedName), PATHINFO_FILENAME)
        : 'ai-output-' . date('Ymd-His');
    $base = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '', $base);
    $base = trim($base) !== '' ? $base : 'ai-output';

    $name = $base . '.' . $ext;
    $kind = file_kind($mime, $ext);
    $size = strlen($binary);

    $id = db_insert('attachments', [
        'user_id'         => (int)$userId,
        'conversation_id' => $conversationId ? (int)$conversationId : null,
        'message_id'      => null,
        'direction'       => 'out',
        'original_name'   => $name,
        'storage'         => 'db',
        'stored_name'     => '',
        'mime'            => mb_substr($mime, 0, 160),
        'size'            => $size,
        'kind'            => $kind,
        'extracted_text'  => null,
        'created_at'      => now_vn(),
    ]);

    try {
        storage_put($id, $binary);
    } catch (Exception $ex) {
        // Không lưu được nội dung thì bỏ luôn bản ghi để không còn tệp "rỗng".
        db_run('DELETE FROM `attachments` WHERE `id` = ?', [$id]);
        throw new RuntimeException('Không lưu được tệp kết quả vào cơ sở dữ liệu: ' . $ex->getMessage());
    }

    return [
        'id'   => $id,
        'name' => $name,
        'mime' => $mime,
        'size' => $size,
        'kind' => $kind,
        'url'  => 'api/download.php?id=' . $id,
    ];
}

/**
 * Xoá phần nội dung của một tệp đính kèm.
 *
 * Với tệp trong cơ sở dữ liệu, khoá ngoại đã tự xoá các khối khi bản ghi
 * attachments bị xoá — hàm này chỉ cần thiết cho tệp cũ còn nằm trên đĩa.
 */
function delete_attachment_file($row)
{
    $path = attachment_legacy_path($row);
    if ($path && is_file($path)) {
        @unlink($path);
    }
}
