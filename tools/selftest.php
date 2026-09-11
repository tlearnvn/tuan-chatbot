<?php
/**
 * Tự kiểm tra các phần xử lý dữ liệu của Tuấn Chatbot.
 *
 *     php tools/selftest.php
 *
 * Không cần cơ sở dữ liệu, không cần mạng: mọi tệp mẫu được dựng ngay trong
 * bộ nhớ. Chạy lệnh này sau khi sửa mã để chắc chắn việc đọc nội dung tệp
 * đính kèm (PDF, Office, OpenDocument, EPUB, RTF, ZIP…) vẫn đúng.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Chỉ chạy được từ dòng lệnh.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/version.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/files.php';
require_once APP_ROOT . '/includes/pdf.php';

/** Thiết lập tối thiểu để dùng lại helpers mà không cần cơ sở dữ liệu. */
function cfg($key, $default = null)
{
    return $default;
}

$GLOBALS['tests'] = ['pass' => 0, 'fail' => 0, 'messages' => []];
$GLOBALS['tmpdir'] = sys_get_temp_dir() . '/tchat-selftest-' . getmypid();
@mkdir($GLOBALS['tmpdir'], 0777, true);

/** Ghi tệp mẫu tạm và trả về đường dẫn. */
function fixture($name, $bytes)
{
    $path = $GLOBALS['tmpdir'] . '/' . $name;
    file_put_contents($path, $bytes);
    return $path;
}

function check($label, $condition, $detail = '')
{
    if ($condition) {
        $GLOBALS['tests']['pass']++;
        echo "  \033[32m✓\033[0m " . $label . "\n";
        return true;
    }
    $GLOBALS['tests']['fail']++;
    $GLOBALS['tests']['messages'][] = $label . ($detail !== '' ? ' — ' . $detail : '');
    echo "  \033[31m✗\033[0m " . $label . ($detail !== '' ? "\n      " . $detail : '') . "\n";
    return false;
}

function check_contains($label, $haystack, $needle)
{
    return check($label, mb_strpos((string)$haystack, $needle) !== false,
        'không thấy "' . $needle . '" trong: ' . mb_substr(trim((string)$haystack), 0, 160));
}

function section($title)
{
    echo "\n\033[1m" . $title . "\033[0m\n";
}

// ---------------------------------------------------------------------------
// Bộ dựng PDF tối giản, đủ để tái tạo các kiểu tệp gặp ngoài thực tế
// ---------------------------------------------------------------------------

/** Ghép danh sách đối tượng thành một tệp PDF hợp lệ. */
function pdf_fixture(array $objects, $version = '1.4', $trailerExtra = '')
{
    $out     = "%PDF-" . $version . "\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($out);
        $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefAt = strlen($out);
    $max    = max(array_keys($offsets)) + 1;
    $out   .= "xref\n0 " . $max . "\n0000000000 65535 f \n";
    for ($n = 1; $n < $max; $n++) {
        $out .= isset($offsets[$n])
            ? sprintf("%010d 00000 n \n", $offsets[$n])
            : "0000000000 65535 f \n";
    }
    $out .= "trailer\n<< /Size " . $max . " /Root 1 0 R " . $trailerExtra . ">>\n"
          . "startxref\n" . $xrefAt . "\n%%EOF\n";
    return $out;
}

/** Một luồng PDF, nén Flate nếu cần. */
function pdf_fixture_stream($dictExtra, $data, $compress = true)
{
    if ($compress) {
        $data = gzcompress($data);
        $dictExtra .= ' /Filter /FlateDecode';
    }
    return '<< ' . $dictExtra . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
}

