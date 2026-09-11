<?php
/**
 * Rút văn bản từ tệp PDF — không cần thư viện ngoài, chạy được trên shared hosting.
 *
 * Vì sao cần cả một bộ phân tích thay vì vài dòng regex?
 * Hầu hết PDF hiện nay (in từ Chrome/Edge, xuất từ Word, Google Docs, LaTeX…)
 * vẽ chữ bằng **chuỗi hex chứa mã glyph của phông nhúng**, ví dụ:
 *
 *     <00D206AB0003004E004C06AD0050> Tj
 *
 * Các byte này KHÔNG phải mã Unicode: 0x00D2 là glyph thứ 210 trong bộ phông con
 * được nhúng, chỉ bảng `/ToUnicode` đi kèm phông mới cho biết nó là chữ "Đ".
 * Muốn đọc được tiếng Việt trong PDF thì buộc phải:
 *
 *   1. tìm các đối tượng trong tệp (kể cả đối tượng nằm trong /ObjStm của PDF 1.5+),
 *   2. giải nén luồng nội dung của từng trang,
 *   3. đọc bảng /ToUnicode của từng phông mà trang đó dùng,
 *   4. dò toán tử hiển thị chữ (Tj, TJ, ' và ") rồi tra mã glyph qua bảng trên.
 *
 * Toàn bộ việc này chỉ dùng zlib (gzuncompress/gzinflate) có sẵn trong PHP.
 * PDF scan từ máy photocopy chỉ chứa ảnh nên không có chữ để rút — khi đó hàm
 * trả về văn bản rỗng và phần gọi sẽ báo cho người dùng biết.
 */

/** Giới hạn an toàn để không bao giờ ăn hết bộ nhớ/CPU của shared hosting. */
function pdf_limits()
{
    return [
        'max_file'    => 64 * 1024 * 1024,   // tệp lớn hơn thì bỏ qua
        'max_objects' => 40000,              // số đối tượng tối đa sẽ quét
        'max_stream'  => 32 * 1024 * 1024,   // cỡ tối đa của một luồng sau giải nén
        'max_range'   => 65536,              // số mã tối đa của một bfrange
    ];
}

/**
 * Rút văn bản từ PDF.
 *
 * @param  string $path  Đường dẫn tệp
 * @param  int    $limit Số ký tự tối đa cần lấy
 * @return string
 */
function pdf_extract_text($path, $limit = 120000)
{
    $info = pdf_extract_info($path, $limit);
    return $info['text'];
}

/**
 * Rút văn bản kèm thông tin chẩn đoán.
 *
 * @return array [
 *   'text'     => string  văn bản đọc được,
 *   'pages'    => int     số trang tìm thấy,
 *   'glyphs'   => int     số mã glyph đã gặp,
 *   'unmapped' => int     số mã glyph không tra được vì phông thiếu /ToUnicode,
 *   'reason'   => string  '' nếu đọc được, ngược lại là mã lý do thất bại
 * ]
 */
function pdf_extract_info($path, $limit = 120000)
{
    $lim  = pdf_limits();
    $fail = ['text' => '', 'pages' => 0, 'glyphs' => 0, 'unmapped' => 0, 'reason' => ''];

    $size = @filesize($path);
    if ($size === false || $size <= 0) {
        $fail['reason'] = 'unreadable';
        return $fail;
    }
    if ($size > $lim['max_file']) {
        $fail['reason'] = 'too_large';
        return $fail;
    }

    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        $fail['reason'] = 'unreadable';
        return $fail;
    }
    if (strpos($data, '%PDF-') !== 0 && strpos(substr($data, 0, 1024), '%PDF-') === false) {
        $fail['reason'] = 'not_pdf';
        return $fail;
    }
    if (strpos($data, '/Encrypt') !== false && pdf_looks_encrypted($data)) {
        $fail['reason'] = 'encrypted';
        return $fail;
    }

    $objects = pdf_scan_objects($data);
    pdf_expand_object_streams($objects);
    unset($data);

    if (!$objects) {
        $fail['reason'] = 'no_objects';
        return $fail;
    }

    $cmaps  = [];    // objnum -> bảng ToUnicode đã phân tích
    $pages  = pdf_find_pages($objects);
    $out    = '';
    $stats  = ['glyphs' => 0, 'unmapped' => 0];
    $seen   = 0;
    // Một ký tự UTF-8 tối đa 4 byte — chừa gấp 4 rồi cắt chính xác ở cuối.
    $cap    = max(4096, $limit * 4);

    foreach ($pages as $page) {
        $fonts   = pdf_page_fonts($objects, $page, $cmaps);
        $content = '';
        foreach ($page['contents'] as $ref) {
            if (!isset($objects[$ref])) {
                continue;
            }
            $part = pdf_stream_data($objects[$ref]);
            if ($part !== '') {
                $content .= $part . "\n";
            }
        }
        if ($content === '') {
            continue;
        }
        $seen++;
        $text = pdf_content_to_text($content, $fonts, $stats, $cap - strlen($out));
        if (trim($text) !== '') {
            $out .= ($out === '' ? '' : "\n\n") . trim($text);
        }
        if (mb_strlen($out) >= $limit) {
            break;
        }
    }

    // Không dựng được cây trang (PDF lạ, bị hỏng một phần): quét mọi luồng có chữ.
    if (trim($out) === '') {
        $fonts = pdf_all_fonts($objects, $cmaps);
        foreach ($objects as $obj) {
            $content = pdf_stream_data($obj);
            if ($content === '' || !pdf_has_text_operator($content)) {
                continue;
            }
            $text = pdf_content_to_text($content, $fonts, $stats, $cap - strlen($out));
            if (trim($text) !== '') {
                $out .= ($out === '' ? '' : "\n\n") . trim($text);
            }
            if (mb_strlen($out) >= $limit) {
                break;
            }
        }
    }

    $out = pdf_tidy($out);
    if (mb_strlen($out) > $limit) {
        $out = mb_substr($out, 0, $limit);
    }

    $reason = '';
    if (trim($out) === '') {
        // Có glyph nhưng không tra được chữ nào → phông nhúng thiếu bảng /ToUnicode.
        if ($stats['unmapped'] > 0) {
            $reason = 'no_tounicode';
        } elseif ($stats['glyphs'] === 0) {
            $reason = 'no_text';       // gần như chắc chắn là PDF scan (chỉ có ảnh)
        } else {
            $reason = 'empty';
        }
    }

    return [
        'text'     => $out,
        'pages'    => $seen ?: count($pages),
        'glyphs'   => $stats['glyphs'],
        'unmapped' => $stats['unmapped'],
        'reason'   => $reason,
    ];
}

