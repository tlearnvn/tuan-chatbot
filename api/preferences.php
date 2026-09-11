<?php
/**
 * Lưu tuỳ chọn cá nhân nhẹ (hiện tại: giao diện sáng/tối) để đồng bộ giữa các thiết bị.
 */
require_once __DIR__ . '/_init.php';

$user = require_login(true);
api_require_method('POST');
csrf_require(true);

$theme = (string)api_param('theme', '');
if (!in_array($theme, ['light', 'dark'], true)) {
    json_error('Giá trị giao diện không hợp lệ.');
}

if ($theme !== $user['theme']) {
    db_update('users', ['theme' => $theme, 'updated_at' => now_vn()], '`id` = ?', [(int)$user['id']]);
}

json_out(['ok' => true, 'theme' => $theme]);
