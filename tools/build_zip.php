<?php
/**
 * Đóng gói mã nguồn thành file ZIP để tải lên shared hosting.
 *
 * Cách dùng:
 *   php tools/build_zip.php              → dist/tuan-chatbot-<phiên bản>.zip
 *   php tools/build_zip.php --out=/duong/dan/ten.zip
 *   php tools/build_zip.php --with-config   (kèm cả config/config.php — CẨN THẬN)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script này chỉ chạy từ dòng lệnh.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/version.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "❌ Cần phần mở rộng PHP zip để đóng gói. Hãy bật extension=zip.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Tham số
// ---------------------------------------------------------------------------
$outPath    = '';
$withConfig = false;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--out=') === 0) {
        $outPath = substr($arg, 6);
    } elseif ($arg === '--with-config') {
        $withConfig = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Cách dùng: php tools/build_zip.php [--out=duong/dan.zip] [--with-config]\n";
        exit(0);
    }
}

if ($outPath === '') {
    $distDir = APP_ROOT . '/dist';
    if (!is_dir($distDir) && !mkdir($distDir, 0755, true) && !is_dir($distDir)) {
        fwrite(STDERR, "❌ Không tạo được thư mục dist/.\n");
        exit(1);
    }
    $outPath = $distDir . '/tuan-chatbot-' . APP_VERSION . '.zip';
}

// ---------------------------------------------------------------------------
// Quy tắc loại trừ
// ---------------------------------------------------------------------------
$excludeDirs = ['.git', '.github', 'dist', 'node_modules', '.idea', '.vscode'];

$excludeFiles = ['.DS_Store', 'Thumbs.db', '.gitignore', '.gitattributes', 'desktop.ini'];
if (!$withConfig) {
    $excludeFiles[] = 'config/config.php';
}

$excludeExt = ['zip', 'log', 'swp', 'tmp', 'bak'];

/** Kiểm tra một đường dẫn tương đối có bị loại trừ hay không. */
function should_skip($relative, array $excludeDirs, array $excludeFiles, array $excludeExt)
{
    $parts = explode('/', $relative);

    foreach ($parts as $part) {
        if (in_array($part, $excludeDirs, true)) {
            return true;
        }
    }
    if (in_array($relative, $excludeFiles, true) || in_array(end($parts), $excludeFiles, true)) {
        return true;
    }
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, $excludeExt, true)) {
        return true;
    }
    // Tệp người dùng đã tải lên: chỉ giữ .htaccess, index.html và .gitkeep.
    if (strpos($relative, 'uploads/') === 0) {
        $name = end($parts);
        if (!in_array($name, ['.htaccess', 'index.html', '.gitkeep'], true)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Tạo file ZIP
// ---------------------------------------------------------------------------
@unlink($outPath);
$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "❌ Không tạo được file ZIP tại: {$outPath}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$fileCount = 0;
$totalSize = 0;
$skipped   = 0;

foreach ($iterator as $item) {
    $path     = str_replace('\\', '/', $item->getPathname());
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', APP_ROOT))), '/');

    if ($relative === '') {
        continue;
    }
    if (should_skip($relative, $excludeDirs, $excludeFiles, $excludeExt)) {
        if ($item->isFile()) {
            $skipped++;
        }
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
    } elseif ($item->isFile()) {
        $zip->addFile($path, $relative);
        $fileCount++;
        $totalSize += $item->getSize();
    }
}

// Kèm theo một hướng dẫn cài đặt ngắn ngay trong gói.
$zip->addFromString('DOC-CAI-DAT.txt', build_install_guide());

if (!$zip->close()) {
    fwrite(STDERR, "❌ Lỗi khi hoàn tất file ZIP.\n");
    exit(1);
}

$zipSize = filesize($outPath);

echo "════════════════════════════════════════════════════\n";
echo "  Đóng gói Tuấn Chatbot v" . APP_VERSION . "\n";
echo "════════════════════════════════════════════════════\n";
echo "  Tệp đã thêm  : {$fileCount}\n";
echo "  Tệp bỏ qua   : {$skipped}\n";
echo "  Dung lượng gốc: " . round($totalSize / 1024, 1) . " KB\n";
echo "  File ZIP     : {$outPath}\n";
echo "  Dung lượng   : " . round($zipSize / 1024, 1) . " KB\n";
echo "────────────────────────────────────────────────────\n";
echo "  Giải nén toàn bộ vào public_html/ rồi mở install.php\n";
echo "🎉 Xong!\n";

/** Nội dung file hướng dẫn kèm trong gói ZIP. */
function build_install_guide()
{
    return "HƯỚNG DẪN CÀI ĐẶT TUẤN CHATBOT v" . APP_VERSION . "\r\n"
        . "Đóng gói lúc: " . date('H:i d/m/Y') . " (giờ Việt Nam)\r\n"
        . str_repeat('=', 60) . "\r\n\r\n"
        . "BƯỚC 1 — TẠO DATABASE\r\n"
        . "  Vào cPanel/DirectAdmin > MySQL Databases, tạo một database mới\r\n"
        . "  với bộ mã utf8mb4, tạo user và gán toàn quyền cho database đó.\r\n"
        . "  Ghi lại: tên database, tên user, mật khẩu.\r\n\r\n"
        . "BƯỚC 2 — TẢI MÃ NGUỒN LÊN\r\n"
        . "  Giải nén toàn bộ nội dung gói này vào thư mục public_html/\r\n"
        . "  (hoặc thư mục con nếu muốn đặt ở đường dẫn phụ).\r\n\r\n"
        . "BƯỚC 3 — CẤP QUYỀN THƯ MỤC\r\n"
        . "  uploads/               -> 755 (phải ghi được)\r\n"
        . "  config/                -> 755 (phải ghi được)\r\n"
        . "  includes/version.php   -> 664 (nếu muốn tăng phiên bản từ trang quản trị)\r\n"
        . "  CHANGELOG.md           -> 664 (nếu muốn tăng phiên bản từ trang quản trị)\r\n\r\n"
        . "BƯỚC 4 — CHẠY TRÌNH CÀI ĐẶT\r\n"
        . "  Mở: https://ten-mien-cua-ban/install.php\r\n"
        . "  Làm theo 5 bước: kiểm tra máy chủ > database > tài khoản quản trị\r\n"
        . "  > kết nối AI > hoàn tất.\r\n\r\n"
        . "BƯỚC 5 — BẢO MẬT\r\n"
        . "  * XOÁ file install.php sau khi cài xong.\r\n"
        . "  * Bật HTTPS cho tên miền (Let's Encrypt miễn phí).\r\n"
        . "  * Kiểm tra uploads/.htaccess vẫn còn nguyên.\r\n\r\n"
        . "YÊU CẦU MÁY CHỦ\r\n"
        . "  PHP 7.4 trở lên, MySQL 5.7+ / MariaDB 10.2+\r\n"
        . "  Phần mở rộng bắt buộc: pdo_mysql, curl, mbstring, json\r\n"
        . "  Nên có thêm: openssl, zip, fileinfo\r\n\r\n"
        . "CẤU HÌNH AI\r\n"
        . "  Quản trị > AI API Endpoint > Thêm endpoint.\r\n"
        . "  Nhập URL, API key, tên mô hình; token mặc định 64000, timeout 300s.\r\n"
        . "  Bấm \"Kiểm tra kết nối\" để xác nhận trước khi lưu.\r\n\r\n"
        . "GẶP SỰ CỐ?\r\n"
        . "  Đọc mục \"Khắc phục sự cố\" trong README.md.\r\n";
}