/** Bảng /ToUnicode dạng beginbfchar cho các mã glyph đã cho. */
function pdf_fixture_cmap(array $map)
{
    $lines = ['/CIDInit /ProcSet findresource begin', '12 dict begin', 'begincmap',
              '/CMapName /Adobe-Identity-UCS def', '/CMapType 2 def',
              '1 begincodespacerange', '<0000> <FFFF>', 'endcodespacerange',
              count($map) . ' beginbfchar'];
    foreach ($map as $code => $char) {
        $utf16 = '';
        foreach (preg_split('//u', $char, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            $utf16 .= strtoupper(bin2hex(mb_convert_encoding($c, 'UTF-16BE', 'UTF-8')));
        }
        $lines[] = sprintf('<%04X> <%s>', $code, $utf16);
    }
    $lines[] = 'endbfchar';
    $lines[] = 'endcmap';
    $lines[] = 'end';
    $lines[] = 'end';
    return implode("\n", $lines);
}

/** Chuỗi hex glyph + bảng ToUnicode tương ứng cho một đoạn văn bản. */
function pdf_fixture_encode($text)
{
    $chars = [];
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if (!in_array($c, $chars, true)) {
            $chars[] = $c;
        }
    }
    $map = [];
    $hex = '';
    foreach ($chars as $i => $c) {
        $map[$i + 3] = $c;
    }
    $lookup = array_flip($map);
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        $hex .= sprintf('%04X', $lookup[$c]);
    }
    return ['hex' => $hex, 'map' => $map];
}

// ===========================================================================
section('PDF — chữ vẽ bằng mã glyph của phông nhúng (Chrome, Word, Google Docs)');
// ===========================================================================
// Đây là dạng PDF phổ biến nhất hiện nay: nội dung là chuỗi hex chứa số hiệu
// glyph, chỉ đọc được khi tra bảng /ToUnicode của phông.
$line1 = 'Đề kiểm tra Ngữ văn lớp 9';
$line2 = 'Câu 1. Phân tích bài thơ Đồng chí của Chính Hữu.';
$enc   = pdf_fixture_encode($line1 . $line2);
$lookup = array_flip($enc['map']);
$hex1 = $hex2 = '';
foreach (preg_split('//u', $line1, -1, PREG_SPLIT_NO_EMPTY) as $c) { $hex1 .= sprintf('%04X', $lookup[$c]); }
foreach (preg_split('//u', $line2, -1, PREG_SPLIT_NO_EMPTY) as $c) { $hex2 .= sprintf('%04X', $lookup[$c]); }

$content = "BT /F1 16 Tf 1 0 0 -1 8 37 Tm <" . $hex1 . "> Tj ET\n"
         . "BT /F1 12 Tf 1 0 0 -1 8 80 Tm <" . $hex2 . "> Tj ET\n";
$cid = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
       . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', $content),
    5 => '<< /Type /Font /Subtype /Type0 /BaseFont /AAAAAA+Roboto '
       . '/Encoding /Identity-H /DescendantFonts [7 0 R] /ToUnicode 6 0 R >>',
    6 => pdf_fixture_stream('', pdf_fixture_cmap($enc['map'])),
    7 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AAAAAA+Roboto >>',
]);
$info = pdf_extract_info(fixture('cid.pdf', $cid));
check_contains('đọc đúng tiêu đề tiếng Việt có dấu', $info['text'], $line1);
check_contains('đọc đúng đoạn thứ hai', $info['text'], $line2);
check('mỗi đoạn nằm trên một dòng riêng', strpos($info['text'], $line1 . "\n") === 0,
    'thực tế: ' . str_replace("\n", '\n', mb_substr($info['text'], 0, 80)));
check('không còn glyph nào bị bỏ sót', $info['unmapped'] === 0, 'unmapped=' . $info['unmapped']);

// ===========================================================================
section('PDF — chuỗi literal, phông 1 byte (pdflatex, Ghostscript, bản cũ)');
// ===========================================================================
$legacy = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
       . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', "BT /F1 12 Tf 72 720 Td (Annual Report 2026) Tj ET\n"
       . "BT /F1 12 Tf 72 700 Td (Revenue grew 14 percent \\(net\\)) Tj ET\n"
       . "BT /F1 12 Tf 72 680 Td [(Invoice) -600 (INV-42)] TJ ET\n"),
    5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
]);
$info = pdf_extract_info(fixture('legacy.pdf', $legacy));
check_contains('đọc được chuỗi literal', $info['text'], 'Annual Report 2026');
check_contains('giải mã đúng dấu ngoặc thoát', $info['text'], 'Revenue grew 14 percent (net)');
check_contains('đọc được mảng TJ có kerning', $info['text'], 'Invoice INV-42');

