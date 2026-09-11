<?php
/**
 * Nâng cấp lược đồ cơ sở dữ liệu.
 *
 * Chạy tự động (và rất nhẹ) khi quản trị viên mở khu vực quản trị: chỉ so số
 * hiệu lược đồ lưu trong bảng `settings`, nếu đã khớp thì không truy vấn gì thêm.
 */

/** Số hiệu lược đồ mà mã nguồn hiện tại yêu cầu. */
define('DB_SCHEMA_VERSION', 2);

/** Bảng đã có cột này chưa? */
function db_column_exists($table, $column)
{
    try {
        $row = db_one(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );
        return $row !== null;
    } catch (Exception $ex) {
        return false;
    }
}

/**
 * Thực hiện các bước nâng cấp còn thiếu.
 *
 * @param bool $force Bỏ qua số hiệu đã lưu, kiểm tra lại từ đầu
 * @return array Danh sách việc đã làm (rỗng = không cần làm gì)
 */
function db_migrate($force = false)
{
    $current = (int)setting('schema_version', '0');
    if (!$force && $current >= DB_SCHEMA_VERSION) {
        return [];
    }

    $done = [];

    // --- Lược đồ 2: chuyển nội dung tệp và session vào cơ sở dữ liệu ---------
    if (!db_table_exists('attachment_chunks')) {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `attachment_chunks` (
              `attachment_id` INT UNSIGNED NOT NULL,
              `seq`           SMALLINT UNSIGNED NOT NULL,
              `content`       MEDIUMBLOB NOT NULL,
              PRIMARY KEY (`attachment_id`, `seq`),
              CONSTRAINT `fk_chunk_attachment` FOREIGN KEY (`attachment_id`)
                REFERENCES `attachments` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done[] = 'Tạo bảng `attachment_chunks` để lưu nội dung tệp.';
    }

    if (!db_table_exists('sessions')) {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `sessions` (
              `id`            VARCHAR(128) NOT NULL,
              `user_id`       INT UNSIGNED NULL,
              `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
              `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
              `payload`       MEDIUMBLOB   NULL,
              `last_activity` INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_sessions_activity` (`last_activity`),
              KEY `idx_sessions_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done[] = 'Tạo bảng `sessions` để lưu phiên đăng nhập.';
    }

    if (db_table_exists('attachments') && !db_column_exists('attachments', 'storage')) {
        // Bản ghi cũ vẫn trỏ tới tệp trong uploads/ nên đánh dấu storage = 'file'.
        db()->exec(
            "ALTER TABLE `attachments`
               ADD COLUMN `storage` ENUM('db','file') NOT NULL DEFAULT 'db' AFTER `original_name`"
        );
        db()->exec("UPDATE `attachments` SET `storage` = 'file' WHERE `stored_name` <> ''");
        $done[] = 'Thêm cột `attachments.storage`; các tệp cũ được giữ nguyên trên đĩa.';
    }

    if (db_table_exists('attachments') && db_column_exists('attachments', 'stored_name')) {
        // Tệp mới không cần tên trên đĩa nữa nên cột phải cho phép rỗng.
        try {
            db()->exec("ALTER TABLE `attachments` MODIFY `stored_name` VARCHAR(255) NOT NULL DEFAULT ''");
        } catch (Exception $ex) {
            // Không quan trọng nếu đã đúng định dạng.
        }
    }

    setting_set('schema_version', (string)DB_SCHEMA_VERSION);
    if ($done) {
        log_activity('db_migrate', 'Nâng cấp lược đồ lên v' . DB_SCHEMA_VERSION . ': ' . implode(' ', $done));
    }
    return $done;
}

/**
 * Chuyển các tệp cũ còn nằm trong uploads/ vào cơ sở dữ liệu.
 *
 * @param int $limit Số tệp xử lý mỗi lần gọi (tránh quá thời gian thực thi)
 * @return array ['moved' => int, 'failed' => int, 'bytes' => int, 'remaining' => int, 'errors' => [...]]
 */
function db_migrate_files_to_db($limit = 25)
{
    $result = ['moved' => 0, 'failed' => 0, 'bytes' => 0, 'remaining' => 0, 'errors' => []];

    if (!db_column_exists('attachments', 'storage')) {
        return $result;
    }

    $limit = max(1, min(200, (int)$limit));
    $rows  = db_all(
        "SELECT * FROM `attachments` WHERE `storage` = 'file' ORDER BY `id` LIMIT " . $limit
    );

    foreach ($rows as $row) {
        $path = attachment_legacy_path($row);
        if (!$path || !is_file($path)) {
            // Tệp đã mất trên đĩa: đánh dấu đã xử lý để không thử lại mãi.
            // Bản ghi vẫn giữ để lịch sử chat không bị khuyết, chỉ là không tải được nội dung.
            db_run("UPDATE `attachments` SET `storage` = 'db', `stored_name` = '', `size` = 0 WHERE `id` = ?",
                [$row['id']]);
            $result['failed']++;
            $result['errors'][] = 'Không tìm thấy tệp trên đĩa: ' . $row['original_name'];
            continue;
        }
        try {
            $bytes = storage_put_from_file($row['id'], $path);
            db_run("UPDATE `attachments` SET `storage` = 'db', `size` = ?, `stored_name` = '' WHERE `id` = ?",
                [$bytes, $row['id']]);
            @unlink($path);
            $result['moved']++;
            $result['bytes'] += $bytes;
        } catch (Exception $ex) {
            $result['failed']++;
            $result['errors'][] = $row['original_name'] . ': ' . $ex->getMessage();
        }
    }

    $result['remaining'] = (int)db_value(
        "SELECT COUNT(*) FROM `attachments` WHERE `storage` = 'file'", [], 0
    );
    return $result;
}