/** PDF có mã hoá thật (không chỉ là chữ "/Encrypt" nằm trong một chuỗi)? */
function pdf_looks_encrypted($data)
{
    // Trailer/xref stream khai báo /Encrypt <ref> thì mọi luồng đều bị mã hoá.
    return (bool)preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', substr($data, -4096))
        || (bool)preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $data);
}

// ---------------------------------------------------------------------------
// Quét đối tượng
// ---------------------------------------------------------------------------

/**
 * Tìm mọi đối tượng "N G obj … endobj" trong tệp.
 *
 * Dùng strpos thay vì một regex lớn để không chạm ngưỡng pcre.backtrack_limit
 * (PDF vài MB rất dễ làm preg_match_all trả về false mà không báo lỗi).
 *
 * @return array objnum => ['dict' => string, 'stream' => string|null]
 */
function pdf_scan_objects($data)
{
    $lim     = pdf_limits();
    $objects = [];
    $len     = strlen($data);
    $pos     = 0;
    $count   = 0;

    while ($count < $lim['max_objects'] && ($at = strpos($data, 'obj', $pos)) !== false) {
        $pos = $at + 3;

        // Lùi lại để đọc "N G " ngay trước từ khoá obj.
        $head = substr($data, max(0, $at - 24), min(24, $at));
        if (!preg_match('#(\d+)\s+(\d+)\s+$#', $head, $m)) {
            continue;
        }
        $num = (int)$m[1];

        $end = strpos($data, 'endobj', $pos);
        $body = $end === false ? substr($data, $pos) : substr($data, $pos, $end - $pos);
        if ($end !== false) {
            $pos = $end + 6;
        }
        $count++;

        // Tách phần từ điển và phần luồng (nếu có).
        $stream   = null;
        $dict     = $body;
        $streamAt = strpos($body, 'stream');
        if ($streamAt !== false) {
            $dict  = substr($body, 0, $streamAt);
            $after = $streamAt + 6;
            // Sau từ khoá stream là CRLF hoặc LF (theo đặc tả).
            if (substr($body, $after, 2) === "\r\n") {
                $after += 2;
            } elseif (substr($body, $after, 1) === "\n" || substr($body, $after, 1) === "\r") {
                $after += 1;
            }
            $stop   = strrpos($body, 'endstream');
            $stream = $stop !== false && $stop > $after
                ? substr($body, $after, $stop - $after)
                : substr($body, $after);
        }

        // Đối tượng trùng số (bản PDF được sửa nhiều lần): bản sau thắng.
        $objects[$num] = ['dict' => $dict, 'stream' => $stream];
    }

    return $objects;
}

/**
 * PDF 1.5+ gói nhiều từ điển (kể cả /Type /Page và phông) vào luồng /ObjStm.
 * Hàm này giải nén chúng và thêm vào danh sách đối tượng.
 */