// ===========================================================================
section('PDF 1.5 — trang và phông nằm trong luồng đối tượng /ObjStm');
// ===========================================================================
$text   = 'Hợp đồng thuê nhà số 07/2026';
$enc    = pdf_fixture_encode($text);
$lookup = array_flip($enc['map']);
$hex    = '';
foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $c) { $hex .= sprintf('%04X', $lookup[$c]); }

$pageDict = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
          . '/Resources << /Font << /FF1 5 0 R >> >> /Contents 4 0 R >>';
$fontDict = '<< /Type /Font /Subtype /Type0 /BaseFont /BBBBBB+Times '
          . '/Encoding /Identity-H /DescendantFonts [8 0 R] /ToUnicode 6 0 R >>';
$blob  = $pageDict . ' ' . $fontDict . ' ';
$table = '3 0 5 ' . (strlen($pageDict) + 1) . "\n";
$objstm = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    4 => pdf_fixture_stream('', "BT /FF1 14 Tf 1 0 0 1 72 720 Tm <" . $hex . "> Tj ET\n"),
    6 => pdf_fixture_stream('', pdf_fixture_cmap($enc['map'])),
    7 => pdf_fixture_stream('/Type /ObjStm /N 2 /First ' . strlen($table), $table . $blob),
    8 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /BBBBBB+Times >>',
], '1.5');
$info = pdf_extract_info(fixture('objstm.pdf', $objstm));
check_contains('đọc được trang nằm trong /ObjStm', $info['text'], $text);

// ===========================================================================
section('PDF — các trường hợp KHÔNG đọc được phải báo đúng lý do');
// ===========================================================================
// Chỉ có ảnh: PDF scan từ máy photocopy.
$scan = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
       . '/Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', "q 612 0 0 792 0 0 cm /Im1 Do Q\n"),
    5 => pdf_fixture_stream('/Type /XObject /Subtype /Image /Width 8 /Height 8 '
       . '/ColorSpace /DeviceGray /BitsPerComponent 8', str_repeat("\x7F", 64)),
]);
$info = pdf_extract_info(fixture('scan.pdf', $scan));
check('PDF scan: báo "no_text"', $info['reason'] === 'no_text', 'reason=' . $info['reason']);
check('PDF scan: không trả về chữ rác', $info['text'] === '', 'text=' . $info['text']);

// Identity-H nhưng thiếu /ToUnicode: có glyph mà không tra được chữ.
$noCmap = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
       . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', "BT /F1 12 Tf 72 720 Td <00480065006C006C006F> Tj ET\n"),
    5 => '<< /Type /Font /Subtype /Type0 /BaseFont /CCCCCC+Sub /Encoding /Identity-H '
       . '/DescendantFonts [6 0 R] >>',
    6 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /CCCCCC+Sub >>',
]);
$info = pdf_extract_info(fixture('nocmap.pdf', $noCmap));
check('thiếu /ToUnicode: báo "no_tounicode"', $info['reason'] === 'no_tounicode',
    'reason=' . $info['reason']);
check('thiếu /ToUnicode: thà không trả gì hơn trả chữ rác', $info['text'] === '',
    'text=' . $info['text']);

// PDF đặt mật khẩu.
$enc7 = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', random_bytes(200), false),
    9 => '<< /Filter /Standard /V 2 /R 3 /Length 128 /P -4 >>',
], '1.4', '/Encrypt 9 0 R ');
$info = pdf_extract_info(fixture('enc.pdf', $enc7));
check('PDF mã hoá: báo "encrypted"', $info['reason'] === 'encrypted', 'reason=' . $info['reason']);

// Tệp không phải PDF nhưng mang đuôi .pdf.
$info = pdf_extract_info(fixture('fake.pdf', 'Đây chỉ là văn bản thường.'));
check('không phải PDF: báo "not_pdf"', $info['reason'] === 'not_pdf', 'reason=' . $info['reason']);

