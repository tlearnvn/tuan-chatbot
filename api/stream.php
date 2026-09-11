<?php
/**
 * Gửi tin nhắn và nhận phản hồi AI theo luồng (Server-Sent Events).
 *
 * Các sự kiện gửi về trình duyệt:
 *   start     {messageId, conversationId, title, endpoint}
 *   delta     {text}
 *   reasoning {text}
 *   file      {id, name, url, mime, size, kind}
 *   usage     {prompt, completion}
 *   done      {messageId, content, durationMs, tokens}
 *   error     {message}
 */
require_once __DIR__ . '/_init.php';

$user = require_login(true);
api_require_method('POST');
csrf_require(true);

if (setting_bool('maintenance') && $user['role'] !== 'admin') {
    json_error(setting('maintenance_note'), 503);
}

$input        = json_input();
$content      = trim((string)($input['message'] ?? ''));
$conversation = (int)($input['conversation_id'] ?? 0);
$endpointId   = (int)($input['endpoint_id'] ?? 0);
$attachIds    = array_slice(array_map('intval', (array)($input['attachments'] ?? [])), 0, 20);
$regenerate   = !empty($input['regenerate']);

if ($content === '' && !$attachIds && !$regenerate) {
    json_error('Vui lòng nhập nội dung tin nhắn.');
}

$endpoint = endpoint_pick($endpointId ?: null);
if (!$endpoint) {
    json_error('Hệ thống chưa được cấu hình endpoint AI. Vui lòng liên hệ quản trị viên.', 503);
}

// --- Giới hạn số tin nhắn mỗi ngày (nếu quản trị viên có đặt) ----------------
if ((int)$user['daily_limit'] > 0) {
    $todayCount = (int)db_value(
        "SELECT COUNT(*) FROM `messages` WHERE `user_id` = ? AND `role` = 'user' AND `created_at` >= ?",
        [$user['id'], date('Y-m-d 00:00:00')], 0
    );
    if ($todayCount >= (int)$user['daily_limit']) {
        json_error('Bạn đã dùng hết ' . (int)$user['daily_limit'] . ' lượt hỏi trong ngày hôm nay. Hẹn gặp lại vào ngày mai nhé!', 429);
    }
}

// --- Chuẩn bị cuộc trò chuyện ------------------------------------------------
$newConversation = false;
if ($conversation > 0) {
    $conv = db_one('SELECT * FROM `conversations` WHERE `id` = ? AND `user_id` = ? AND `is_deleted` = 0 LIMIT 1',
        [$conversation, $user['id']]);
    if (!$conv) {
        json_error('Không tìm thấy cuộc trò chuyện.', 404);
    }
} else {
    $conversation = db_insert('conversations', [
        'user_id'     => (int)$user['id'],
        'endpoint_id' => (int)$endpoint['id'],
        'title'       => $content !== '' ? str_limit($content, 60, '…') : 'Cuộc trò chuyện mới',
        'created_at'  => now_vn(),
        'updated_at'  => now_vn(),
    ]);
    $conv = db_one('SELECT * FROM `conversations` WHERE `id` = ?', [$conversation]);
    $newConversation = true;
}

// Đặt tên cho cuộc trò chuyện ngay khi có tin nhắn đầu tiên.
if (!$newConversation && $conv['title'] === 'Cuộc trò chuyện mới' && $content !== '') {
    $conv['title'] = str_limit($content, 60, '…');
    db_update('conversations', ['title' => $conv['title']], '`id` = ?', [$conversation]);
}

// --- Khi tạo lại câu trả lời: xoá câu trả lời cũ ở cuối ----------------------
if ($regenerate) {
    $last = db_one("SELECT * FROM `messages` WHERE `conversation_id` = ? ORDER BY `id` DESC LIMIT 1", [$conversation]);
    if ($last && $last['role'] === 'assistant') {
        db_run('DELETE FROM `messages` WHERE `id` = ?', [$last['id']]);
    }
}

// --- Ghi tin nhắn của người dùng --------------------------------------------
$userMessageId = 0;
if (!$regenerate) {
    $userMessageId = db_insert('messages', [
        'conversation_id' => $conversation,
        'user_id'         => (int)$user['id'],
        'role'            => 'user',
        'content'         => $content,
        'endpoint_id'     => (int)$endpoint['id'],
        'status'          => 'ok',
        'created_at'      => now_vn(),
    ]);

    // Gắn các tệp đã tải lên vào tin nhắn này.
    if ($attachIds) {
        $in = implode(',', array_fill(0, count($attachIds), '?'));
        db_run(
            'UPDATE `attachments` SET `message_id` = ?, `conversation_id` = ?
             WHERE `id` IN (' . $in . ') AND `user_id` = ? AND `message_id` IS NULL',
            array_merge([$userMessageId, $conversation], $attachIds, [$user['id']])
        );
    }
}

