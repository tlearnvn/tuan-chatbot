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
            $ext = strtolower($ext);
            if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) return '🗜️';
            if (in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'dot'], true)) return '📘';
            if (in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true)) return '📗';
            if (in_array($ext, ['ppt', 'pptx', 'odp'], true)) return '📙';
            if ($ext === 'epub') return '📚';
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
 *
 * Trả về chuỗi rỗng khi không đọc được. Dùng extract_file_content() nếu cần
 * biết *lý do* không đọc được để nói lại cho người dùng và cho mô hình.
 */
function extract_text_from_file($path, $kind, $ext, $limit = 120000)
{
    $result = extract_file_content($path, $kind, $ext, $limit);
    return $result['text'];
}

/**
 * Trích xuất nội dung văn bản kèm lý do thất bại.
 *
 * Định dạng đọc được nội dung:
 *   - văn bản thuần, mã nguồn, csv, json, xml, srt…       (đọc trực tiếp)
 *   - html/htm                                            (bỏ thẻ, giữ chữ)
 *   - docx / xlsx / pptx                                  (XML trong ZIP)
 *   - odt / ods / odp / odg  (LibreOffice, Google Docs)   (XML trong ZIP)
 *   - epub                                                (XHTML trong ZIP)
 *   - rtf                                                 (bỏ mã điều khiển)
 *   - doc / xls / ppt  (Office 97-2003)                   (vớt chữ trong OLE)
 *   - pdf                                                 (xem includes/pdf.php)
 *   - zip                                                 (liệt kê danh sách tệp bên trong)
 *
 * Định dạng KHÔNG thể đọc bằng PHP thuần — phải để mô hình tự xem tệp:
 *   - ảnh (cần thị giác máy tính), âm thanh, video
 *   - PDF scan từ máy photocopy (chỉ có ảnh, không có chữ)
 *
 * @return array ['text' => string, 'reason' => string, 'note' => string]
 */
function extract_file_content($path, $kind, $ext, $limit = 120000)
{
    $ext = strtolower((string)$ext);
    $ok  = function ($text) use ($limit) {
        $text = normalize_text($text, $limit);
        return ['text' => $text, 'reason' => $text === '' ? 'empty' : '', 'note' => ''];
    };

    // --- Văn bản thuần và mã nguồn -----------------------------------------
    if (in_array($ext, ['html', 'htm', 'xhtml'], true)) {
        $raw = @file_get_contents($path, false, null, 0, ($limit * 8) + 1024);
        return $ok($raw === false ? '' : extract_html_text($raw));
    }
    if ($kind === 'text') {
        $raw = @file_get_contents($path, false, null, 0, $limit + 1024);
        return $ok($raw === false ? '' : $raw);
    }

    // --- Các định dạng đóng gói bằng ZIP ------------------------------------
    if (class_exists('ZipArchive')) {
        if (in_array($ext, ['docx', 'xlsx', 'pptx', 'docm', 'xlsm', 'pptm'], true)) {
            return $ok(extract_ooxml_text($path, preg_replace('/m$/', 'x', $ext)));
        }
        if (in_array($ext, ['odt', 'ods', 'odp', 'odg', 'odf', 'fodt'], true)) {
            return $ok(extract_odf_text($path));
        }
        if ($ext === 'epub') {
            return $ok(extract_epub_text($path, $limit));
        }
        if (in_array($ext, ['zip', 'jar', 'apk'], true)) {
            $listing = extract_zip_listing($path);
            if ($listing !== '') {
                return ['text' => $listing, 'reason' => 'archive_listing', 'note' => ''];
            }
        }
    }

    // --- RTF ----------------------------------------------------------------
    if ($ext === 'rtf') {
        $raw = @file_get_contents($path, false, null, 0, ($limit * 8) + 1024);
        return $ok($raw === false ? '' : extract_rtf_text($raw));
    }

    // --- Office 97-2003 (định dạng nhị phân OLE) ----------------------------
    if (in_array($ext, ['doc', 'xls', 'ppt', 'dot', 'wps'], true)) {
        $text = extract_ole_text($path, $limit);
        if (trim($text) === '') {
            return ['text' => '', 'reason' => 'legacy_office', 'note' => ''];
        }
        return ['text' => normalize_text($text, $limit), 'reason' => 'legacy_office_partial', 'note' => ''];
    }

    // --- PDF ----------------------------------------------------------------
    if ($ext === 'pdf' || $kind === 'pdf') {
        require_once __DIR__ . '/pdf.php';
        $info = pdf_extract_info($path, $limit);
        $text = normalize_text($info['text'], $limit);
        if ($text !== '') {
            return ['text' => $text, 'reason' => '', 'note' => ''];
        }
        return ['text' => '', 'reason' => 'pdf_' . ($info['reason'] ?: 'empty'), 'note' => ''];
    }

    // --- Những gì chỉ mô hình mới "xem" được --------------------------------
    if ($kind === 'image') {
        return ['text' => '', 'reason' => 'needs_vision', 'note' => ''];
    }
    if ($kind === 'audio' || $kind === 'video') {
        return ['text' => '', 'reason' => 'needs_media', 'note' => ''];
    }

    return ['text' => '', 'reason' => 'unsupported', 'note' => ''];
}