// ===========================================================================
section('PDF — hiệu năng và giới hạn bộ nhớ');
// ===========================================================================
$lines = [];
for ($i = 0; $i < 6000; $i++) {
    $lines[] = 'BT /F1 10 Tf 72 ' . (720 - ($i % 60) * 12) . ' Td (Dong du lieu so ' . $i
             . ' voi noi dung dai de kiem tra hieu nang.) Tj ET';
}
$bigPdf = pdf_fixture([
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
       . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    4 => pdf_fixture_stream('', implode("\n", $lines), false),
    5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
]);
$bigPath = fixture('big.pdf', $bigPdf);
$before  = microtime(true);
$info    = pdf_extract_info($bigPath, 120000);
$elapsed = (microtime(true) - $before) * 1000;
check('PDF ' . fmt_bytes(filesize($bigPath)) . ' xử lý dưới 3 giây',
    $elapsed < 3000, sprintf('%.0f ms', $elapsed));
check('tôn trọng giới hạn số ký tự', mb_strlen($info['text']) <= 120000,
    'len=' . mb_strlen($info['text']));
check('đọc được nội dung của tệp lớn', mb_strlen($info['text']) > 10000,
    'len=' . mb_strlen($info['text']));

// ===========================================================================
section('OpenDocument, OOXML, EPUB, ZIP (cần ZipArchive)');
// ===========================================================================
if (!class_exists('ZipArchive')) {
    echo "  – bỏ qua: PHP không có ZipArchive\n";
} else {
    // --- ODT ---
    $odtPath = $GLOBALS['tmpdir'] . '/bao-cao.odt';
    $zip = new ZipArchive();
    $zip->open($odtPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
    $zip->addFromString('content.xml',
        '<?xml version="1.0" encoding="UTF-8"?><office:document-content '
      . 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
      . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text>'
      . '<text:h>Báo cáo tổng kết năm học</text:h>'
      . '<text:p>Trường có 1.245 học sinh, tỉ lệ lên lớp 98,6%.</text:p>'
      . '</office:text></office:body></office:document-content>');
    $zip->close();
    $res = extract_file_content($odtPath, 'file', 'odt');
    check_contains('ODT: đọc được tiêu đề', $res['text'], 'Báo cáo tổng kết năm học');
    check_contains('ODT: đọc được đoạn văn', $res['text'], 'tỉ lệ lên lớp 98,6%');

    // --- DOCX ---
    $docxPath = $GLOBALS['tmpdir'] . '/de-thi.docx';
    $zip = new ZipArchive();
    $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/'
      . 'wordprocessingml/2006/main"><w:body>'
      . '<w:p><w:r><w:t>Đề thi học kỳ II môn Toán</w:t></w:r></w:p>'
      . '<w:p><w:r><w:t>Bài 1. Giải phương trình x² − 5x + 6 = 0.</w:t></w:r></w:p>'
      . '</w:body></w:document>');
    $zip->close();
    $res = extract_file_content($docxPath, 'file', 'docx');
    check_contains('DOCX: đọc được tiêu đề', $res['text'], 'Đề thi học kỳ II môn Toán');
    check_contains('DOCX: mỗi đoạn một dòng', $res['text'], "Toán\nBài 1.");

    // --- EPUB ---
    $epubPath = $GLOBALS['tmpdir'] . '/truyen.epub';
    $zip = new ZipArchive();
    $zip->open($epubPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->addFromString('OEBPS/ch01.xhtml',
        '<html><head><style>p{color:red}</style></head><body><h1>Chương 1</h1>'
      . '<p>Ngày xưa có một cô bé tên là Tấm.</p><script>alert(1)</script></body></html>');
    $zip->addFromString('OEBPS/ch02.xhtml',
        '<html><body><h1>Chương 2</h1><p>Cô bé sống cùng mẹ con dì.</p></body></html>');
    $zip->close();
    $res = extract_file_content($epubPath, 'file', 'epub');
    check_contains('EPUB: đọc được chương 1', $res['text'], 'cô bé tên là Tấm');
    check_contains('EPUB: đọc được chương 2', $res['text'], 'mẹ con dì');
    check('EPUB: bỏ được script và style',
        strpos($res['text'], 'alert(1)') === false && strpos($res['text'], 'color:red') === false);

    // --- ZIP: liệt kê nội dung ---
    $zipPath = $GLOBALS['tmpdir'] . '/ho-so.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('danh-sach.xlsx', str_repeat('x', 5000));
    $zip->addFromString('anh/lop9a.jpg', str_repeat('y', 40000));
    $zip->close();
    $res = extract_file_content($zipPath, 'file', 'zip');
    check('ZIP: báo trạng thái "archive_listing"', $res['reason'] === 'archive_listing',
        'reason=' . $res['reason']);
    check_contains('ZIP: liệt kê tệp bên trong', $res['text'], 'danh-sach.xlsx');
    check_contains('ZIP: có kèm dung lượng', $res['text'], 'anh/lop9a.jpg');
}

// ===========================================================================
section('RTF, HTML và Office 97-2003');
// ===========================================================================
/** Chuyển chuỗi UTF-8 thành RTF (ký tự ngoài ASCII ghi bằng \uN?). */
function rtf_escape($text)
{
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        $code = mb_ord($c, 'UTF-8');
        $out .= $code < 128 ? $c : '\\u' . $code . '?';
    }
    return $out;
}
$rtf = '{\rtf1\ansi\ansicpg1252\deff0'
     . '{\fonttbl{\f0\fnil\fcharset0 Times New Roman;}{\f1\fnil Calibri;}}'
     . '{\colortbl ;\red0\green0\blue0;}'
     . '{\*\generator Riched20 10.0.19041;}'
     . '\viewkind4\uc1\pard\f0\fs28 ' . rtf_escape('Biên bản họp phụ huynh') . '\par' . "\n"
     . '\fs24 ' . rtf_escape('Quỹ lớp 200.000 đồng mỗi học kỳ.') . '\par' . "\n"
     . rtf_escape('Ghi chú: ') . "\\'93" . rtf_escape('quan trọng') . "\\'94\\par\n}";
