<?php
/**
 * Tăng phiên bản từ dòng lệnh.
 *
 * Cách dùng:
 *   php tools/bump.php [patch|minor|major] ["ghi chú 1" "ghi chú 2" ...]
 *
 * Tuỳ chọn:
 *   --codename="Tên mã"   Đặt tên mã cho phiên bản
 *   --git                 Tự chạy git add / commit / tag sau khi tăng phiên bản
 *   --dry                 Chỉ hiển thị kết quả dự kiến, không ghi file
 *
 * Ví dụ:
 *   php tools/bump.php patch "Sửa lỗi hiển thị LaTeX trong bảng"
 *   php tools/bump.php minor --codename="Mùa Thu" --git "Thêm hỗ trợ Ollama"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script này chỉ chạy từ dòng lệnh.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/version.php';
require_once APP_ROOT . '/includes/helpers.php';

// Phiên bản CLI không cần CSDL — nạp một bản versioning tối giản.
define('APP_TZ', 'Asia/Ho_Chi_Minh');
date_default_timezone_set(APP_TZ);

/** Thay hàm db_* bằng phiên bản rỗng để versioning.php dùng được ngoài web. */
function db_table_exists($table) { return false; }
function db_run($sql, $params = []) { return null; }

require_once APP_ROOT . '/includes/versioning.php';

// ---------------------------------------------------------------------------
// Phân tích tham số
// ---------------------------------------------------------------------------
$args     = array_slice($argv, 1);
$type     = 'patch';
$codename = '';
$doGit    = false;
$dryRun   = false;
$notes    = [];

foreach ($args as $arg) {
    if (in_array($arg, ['patch', 'minor', 'major'], true)) {
        $type = $arg;
    } elseif (strpos($arg, '--codename=') === 0) {
        $codename = trim(substr($arg, 11), '"\'');
    } elseif ($arg === '--git') {
        $doGit = true;
    } elseif ($arg === '--dry') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Cách dùng: php tools/bump.php [patch|minor|major] [--codename=\"Tên\"] [--git] [--dry] \"ghi chú\"...\n";
        exit(0);
    } elseif (strpos($arg, '--') !== 0) {
        $notes[] = $arg;
    }
}

$current = APP_VERSION;
$next    = version_next($current, $type);

echo "════════════════════════════════════════════════════\n";
echo "  Tăng phiên bản Tuấn Chatbot\n";
echo "════════════════════════════════════════════════════\n";
echo "  Hiện tại : v{$current} (" . APP_CODENAME . ")\n";
echo "  Mức tăng : {$type}\n";
echo "  Sẽ thành : v{$next}" . ($codename !== '' ? " ({$codename})" : '') . "\n";
echo "  Ngày     : " . date('d/m/Y') . " (giờ Việt Nam)\n";

if (!$notes) {
    $notes = ['Cập nhật nhỏ và tinh chỉnh giao diện.'];
}
echo "  Ghi chú  :\n";
foreach ($notes as $note) {
    echo "    - {$note}\n";
}
echo "────────────────────────────────────────────────────\n";

if ($dryRun) {
    echo "  (--dry) Không ghi file nào.\n";
    exit(0);
}

$result = version_bump($type, $notes, $codename);

if (!$result['ok']) {
    fwrite(STDERR, "❌ Lỗi: " . $result['error'] . "\n");
    exit(1);
}

echo "✅ Đã ghi includes/version.php → v{$result['version']}\n";
echo "✅ Đã thêm mục mới vào CHANGELOG.md\n";

// ---------------------------------------------------------------------------
// Đồng bộ với Git (tuỳ chọn)
// ---------------------------------------------------------------------------
if ($doGit) {
    $version = $result['version'];
    $tag     = 'v' . $version;
    $message = 'chore(release): ' . $tag;

    $commands = [
        'git add CHANGELOG.md includes/version.php',
        'git commit -m ' . escapeshellarg($message),
        'git tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg('Phiên bản ' . $version
            . ($codename !== '' ? ' — ' . $codename : '')),
    ];

    foreach ($commands as $command) {
        echo "→ {$command}\n";
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        foreach ($output as $line) {
            echo "   {$line}\n";
        }
        if ($status !== 0) {
            fwrite(STDERR, "⚠️  Lệnh git thất bại, hãy xử lý thủ công.\n");
            exit(1);
        }
    }
    echo "\n✅ Đã commit và tạo tag {$tag}.\n";
    echo "   Đẩy lên GitHub: git push origin HEAD --tags\n";
} else {
    echo "\n📌 Việc tiếp theo — đẩy lịch sử lên GitHub:\n";
    echo "   git add CHANGELOG.md includes/version.php\n";
    echo "   git commit -m \"chore(release): v{$result['version']}\"\n";
    echo "   git tag -a v{$result['version']} -m \"Phiên bản {$result['version']}\"\n";
    echo "   git push origin HEAD --tags\n";
}

echo "\n🎉 Hoàn tất!\n";