function pdf_expand_object_streams(array &$objects)
{
    foreach ($objects as $obj) {
        if ($obj['stream'] === null || strpos($obj['dict'], '/ObjStm') === false) {
            continue;
        }
        $data = pdf_stream_data($obj);
        if ($data === '') {
            continue;
        }
        $n     = (int)pdf_dict_number($obj['dict'], 'N');
        $first = (int)pdf_dict_number($obj['dict'], 'First');
        if ($n <= 0 || $first <= 0 || $first > strlen($data)) {
            continue;
        }
        // Phần đầu là các cặp "số-đối-tượng  vị-trí".
        $table = substr($data, 0, $first);
        if (!preg_match_all('#(\d+)\s+(\d+)#', $table, $m, PREG_SET_ORDER)) {
            continue;
        }
        $entries = array_slice($m, 0, $n);
        foreach ($entries as $i => $pair) {
            $num   = (int)$pair[1];
            $start = $first + (int)$pair[2];
            $stop  = isset($entries[$i + 1])
                ? $first + (int)$entries[$i + 1][2]
                : strlen($data);
            if ($start >= strlen($data) || $stop <= $start) {
                continue;
            }
            if (isset($objects[$num])) {
                continue;   // đã có bản ở ngoài, không ghi đè
            }
            $objects[$num] = ['dict' => substr($data, $start, $stop - $start), 'stream' => null];
        }
    }
}

/** Giải nén phần luồng của một đối tượng theo /Filter đã khai báo. */
function pdf_stream_data($obj)
{
    if (!isset($obj['stream']) || $obj['stream'] === null || $obj['stream'] === '') {
        return '';
    }
    $lim  = pdf_limits();
    $data = $obj['stream'];
    $dict = $obj['dict'];

    // Không xử lý luồng ảnh/phông — vừa vô ích vừa tốn bộ nhớ.
    if (preg_match('#/Subtype\s*/(Image|Form)?#', $dict) && strpos($dict, '/Image') !== false) {
        return '';
    }

    $filters = [];
    if (preg_match('#/Filter\s*\[(.*?)\]#s', $dict, $m)) {
        preg_match_all('#/([A-Za-z0-9]+)#', $m[1], $fm);
        $filters = $fm[1];
    } elseif (preg_match('#/Filter\s*/([A-Za-z0-9]+)#', $dict, $m)) {
        $filters = [$m[1]];
    }

    if (!$filters) {
        // Một số bộ tạo PDF không ghi /Filter dù dữ liệu vẫn nén — thử giải nén.
        $try = pdf_inflate($data);
        return $try !== null ? $try : $data;
    }

    foreach ($filters as $filter) {
        switch ($filter) {
            case 'FlateDecode':
            case 'Fl':
                $out = pdf_inflate($data);
                if ($out === null) {
                    return '';
                }
                $data = $out;
                break;
            case 'ASCIIHexDecode':
            case 'AHx':
                $hex  = preg_replace('/[^0-9A-Fa-f]/', '', strstr($data, '>', true) ?: $data);
                $data = @hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex) ?: '';
                break;
            case 'ASCII85Decode':
            case 'A85':
                $data = pdf_ascii85_decode($data);
                break;
            case 'RunLengthDecode':
            case 'RL':
                $data = pdf_runlength_decode($data);
                break;
            case 'DCTDecode':
            case 'JPXDecode':
            case 'CCITTFaxDecode':
            case 'JBIG2Decode':
                return '';   // dữ liệu ảnh, không có chữ
            case 'Crypt':
                return '';
            default:
                return '';   // LZWDecode và các bộ lọc lạ: bỏ qua an toàn
        }
        if (strlen($data) > $lim['max_stream']) {
            return '';
        }
    }

    // Predictor chỉ dùng cho xref/ảnh, không dùng cho luồng nội dung → bỏ qua.
    return $data;
}

/** gzuncompress rồi gzinflate (một số bộ tạo PDF ghi luồng deflate thô). */
function pdf_inflate($data)
{
    $trimmed = ltrim($data, "\r\n \t");
    $out = @gzuncompress($trimmed);
    if ($out === false) {
        $out = @gzinflate($trimmed);
    }
    if ($out === false) {
        $out = @gzinflate(substr($trimmed, 1));   // bỏ byte header lệch
    }
    return $out === false ? null : $out;
}

/** Giải mã ASCII85 (bộ lọc A85 của PDF). */
function pdf_ascii85_decode($data)
{
    $data = preg_replace('/\s+/', '', $data);
    if (strpos($data, '<~') === 0) {
        $data = substr($data, 2);
    }
    $stop = strpos($data, '~>');
    if ($stop !== false) {
        $data = substr($data, 0, $stop);
    }
    $out   = '';
    $chunk = [];
    $len   = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $ch = $data[$i];
        if ($ch === 'z' && !$chunk) {
            $out .= "\0\0\0\0";
            continue;
        }
        $val = ord($ch) - 33;
        if ($val < 0 || $val > 84) {
            continue;
        }
        $chunk[] = $val;
        if (count($chunk) === 5) {
            $num = 0;
            foreach ($chunk as $c) {
                $num = $num * 85 + $c;
            }
            $out  .= pack('N', $num);
            $chunk = [];
        }
    }
    if ($chunk) {
        $missing = 5 - count($chunk);
        for ($i = 0; $i < $missing; $i++) {
            $chunk[] = 84;
        }
        $num = 0;
        foreach ($chunk as $c) {
            $num = $num * 85 + $c;
        }
        $out .= substr(pack('N', $num), 0, 4 - $missing);
    }
    return $out;
}