$res = extract_file_content(fixture('bien-ban.rtf', $rtf), 'file', 'rtf');
check_contains('RTF: đọc được tiêu đề tiếng Việt', $res['text'], 'Biên bản họp phụ huynh');
check_contains('RTF: đọc được đoạn thân bài', $res['text'], 'Quỹ lớp 200.000 đồng');
check('RTF: bỏ được bảng phông và bảng màu',
    strpos($res['text'], 'Times New Roman') === false && strpos($res['text'], 'Riched20') === false,
    'còn sót: ' . mb_substr($res['text'], 0, 100));

$html = '<html><head><style>b{}</style><script>var x=1</script></head><body>'
      . '<h1>Thông báo</h1><p>Nghỉ Tết từ ngày&nbsp;25/01.</p>'
      . '<table><tr><td>Lớp</td><td>9A</td></tr></table></body></html>';
$res = extract_file_content(fixture('tb.html', $html), 'text', 'html');
check_contains('HTML: giữ lại phần chữ', $res['text'], 'Nghỉ Tết từ ngày 25/01');
check('HTML: bỏ script và style',
    strpos($res['text'], 'var x=1') === false && strpos($res['text'], 'b{}') === false);

// Word 97-2003: nội dung là UTF-16LE nằm lẫn trong ổ đĩa OLE.
$doc  = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 500);
$doc .= mb_convert_encoding('Root Entry', 'UTF-16LE', 'UTF-8') . str_repeat("\x00", 40);
$doc .= mb_convert_encoding('WordDocument', 'UTF-16LE', 'UTF-8') . str_repeat("\x00", 40);
$doc .= mb_convert_encoding('Times New Roman', 'UTF-16LE', 'UTF-8') . str_repeat("\x00", 20);
$doc .= mb_convert_encoding('Biên bản họp phụ huynh lớp 9A ngày 12 tháng 3', 'UTF-16LE', 'UTF-8');
$doc .= str_repeat("\x00", 8);
$doc .= mb_convert_encoding('Thống nhất mức quỹ lớp 200.000 đồng mỗi học kỳ.', 'UTF-16LE', 'UTF-8');
$doc .= str_repeat("\x01\x02\x03", 200);
$res = extract_file_content(fixture('bien-ban.doc', $doc), 'file', 'doc');
check_contains('DOC: đọc trọn câu có dấu tiếng Việt', $res['text'],
    'Biên bản họp phụ huynh lớp 9A ngày 12 tháng 3');