/**
 * Câu giải thích cho người dùng và cho mô hình khi không đọc được nội dung tệp.
 *
 * @param string $reason Mã lý do do extract_file_content() trả về
 * @param string $kind   Nhóm tệp, dùng cho các lý do chung
 */
function extract_reason_text($reason, $kind = '')
{
    switch ($reason) {
        case '':
            return '';
        case 'pdf_no_text':
            return 'PDF này chỉ chứa ảnh chụp/scan nên không có chữ để đọc.';
        case 'pdf_no_tounicode':
            return 'PDF này dùng phông nhúng thiếu bảng ánh xạ Unicode nên không đọc được chữ.';
        case 'pdf_encrypted':
            return 'PDF này bị đặt mật khẩu hoặc mã hoá nên không mở được nội dung.';
        case 'pdf_too_large':
            return 'PDF này quá lớn để phân tích nội dung.';
        case 'pdf_not_pdf':
            return 'Tệp có đuôi .pdf nhưng nội dung không phải PDF hợp lệ.';
        case 'pdf_unreadable':
        case 'pdf_no_objects':
        case 'pdf_empty':
            return 'Không rút được chữ nào từ PDF này.';
        case 'needs_vision':
            return 'Đây là tệp ảnh — chỉ mô hình có khả năng nhìn ảnh mới đọc được.';
        case 'needs_media':
            return 'Đây là tệp âm thanh/video — chỉ mô hình hỗ trợ đa phương tiện mới xử lý được.';
        case 'legacy_office':
            return 'Đây là tệp Office 97-2003 (.doc/.xls/.ppt) nhưng không vớt được chữ nào. '
                 . 'Hãy lưu lại dưới dạng .docx/.xlsx hoặc PDF rồi gửi lại.';
        case 'legacy_office_partial':
            return 'Đây là tệp Office 97-2003 nên nội dung đọc được có thể thiếu hoặc lộn xộn. '
                 . 'Nếu cần chính xác, hãy lưu lại dưới dạng .docx hoặc PDF.';
        case 'archive_listing':
            return 'Đây là tệp nén — chỉ đọc được danh sách tệp bên trong, không đọc được nội dung từng tệp.';
        case 'empty':
            return 'Tệp này không có nội dung văn bản nào.';
        case 'unsupported':
        default:
            return 'Không đọc được nội dung của định dạng tệp này'
                 . ($kind === 'file' ? ' bằng máy chủ' : '') . '.';
    }
}