/** Giải mã RunLength (bộ lọc RL của PDF). */
function pdf_runlength_decode($data)
{
    $out = '';
    $len = strlen($data);
    $i   = 0;
    while ($i < $len) {
        $n = ord($data[$i++]);
        if ($n === 128) {
            break;
        }
        if ($n < 128) {
            $out .= substr($data, $i, $n + 1);
            $i   += $n + 1;
        } else {
            if ($i >= $len) {
                break;
            }
            $out .= str_repeat($data[$i++], 257 - $n);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Cây trang và phông
// ---------------------------------------------------------------------------

/** Lấy một số nguyên/thực từ từ điển. */
function pdf_dict_number($dict, $key)
{
    return preg_match('#/' . $key . '\s+(-?[\d.]+)#', $dict, $m) ? $m[1] : null;
}

/** Danh sách trang kèm tham chiếu nội dung và resources. */
function pdf_find_pages(array $objects)
{
    $pages = [];
    foreach ($objects as $num => $obj) {
        if (!preg_match('#/Type\s*/Page[^s]#', $obj['dict'] . ' ')) {
            continue;
        }
        $contents = [];
        if (preg_match('#/Contents\s+(\d+)\s+\d+\s+R#', $obj['dict'], $m)) {
            $contents[] = (int)$m[1];
        } elseif (preg_match('#/Contents\s*\[(.*?)\]#s', $obj['dict'], $m)) {
            preg_match_all('#(\d+)\s+\d+\s+R#', $m[1], $cm);
            foreach ($cm[1] as $ref) {
                $contents[] = (int)$ref;
            }
        }
        if (!$contents) {
            continue;
        }
        $pages[] = ['num' => $num, 'contents' => $contents, 'dict' => $obj['dict']];
    }
    // Số đối tượng tăng dần thường trùng thứ tự trang.
    usort($pages, function ($a, $b) { return $a['num'] - $b['num']; });
    return $pages;
}

/** Từ điển /Resources của một trang (có thể là tham chiếu, hoặc thừa kế từ /Pages). */
function pdf_page_resources(array $objects, $pageDict)
{
    if (preg_match('#/Resources\s+(\d+)\s+\d+\s+R#', $pageDict, $m)) {
        $ref = (int)$m[1];
        return isset($objects[$ref]) ? $objects[$ref]['dict'] : '';
    }
    if (preg_match('#/Resources\s*<<#', $pageDict, $m, PREG_OFFSET_CAPTURE)) {
        return pdf_balanced_dict($pageDict, strpos($pageDict, '<<', $m[0][1]));
    }
    // Thừa kế: lấy resources của node /Pages đầu tiên có khai báo.
    foreach ($objects as $obj) {
        if (strpos($obj['dict'], '/Type') !== false && strpos($obj['dict'], '/Pages') !== false
            && strpos($obj['dict'], '/Resources') !== false) {
            return pdf_page_resources($objects, $obj['dict']);
        }
    }
    return '';
}

/** Cắt đúng một từ điển << … >> cân bằng dấu, bắt đầu tại $start. */
function pdf_balanced_dict($text, $start)
{
    if ($start === false) {
        return '';
    }
    $depth = 0;
    $len   = strlen($text);
    for ($i = $start; $i < $len - 1; $i++) {
        if ($text[$i] === '<' && $text[$i + 1] === '<') {
            $depth++;
            $i++;
        } elseif ($text[$i] === '>' && $text[$i + 1] === '>') {
            $depth--;
            $i++;
            if ($depth === 0) {
                return substr($text, $start, $i + 1 - $start);
            }
        }
    }
    return substr($text, $start, 4096);
}

/**
 * Bảng phông của một trang: tên tài nguyên (/F4) -> bảng giải mã.
 *
 * @param array $cmaps Bộ nhớ đệm ToUnicode dùng chung giữa các trang
 */
function pdf_page_fonts(array $objects, array $page, array &$cmaps)
{
    $res = pdf_page_resources($objects, $page['dict']);
    if ($res === '') {
        return [];
    }
    $fontDict = '';
    if (preg_match('#/Font\s+(\d+)\s+\d+\s+R#', $res, $m)) {
        $ref = (int)$m[1];
        $fontDict = isset($objects[$ref]) ? $objects[$ref]['dict'] : '';
    } elseif (($at = strpos($res, '/Font')) !== false) {
        $fontDict = pdf_balanced_dict($res, strpos($res, '<<', $at));
    }
    if ($fontDict === '') {
        return [];
    }

    $fonts = [];
    if (preg_match_all('#/([A-Za-z0-9_.\-]+)\s+(\d+)\s+\d+\s+R#', $fontDict, $m, PREG_SET_ORDER)) {
        foreach ($m as $item) {
            $fonts[$item[1]] = pdf_font_table($objects, (int)$item[2], $cmaps);
        }
    }
    return $fonts;
}

/** Gộp mọi phông tìm được trong tệp (dùng khi không dựng được cây trang). */
function pdf_all_fonts(array $objects, array &$cmaps)
{
    $fonts = [];
    foreach ($objects as $obj) {
        if (strpos($obj['dict'], '/Font') === false) {
            continue;
        }
        if (preg_match_all('#/([A-Za-z0-9_.\-]+)\s+(\d+)\s+\d+\s+R#', $obj['dict'], $m, PREG_SET_ORDER)) {
            foreach ($m as $item) {
                if (isset($fonts[$item[1]])) {
                    continue;
                }
                $ref = (int)$item[2];
                if (!isset($objects[$ref]) || strpos($objects[$ref]['dict'], '/Font') === false) {
                    continue;
                }
                $fonts[$item[1]] = pdf_font_table($objects, $ref, $cmaps);
            }
        }
    }
    return $fonts;
}

/**
 * Bảng giải mã của một phông.
 *
 * @return array ['bytes' => 1|2, 'map' => [code => chuỗi UTF-8], 'enc' => tên encoding]
 */
function pdf_font_table(array $objects, $fontRef, array &$cmaps)
{
    $table = ['bytes' => 1, 'map' => [], 'enc' => ''];
    if (!isset($objects[$fontRef])) {
        return $table;
    }
    $dict = $objects[$fontRef]['dict'];

    // Type0 + Identity-H ⇒ mã glyph dài 2 byte.
    if (strpos($dict, '/Type0') !== false
        || preg_match('#/Encoding\s*/Identity-[HV]#', $dict)
        || strpos($dict, '/DescendantFonts') !== false) {
        $table['bytes'] = 2;
    }
    if (preg_match('#/Encoding\s*/([A-Za-z0-9-]+)#', $dict, $m)) {
        $table['enc'] = $m[1];
    }

    if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $dict, $m)) {
        $ref = (int)$m[1];
        if (!array_key_exists($ref, $cmaps)) {
            $cmaps[$ref] = isset($objects[$ref])
                ? pdf_parse_cmap(pdf_stream_data($objects[$ref]))
                : ['bytes' => 0, 'map' => []];
        }
        $cmap = $cmaps[$ref];
        if ($cmap['map']) {
            $table['map'] = $cmap['map'];
            if ($cmap['bytes'] > 0) {
                $table['bytes'] = $cmap['bytes'];
            }
        }
    }
    return $table;
}

/**
 * Phân tích một CMap /ToUnicode: beginbfchar và beginbfrange.
 *
 * @return array ['bytes' => 0|1|2, 'map' => [code => chuỗi UTF-8]]
 */
function pdf_parse_cmap($text)
{
    $out = ['bytes' => 0, 'map' => []];
    if ($text === '') {
        return $out;
    }
    $lim = pdf_limits();

    // Độ dài mã nguồn suy ra từ codespacerange (<0000> <FFFF> ⇒ 2 byte).
    if (preg_match('#begincodespacerange(.*?)endcodespacerange#s', $text, $m)
        && preg_match('#<([0-9A-Fa-f]+)>#', $m[1], $cm)) {
        $out['bytes'] = max(1, (int)floor(strlen($cm[1]) / 2));
    }

    // <src> <dst>
    if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $text, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (!preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>#', $block, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $pair) {
                $code = hexdec($pair[1]);
                $char = pdf_utf16_hex_to_utf8($pair[2]);
                if ($char !== '') {
                    $out['map'][$code] = $char;
                }
                if ($out['bytes'] === 0) {
                    $out['bytes'] = max(1, (int)floor(strlen($pair[1]) / 2));
                }
            }
        }
    }

    // <lo> <hi> <dst>   hoặc   <lo> <hi> [ <d1> <d2> … ]
    if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $text, $blocks)) {
        foreach ($blocks[1] as $block) {
            $pattern = '#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]*)>|\[(.*?)\])#s';
            if (!preg_match_all($pattern, $block, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $item) {
                $lo = hexdec($item[1]);
                $hi = hexdec($item[2]);
                if ($hi < $lo || $hi - $lo > $lim['max_range']) {
                    continue;
                }
                if ($out['bytes'] === 0) {
                    $out['bytes'] = max(1, (int)floor(strlen($item[1]) / 2));
                }
                if (isset($item[4]) && $item[4] !== '') {
                    // Mảng: mỗi mã trong khoảng có một đích riêng.
                    preg_match_all('#<([0-9A-Fa-f]*)>#', $item[4], $dm);
                    foreach ($dm[1] as $i => $hex) {
                        $char = pdf_utf16_hex_to_utf8($hex);
                        if ($char !== '') {
                            $out['map'][$lo + $i] = $char;
                        }
                    }
                    continue;
                }
                // Đích liên tiếp: tăng dần đơn vị UTF-16 cuối.
                $hex = $item[3];
                if ($hex === '') {
                    continue;
                }
                $prefix = strlen($hex) > 4 ? substr($hex, 0, -4) : '';
                $startU = hexdec(substr($hex, -4));
                for ($code = $lo; $code <= $hi; $code++) {
                    $unit = str_pad(dechex($startU + ($code - $lo)), 4, '0', STR_PAD_LEFT);
                    $char = pdf_utf16_hex_to_utf8($prefix . $unit);
                    if ($char !== '') {
                        $out['map'][$code] = $char;
                    }
                }
            }
        }
    }

    return $out;
}