check_contains('DOC: đọc được câu thứ hai', $res['text'], 'quỹ lớp 200.000 đồng');
check('DOC: có cảnh báo nội dung gần đúng', $res['reason'] === 'legacy_office_partial',
    'reason=' . $res['reason']);
check('DOC: lọc bỏ tên stream nội bộ của OLE',
    strpos($res['text'], 'WordDocument') === false && strpos($res['text'], 'Root Entry') === false,
    'còn sót: ' . mb_substr($res['text'], 0, 120));

// ===========================================================================
section('Tệp chỉ mô hình mới xem được');
// ===========================================================================
$res = extract_file_content(fixture('anh.png', "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 64)),
    'image', 'png');
check('ảnh: báo "needs_vision"', $res['reason'] === 'needs_vision', 'reason=' . $res['reason']);
check_contains('ảnh: có câu giải thích cho người dùng',
    extract_reason_text($res['reason'], 'image'), 'nhìn ảnh');

$res = extract_file_content(fixture('ghi-am.mp3', str_repeat("\x00", 256)), 'audio', 'mp3');
check('âm thanh: báo "needs_media"', $res['reason'] === 'needs_media', 'reason=' . $res['reason']);

$res = extract_file_content(fixture('la.bin', str_repeat("\x07", 256)), 'file', 'bin');
check('định dạng lạ: báo "unsupported"', $res['reason'] === 'unsupported',
    'reason=' . $res['reason']);
check('mọi mã lý do đều có câu giải thích tiếng Việt',
    extract_reason_text('unsupported') !== '' && extract_reason_text('pdf_no_text') !== ''
    && extract_reason_text('') === '');

// ===========================================================================
section('Bộ tách câu lệnh SQL của trình cài đặt');
// ===========================================================================
require_once APP_ROOT . '/includes/db.php';
$sql = "CREATE TABLE `a` (\n  `x` INT COMMENT 'có dấu; chấm phẩy'\n);\n"
     . "-- chú thích; có chấm phẩy\n"
     . "INSERT INTO `a` VALUES ('chuỗi; lồng'), (\"nháy kép; nữa\");\n"
     . "/* chú thích khối;\n   nhiều dòng; */\n"
     . "UPDATE `a` SET `x` = 1 WHERE `x` = 0;";
$parts = db_split_sql($sql);
check('tách đúng 3 câu lệnh', count($parts) === 3, 'đếm được ' . count($parts));
check('dấu ; trong COMMENT không làm đứt câu lệnh',
    isset($parts[0]) && strpos($parts[0], 'chấm phẩy') !== false);
check('dấu ; trong chuỗi không làm đứt câu lệnh',
    isset($parts[1]) && strpos($parts[1], 'nháy kép; nữa') !== false);

// ===========================================================================
section('Lọc tên mô hình');
// ===========================================================================
// Không có cơ sở dữ liệu nên chỉ kiểm tra hàm thuần.
require_once APP_ROOT . '/includes/settings.php';
check('filter_model_name tồn tại và trả về chuỗi',
    function_exists('filter_model_name') && is_string(@filter_model_name('x/y')));

// ---------------------------------------------------------------------------
// Tổng kết
// ---------------------------------------------------------------------------
foreach (glob($GLOBALS['tmpdir'] . '/*') as $file) {
    @unlink($file);
}
@rmdir($GLOBALS['tmpdir']);

$pass = $GLOBALS['tests']['pass'];
$fail = $GLOBALS['tests']['fail'];
echo "\n" . str_repeat('─', 60) . "\n";
if ($fail === 0) {
    echo "\033[32mTẤT CẢ " . $pass . " KIỂM TRA ĐỀU ĐẠT.\033[0m\n";
    exit(0);
}
echo "\033[31m" . $fail . " kiểm tra KHÔNG đạt\033[0m (" . $pass . " đạt):\n";
foreach ($GLOBALS['tests']['messages'] as $msg) {
    echo '  · ' . $msg . "\n";
}
exit(1);