/** Bỏ thẻ HTML, giữ lại phần chữ đọc được. */
function extract_html_text($html)
{
    // Bỏ hẳn phần không phải nội dung.
    $html = preg_replace('#<(script|style|noscript|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html);
    // Giữ ngắt dòng ở các thẻ khối.
    $html = preg_replace('#</(p|div|li|tr|h[1-6]|section|article|blockquote|pre)>#i', "\n", $html);
    $html = preg_replace('#<(br|hr)\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(td|th)>#i', "\t", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    return $text;
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
    // Khoảng trắng "lạ" (nbsp của HTML/Office, dấu ngắt dòng mềm, BOM giữa tệp)
    // đưa về khoảng trắng thường để mô hình đọc đúng và tìm kiếm được.
    $text = str_replace(["\xC2\xA0", "\xE2\x80\xA8", "\xE2\x80\xA9", "\xEF\xBB\xBF",
                         "\xE2\x80\x8B", "\xC2\xAD"],
                        [' ', "\n", "\n", '', '', ''], $text);
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

/** Rút văn bản từ PDF — bộ phân tích đầy đủ nằm ở includes/pdf.php. */
function extract_pdf_text($path, $limit = 120000)
{
    require_once __DIR__ . '/pdf.php';
    return pdf_extract_text($path, $limit);
}

/** Đọc văn bản trong tệp OpenDocument (odt/ods/odp — LibreOffice, Google Docs). */
function extract_odf_text($path)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $out = [];
    foreach (['content.xml', 'styles.xml'] as $name) {
        $xml = $zip->getFromName($name);
        if ($xml === false || $xml === '') {
            continue;
        }
        // Giữ ngắt đoạn, ngắt dòng và ô bảng trước khi bỏ thẻ.
        $xml = preg_replace('#</(text:p|text:h|table:table-row)>#', "\n", $xml);
        $xml = preg_replace('#<text:line-break[^>]*/?>#', "\n", $xml);
        $xml = preg_replace('#<text:(tab|s)[^>]*/?>#', ' ', $xml);
        $xml = preg_replace('#</table:table-cell>#', "\t", $xml);
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

/** Đọc văn bản trong sách EPUB (các chương là XHTML nằm trong ZIP). */
function extract_epub_text($path, $limit = 120000)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#\.(xhtml|html|htm)$#i', (string)$name)) {
            $names[] = $name;
        }
    }
    sort($names);   // tên chương thường có số thứ tự
    $out  = [];
    $size = 0;
    foreach ($names as $name) {
        $html = $zip->getFromName($name);
        if ($html === false || $html === '') {
            continue;
        }
        $text = trim(extract_html_text($html));
        if ($text === '') {
            continue;
        }
        $out[] = $text;
        $size += strlen($text);
        if ($size > $limit * 4) {
            break;
        }
    }
    $zip->close();
    return implode("\n\n", $out);
}

/** Đọc văn bản trong tệp RTF (bỏ nhóm điều khiển, giải mã \'xx và \uN). */
function extract_rtf_text($rtf)
{
    if (strpos($rtf, '{\\rtf') === false && strpos($rtf, '{\\*\\') === false) {
        return '';
    }
    // Bỏ các nhóm chỉ chứa siêu dữ liệu/nhị phân (phông, màu, ảnh, chỉ mục…).
    // Tiền tố có hai dạng: "{\fonttbl…}" và "{\*\generator…}".
    $groups = 'fonttbl|colortbl|stylesheet|info|pict|object|objdata|themedata|'
            . 'colorschememapping|latentstyles|datastore|generator|listtable|'
            . 'listoverridetable|rsidtbl|xmlnstbl|fchars|lchars|filetbl|revtbl';
    for ($pass = 0; $pass < 3; $pass++) {
        // Lặp vài lượt để bóc dần nhóm lồng nhau từ trong ra ngoài.
        $rtf = preg_replace('#\{\\\\(?:\*\\\\)?(?:' . $groups . ')\b[^{}]*(?:\{[^{}]*\}[^{}]*)*\}#is',
                            ' ', $rtf);
    }
    // Ngắt đoạn/dòng.
    $rtf = preg_replace('#\\\\(par|line|pard|sect|page)\b#', "\n", $rtf);
    $rtf = preg_replace('#\\\\(tab|cell)\b#', "\t", $rtf);
    // \uN? — ký tự Unicode kèm ký tự thay thế phía sau.
    $rtf = preg_replace_callback('#\\\\u(-?\d+)\s*\??#', function ($m) {
        $code = (int)$m[1];
        if ($code < 0) {
            $code += 65536;
        }
        return ($code > 0 && $code < 0x110000) ? mb_chr($code, 'UTF-8') : '';
    }, $rtf);
    // \'xx — byte theo bảng mã của tài liệu (mặc định CP1252).
    $rtf = preg_replace_callback("#\\\\'([0-9a-fA-F]{2})#", function ($m) {
        $char = chr(hexdec($m[1]));
        $conv = @iconv('CP1252', 'UTF-8//IGNORE', $char);
        return $conv === false ? '' : $conv;
    }, $rtf);
    // Các từ điều khiển còn lại và dấu ngoặc nhóm.
    $rtf = preg_replace('#\\\\[a-zA-Z]+-?\d*\s?#', '', $rtf);
    $rtf = preg_replace('#\\\\[^a-zA-Z]#', '', $rtf);
    $rtf = str_replace(['{', '}'], '', $rtf);
    $rtf = preg_replace('/[ \t]{2,}/', ' ', $rtf);
    return $rtf;
}

/**
 * Vớt chữ trong tệp Office 97-2003 (.doc/.xls/.ppt).
 *
 * Các định dạng này là ổ đĩa OLE nhị phân, đọc đúng cấu trúc thì cần cả một thư
 * viện. Ở đây chỉ lấy những đoạn ký tự đọc được (cả một byte và UTF-16LE) rồi
 * lọc bỏ tên stream nội bộ — kết quả gần đúng, đủ để mô hình hiểu nội dung, nên
 * phần gọi luôn kèm ghi chú "có thể thiếu hoặc lộn xộn".
 */
function extract_ole_text($path, $limit = 120000)
{
    $data = @file_get_contents($path, false, null, 0, 8 * 1024 * 1024);
    if ($data === false || $data === '') {
        return '';
    }
    // Rác kỹ thuật của OLE/Word không phải nội dung người dùng.
    $noise = ['Root Entry', 'WordDocument', 'ObjectPool', 'CompObj', 'SummaryInformation',
              'DocumentSummaryInformation', 'Workbook', 'PowerPoint Document', 'Current User',
              'Pictures', 'Data', '1Table', '0Table', 'MsoDataStore', 'Microsoft Word',
              'Times New Roman', 'Calibri', 'Arial', 'Symbol', 'Wingdings', 'Cambria Math',
              'Normal.dotm', 'Office Word', 'MSWordDoc', 'Word.Document'];

    $pieces = [];

    // Word 97+ lưu nội dung dạng UTF-16LE. Không thể tìm bằng mẫu "chữ + 0x00":
    // tiếng Việt có rất nhiều ký tự ngoài Latin-1 (chữ "ộ" là U+1ED9 ⇒ byte cao
    // 0x1E chứ không phải 0x00), mẫu đó sẽ cắt câu ngay tại mỗi dấu thanh.
    // Vì vậy giải mã cả vùng dữ liệu rồi mới lọc ra những đoạn ra chữ.
    // Gióng byte chẵn gần như luôn đúng (sector OLE là bội của 512). Chỉ khi
    // không ra chữ nào mới thử lệch một byte — giải mã lệch biến chữ ASCII
    // thành ký tự Hán nên không dùng song song cả hai cách.
    foreach ([0, 1] as $shift) {
        $chunk = $shift ? substr($data, 1) : $data;
        if (strlen($chunk) % 2 !== 0) {
            $chunk = substr($chunk, 0, -1);
        }
        $text = @mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE');
        if ($text === false || $text === null || $text === '') {
            continue;
        }
        if (preg_match_all('/[\p{L}\p{N}\p{P}\x20\t]{12,}/u', $text, $m)) {
            foreach ($m[0] as $run) {
                $pieces[] = $run;
            }
        }
        if ($pieces) {
            break;
        }
    }
    // Bản Word/Excel cũ hơn lưu chữ một byte theo bảng mã CP1252.
    if (preg_match_all('#[\x20-\x7E\x09]{14,}#', $data, $m)) {
        foreach ($m[0] as $run) {
            $conv = @iconv('CP1252', 'UTF-8//IGNORE', $run);
            if ($conv !== false && $conv !== '') {
                $pieces[] = $conv;
            }
        }
    }
    unset($data);

    $out  = [];
    $size = 0;
    foreach ($pieces as $piece) {
        $piece = trim(preg_replace('/[ \t]{2,}/', ' ', $piece));
        if (mb_strlen($piece) < 8) {
            continue;
        }
        foreach ($noise as $bad) {
            if (stripos($piece, $bad) !== false && mb_strlen($piece) < mb_strlen($bad) + 16) {
                continue 2;
            }
        }
        // Phải có tỉ lệ chữ/khoảng trắng hợp lý mới coi là câu văn.
        if (!preg_match('/[a-zA-Z\p{L}]{3}/u', $piece)) {
            continue;
        }
        $out[] = $piece;
        $size += mb_strlen($piece);
        if ($size > $limit) {
            break;
        }
    }
    // Nhiều đoạn bị lặp do Word lưu cả bản nháp — bỏ trùng, giữ thứ tự.
    $out = array_values(array_unique($out));
    return implode("\n", $out);
}

/** Liệt kê nội dung tệp nén để mô hình biết bên trong có gì. */
function extract_zip_listing($path, $maxEntries = 400)
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $lines   = [];
    $total   = 0;
    $entries = $zip->numFiles;
    $count   = min($entries, $maxEntries);
    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) {
            continue;
        }
        $lines[] = '- ' . $stat['name'] . ' (' . fmt_bytes((int)$stat['size']) . ')';
        $total  += (int)$stat['size'];
    }
    $more = $entries - $count;
    $zip->close();
    if (!$lines) {
        return '';
    }
    $head = 'Tệp nén gồm ' . $entries . ' mục, tổng ' . fmt_bytes($total)
          . " sau khi giải nén. Danh sách:\n";
    return $head . implode("\n", $lines)
         . ($more > 0 ? "\n… và " . $more . ' mục nữa.' : '');
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
