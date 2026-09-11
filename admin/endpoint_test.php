<?php
/**
 * Kiểm tra kết nối tới một endpoint AI, dùng ngay dữ liệu đang có trên biểu mẫu
 * (không cần lưu trước). Trả về JSON.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai.php';

require_admin(true);
csrf_require(true);

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    json_error('Phương thức không được hỗ trợ.', 405);
}

$endpointId = (int)($_POST['endpoint_id'] ?? 0);
$apiKey     = trim($_POST['api_key'] ?? '');

// Nếu người quản trị không nhập key mới thì lấy key đang lưu trong CSDL.
if ($apiKey === '' && $endpointId > 0) {
    $saved = db_one('SELECT `api_key` FROM `endpoints` WHERE `id` = ? LIMIT 1', [$endpointId]);
    if ($saved) {
        $apiKey = crypto_decrypt($saved['api_key']);
    }
}

$endpoint = [
    'id'              => $endpointId,
    'name'            => trim($_POST['name'] ?? 'Endpoint thử'),
    'api_type'        => $_POST['api_type'] ?? 'openai',
    'base_url'        => trim($_POST['base_url'] ?? ''),
    'api_key'         => '',
    'api_key_plain'   => $apiKey,
    'model'           => trim($_POST['model'] ?? ''),
    'max_tokens'      => max(1, (int)($_POST['max_tokens'] ?? 1024)),
    'timeout'         => max(10, (int)($_POST['timeout'] ?? 60)),
    'temperature'     => (float)($_POST['temperature'] ?? 1),
    'top_p'           => (float)($_POST['top_p'] ?? 1),
    'system_prompt'   => '',
    'extra_headers'   => trim($_POST['extra_headers'] ?? ''),
    'extra_body'      => trim($_POST['extra_body'] ?? ''),
    'supports_vision' => 0,
    'supports_files'  => 0,
    'supports_stream' => isset($_POST['supports_stream']) ? 1 : 0,
    'history_limit'   => 20,
];

if (!array_key_exists($endpoint['api_type'], ai_types())) {
    json_error('Loại API không hợp lệ.');
}
if ($endpoint['base_url'] === '' || !preg_match('#^https?://#i', $endpoint['base_url'])) {
    json_error('URL gốc chưa hợp lệ (phải bắt đầu bằng http:// hoặc https://).');
}
if ($endpoint['model'] === '') {
    json_error('Vui lòng nhập tên mô hình trước khi kiểm tra.');
}
foreach (['extra_headers', 'extra_body'] as $field) {
    if ($endpoint[$field] !== '' && !is_array(json_decode($endpoint[$field], true))) {
        json_error('Trường "' . $field . '" phải là JSON hợp lệ.');
    }
}

$result = ai_test_endpoint($endpoint);

log_activity('endpoint_test', ($result['ok'] ? 'Thành công' : 'Thất bại') . ' — ' . $endpoint['name']
    . ' (' . $endpoint['model'] . '): ' . str_limit($result['message'], 200));

json_out([
    'ok'      => $result['ok'],
    'message' => $result['message'],
    'reply'   => $result['reply'],
    'ms'      => $result['ms'],
    'model'   => $result['model'] ?: $endpoint['model'],
]);
