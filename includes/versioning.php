<?php
/**
 * Quản lý phiên bản & lịch sử thay đổi.
 *
 * Nguồn dữ liệu:
 *   - includes/version.php : hằng số APP_VERSION hiện hành (hiện ở chân trang)
 *   - CHANGELOG.md         : lịch sử thay đổi dạng văn bản (đẩy lên GitHub)
 *   - bảng app_versions    : bản ghi phiên bản trong CSDL
 */

/** Đường dẫn file phiên bản. */
function version_file()
{
    return APP_ROOT . '/includes/version.php';
}

/** Đường dẫn file lịch sử thay đổi. */
function changelog_file()
{
    return APP_ROOT . '/CHANGELOG.md';
}

/** Tách chuỗi phiên bản thành [major, minor, patch]. */
function version_parts($version)
{
    $bits = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$version) . '.0.0'));
    return [$bits[0] ?? 0, $bits[1] ?? 0, $bits[2] ?? 0];
}

/**
 * Tính phiên bản kế tiếp.
 *
 * @param string $current  Phiên bản hiện tại
 * @param string $type     major | minor | patch
 */
function version_next($current, $type = 'patch')
{
    list($major, $minor, $patch) = version_parts($current);
    if ($type === 'major') {
        return ($major + 1) . '.0.0';
    }
    if ($type === 'minor') {
        return $major . '.' . ($minor + 1) . '.0';
    }
    return $major . '.' . $minor . '.' . ($patch + 1);
}

/** So sánh hai phiên bản: -1, 0, 1. */
function version_compare_semver($a, $b)
{
    $pa = version_parts($a);
    $pb = version_parts($b);
    for ($i = 0; $i < 3; $i++) {
        if ($pa[$i] !== $pb[$i]) {
            return $pa[$i] < $pb[$i] ? -1 : 1;
        }
    }
    return 0;
}

/**
 * Ghi phiên bản mới vào includes/version.php.
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function version_write($version, $date = null, $codename = null)
{
    $path = version_file();
    if (!is_writable($path)) {
        return ['ok' => false, 'error' => 'Không ghi được vào includes/version.php. '
            . 'Hãy đặt quyền ghi (644 hoặc 664) cho file này.'];
    }
    $source = file_get_contents($path);
    if ($source === false) {
        return ['ok' => false, 'error' => 'Không đọc được includes/version.php.'];
    }

    $date     = $date ?: date('Y-m-d');
    $replaced = preg_replace(
        "/define\('APP_VERSION',\s*'[^']*'\);/",
        "define('APP_VERSION', '" . addslashes($version) . "');",
        $source,
        1
    );
    $replaced = preg_replace(
        "/define\('APP_VERSION_DATE',\s*'[^']*'\);/",
        "define('APP_VERSION_DATE', '" . addslashes($date) . "');",
        $replaced,
        1
    );
    if ($codename !== null && $codename !== '') {
        $replaced = preg_replace(
            "/define\('APP_CODENAME',\s*'[^']*'\);/",
            "define('APP_CODENAME', '" . addslashes($codename) . "');",
            $replaced,
            1
        );
    }

    if ($replaced === null || strpos($replaced, "'" . $version . "'") === false) {
        return ['ok' => false, 'error' => 'Không nhận diện được định dạng của includes/version.php.'];
    }
    if (file_put_contents($path, $replaced) === false) {
        return ['ok' => false, 'error' => 'Ghi file phiên bản thất bại.'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Thêm một mục mới vào đầu CHANGELOG.md.
 *
 * @param string $version
 * @param array  $notes    Danh sách dòng ghi chú
 */
function changelog_prepend($version, array $notes, $date = null, $codename = '')
{
    $path = changelog_file();
    $date = $date ?: date('Y-m-d');

    $entry = '## [' . $version . '] — ' . $date
           . ($codename !== '' ? ' · _' . $codename . '_' : '') . "\n\n";
    foreach ($notes as $note) {
        $note = trim($note);
        if ($note !== '') {
            $entry .= '- ' . $note . "\n";
        }
    }
    $entry .= "\n";

    $existing = file_exists($path) ? file_get_contents($path) : '';
    if ($existing === '') {
        $existing = changelog_header();
    }

    // Chèn ngay sau phần tiêu đề (trước mục "## [" đầu tiên).
    $pos = strpos($existing, "\n## [");
    if ($pos === false) {
        $content = rtrim($existing) . "\n\n" . $entry;
    } else {
        $content = substr($existing, 0, $pos + 1) . $entry . ltrim(substr($existing, $pos + 1), "\n");
    }

    if (!is_writable($path) && file_exists($path)) {
        return ['ok' => false, 'error' => 'Không ghi được CHANGELOG.md. Hãy đặt quyền ghi cho file này.'];
    }
    if (file_put_contents($path, $content) === false) {
        return ['ok' => false, 'error' => 'Ghi CHANGELOG.md thất bại.'];
    }
    return ['ok' => true, 'error' => ''];
}

