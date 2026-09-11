<?php
/**
 * Lưu trữ nội dung tệp trong cơ sở dữ liệu.
 *
 * Toàn bộ tệp người dùng tải lên và tệp AI trả về đều nằm trong bảng
 * `attachment_chunks` thay vì ghi ra đĩa — nhờ vậy hosting không tốn inode
 * (nhiều gói shared hosting giới hạn 100.000–300.000 inode).
 *
 * Nội dung được cắt thành nhiều khối nhỏ để:
 *   - không vượt `max_allowed_packet` của MySQL (nhiều hosting chỉ cho 4–16MB);
 *   - đọc/ghi tệp lớn mà bộ nhớ PHP chỉ giữ đúng một khối.
 */

/**
 * Kích thước mỗi khối (byte), tính theo `max_allowed_packet` của máy chủ.
 * Giữ trong khoảng 64KB – 1MB để an toàn với mọi cấu hình.
 */
function storage_chunk_size()
{
    static $size = null;
    if ($size !== null) {
        return $size;
    }
    $packet = 0;
    try {
        $packet = (int)db_value('SELECT @@max_allowed_packet', [], 0);
    } catch (Exception $ex) {
        $packet = 0;
    }
    if ($packet <= 0) {
        $packet = 4 * 1024 * 1024;   // giá trị mặc định phổ biến của MySQL
    }
    // Chừa chỗ cho phần bao của câu lệnh: chỉ dùng 40% dung lượng gói tin.
    $size = (int)floor($packet * 0.4);
    $size = max(64 * 1024, min(1024 * 1024, $size));
    return $size;
}

/**
 * Ghi nội dung nhị phân của một tệp vào cơ sở dữ liệu.
 *
 * @param int      $attachmentId  Id bản ghi trong bảng attachments
 * @param string   $binary        Nội dung tệp (đã nằm trong bộ nhớ)
 * @return int     Số byte đã ghi
 */
function storage_put($attachmentId, $binary)
{
    $attachmentId = (int)$attachmentId;
    $chunkSize    = storage_chunk_size();
    $total        = strlen($binary);

    db_run('DELETE FROM `attachment_chunks` WHERE `attachment_id` = ?', [$attachmentId]);

    $stmt = db()->prepare(
        'INSERT INTO `attachment_chunks` (`attachment_id`, `seq`, `content`) VALUES (?, ?, ?)'
    );
    $seq = 0;
    for ($offset = 0; $offset < $total; $offset += $chunkSize) {
        $stmt->execute([$attachmentId, $seq, substr($binary, $offset, $chunkSize)]);
        $seq++;
    }
    // Tệp rỗng vẫn cần một khối để phân biệt với "chưa ghi gì".
    if ($total === 0) {
        $stmt->execute([$attachmentId, 0, '']);
    }
    return $total;
}

/**
 * Ghi nội dung từ một tệp trên đĩa (thường là tệp tạm của PHP khi upload)
 * vào cơ sở dữ liệu, đọc theo từng khối để không ngốn bộ nhớ.
 *
 * @return int Số byte đã ghi
 */
function storage_put_from_file($attachmentId, $path)
{
    $attachmentId = (int)$attachmentId;
    $chunkSize    = storage_chunk_size();

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Không đọc được tệp tạm để lưu vào cơ sở dữ liệu.');
    }

    db_run('DELETE FROM `attachment_chunks` WHERE `attachment_id` = ?', [$attachmentId]);

    $stmt = db()->prepare(
        'INSERT INTO `attachment_chunks` (`attachment_id`, `seq`, `content`) VALUES (?, ?, ?)'
    );

    $seq   = 0;
    $total = 0;
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                throw new RuntimeException('Lỗi khi đọc tệp tạm.');
            }
            if ($chunk === '') {
                continue;
            }
            $stmt->execute([$attachmentId, $seq, $chunk]);
            $total += strlen($chunk);
            $seq++;
        }
    } finally {
        fclose($handle);
    }

    if ($total === 0) {
        $stmt->execute([$attachmentId, 0, '']);
    }
    return $total;
}