// --- Dựng lịch sử hội thoại gửi cho AI --------------------------------------
$historyLimit = (int)$endpoint['history_limit'] ?: setting_int('history_limit', 20);
$historyLimit = max(2, min(200, $historyLimit));
// LIMIT không dùng tham số ràng buộc được với prepared statement gốc của MySQL,
// nên giá trị đã được ép kiểu số nguyên và giới hạn khoảng ở trên.
$rows = db_all(
    "SELECT * FROM `messages` WHERE `conversation_id` = ? AND `status` <> 'error'
     ORDER BY `id` DESC LIMIT " . $historyLimit,
    [$conversation]
);
$rows = array_reverse($rows);

$msgIds = array_map(function ($r) { return (int)$r['id']; }, $rows);
$attMap = api_attachments_for_messages($msgIds);

$history = [];
foreach ($rows as $row) {
    $atts = array_filter($attMap[(int)$row['id']] ?? [], function ($a) {
        return $a['direction'] === 'in';
    });
    $history[] = [
        'role'        => $row['role'] === 'assistant' ? 'assistant' : 'user',
        'content'     => (string)$row['content'],
        'attachments' => array_values($atts),
    ];
}
if (!$history) {
    $history[] = ['role' => 'user', 'content' => $content ?: '(trống)', 'attachments' => []];
}

$systemPrompt = ai_build_system_prompt($endpoint, $user);

// =============================================================================
//  Bắt đầu phát luồng
// =============================================================================
@set_time_limit(max(60, (int)$endpoint['timeout']) + 60);
ignore_user_abort(true);          // vẫn lưu được nội dung dở nếu người dùng thoát

// Không cần ghi gì vào phiên nữa. Đóng sớm để trong lúc phát luồng (có thể tới
// vài phút) các request khác của cùng người dùng không bị chờ khoá phiên.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // tắt đệm của Nginx

/** Gửi một sự kiện SSE về trình duyệt. */
function sse($event, array $data)
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    @flush();
}

// Đệm 2KB giúp một số cấu hình proxy/FastCGI đẩy dữ liệu ra ngay.
echo ':' . str_repeat(' ', 2048) . "\n\n";
@ob_flush();
@flush();

sse('start', [
    'conversationId' => $conversation,
    'userMessageId'  => $userMessageId,
    'title'          => $conv['title'],
    'isNew'          => $newConversation,
    'endpoint'       => ['id' => (int)$endpoint['id'], 'name' => $endpoint['name'],
                         'model' => filter_model_name($endpoint['model'])],
]);

$startedAt   = microtime(true);
$fullText    = '';
$fullReason  = '';
$usage       = ['prompt' => 0, 'completion' => 0];
$modelUsed   = $endpoint['model'];
$outFiles    = [];
$aborted     = false;
$lastFlush   = microtime(true);

$result = ai_stream($endpoint, $history, $systemPrompt, [
    'onDelta' => function ($text) use (&$fullText, &$lastFlush) {
        $fullText .= $text;
        sse('delta', ['text' => $text]);
        $lastFlush = microtime(true);
    },
    'onReasoning' => function ($text) use (&$fullReason) {
        $fullReason .= $text;
        sse('reasoning', ['text' => $text]);
    },
    'onUsage' => function ($u) use (&$usage) {
        if (!empty($u['prompt']))     $usage['prompt'] = (int)$u['prompt'];
        if (!empty($u['completion'])) $usage['completion'] = (int)$u['completion'];
    },
    'onMeta' => function ($meta) use (&$modelUsed) {
        if (!empty($meta['model'])) {
            $modelUsed = $meta['model'];
        }
    },
    'onFile' => function ($file) use (&$outFiles, &$fullText, $user, $conversation) {
        try {
            $binary = base64_decode($file['b64'], true);
            if ($binary === false || $binary === '') {
                return;
            }
            $stored = store_output_file($user['id'], $conversation, $binary, $file['mime'], $file['name'] ?? '');
            $outFiles[] = $stored;
            // Chèn ngay vào nội dung để người dùng thấy kết quả trong luồng.
            $snippet = $stored['kind'] === 'image'
                ? "\n\n![" . $stored['name'] . "](" . $stored['url'] . ")\n\n"
                : "\n\n[📎 " . $stored['name'] . "](" . $stored['url'] . ")\n\n";
            $fullText .= $snippet;
            sse('file', $stored);
            sse('delta', ['text' => $snippet]);
        } catch (Exception $ex) {
            // Bỏ qua tệp lỗi, không làm gián đoạn luồng trả lời.
        }
    },
    'shouldStop' => function () use (&$aborted) {
        if (connection_aborted()) {
            $aborted = true;
            return true;
        }
        return false;
    },
]);

