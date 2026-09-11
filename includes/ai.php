<?php
/**
 * Bộ chuyển đổi API AI.
 *
 * Hỗ trợ 4 họ API phổ biến, khai báo bằng cột `api_type` của bảng `endpoints`:
 *   - openai     : OpenAI, Azure-style, OpenRouter, Groq, DeepSeek, Together, LM Studio, vLLM…
 *   - anthropic  : Anthropic Messages API
 *   - gemini     : Google Generative Language API
 *   - ollama     : Ollama /api/chat (NDJSON)
 *
 * Mọi họ API đều được quy về cùng một giao diện:
 *   ai_stream($endpoint, $messages, $callbacks)
 * với $callbacks gồm: onDelta, onReasoning, onFile, onUsage, onMeta.
 */

/** Danh sách loại API kèm nhãn tiếng Việt. */
function ai_types()
{
    return [
        'openai'    => 'OpenAI & tương thích (OpenRouter, Groq, DeepSeek, vLLM, LM Studio…)',
        'anthropic' => 'Anthropic — Claude Messages API',
        'gemini'    => 'Google Gemini — Generative Language API',
        'ollama'    => 'Ollama — máy chủ nội bộ (/api/chat)',
    ];
}

/** Gợi ý URL gốc cho từng loại API. */
function ai_default_base_url($type)
{
    $map = [
        'openai'    => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com/v1',
        'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
        'ollama'    => 'http://localhost:11434',
    ];
    return $map[$type] ?? '';
}

// ---------------------------------------------------------------------------
// Truy vấn endpoint
// ---------------------------------------------------------------------------

/** Lấy một endpoint theo id (kèm giải mã API key). */
function endpoint_get($id)
{
    $row = db_one('SELECT * FROM `endpoints` WHERE `id` = ? LIMIT 1', [(int)$id]);
    if (!$row) {
        return null;
    }
    $row['api_key_plain'] = crypto_decrypt($row['api_key']);
    return $row;
}

/** Endpoint mặc định đang bật. */
function endpoint_default()
{
    $row = db_one('SELECT * FROM `endpoints` WHERE `is_active` = 1 AND `is_default` = 1 ORDER BY `sort_order`, `id` LIMIT 1');
    if (!$row) {
        $row = db_one('SELECT * FROM `endpoints` WHERE `is_active` = 1 ORDER BY `sort_order`, `id` LIMIT 1');
    }
    if (!$row) {
        return null;
    }
    $row['api_key_plain'] = crypto_decrypt($row['api_key']);
    return $row;
}