/** Chuỗi hex UTF-16BE (có thể nhiều đơn vị, kể cả surrogate) -> UTF-8. */
function pdf_utf16_hex_to_utf8($hex)
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', (string)$hex);
    if ($hex === '' || strlen($hex) % 2 !== 0) {
        return '';
    }
    $bin = @hex2bin($hex);
    if ($bin === false || $bin === '') {
        return '';
    }
    if (strlen($bin) % 2 !== 0) {
        $bin .= "\0";
    }
    $utf8 = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    if ($utf8 === false || $utf8 === null) {
        return '';
    }
    // Bỏ ký tự điều khiển và ký tự thay thế do giải mã lỗi.
    $utf8 = str_replace("\xEF\xBF\xBD", '', $utf8);
    return $utf8;
}

// ---------------------------------------------------------------------------
// Đọc luồng nội dung
// ---------------------------------------------------------------------------

/** Luồng có toán tử hiển thị chữ nào không? */
function pdf_has_text_operator($content)
{
    return strpos($content, 'Tj') !== false
        || strpos($content, 'TJ') !== false
        || strpos($content, ' Tf') !== false;
}

/**
 * Chuyển luồng nội dung PDF thành văn bản.
 *
 * Bám theo toán tử của đặc tả: Tf (chọn phông), Tj, TJ, dấu nháy đơn và nháy
 * kép (hiển thị chữ), Td, TD, Tm và T-sao (dời con trỏ — nhờ đó biết khi nào
 * sang dòng mới).
 *
 * @param array $stats    Được cộng dồn: 'glyphs', 'unmapped'
 * @param int   $maxBytes Dừng sớm khi đã đủ chữ (tránh dựng chuỗi khổng lồ)
 */