// --- Hậu xử lý: chuyển data URI trong nội dung thành tệp tải xuống được ------
$fullText = extract_inline_files($fullText, $user['id'], $conversation, $outFiles);

$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
$status     = 'ok';
$errorText  = null;

if (!$result['ok']) {
    $status    = 'error';
    $errorText = $result['error'];
} elseif ($aborted) {
    $status = 'aborted';
} elseif (trim($fullText) === '') {
    $status    = 'error';
    $errorText = 'Mô hình không trả về nội dung nào. Hãy thử lại hoặc kiểm tra cấu hình endpoint.';
}

// --- Lưu câu trả lời ---------------------------------------------------------
$assistantId = db_insert('messages', [
    'conversation_id'   => $conversation,
    'user_id'           => (int)$user['id'],
    'role'              => 'assistant',
    'content'           => $fullText,
    'reasoning'         => $fullReason !== '' ? $fullReason : null,
    'model'             => mb_substr((string)$modelUsed, 0, 190),
    'endpoint_id'       => (int)$endpoint['id'],
    'prompt_tokens'     => $usage['prompt'],
    'completion_tokens' => $usage['completion'],
    'duration_ms'       => $durationMs,
    'status'            => $status,
    'error_message'     => $errorText,
    'created_at'        => now_vn(),
]);

// Gắn tệp AI trả về vào tin nhắn.
if ($outFiles) {
    $ids = array_column($outFiles, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    db_run('UPDATE `attachments` SET `message_id` = ? WHERE `id` IN (' . $in . ')',
        array_merge([$assistantId], $ids));
}

// Cập nhật thống kê cuộc trò chuyện.
db_run(
    'UPDATE `conversations` SET `updated_at` = ?, `endpoint_id` = ?,
        `msg_count` = (SELECT COUNT(*) FROM `messages` WHERE `conversation_id` = ?)
     WHERE `id` = ?',
    [now_vn(), (int)$endpoint['id'], $conversation, $conversation]
);

if ($status === 'error') {
    sse('error', ['message' => $errorText, 'messageId' => $assistantId]);
} else {
    sse('done', [
        'messageId'  => $assistantId,
        'content'    => $fullText,
        'reasoning'  => $fullReason,
        'model'      => filter_model_name($modelUsed),
        'status'     => $status,
        'durationMs' => $durationMs,
        'tokens'     => $usage,
        'files'      => $outFiles,
        'timeText'   => fmt_datetime(now_vn(), 'H:i · d/m/Y'),
    ]);
}

echo "event: end\ndata: {}\n\n";
@ob_flush();
@flush();
exit;

/**
 * Tìm các data URI nhúng trong nội dung trả về, lưu thành tệp và thay bằng liên kết.
 * Nhờ vậy cơ sở dữ liệu không phình to và người dùng tải được tệp về máy.
 */
function extract_inline_files($text, $userId, $conversationId, array &$outFiles)
{
    if (strpos($text, 'data:') === false) {
        return $text;
    }
    // Chỉ khớp phần thân base64 hợp lệ (không ăn lan sang văn bản phía sau).
    return preg_replace_callback(
        '#data:([a-zA-Z0-9.+/-]+);base64,([A-Za-z0-9+/]{200,}={0,2})#',
        function ($m) use ($userId, $conversationId, &$outFiles) {
            $mime   = $m[1];
            $b64    = $m[2];
            $binary = base64_decode($b64, true);
            if ($binary === false || strlen($binary) < 64) {
                return $m[0];
            }
            try {
                $stored = store_output_file($userId, $conversationId, $binary, $mime);
                $outFiles[] = $stored;
                return $stored['url'];
            } catch (Exception $ex) {
                return $m[0];
            }
        },
        $text
    );
}
