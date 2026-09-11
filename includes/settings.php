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
        // Lưu ý: các đuôi thi hành được (php, sh, exe…) luôn bị chặn ở api/upload.php
        // dù có xuất hiện ở đây hay không.
        'allowed_ext'       => 'jpg,jpeg,png,gif,webp,bmp,svg,heic,pdf,txt,md,markdown,csv,tsv,json,xml,html,htm,css,js,ts,java,c,h,cpp,cs,go,rb,rs,sql,yml,yaml,ini,conf,log,srt,vtt,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,rtf,epub,zip,rar,7z,mp3,wav,ogg,m4a,flac,mp4,webm,mov',
        'history_limit'     => '20',
        'show_version'      => '1',
        'show_model_name'   => '1',
        'github_url'        => 'https://github.com/tlearnvn/tuan-chatbot',
        'facebook_url'      => '',
        // Liên kết mạng xã hội hiện ở chân trang: github | facebook | both | none
        'social_link'       => 'github',
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

/**
 * Người đang xem có được thấy tên mô hình AI hay không.
 *
 * Khi quản trị viên tắt thiết lập `show_model_name`, tên mô hình bị loại khỏi
 * MỌI dữ liệu gửi ra trình duyệt (không chỉ ẩn bằng CSS) nên không đọc được
 * qua mã nguồn trang hay công cụ nhà phát triển. Quản trị viên vẫn thấy để
 * còn đối chiếu khi gỡ lỗi.
 */
function can_see_model_name()
{
    if (setting_bool('show_model_name')) {
        return true;
    }
    return function_exists('is_admin') && is_admin();
}

/** Lọc tên mô hình khỏi dữ liệu trả về nếu người xem không được phép thấy. */
function filter_model_name($model)
{
    return can_see_model_name() ? (string)$model : '';
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

/** Các kiểu liên kết mạng xã hội hiện ở chân trang, kèm nhãn tiếng Việt. */
function social_link_types()
{
    return [
        'github'   => 'GitHub — mã nguồn dự án',
        'facebook' => 'Facebook — trang hoặc nhóm của bạn',
        'both'     => 'Cả hai',
        'none'     => 'Không hiện liên kết nào',
    ];
}

/**
 * Liên kết mạng xã hội sẽ vẽ ở chân trang.
 *
 * @return array [['key' => 'facebook', 'url' => ..., 'label' => 'Facebook'], …]
 */
function footer_social_links()
{
    $mode = (string)setting('social_link', 'github');
    if (!array_key_exists($mode, social_link_types())) {
        $mode = 'github';
    }
    if ($mode === 'none') {
        return [];
    }

    $wanted = $mode === 'both' ? ['github', 'facebook'] : [$mode];
    $labels = ['github' => 'GitHub', 'facebook' => 'Facebook'];

    $out = [];
    foreach ($wanted as $key) {
        $url = trim((string)setting($key . '_url'));
        if ($url === '') {
            continue;
        }
        $out[] = ['key' => $key, 'url' => $url, 'label' => $labels[$key]];
    }
    return $out;
}

/** Biểu tượng SVG của một mạng xã hội (dùng ở chân trang). */
function social_link_icon($key)
{
    $paths = [
        'github' => 'M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 '
                  . '0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 '
                  . '1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 '
                  . '0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 '
                  . '1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 '
                  . '3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 '
                  . '8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z',
        'facebook' => 'M16 8.05C16 3.6 12.42 0 8 0S0 3.6 0 8.05C0 12.07 2.93 15.4 6.75 16v-5.61H4.72V8.05h2.03V6.28'
                    . 'c0-2.02 1.2-3.13 3.02-3.13.88 0 1.79.16 1.79.16v1.97h-1.01c-.99 0-1.3.62-1.3 1.26v1.51h2.22'
                    . 'l-.36 2.34H9.25V16C13.07 15.4 16 12.07 16 8.05z',
    ];
    $path = $paths[$key] ?? '';
    if ($path === '') {
        return '';
    }
    return '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">'
         . '<path d="' . $path . '"/></svg>';
}
