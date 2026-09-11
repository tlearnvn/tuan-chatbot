<?php
/**
 * Bộ xử lý session lưu trong MySQL, thay cho file session trên đĩa.
 *
 * Lợi ích:
 *   - Không sinh file nào trên đĩa → không tốn inode của hosting.
 *   - Quản trị viên xem được danh sách phiên đang hoạt động và ngắt được phiên.
 *
 * Mọi thao tác đều bọc try/catch: nếu cơ sở dữ liệu có sự cố thì session coi như
 * rỗng thay vì làm sập cả trang.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    /** @var int Thời gian sống tối đa của phiên (giây) */
    private $lifetime;

    public function __construct($lifetime = 0)
    {
        $this->lifetime = $lifetime > 0 ? (int)$lifetime : (int)ini_get('session.gc_maxlifetime');
        if ($this->lifetime <= 0) {
            $this->lifetime = 1440;
        }
    }

    #[\ReturnTypeWillChange]
    public function open($path, $name)
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close()
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        try {
            $row = db_one(
                'SELECT `payload`, `last_activity` FROM `sessions` WHERE `id` = ? LIMIT 1',
                [$id]
            );
            if (!$row) {
                return '';
            }
            // Phiên đã quá hạn thì coi như không có.
            if ((int)$row['last_activity'] + $this->lifetime < time()) {
                return '';
            }
            return (string)$row['payload'];
        } catch (Exception $ex) {
            return '';
        }
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data)
    {
        try {
            // Lấy user_id từ chính dữ liệu phiên để trang quản trị hiển thị được.
            $userId = null;
            if (preg_match('/user_id\|i:(\d+);/', (string)$data, $m)) {
                $userId = (int)$m[1];
            }

            db_run(
                'INSERT INTO `sessions` (`id`, `user_id`, `ip`, `user_agent`, `payload`, `last_activity`)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `user_id`       = VALUES(`user_id`),
                    `ip`            = VALUES(`ip`),
                    `user_agent`    = VALUES(`user_agent`),
                    `payload`       = VALUES(`payload`),
                    `last_activity` = VALUES(`last_activity`)',
                [
                    $id,
                    $userId,
                    client_ip(),
                    mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    (string)$data,
                    time(),
                ]
            );
            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    #[\ReturnTypeWillChange]
    public function destroy($id)
    {
        try {
            db_run('DELETE FROM `sessions` WHERE `id` = ?', [$id]);
            return true;
        } catch (Exception $ex) {
            return false;
        }
    }

    /** Dọn phiên hết hạn. PHP gọi hàm này theo xác suất gc_probability/gc_divisor. */
    #[\ReturnTypeWillChange]
    public function gc($maxlifetime)
    {
        try {
            $cutoff = time() - max((int)$maxlifetime, $this->lifetime);
            return db_run('DELETE FROM `sessions` WHERE `last_activity` < ?', [$cutoff])->rowCount();
        } catch (Exception $ex) {
            return 0;
        }
    }
}

/**
 * Đăng ký bộ xử lý session dùng cơ sở dữ liệu.
 * Phải gọi TRƯỚC session_start(). Trả về true nếu đăng ký thành công.
 */
function session_use_database($lifetime = 0)
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    try {
        // Chưa cài đặt hoặc chưa có bảng sessions thì dùng cơ chế mặc định của PHP.
        if (!db_table_exists('sessions')) {
            return false;
        }
    } catch (Exception $ex) {
        return false;
    }

    $handler = new DbSessionHandler($lifetime);
    return @session_set_save_handler($handler, true);
}

/**
 * Danh sách phiên đang hoạt động (dùng cho trang quản trị).
 */
function session_list_active($limit = 100)
{
    $limit  = max(1, min(500, (int)$limit));
    $cutoff = time() - (int)ini_get('session.gc_maxlifetime');
    // Không lấy cột `payload` để truy vấn liệt kê luôn nhẹ.
    return db_all(
        'SELECT s.`id`, s.`user_id`, s.`ip`, s.`user_agent`, s.`last_activity`,
                OCTET_LENGTH(s.`payload`) AS payload_size,
                u.`username`, u.`avatar_emoji`, u.`role`
         FROM `sessions` s
         LEFT JOIN `users` u ON u.`id` = s.`user_id`
         WHERE s.`last_activity` >= ?
         ORDER BY s.`last_activity` DESC
         LIMIT ' . $limit,
        [$cutoff]
    );
}