/**
 * Đọc toàn bộ nội dung tệp từ cơ sở dữ liệu.
 *
 * @param int      $attachmentId
 * @param int|null $maxBytes  Vượt quá mức này thì trả về null (tránh hết bộ nhớ)
 * @return string|null
 */
function storage_get($attachmentId, $maxBytes = null)
{
    $attachmentId = (int)$attachmentId;

    if ($maxBytes !== null) {
        $size = storage_size($attachmentId);
        if ($size > $maxBytes) {
            return null;
        }
    }

    $buffer = '';
    $seq    = 0;
    // Lấy từng khối một để bộ nhớ chỉ giữ đúng một khối tại mỗi thời điểm
    // (PDO của MySQL mặc định nạp sẵn toàn bộ tập kết quả).
    while (true) {
        $chunk = db_value(
            'SELECT `content` FROM `attachment_chunks` WHERE `attachment_id` = ? AND `seq` = ?',
            [$attachmentId, $seq], null
        );
        if ($chunk === null) {
            break;
        }
        $buffer .= $chunk;
        $seq++;
    }
    return $seq === 0 ? null : $buffer;
}

/**
 * Đẩy nội dung tệp trực tiếp ra trình duyệt theo từng khối.
 *
 * @return int Số byte đã gửi
 */
function storage_passthru($attachmentId)
{
    $attachmentId = (int)$attachmentId;
    $seq  = 0;
    $sent = 0;

    while (true) {
        $chunk = db_value(
            'SELECT `content` FROM `attachment_chunks` WHERE `attachment_id` = ? AND `seq` = ?',
            [$attachmentId, $seq], null
        );
        if ($chunk === null) {
            break;
        }
        echo $chunk;
        $sent += strlen($chunk);
        @ob_flush();
        @flush();
        if (connection_aborted()) {
            break;
        }
        $seq++;
    }
    return $sent;
}

/** Tổng dung lượng (byte) của một tệp đang lưu trong cơ sở dữ liệu. */
function storage_size($attachmentId)
{
    return (int)db_value(
        'SELECT COALESCE(SUM(OCTET_LENGTH(`content`)), 0) FROM `attachment_chunks` WHERE `attachment_id` = ?',
        [(int)$attachmentId], 0
    );
}

/** Bản ghi đã có nội dung trong cơ sở dữ liệu chưa? */
function storage_exists($attachmentId)
{
    return (int)db_value(
        'SELECT COUNT(*) FROM `attachment_chunks` WHERE `attachment_id` = ? AND `seq` = 0',
        [(int)$attachmentId], 0
    ) > 0;
}

/** Xoá nội dung của một tệp (khoá ngoại đã tự xoá khi xoá bản ghi attachments). */
function storage_delete($attachmentId)
{
    db_run('DELETE FROM `attachment_chunks` WHERE `attachment_id` = ?', [(int)$attachmentId]);
}

/** Tổng dung lượng tệp toàn hệ thống, dùng cho trang quản trị. */
function storage_total_bytes()
{
    return (int)db_value(
        'SELECT COALESCE(SUM(OCTET_LENGTH(`content`)), 0) FROM `attachment_chunks`', [], 0
    );
}

/**
 * Dọn các khối "mồ côi" — nội dung còn sót mà bản ghi attachments đã mất.
 * Bình thường khoá ngoại đã lo việc này; hàm dùng cho trường hợp bảng bị tạo
 * trước khi có khoá ngoại, hoặc dữ liệu nhập tay.
 *
 * @return int Số khối đã xoá
 */
function storage_purge_orphans()
{
    return db_run(
        'DELETE c FROM `attachment_chunks` c
         LEFT JOIN `attachments` a ON a.`id` = c.`attachment_id`
         WHERE a.`id` IS NULL'
    )->rowCount();
}
