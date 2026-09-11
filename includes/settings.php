<?php
/**
 * Thiết lập chung của website — lưu trong bảng `settings`, nạp một lần mỗi request.
 */

/** Giá trị mặc định cho mọi thiết lập. */
function settings_defaults()
{
    return [
        'site_name'         => APP_NAME_DEFAULT,
        'site_tagline'      => 'Trợ lý AI vui vẻ, nhanh nhẹn và luôn sẵn sàng 🇻🇳',
        'brand_emoji'       => '🤖',
        'copyright'         => '© ' . date('Y') . ' Tuấn Chatbot — Được tạo với ❤️ tại Việt Nam',
        'welcome_message'   => 'Xin chào! Mình là trợ lý AI của bạn. Hôm nay mình giúp được gì nào?',
        'allow_register'    => '1',
        'maintenance'       => '0',
        'maintenance_note'  => 'Hệ thống đang bảo trì, vui lòng quay lại sau ít phút nhé!',
        'max_upload_mb'     => '25',
        'max_files_per_msg' => '10',
        'allowed_ext'       => 'jpg,jpeg,png,gif,webp,bmp,svg,pdf,txt,md,csv,json,xml,html,htm,css,js,ts,py,php,java,c,cpp,cs,go,rb,rs,sql,yml,yaml,ini,log,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,mp3,wav,ogg,m4a,mp4,webm,mov',
        'history_limit'     => '20',
        'show_version'      => '1',
        'github_url'        => 'https://github.com/tlearnvn/tuan-chatbot',
        'theme_primary'     => '#7c5cff',
        'theme_accent'      => '#ff5c9d',
        'theme_mode'        => 'light',
        'suggestions'       => "Giải thích định lý Pythagore kèm công thức LaTeX\nViết đoạn văn tiếng Việt về mùa thu Hà Nội\nTóm tắt tài liệu mình vừa tải lên\nGiúp mình viết hàm PHP kết nối MySQL",
        'admin_email'       => '',
        'footer_links'      => '',
    ];
}

/** Nạp toàn bộ thiết lập (có cache tĩnh). */
function settings_all($refresh = false)
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = settings_defaults();
    try {
        foreach (db_all('SELECT `k`, `v` FROM `settings`') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    } catch (Exception $ex) {
        // Chưa cài đặt xong — dùng mặc định.
    }
    return $cache;
}

/** Đọc một thiết lập. */
function setting($key, $default = null)
{
    $all = settings_all();
    if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
        return $all[$key];
    }
    if ($default !== null) {
        return $default;
    }
    $defaults = settings_defaults();
    return $defaults[$key] ?? '';
}

/** Đọc thiết lập kiểu boolean. */
function setting_bool($key)
{
    return in_array((string)setting($key), ['1', 'true', 'on', 'yes'], true);
}

/** Đọc thiết lập kiểu số nguyên. */
function setting_int($key, $default = 0)
{
    $value = setting($key, (string)$default);
    return is_numeric($value) ? (int)$value : $default;
}

/** Ghi một thiết lập. */
function setting_set($key, $value)
{
    db_run(
        'INSERT INTO `settings` (`k`, `v`, `updated_at`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `updated_at` = VALUES(`updated_at`)',
        [$key, (string)$value, now_vn()]
    );
    settings_all(true);
}

/** Ghi nhiều thiết lập cùng lúc. */
function settings_save(array $pairs)
{
    foreach ($pairs as $key => $value) {
        db_run(
            'INSERT INTO `settings` (`k`, `v`, `updated_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `updated_at` = VALUES(`updated_at`)',
            [$key, (string)$value, now_vn()]
        );
    }
    settings_all(true);
}

/** Tên website hiển thị. */
function site_name()
{
    return setting('site_name');
}

/** Danh sách câu gợi ý ở màn hình chào. */
function setting_suggestions()
{
    $raw = (string)setting('suggestions');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Danh sách phần mở rộng được phép tải lên. */
function allowed_extensions()
{
    $raw = strtolower((string)setting('allowed_ext'));
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    return array_values(array_unique($parts));
}