function pdf_content_to_text($content, array $fonts, array &$stats, $maxBytes = 4000000)
{
    $out      = '';
    $operands = [];
    $font     = null;
    $fontSize = 0.0;
    $lineY    = null;
    $len      = strlen($content);
    $i        = 0;

    while ($i < $len) {
        $ch = $content[$i];

        // Khoảng trắng
        if ($ch === "\n" || $ch === "\r" || $ch === "\t" || $ch === ' ' || $ch === "\0" || $ch === "\x0C") {
            $i++;
            continue;
        }
        // Chú thích
        if ($ch === '%') {
            $nl = strcspn($content, "\r\n", $i);
            $i += $nl ?: 1;
            continue;
        }
        // Chuỗi literal ( … ) — có lồng dấu và dấu thoát
        if ($ch === '(') {
            $depth = 1;
            $j     = $i + 1;
            $buf   = '';
            while ($j < $len && $depth > 0) {
                $c = $content[$j];
                if ($c === '\\') {
                    $buf .= $c . ($content[$j + 1] ?? '');
                    $j   += 2;
                    continue;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $j++;
                        break;
                    }
                }
                $buf .= $c;
                $j++;
            }
            $operands[] = ['str', pdf_literal_bytes($buf)];
            $i = $j;
            continue;
        }
        // << … >> (từ điển) hoặc < … > (chuỗi hex)
        if ($ch === '<') {
            if (($content[$i + 1] ?? '') === '<') {
                $operands[] = ['dict', ''];
                $i += 2;
                continue;
            }
            $stop = strpos($content, '>', $i + 1);
            if ($stop === false) {
                break;
            }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $i + 1, $stop - $i - 1));
            if (strlen($hex) % 2 !== 0) {
                $hex .= '0';
            }
            $bin        = $hex === '' ? '' : (string)@hex2bin($hex);
            $operands[] = ['str', $bin];
            $i = $stop + 1;
            continue;
        }
        if ($ch === '>') {
            $i += (($content[$i + 1] ?? '') === '>') ? 2 : 1;
            continue;
        }
        if ($ch === '[') {
            $operands[] = ['arr_open', ''];
            $i++;
            continue;
        }
        if ($ch === ']') {
            $operands[] = ['arr_close', ''];
            $i++;
            continue;
        }
        if ($ch === '{' || $ch === '}') {
            $i++;
            continue;
        }
        // Tên /Xyz
        if ($ch === '/') {
            $n = strspn($content, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.+-#', $i + 1);
            $operands[] = ['name', substr($content, $i + 1, $n)];
            $i += $n + 1;
            continue;
        }
        // Số
        if (($ch >= '0' && $ch <= '9') || $ch === '-' || $ch === '+' || $ch === '.') {
            $n = strspn($content, '0123456789.-+eE', $i);
            $operands[] = ['num', (float)substr($content, $i, $n)];
            $i += $n ?: 1;
            continue;
        }

        // Toán tử
        $n = strspn($content, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz*01', $i);
        if ($n === 0) {
            // Toán tử một ký tự: ' và "
            $op = $ch;
            $i++;
        } else {
            $op = substr($content, $i, $n);
            $i += $n;
        }

        switch ($op) {
            case 'Tf':
                $name = null;
                foreach ($operands as $operand) {
                    if ($operand[0] === 'name') {
                        $name = $operand[1];
                    }
                }
                $nums     = pdf_numbers($operands);
                $fontSize = $nums ? abs((float)end($nums)) : $fontSize;
                $font = ($name !== null && isset($fonts[$name])) ? $fonts[$name] : null;
                if ($name !== null && $font === null) {
                    $font = ['bytes' => 1, 'map' => [], 'enc' => '', 'unknown' => true];
                }
                break;

            case 'Tj':
                $out .= pdf_decode_show(pdf_last_string($operands), $font, $stats);
                break;

            case "'":
                $out .= pdf_newline($out) . pdf_decode_show(pdf_last_string($operands), $font, $stats);
                break;

            case '"':
                $out .= pdf_newline($out) . pdf_decode_show(pdf_last_string($operands), $font, $stats);
                break;

            case 'TJ':
                // [ (chuỗi) khoảng-cách (chuỗi) … ] TJ
                $piece = '';
                foreach ($operands as $operand) {
                    if ($operand[0] === 'str') {
                        $piece .= pdf_decode_show($operand[1], $font, $stats);
                    } elseif ($operand[0] === 'num' && $operand[1] <= -150) {
                        // Khoảng dời âm lớn = khoảng trắng giữa hai từ.
                        if ($piece !== '' && substr($piece, -1) !== ' ') {
                            $piece .= ' ';
                        }
                    }
                }
                $out .= $piece;
                break;

            case 'Td':
            case 'TD':
                // Dời tương đối. Lệch dọc ⇒ sang dòng mới.
                //
                // Lệch ngang thì phải cẩn thận: Chrome và Word dùng Td để đặt
                // từng con chữ trong cùng một từ, và bước nhảy chính là bề rộng
                // của chữ liền trước (tối đa khoảng 1 em). Lấy ngưỡng 1,5 em nên
                // chỉ khoảng cách lớn hơn mọi con chữ đơn lẻ mới thành khoảng
                // trắng — nếu không, "Trình" bị xé thành "T rình". Khoảng trắng
                // thật giữa các từ vốn đã nằm trong chuỗi glyph.
                $nums = pdf_numbers($operands);
                $tx   = count($nums) >= 2 ? $nums[count($nums) - 2] : 0.0;
                $ty   = count($nums) >= 1 ? $nums[count($nums) - 1] : 0.0;
                if (abs($ty) > 0.5) {
                    $out .= pdf_newline($out);
                } elseif (abs($tx) > max(4.0, $fontSize * 1.5)) {
                    $out .= pdf_space($out);
                }
                break;

            case 'T*':
                $out .= pdf_newline($out);
                break;

            case 'Tm':
                // Đặt lại ma trận chữ: đổi toạ độ dọc nghĩa là dòng mới. Cùng
                // dòng thì không chèn gì — khoảng trắng giữa các từ đã nằm sẵn
                // trong chuỗi glyph mà bộ tạo PDF ghi ra.
                $nums = pdf_numbers($operands);
                if (count($nums) >= 6) {
                    $y = $nums[count($nums) - 1];
                    if ($lineY !== null && abs($y - $lineY) > 0.5) {
                        $out .= pdf_newline($out);
                    }
                    $lineY = $y;
                }
                break;

            case 'BI':
                // Ảnh nội tuyến: nhảy qua toàn bộ dữ liệu nhị phân tới EI.
                $stop = strpos($content, 'EI', $i);
                $i    = $stop === false ? $len : $stop + 2;
                break;
        }

        $operands = [];

        if (strlen($out) > $maxBytes) {
            break;
        }
    }

    return $out;
}