/** Phần tiêu đề mặc định của CHANGELOG.md. */
function changelog_header()
{
    return "# Lịch sử phiên bản\n\n"
         . "Mọi thay đổi đáng chú ý của dự án được ghi lại tại đây.\n"
         . "Dự án tuân theo [Semantic Versioning](https://semver.org/lang/vi/): `MAJOR.MINOR.PATCH`.\n\n";
}

/**
 * Đọc CHANGELOG.md và tách thành danh sách phiên bản.
 *
 * @return array [['version' => .., 'date' => .., 'codename' => .., 'notes' => [..]], ...]
 */
function changelog_entries()
{
    $path = changelog_file();
    if (!file_exists($path)) {
        return [];
    }
    $lines   = preg_split('/\r\n|\r|\n/', (string)file_get_contents($path));
    $entries = [];
    $current = null;

    foreach ($lines as $line) {
        if (preg_match('/^##\s*\[?([0-9]+\.[0-9]+\.[0-9]+)\]?\s*[—\-–]*\s*([0-9]{4}-[0-9]{2}-[0-9]{2})?\s*(?:·\s*_?(.*?)_?)?\s*$/u', $line, $m)) {
            if ($current) {
                $entries[] = $current;
            }
            $current = [
                'version'  => $m[1],
                'date'     => $m[2] ?? '',
                'codename' => trim($m[3] ?? ''),
                'notes'    => [],
            ];
            continue;
        }
        if ($current !== null && preg_match('/^[-*]\s+(.*)$/u', $line, $m)) {
            $current['notes'][] = trim($m[1]);
        }
    }
    if ($current) {
        $entries[] = $current;
    }
    return $entries;
}

/** Đồng bộ danh sách phiên bản từ CHANGELOG.md vào bảng app_versions. */
function version_sync_db()
{
    if (!db_table_exists('app_versions')) {
        return 0;
    }
    $count = 0;
    foreach (changelog_entries() as $entry) {
        $date = $entry['date'] !== '' ? $entry['date'] . ' 00:00:00' : now_vn();
        db_run(
            'INSERT INTO `app_versions` (`version`, `released_at`, `notes`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `released_at` = VALUES(`released_at`), `notes` = VALUES(`notes`)',
            [$entry['version'], $date, implode("\n", $entry['notes'])]
        );
        $count++;
    }
    return $count;
}

/**
 * Tăng phiên bản: ghi file version.php, thêm mục vào CHANGELOG.md, lưu vào CSDL.
 *
 * @return array ['ok' => bool, 'version' => string, 'error' => string]
 */
function version_bump($type, array $notes, $codename = '')
{
    $next = version_next(APP_VERSION, $type);
    $date = date('Y-m-d');

    if (!$notes) {
        $notes = ['Cập nhật nhỏ và tinh chỉnh giao diện.'];
    }

    $written = version_write($next, $date, $codename !== '' ? $codename : null);
    if (!$written['ok']) {
        return ['ok' => false, 'version' => APP_VERSION, 'error' => $written['error']];
    }

    $logged = changelog_prepend($next, $notes, $date, $codename);
    if (!$logged['ok']) {
        // Hoàn tác thay đổi file phiên bản để dữ liệu không lệch nhau.
        version_write(APP_VERSION, APP_VERSION_DATE, APP_CODENAME);
        return ['ok' => false, 'version' => APP_VERSION, 'error' => $logged['error']];
    }

    try {
        db_run(
            'INSERT INTO `app_versions` (`version`, `released_at`, `notes`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `released_at` = VALUES(`released_at`), `notes` = VALUES(`notes`)',
            [$next, $date . ' ' . date('H:i:s'), implode("\n", $notes)]
        );
    } catch (Exception $ex) {
        // Không chặn việc tăng phiên bản nếu CSDL lỗi.
    }

    return ['ok' => true, 'version' => $next, 'error' => ''];
}
