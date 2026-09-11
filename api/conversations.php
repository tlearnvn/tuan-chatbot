<?php
/**
 * API quản lý cuộc trò chuyện: danh sách, xem, đổi tên, ghim, xoá.
 */
require_once __DIR__ . '/_init.php';

$user   = require_login(true);
$action = api_param('action', 'list');

// Mọi thao tác thay đổi dữ liệu đều phải có CSRF hợp lệ.
$writeActions = ['rename', 'delete', 'pin', 'clear', 'create'];
if (in_array($action, $writeActions, true)) {
    api_require_method('POST');
    csrf_require(true);
}

switch ($action) {

    // ---- Danh sách cuộc trò chuyện -----------------------------------------
    case 'list':
        $q      = trim((string)api_param('q', ''));
        $params = [$user['id']];
        $sql    = 'SELECT `id`, `title`, `is_pinned`, `msg_count`, `created_at`, `updated_at`
                   FROM `conversations` WHERE `user_id` = ? AND `is_deleted` = 0';
        if ($q !== '') {
            $sql .= ' AND (`title` LIKE ? OR `id` IN (
                        SELECT DISTINCT `conversation_id` FROM `messages`
                        WHERE `user_id` = ? AND `content` LIKE ?))';
            $like     = '%' . $q . '%';
            $params[] = $like;
            $params[] = $user['id'];
            $params[] = $like;
        }
        $sql .= ' ORDER BY `is_pinned` DESC, `updated_at` DESC LIMIT 300';

        $rows = db_all($sql, $params);
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'        => (int)$row['id'],
                'title'     => $row['title'],
                'pinned'    => (int)$row['is_pinned'] === 1,
                'count'     => (int)$row['msg_count'],
                'updatedAt' => $row['updated_at'],
                'timeText'  => fmt_relative($row['updated_at']),
                'group'     => conv_group_label($row['updated_at']),
            ];
        }
        json_out(['ok' => true, 'conversations' => $out]);
        break;

    // ---- Xem toàn bộ tin nhắn của một cuộc trò chuyện -----------------------
    case 'get':
        $conv = api_conversation((int)api_param('id', 0), $user);
        // Bỏ qua câu trả lời đã bị thay thế bằng nút "tạo lại" — chỉ quản trị
        // viên mới xem được chúng trong Quản trị → Lịch sử chat.
        $rows = db_all("SELECT * FROM `messages` WHERE `conversation_id` = ?
                          AND `status` <> 'replaced' ORDER BY `id` ASC", [$conv['id']]);

        $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
        $attMap = api_attachments_for_messages($ids);

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = api_format_message($row, $attMap[(int)$row['id']] ?? []);
        }
        json_out([
            'ok'           => true,
            'conversation' => [
                'id'         => (int)$conv['id'],
                'title'      => $conv['title'],
                'pinned'     => (int)$conv['is_pinned'] === 1,
                'endpointId' => $conv['endpoint_id'] ? (int)$conv['endpoint_id'] : null,
                'createdAt'  => $conv['created_at'],
                'timeText'   => fmt_datetime($conv['created_at'], 'H:i · d/m/Y'),
            ],
            'messages'     => $messages,
        ]);
        break;

    // ---- Tạo cuộc trò chuyện rỗng ------------------------------------------
    case 'create':
        $endpointId = (int)api_param('endpoint_id', 0);
        $endpoint   = endpoint_pick($endpointId ?: null);
        $id = db_insert('conversations', [
            'user_id'     => (int)$user['id'],
            'endpoint_id' => $endpoint ? (int)$endpoint['id'] : null,
            'title'       => 'Cuộc trò chuyện mới',
            'created_at'  => now_vn(),
            'updated_at'  => now_vn(),
        ]);
        json_out(['ok' => true, 'id' => $id]);
        break;

    // ---- Đổi tên ------------------------------------------------------------
    case 'rename':
        $conv  = api_conversation((int)api_param('id', 0), $user);
        $title = trim((string)api_param('title', ''));
        if ($title === '') {
            json_error('Tên cuộc trò chuyện không được để trống.');
        }
        $title = str_limit($title, 200, '');
        db_update('conversations', ['title' => $title, 'updated_at' => now_vn()],
            '`id` = ?', [$conv['id']]);
        json_out(['ok' => true, 'title' => $title]);
        break;

    // ---- Ghim / bỏ ghim ------------------------------------------------------
    case 'pin':
        $conv    = api_conversation((int)api_param('id', 0), $user);
        $pinned  = (int)$conv['is_pinned'] === 1 ? 0 : 1;
        db_update('conversations', ['is_pinned' => $pinned], '`id` = ?', [$conv['id']]);
        json_out(['ok' => true, 'pinned' => $pinned === 1]);
        break;

    // ---- Xoá ------------------------------------------------------------------
    case 'delete':
        // Chặn ở máy chủ, không chỉ ẩn nút: người dùng vẫn gọi thẳng API được.
        if (!can_delete_history()) {
            json_error(delete_locked_message(), 403);
        }
        $conv = api_conversation((int)api_param('id', 0), $user);
        foreach (db_all('SELECT * FROM `attachments` WHERE `conversation_id` = ?', [$conv['id']]) as $att) {
            delete_attachment_file($att);
        }
        db_run('DELETE FROM `attachments` WHERE `conversation_id` = ?', [$conv['id']]);
        db_run('DELETE FROM `conversations` WHERE `id` = ?', [$conv['id']]);
        json_out(['ok' => true]);
        break;

    default:
        json_error('Thao tác không hợp lệ.', 400);
}

/** Nhãn nhóm theo thời gian cho danh sách bên trái. */
function conv_group_label($datetime)
{
    $then  = strtotime($datetime);
    $today = strtotime('today');

    if ($then >= $today)                    return 'Hôm nay';
    if ($then >= strtotime('-1 day', $today)) return 'Hôm qua';
    if ($then >= strtotime('-7 day', $today)) return '7 ngày qua';
    if ($then >= strtotime('-30 day', $today)) return '30 ngày qua';
    return 'Cũ hơn';
}