/** Chuỗi cuối cùng trong danh sách toán hạng. */
function pdf_last_string(array $operands)
{
    for ($i = count($operands) - 1; $i >= 0; $i--) {
        if ($operands[$i][0] === 'str') {
            return $operands[$i][1];
        }
    }
    return '';
}

/** Các toán hạng số. */
function pdf_numbers(array $operands)
{
    $out = [];
    foreach ($operands as $operand) {
        if ($operand[0] === 'num') {
            $out[] = $operand[1];
        }
    }
    return $out;
}

/** Thêm ngắt dòng nếu chưa có. */
function pdf_newline($out)
{
    if ($out === '' || substr($out, -1) === "\n") {
        return '';
    }
    return "\n";
}

/** Thêm khoảng trắng nếu chưa có. */
function pdf_space($out)
{
    if ($out === '' || substr($out, -1) === ' ' || substr($out, -1) === "\n") {
        return '';
    }
    return ' ';
}

/**
 * Giải mã một chuỗi hiển thị theo phông đang chọn.
 *
 * Không có bảng /ToUnicode và phông dùng mã 2 byte ⇒ các byte chỉ là số hiệu
 * glyph, diễn giải kiểu gì cũng ra rác. Khi đó trả về chuỗi rỗng và đếm vào
 * $stats['unmapped'] để phần gọi biết là "đọc không được" chứ không phải
 * "tệp không có chữ".
 */