/** Danh sách endpoint đang bật (dùng cho hộp chọn mô hình). */
function endpoint_list_active()
{
    return db_all('SELECT `id`, `name`, `description`, `model`, `api_type`, `is_default`,
                          `supports_vision`, `supports_files`
                   FROM `endpoints` WHERE `is_active` = 1 ORDER BY `is_default` DESC, `sort_order`, `id`');
}

/** Chọn endpoint theo yêu cầu người dùng, có kiểm tra hợp lệ. */
function endpoint_pick($requestedId = null)
{
    if ($requestedId) {
        $ep = endpoint_get($requestedId);
        if ($ep && (int)$ep['is_active'] === 1) {
            return $ep;
        }
    }
    return endpoint_default();
}

// ---------------------------------------------------------------------------
// Dựng system prompt (gồm cả tài liệu đi kèm)
// ---------------------------------------------------------------------------

/** Ghép system prompt của endpoint + tài liệu đi kèm + ngữ cảnh phiên. */
function ai_build_system_prompt($endpoint, $user = null)
{
    $parts = [];

    $base = trim((string)($endpoint['system_prompt'] ?? ''));
    if ($base !== '') {
        $parts[] = $base;
    }

    // Tài liệu đi kèm: dùng chung (endpoint_id IS NULL) + riêng của endpoint.
    $docs = db_all(
        'SELECT `title`, `file_name`, `content` FROM `documents`
         WHERE `is_active` = 1 AND (`endpoint_id` IS NULL OR `endpoint_id` = ?)
         ORDER BY `sort_order`, `id`',
        [(int)$endpoint['id']]
    );
    if ($docs) {
        $buf = "# TÀI LIỆU THAM KHẢO ĐI KÈM\n"
             . "Hãy ưu tiên sử dụng thông tin trong các tài liệu dưới đây khi trả lời.\n";
        foreach ($docs as $i => $doc) {
            $content = trim((string)$doc['content']);
            if ($content === '') {
                continue;
            }
            $buf .= "\n--- TÀI LIỆU " . ($i + 1) . ': ' . $doc['title'] . " ---\n" . $content . "\n";
        }
        $parts[] = $buf;
    }

    // Ngữ cảnh thời gian (giờ Việt Nam) để mô hình trả lời đúng bối cảnh.
    $now = new DateTime('now', new DateTimeZone(APP_TZ));
    $thu = ['Sun' => 'Chủ Nhật', 'Mon' => 'Thứ Hai', 'Tue' => 'Thứ Ba', 'Wed' => 'Thứ Tư',
            'Thu' => 'Thứ Năm', 'Fri' => 'Thứ Sáu', 'Sat' => 'Thứ Bảy'];
    $ctx = "# BỐI CẢNH\n"
         . '- Thời điểm hiện tại (giờ Việt Nam, UTC+7): ' . $thu[$now->format('D')] . ', '
         . $now->format('H:i \n\g\à\y d/m/Y') . "\n"
         . '- Website: ' . site_name() . "\n";
    if ($user) {
        $ctx .= '- Người dùng đang trò chuyện: ' . ($user['full_name'] ?: $user['username']) . "\n";
    }
    $ctx .= "- Trả lời bằng tiếng Việt, trừ khi người dùng yêu cầu ngôn ngữ khác.\n"
          . "- Được phép dùng Markdown, bảng, khối mã và công thức LaTeX (\$...\$ cho công thức trong dòng, \$\$...\$\$ cho công thức riêng dòng).\n";
    $parts[] = $ctx;

    return trim(implode("\n\n", $parts));
}

// ---------------------------------------------------------------------------
// Dựng nội dung tin nhắn theo từng họ API
// ---------------------------------------------------------------------------

/** Dung lượng tối đa cho phép nhúng trực tiếp (base64) vào request. */
function ai_max_inline_bytes()
{
    return 18 * 1024 * 1024;
}

/**
 * Chuyển một tin nhắn nội bộ thành cấu trúc content của nhà cung cấp.
 *
 * @param array $message ['role' => ..., 'content' => ..., 'attachments' => [rows]]
 */
function ai_message_parts($message, $endpoint)
{
    $type       = $endpoint['api_type'];
    $canVision  = (int)$endpoint['supports_vision'] === 1;
    $canFiles   = (int)$endpoint['supports_files'] === 1;
    $text       = (string)($message['content'] ?? '');
    $atts       = $message['attachments'] ?? [];
    $binaries   = [];   // phần nhị phân gắn kèm
    $textBlocks = [];

    foreach ($atts as $att) {
        $ext  = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
        $kind = $att['kind'];
        $size = (int)$att['size'];

        // Chỉ nhúng được khi kích thước còn trong ngưỡng an toàn về bộ nhớ.
        $inlineable = $size > 0 && $size <= ai_max_inline_bytes();

        // Nội dung đọc từ cơ sở dữ liệu, chỉ nạp khi thật sự cần nhúng.
        $wantsBinary = $inlineable && (
            ($kind === 'image' && $canVision)
            || ($kind === 'pdf' && $canFiles && in_array($type, ['openai', 'anthropic', 'gemini'], true))
            || (($kind === 'audio' || $kind === 'video') && $canFiles && $type === 'gemini')
        );
        $raw = $wantsBinary ? attachment_binary($att, ai_max_inline_bytes()) : null;

        if ($raw !== null && $raw !== '') {
            if ($kind === 'image') {
                $binaries[] = [
                    'kind' => 'image',
                    'mime' => $att['mime'] ?: mime_from_ext($ext),
                    'data' => base64_encode($raw),
                    'name' => $att['original_name'],
                ];
            } elseif ($kind === 'pdf') {
                $binaries[] = [
                    'kind' => 'document',
                    'mime' => 'application/pdf',
                    'data' => base64_encode($raw),
                    'name' => $att['original_name'],
                ];
            } else {
                $binaries[] = [
                    'kind' => 'media',
                    'mime' => $att['mime'] ?: mime_from_ext($ext),
                    'data' => base64_encode($raw),
                    'name' => $att['original_name'],
                ];
            }
            unset($raw);   // giải phóng bộ nhớ ngay, tránh giữ hai bản của tệp lớn
            continue;
        }

        // Mọi trường hợp còn lại: đưa nội dung văn bản đã trích xuất vào prompt.
        $extracted = (string)$att['extracted_text'];
        if ($extracted !== '') {
            $textBlocks[] = "--- NỘI DUNG TỆP \"" . $att['original_name'] . "\" (" . fmt_bytes($size) . ") ---\n"
                          . $extracted . "\n--- HẾT TỆP \"" . $att['original_name'] . "\" ---";
        } else {
            $textBlocks[] = '[Người dùng đã đính kèm tệp "' . $att['original_name'] . '" ('
                          . fmt_bytes($size) . ', ' . ($att['mime'] ?: 'không rõ định dạng')
                          . '). Hệ thống không đọc được nội dung tệp này.]';
        }
    }

    if ($textBlocks) {
        $text = trim($text . "\n\n" . implode("\n\n", $textBlocks));
    }
    if ($text === '' && !$binaries) {
        $text = '(trống)';
    }

    return ['text' => $text, 'binaries' => $binaries];
}

/** Dựng mảng messages cho OpenAI & tương thích. */
function ai_build_openai_messages($history, $endpoint, $system)
{
    $out = [];
    if ($system !== '') {
        $out[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($history as $msg) {
        $parts = ai_message_parts($msg, $endpoint);
        if ($msg['role'] === 'assistant' || !$parts['binaries']) {
            $out[] = ['role' => $msg['role'], 'content' => $parts['text']];
            continue;
        }
        $content = [];
        if ($parts['text'] !== '') {
            $content[] = ['type' => 'text', 'text' => $parts['text']];
        }
        foreach ($parts['binaries'] as $bin) {
            if ($bin['kind'] === 'image') {
                $content[] = [
                    'type'      => 'image_url',
                    'image_url' => ['url' => 'data:' . $bin['mime'] . ';base64,' . $bin['data']],
                ];
            } else {
                $content[] = [
                    'type' => 'file',
                    'file' => [
                        'filename'  => $bin['name'],
                        'file_data' => 'data:' . $bin['mime'] . ';base64,' . $bin['data'],
                    ],
                ];
            }
        }
        $out[] = ['role' => $msg['role'], 'content' => $content];
    }
    return $out;
}

/** Dựng mảng messages cho Anthropic. */
function ai_build_anthropic_messages($history, $endpoint)
{
    $out = [];
    foreach ($history as $msg) {
        $parts   = ai_message_parts($msg, $endpoint);
        $content = [];
        foreach ($parts['binaries'] as $bin) {
            if ($bin['kind'] === 'image') {
                $content[] = ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $bin['mime'], 'data' => $bin['data'],
                ]];
            } elseif ($bin['kind'] === 'document') {
                $content[] = ['type' => 'document', 'source' => [
                    'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $bin['data'],
                ]];
            }
        }
        if ($parts['text'] !== '') {
            $content[] = ['type' => 'text', 'text' => $parts['text']];
        }
        if (!$content) {
            $content[] = ['type' => 'text', 'text' => '(trống)'];
        }
        $out[] = ['role' => $msg['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $content];
    }
    return $out;
}

/** Dựng mảng contents cho Gemini. */
function ai_build_gemini_contents($history, $endpoint)
{
    $out = [];
    foreach ($history as $msg) {
        $parts    = ai_message_parts($msg, $endpoint);
        $gparts   = [];
        if ($parts['text'] !== '') {
            $gparts[] = ['text' => $parts['text']];
        }
        foreach ($parts['binaries'] as $bin) {
            $gparts[] = ['inline_data' => ['mime_type' => $bin['mime'], 'data' => $bin['data']]];
        }
        if (!$gparts) {
            $gparts[] = ['text' => '(trống)'];
        }
        $out[] = ['role' => $msg['role'] === 'assistant' ? 'model' : 'user', 'parts' => $gparts];
    }
    return $out;
}

/** Dựng mảng messages cho Ollama. */
function ai_build_ollama_messages($history, $endpoint, $system)
{
    $out = [];
    if ($system !== '') {
        $out[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($history as $msg) {
        $parts = ai_message_parts($msg, $endpoint);
        $item  = ['role' => $msg['role'], 'content' => $parts['text']];
        $images = [];
        foreach ($parts['binaries'] as $bin) {
            if ($bin['kind'] === 'image') {
                $images[] = $bin['data'];
            }
        }
        if ($images) {
            $item['images'] = $images;
        }
        $out[] = $item;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Dựng request
// ---------------------------------------------------------------------------

/** Ghép URL đích từ base_url của endpoint. */
function ai_resolve_url($endpoint, $stream = true)
{
    $base = rtrim(trim((string)$endpoint['base_url']), '/');
    $type = $endpoint['api_type'];

    if ($base === '') {
        $base = rtrim(ai_default_base_url($type), '/');
    }

    switch ($type) {
        case 'anthropic':
            if (preg_match('#/messages$#', $base)) return $base;
            if (preg_match('#/v\d[^/]*$#', $base)) return $base . '/messages';
            return $base . '/v1/messages';

        case 'gemini':
            if (strpos($base, ':generateContent') !== false || strpos($base, ':streamGenerateContent') !== false) {
                $url = $base;
            } else {
                if (!preg_match('#/v\d[^/]*#', $base)) {
                    $base .= '/v1beta';
                }
                $model = trim((string)$endpoint['model']) ?: 'gemini-2.0-flash';
                $model = preg_replace('#^models/#', '', $model);
                $url = $base . '/models/' . rawurlencode($model)
                     . ':' . ($stream ? 'streamGenerateContent' : 'generateContent');
            }
            if ($stream && strpos($url, 'alt=sse') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'alt=sse';
            }
            return $url;

        case 'ollama':
            if (preg_match('#/api/chat$#', $base)) return $base;
            return $base . '/api/chat';

        case 'openai':
        default:
            if (preg_match('#/(chat/completions|completions|responses)$#', $base)) return $base;
            if (preg_match('#/v\d[^/]*$#', $base)) return $base . '/chat/completions';
            return $base . '/v1/chat/completions';
    }
}

/** Header HTTP cho request. */
function ai_build_headers($endpoint)
{
    $key     = (string)($endpoint['api_key_plain'] ?? '');
    $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];

    switch ($endpoint['api_type']) {
        case 'anthropic':
            if ($key !== '') {
                $headers[] = 'x-api-key: ' . $key;
            }
            $headers[] = 'anthropic-version: 2023-06-01';
            break;
        case 'gemini':
            if ($key !== '') {
                $headers[] = 'x-goog-api-key: ' . $key;
            }
            break;
        case 'ollama':
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            break;
        default:
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            break;
    }

    $extra = json_decode((string)$endpoint['extra_headers'], true);
    if (is_array($extra)) {
        foreach ($extra as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $headers[] = $name . ': ' . $value;
            }
        }
    }
    return $headers;
}

/** Dựng payload gửi đi. */
function ai_build_body($endpoint, $history, $system, $stream = true)
{
    $maxTokens = max(1, (int)$endpoint['max_tokens']);
    $temp      = (float)$endpoint['temperature'];
    $topP      = (float)$endpoint['top_p'];
    $model     = trim((string)$endpoint['model']);

    switch ($endpoint['api_type']) {
        case 'anthropic':
            $body = [
                'model'      => $model ?: 'claude-sonnet-4-20250514',
                'max_tokens' => $maxTokens,
                'messages'   => ai_build_anthropic_messages($history, $endpoint),
                'stream'     => (bool)$stream,
            ];
            if ($system !== '') {
                $body['system'] = $system;
            }
            if ($temp >= 0 && $temp <= 1) {
                $body['temperature'] = $temp;
            }
            break;

        case 'gemini':
            $body = [
                'contents'         => ai_build_gemini_contents($history, $endpoint),
                'generationConfig' => [
                    'maxOutputTokens' => $maxTokens,
                    'temperature'     => $temp,
                    'topP'            => $topP,
                ],
            ];
            if ($system !== '') {
                $body['systemInstruction'] = ['parts' => [['text' => $system]]];
            }
            break;

        case 'ollama':
            $body = [
                'model'    => $model ?: 'llama3.1',
                'messages' => ai_build_ollama_messages($history, $endpoint, $system),
                'stream'   => (bool)$stream,
                'options'  => [
                    'temperature' => $temp,
                    'top_p'       => $topP,
                    'num_predict' => $maxTokens,
                ],
            ];
            break;

        case 'openai':
        default:
            $body = [
                'model'       => $model ?: 'gpt-4o-mini',
                'messages'    => ai_build_openai_messages($history, $endpoint, $system),
                'stream'      => (bool)$stream,
                'max_tokens'  => $maxTokens,
                'temperature' => $temp,
                'top_p'       => $topP,
            ];
            if ($stream) {
                $body['stream_options'] = ['include_usage' => true];
            }
            break;
    }

    $extra = json_decode((string)$endpoint['extra_body'], true);
    if (is_array($extra)) {
        $body = ai_deep_merge($body, $extra);
    }
    return $body;
}

/** Hợp nhất đệ quy hai mảng cấu hình. */
function ai_deep_merge(array $base, array $override)
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])
            && !array_is_list_compat($value)) {
            $base[$key] = ai_deep_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

/** array_is_list() có từ PHP 8.1 — bản tương thích cho PHP 7.4. */
function array_is_list_compat(array $arr)
{
    if (function_exists('array_is_list')) {
        return array_is_list($arr);
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Bộ phân tích luồng trả về
// ---------------------------------------------------------------------------

/**
 * Phân tích một sự kiện đã giải mã JSON thành các phần chuẩn hoá.
 *
 * @return array ['text' => string, 'reasoning' => string, 'files' => [...], 'usage' => [...], 'model' => string]
 */
function ai_parse_event($type, $data, $eventName = '')
{
    $out = ['text' => '', 'reasoning' => '', 'files' => [], 'usage' => null, 'model' => ''];
    if (!is_array($data)) {
        return $out;
    }

    switch ($type) {
        case 'anthropic':
            $kind = $data['type'] ?? $eventName;
            if ($kind === 'content_block_delta') {
                $delta = $data['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    $out['text'] = (string)($delta['text'] ?? '');
                } elseif (($delta['type'] ?? '') === 'thinking_delta') {
                    $out['reasoning'] = (string)($delta['thinking'] ?? '');
                }
            } elseif ($kind === 'content_block_start') {
                $block = $data['content_block'] ?? [];
                if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                    $out['text'] = (string)$block['text'];
                }
            } elseif ($kind === 'message_start') {
                $msg = $data['message'] ?? [];
                $out['model'] = (string)($msg['model'] ?? '');
                if (!empty($msg['usage'])) {
                    $out['usage'] = [
                        'prompt'     => (int)($msg['usage']['input_tokens'] ?? 0),
                        'completion' => (int)($msg['usage']['output_tokens'] ?? 0),
                    ];
                }
            } elseif ($kind === 'message_delta') {
                if (!empty($data['usage'])) {
                    $out['usage'] = [
                        'prompt'     => (int)($data['usage']['input_tokens'] ?? 0),
                        'completion' => (int)($data['usage']['output_tokens'] ?? 0),
                    ];
                }
            } elseif ($kind === 'error') {
                $out['error'] = (string)($data['error']['message'] ?? 'Lỗi không xác định từ máy chủ AI.');
            }
            break;

        case 'gemini':
            $out['model'] = (string)($data['modelVersion'] ?? '');
            $candidates = $data['candidates'] ?? [];
            foreach ($candidates as $cand) {
                foreach (($cand['content']['parts'] ?? []) as $part) {
                    if (isset($part['text'])) {
                        if (!empty($part['thought'])) {
                            $out['reasoning'] .= (string)$part['text'];
                        } else {
                            $out['text'] .= (string)$part['text'];
                        }
                    }
                    $inline = $part['inlineData'] ?? ($part['inline_data'] ?? null);
                    if ($inline && !empty($inline['data'])) {
                        $out['files'][] = [
                            'mime' => $inline['mimeType'] ?? ($inline['mime_type'] ?? 'application/octet-stream'),
                            'b64'  => $inline['data'],
                            'name' => '',
                        ];
                    }
                }
            }
            if (!empty($data['usageMetadata'])) {
                $out['usage'] = [
                    'prompt'     => (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
                    'completion' => (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
                ];
            }
            if (!empty($data['error'])) {
                $out['error'] = (string)($data['error']['message'] ?? 'Lỗi từ Gemini.');
            }
            break;

        case 'ollama':
            if (isset($data['message']['content'])) {
                $out['text'] = (string)$data['message']['content'];
            }
            if (isset($data['message']['thinking'])) {
                $out['reasoning'] = (string)$data['message']['thinking'];
            }
            if (!empty($data['model'])) {
                $out['model'] = (string)$data['model'];
            }
            if (!empty($data['done']) && (isset($data['prompt_eval_count']) || isset($data['eval_count']))) {
                $out['usage'] = [
                    'prompt'     => (int)($data['prompt_eval_count'] ?? 0),
                    'completion' => (int)($data['eval_count'] ?? 0),
                ];
            }
            if (!empty($data['error'])) {
                $out['error'] = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
            }
            break;

        case 'openai':
        default:
            if (!empty($data['model'])) {
                $out['model'] = (string)$data['model'];
            }
            $choices = $data['choices'] ?? [];
            foreach ($choices as $choice) {
                $delta = $choice['delta'] ?? ($choice['message'] ?? []);
                if (isset($delta['content'])) {
                    if (is_string($delta['content'])) {
                        $out['text'] .= $delta['content'];
                    } elseif (is_array($delta['content'])) {
                        foreach ($delta['content'] as $piece) {
                            if (isset($piece['text'])) {
                                $out['text'] .= (string)$piece['text'];
                            }
                        }
                    }
                }
                // Trường suy luận của DeepSeek / OpenRouter / Qwen…
                foreach (['reasoning_content', 'reasoning'] as $rk) {
                    if (!empty($delta[$rk]) && is_string($delta[$rk])) {
                        $out['reasoning'] .= $delta[$rk];
                    }
                }
                // Ảnh do mô hình sinh (OpenRouter, một số gateway).
                foreach (($delta['images'] ?? []) as $img) {
                    $url = $img['image_url']['url'] ?? ($img['url'] ?? '');
                    if (is_string($url) && strpos($url, 'data:') === 0) {
                        $parsed = ai_parse_data_uri($url);
                        if ($parsed) {
                            $out['files'][] = ['mime' => $parsed['mime'], 'b64' => $parsed['b64'], 'name' => ''];
                        }
                    }
                }
            }
            if (!empty($data['usage'])) {
                $out['usage'] = [
                    'prompt'     => (int)($data['usage']['prompt_tokens'] ?? 0),
                    'completion' => (int)($data['usage']['completion_tokens'] ?? 0),
                ];
            }
            if (!empty($data['error'])) {
                $msg = is_array($data['error'])
                    ? ($data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE))
                    : (string)$data['error'];
                $out['error'] = $msg;
            }
            break;
    }
    return $out;
}

/** Tách data URI thành mime + base64. */
function ai_parse_data_uri($uri)
{
    if (!preg_match('#^data:([a-zA-Z0-9.+/-]+)?(;charset=[^;,]+)?(;base64)?,(.*)$#s', $uri, $m)) {
        return null;
    }
    $mime = $m[1] ?: 'application/octet-stream';
    $isB64 = !empty($m[3]);
    $payload = $m[4];
    return [
        'mime' => $mime,
        'b64'  => $isB64 ? $payload : base64_encode(rawurldecode($payload)),
    ];
}

// ---------------------------------------------------------------------------
// Gọi API dạng luồng
// ---------------------------------------------------------------------------

/**
 * Gọi endpoint AI và phát dữ liệu qua callback theo thời gian thực.
 *
 * @param array $endpoint  Bản ghi endpoint (đã có api_key_plain)
 * @param array $history   Danh sách tin nhắn nội bộ
 * @param array $callbacks onDelta, onReasoning, onFile, onUsage, onMeta, shouldStop
 * @return array ['ok' => bool, 'error' => string, 'http_code' => int]
 */
function ai_stream($endpoint, $history, $system, array $callbacks)
{
    $type    = $endpoint['api_type'];
    $stream  = (int)$endpoint['supports_stream'] === 1;
    $url     = ai_resolve_url($endpoint, $stream);
    $headers = ai_build_headers($endpoint);
    $body    = ai_build_body($endpoint, $history, $system, $stream);
    $timeout = max(10, (int)$endpoint['timeout']);

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Không tạo được dữ liệu gửi đi (JSON encode thất bại).', 'http_code' => 0];
    }

    $onDelta     = $callbacks['onDelta']     ?? function () {};
    $onReasoning = $callbacks['onReasoning'] ?? function () {};
    $onFile      = $callbacks['onFile']      ?? function () {};
    $onUsage     = $callbacks['onUsage']     ?? function () {};
    $onMeta      = $callbacks['onMeta']      ?? function () {};
    $shouldStop  = $callbacks['shouldStop']  ?? function () { return false; };

    $buffer     = '';
    $errorText  = '';
    $httpCode   = 0;
    $headerBlob = '';
    $rawBody    = '';   // dùng khi máy chủ trả lỗi dạng JSON thường

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        // Khi phát luồng thì yêu cầu dữ liệu không nén, để từng đoạn tới ngay
        // thay vì bị bộ nén giữ lại. Không phát luồng thì cho nén để nhẹ băng thông.
        CURLOPT_ENCODING       => $stream ? 'identity' : '',
        CURLOPT_TCP_NODELAY    => true,
        CURLOPT_USERAGENT      => 'TuanChatbot/' . APP_VERSION,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headerBlob) {
            $headerBlob .= $line;
            return strlen($line);
        },
    ]);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
        &$buffer, &$rawBody, &$errorText, &$httpCode,
        $type, $stream, $onDelta, $onReasoning, $onFile, $onUsage, $onMeta, $shouldStop
    ) {
        $len = strlen($chunk);

        if ($httpCode === 0) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        // Mã lỗi HTTP → gom toàn bộ body để báo lỗi cho người dùng.
        if ($httpCode >= 400) {
            $rawBody .= $chunk;
            return $len;
        }
        if (!$stream) {
            $rawBody .= $chunk;
            return $len;
        }

        $buffer .= $chunk;

        if ($type === 'ollama') {
            // NDJSON: mỗi dòng là một đối tượng JSON.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                $ev   = ai_parse_event($type, $data);
                ai_dispatch_event($ev, $onDelta, $onReasoning, $onFile, $onUsage, $onMeta, $errorText);
            }
        } else {
            // SSE: các khối cách nhau bằng dòng trống.
            while (preg_match('/\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                $blockEnd = $m[0][1];
                $block    = substr($buffer, 0, $blockEnd);
                $buffer   = substr($buffer, $blockEnd + strlen($m[0][0]));

                $eventName = '';
                $dataLines = [];
                foreach (preg_split('/\r?\n/', $block) as $line) {
                    if (strpos($line, 'event:') === 0) {
                        $eventName = trim(substr($line, 6));
                    } elseif (strpos($line, 'data:') === 0) {
                        $dataLines[] = ltrim(substr($line, 5), ' ');
                    }
                }
                if (!$dataLines) {
                    continue;
                }
                $raw = implode("\n", $dataLines);
                if ($raw === '[DONE]') {
                    continue;
                }
                $data = json_decode($raw, true);
                if ($data === null) {
                    continue;
                }
                $ev = ai_parse_event($type, $data, $eventName);
                ai_dispatch_event($ev, $onDelta, $onReasoning, $onFile, $onUsage, $onMeta, $errorText);
            }
        }

        if ($shouldStop()) {
            return 0; // dừng cURL
        }
        return $len;
    });

    $ok = curl_exec($ch);
    if ($httpCode === 0) {
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
    $curlErrNo = curl_errno($ch);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    // Trường hợp không streaming: xử lý toàn bộ body một lần.
    if (!$stream && $httpCode < 400 && $rawBody !== '') {
        $data = json_decode($rawBody, true);
        $ev   = ai_parse_event($type, $data);
        ai_dispatch_event($ev, $onDelta, $onReasoning, $onFile, $onUsage, $onMeta, $errorText);
    }

    if ($httpCode >= 400) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => ai_humanize_http_error($httpCode, $rawBody)];
    }
    if ($errorText !== '') {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => $errorText];
    }
    if ($ok === false && $curlErrNo !== 0 && $curlErrNo !== CURLE_WRITE_ERROR) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => ai_humanize_curl_error($curlErrNo, $curlErr, $timeout)];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'error' => ''];
}

