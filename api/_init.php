<?php
/**
 * Khởi động chung cho mọi endpoint API.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** Bảo đảm request dùng đúng phương thức. */
function api_require_method($method)
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        json_error('Phương thức không được hỗ trợ.', 405);
    }
}

/** Lấy tham số từ query string hoặc body JSON/POST. */
function api_param($key, $default = null)
{
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    $body = json_input();
    return $body[$key] ?? $default;
}

/** Lấy cuộc trò chuyện thuộc về người dùng hiện tại. */
function api_conversation($id, $user, $allowAdmin = false)
{
    $row = db_one('SELECT * FROM `conversations` WHERE `id` = ? LIMIT 1', [(int)$id]);
    if (!$row) {
        json_error('Không tìm thấy cuộc trò chuyện.', 404);
    }
    $isOwner = (int)$row['user_id'] === (int)$user['id'];
    if (!$isOwner && !($allowAdmin && $user['role'] === 'admin')) {
        json_error('Bạn không có quyền truy cập cuộc trò chuyện này.', 403);
    }
    return $row;
}

/** Chuẩn hoá một tin nhắn để trả về cho giao diện. */
function api_format_message($row, $attachments = [])
{
    return [
        'id'         => (int)$row['id'],
        'role'       => $row['role'],
        'content'    => (string)$row['content'],
        'reasoning'  => (string)$row['reasoning'],
        'model'      => filter_model_name($row['model']),
        'status'     => $row['status'],
        'error'      => $row['error_message'],
        'tokens'     => [
            'prompt'     => (int)$row['prompt_tokens'],
            'completion' => (int)$row['completion_tokens'],
        ],
        'durationMs' => (int)$row['duration_ms'],
        'createdAt'  => $row['created_at'],
        'timeText'   => fmt_datetime($row['created_at'], 'H:i · d/m/Y'),
        'files'      => array_map('api_format_attachment', $attachments),
    ];
}

/** Chuẩn hoá một tệp đính kèm. */
function api_format_attachment($att)
{
    $ext = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
    return [
        'id'        => (int)$att['id'],
        'name'      => $att['original_name'],
        'mime'      => $att['mime'],
        'size'      => (int)$att['size'],
        'sizeText'  => fmt_bytes($att['size']),
        'kind'      => $att['kind'],
        'icon'      => file_icon($att['kind'], $ext),
        'direction' => $att['direction'],
        'url'       => 'api/download.php?id=' . (int)$att['id'],
    ];
}

/** Nạp tệp đính kèm theo danh sách message id. */
function api_attachments_for_messages(array $messageIds)
{
    if (!$messageIds) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($messageIds), '?'));
    $rows = db_all('SELECT * FROM `attachments` WHERE `message_id` IN (' . $in . ') ORDER BY `id`', $messageIds);
    $map  = [];
    foreach ($rows as $row) {
        $map[(int)$row['message_id']][] = $row;
    }
    return $map;
}