function pdf_decode_show($bytes, $font, array &$stats)
{
    if ($bytes === '') {
        return '';
    }
    $map   = $font['map'] ?? [];
    $width = (int)($font['bytes'] ?? 1) === 2 ? 2 : 1;
    $len   = strlen($bytes);

    if (!$map) {
        if ($width === 2) {
            $stats['glyphs']   += (int)($len / 2);
            $stats['unmapped'] += (int)($len / 2);
            return '';
        }
        // Phông 1 byte không có ToUnicode: mã gần như luôn là WinAnsi/Latin-1.
        $stats['glyphs'] += $len;
        return pdf_bytes_to_utf8($bytes);
    }

    $out = '';
    for ($i = 0; $i < $len; $i += $width) {
        $code = $width === 2
            ? ((ord($bytes[$i]) << 8) | ord($bytes[$i + 1] ?? "\0"))
            : ord($bytes[$i]);
        $stats['glyphs']++;
        if (isset($map[$code])) {
            $out .= $map[$code];
        } elseif ($width === 1) {
            $out .= pdf_bytes_to_utf8($bytes[$i]);
        } else {
            $stats['unmapped']++;
        }
    }
    return $out;
}

/** Byte của phông 1 byte -> UTF-8 (CP1252 phủ được cả ASCII và WinAnsi). */
function pdf_bytes_to_utf8($bytes)
{
    if ($bytes === '') {
        return '';
    }
    // Nhanh: toàn ASCII in được thì khỏi chuyển mã.
    if (!preg_match('/[\x00-\x08\x0E-\x1F\x80-\xFF]/', $bytes)) {
        return $bytes;
    }
    $out = @iconv('CP1252', 'UTF-8//IGNORE', $bytes);
    if ($out === false) {
        $out = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $bytes);
    }
    return $out === false ? '' : $out;
}

/** Dọn văn bản thu được: bỏ khoảng trắng thừa, gộp dòng trống. */
function pdf_tidy($text)
{
    $text = str_replace("\xC2\xA0", ' ', $text);              // no-break space
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    $text = preg_replace('/ +\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    // Bỏ ký tự điều khiển còn sót (giữ \n và \t).
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    return trim($text);
}

/** Giải mã dấu thoát trong chuỗi literal của PDF. */
function pdf_literal_bytes($buf)
{
    $out = '';
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        if ($buf[$i] !== '\\') {
            $out .= $buf[$i];
            continue;
        }
        $next = $buf[$i + 1] ?? '';
        switch ($next) {
            case 'n': $out .= "\n"; $i++; break;
            case 'r': $out .= "\r"; $i++; break;
            case 't': $out .= "\t"; $i++; break;
            case 'b': $out .= "\x08"; $i++; break;
            case 'f': $out .= "\x0C"; $i++; break;
            case '(': $out .= '('; $i++; break;
            case ')': $out .= ')'; $i++; break;
            case '\\': $out .= '\\'; $i++; break;
            case "\n": $i++; break;                 // nối dòng
            case "\r": $i += (($buf[$i + 2] ?? '') === "\n") ? 2 : 1; break;
            default:
                if ($next >= '0' && $next <= '7') {
                    $n = strspn($buf, '01234567', $i + 1);
                    $n = min($n, 3);
                    $out .= chr(octdec(substr($buf, $i + 1, $n)) & 0xFF);
                    $i  += $n;
                } else {
                    $out .= $next;
                    $i++;
                }
        }
    }
    return $out;
}

/** Mô tả lý do không đọc được nội dung PDF, để hiển thị cho người dùng. */
function pdf_reason_text($reason)
{
    switch ($reason) {
        case 'no_text':
            return 'PDF này chỉ chứa ảnh chụp/scan nên không có chữ để đọc (cần nhận dạng OCR).';
        case 'no_tounicode':
            return 'PDF này dùng phông nhúng không kèm bảng ánh xạ Unicode nên không thể đọc chữ.';
        case 'encrypted':
            return 'PDF này được đặt mật khẩu/mã hoá nên không đọc được nội dung.';
        case 'too_large':
            return 'PDF này quá lớn để phân tích nội dung.';
        case 'not_pdf':
            return 'Tệp này không phải PDF hợp lệ.';
        case 'unreadable':
            return 'Không đọc được tệp PDF.';
        default:
            return 'Không rút được chữ nào từ PDF này.';
    }
}