/** Gọi các callback tương ứng với một sự kiện đã phân tích. */
function ai_dispatch_event($ev, $onDelta, $onReasoning, $onFile, $onUsage, $onMeta, &$errorText)
{
    if (!empty($ev['error']) && $errorText === '') {
        $errorText = $ev['error'];
    }
    if ($ev['text'] !== '')      $onDelta($ev['text']);
    if ($ev['reasoning'] !== '') $onReasoning($ev['reasoning']);
    if ($ev['model'] !== '')     $onMeta(['model' => $ev['model']]);
    if ($ev['usage'])            $onUsage($ev['usage']);
    foreach ($ev['files'] as $file) {
        $onFile($file);
    }
}

/** Diễn giải lỗi HTTP sang tiếng Việt dễ hiểu. */
function ai_humanize_http_error($code, $rawBody)
{
    $detail = '';
    $data = json_decode($rawBody, true);
    if (is_array($data)) {
        $detail = $data['error']['message']
            ?? $data['message']
            ?? $data['error']
            ?? '';
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
    }
    if ($detail === '' && $rawBody !== '') {
        $detail = str_limit(strip_tags($rawBody), 300);
    }

    $map = [
        400 => 'Yêu cầu không hợp lệ — hãy kiểm tra tên mô hình và các tham số bổ sung.',
        401 => 'API key không hợp lệ hoặc đã hết hạn.',
        403 => 'API key không có quyền truy cập mô hình này.',
        404 => 'Không tìm thấy đường dẫn API hoặc mô hình — hãy kiểm tra lại URL và tên mô hình.',
        408 => 'Máy chủ AI phản hồi quá chậm.',
        413 => 'Nội dung gửi đi quá lớn (tệp đính kèm hoặc lịch sử trò chuyện quá dài).',
        422 => 'Dữ liệu gửi đi không được máy chủ AI chấp nhận.',
        429 => 'Đã vượt hạn mức gọi API. Vui lòng thử lại sau ít phút.',
        500 => 'Máy chủ AI gặp sự cố nội bộ.',
        502 => 'Máy chủ AI tạm thời không phản hồi (502).',
        503 => 'Máy chủ AI đang quá tải (503). Vui lòng thử lại.',
        504 => 'Máy chủ AI phản hồi quá hạn (504).',
    ];
    $message = $map[$code] ?? ('Máy chủ AI trả về mã lỗi HTTP ' . $code . '.');
    return $detail !== '' ? $message . ' Chi tiết: ' . $detail : $message;
}

/** Diễn giải lỗi cURL sang tiếng Việt. */
function ai_humanize_curl_error($errno, $error, $timeout)
{
    switch ($errno) {
        case CURLE_OPERATION_TIMEOUTED:
            return 'Hết thời gian chờ sau ' . $timeout . ' giây. Hãy tăng "Timeout" trong cấu hình endpoint.';
        case CURLE_COULDNT_RESOLVE_HOST:
            return 'Không phân giải được tên miền của API. Hãy kiểm tra lại URL.';
        case CURLE_COULDNT_CONNECT:
            return 'Không kết nối được tới máy chủ AI. Có thể hosting đang chặn kết nối ra ngoài.';
        case CURLE_SSL_CACERT:
        case CURLE_SSL_CONNECT_ERROR:
            return 'Lỗi chứng chỉ SSL khi kết nối tới máy chủ AI.';
        default:
            return 'Lỗi kết nối tới máy chủ AI: ' . ($error ?: ('mã ' . $errno));
    }
}

/**
 * Kiểm tra nhanh một endpoint (dùng ở trang quản trị).
 *
 * @return array ['ok' => bool, 'message' => string, 'reply' => string, 'ms' => int]
 */
function ai_test_endpoint($endpoint, $prompt = 'Xin chào! Hãy trả lời đúng một câu ngắn bằng tiếng Việt để xác nhận kết nối.')
{
    $start   = microtime(true);
    $reply   = '';
    $model   = '';
    $history = [['role' => 'user', 'content' => $prompt, 'attachments' => []]];

    // Kiểm tra nhanh: giới hạn token và thời gian chờ để không treo trang quản trị.
    $probe = $endpoint;
    $probe['max_tokens'] = min((int)$endpoint['max_tokens'], 256);
    $probe['timeout']    = min((int)$endpoint['timeout'], 60);

    $result = ai_stream($probe, $history, 'Bạn là trợ lý kiểm tra kết nối. Hãy trả lời thật ngắn gọn.', [
        'onDelta' => function ($text) use (&$reply) { $reply .= $text; },
        'onMeta'  => function ($meta) use (&$model) { $model = $meta['model'] ?? $model; },
    ]);

    $ms = (int)round((microtime(true) - $start) * 1000);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error'], 'reply' => '', 'ms' => $ms, 'model' => $model];
    }
    if (trim($reply) === '') {
        return ['ok' => false, 'message' => 'Kết nối thành công nhưng mô hình không trả về nội dung nào.',
                'reply' => '', 'ms' => $ms, 'model' => $model];
    }
    return ['ok' => true, 'message' => 'Kết nối thành công!', 'reply' => str_limit($reply, 400), 'ms' => $ms, 'model' => $model];
}
