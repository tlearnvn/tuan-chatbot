<?php
/**
 * Thêm / sửa một endpoint AI.
 */
require_once __DIR__ . '/_init.php';

$id       = (int)($_GET['id'] ?? 0);
$isNew    = $id === 0;
$types    = ai_types();
$errors   = [];

$defaults = [
    'name'            => '',
    'description'     => '',
    'api_type'        => 'openai',
    'base_url'        => 'https://api.openai.com/v1',
    'api_key'         => '',
    'model'           => '',
    'max_tokens'      => 64000,
    'timeout'         => 300,
    'temperature'     => 1.00,
    'top_p'           => 1.00,
    'system_prompt'   => "Bạn là trợ lý AI thân thiện, trả lời bằng tiếng Việt rõ ràng, chính xác và có cấu trúc.\n"
                       . "Khi trình bày công thức toán, hãy dùng LaTeX. Khi viết mã, hãy dùng khối mã có ghi tên ngôn ngữ.",
    'extra_headers'   => '',
    'extra_body'      => '',
    'supports_vision' => 1,
    'supports_files'  => 1,
    'supports_stream' => 1,
    'history_limit'   => 20,
    'is_default'      => 0,
    'is_active'       => 1,
    'sort_order'      => 0,
];

if (!$isNew) {
    $row = db_one('SELECT * FROM `endpoints` WHERE `id` = ? LIMIT 1', [$id]);
    if (!$row) {
        flash('error', 'Không tìm thấy endpoint.');
        redirect('endpoints.php');
    }
    $form = $row;
    $form['api_key'] = '';                       // không hiển thị lại key đã lưu
    $savedKey = crypto_decrypt($row['api_key']);
} else {
    $form = $defaults;
    $savedKey = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $form = [
        'name'            => trim($_POST['name'] ?? ''),
        'description'     => trim($_POST['description'] ?? ''),
        'api_type'        => $_POST['api_type'] ?? 'openai',
        'base_url'        => trim($_POST['base_url'] ?? ''),
        'api_key'         => trim($_POST['api_key'] ?? ''),
        'model'           => trim($_POST['model'] ?? ''),
        'max_tokens'      => (int)($_POST['max_tokens'] ?? 64000),
        'timeout'         => (int)($_POST['timeout'] ?? 300),
        'temperature'     => (float)($_POST['temperature'] ?? 1),
        'top_p'           => (float)($_POST['top_p'] ?? 1),
        'system_prompt'   => (string)($_POST['system_prompt'] ?? ''),
        'extra_headers'   => trim($_POST['extra_headers'] ?? ''),
        'extra_body'      => trim($_POST['extra_body'] ?? ''),
        'supports_vision' => isset($_POST['supports_vision']) ? 1 : 0,
        'supports_files'  => isset($_POST['supports_files']) ? 1 : 0,
        'supports_stream' => isset($_POST['supports_stream']) ? 1 : 0,
        'history_limit'   => (int)($_POST['history_limit'] ?? 20),
        'is_default'      => isset($_POST['is_default']) ? 1 : 0,
        'is_active'       => isset($_POST['is_active']) ? 1 : 0,
        'sort_order'      => (int)($_POST['sort_order'] ?? 0),
    ];

    if ($form['name'] === '') {
        $errors[] = 'Vui lòng đặt tên cho endpoint.';
    }
    if (!array_key_exists($form['api_type'], $types)) {
        $errors[] = 'Loại API không hợp lệ.';
    }
    if ($form['base_url'] === '') {
        $errors[] = 'Vui lòng nhập URL gốc của API.';
    } elseif (!preg_match('#^https?://#i', $form['base_url'])) {
        $errors[] = 'URL phải bắt đầu bằng http:// hoặc https://';
    }
    if ($form['model'] === '') {
        $errors[] = 'Vui lòng nhập tên mô hình (model).';
    }
    if ($form['max_tokens'] < 1 || $form['max_tokens'] > 10000000) {
        $errors[] = 'Số token tối đa phải nằm trong khoảng 1 – 10.000.000.';
    }
    if ($form['timeout'] < 10 || $form['timeout'] > 3600) {
        $errors[] = 'Thời gian chờ phải nằm trong khoảng 10 – 3600 giây.';
    }
    if ($form['history_limit'] < 2 || $form['history_limit'] > 200) {
        $errors[] = 'Số tin nhắn ngữ cảnh phải nằm trong khoảng 2 – 200.';
    }
    foreach (['extra_headers' => 'Header bổ sung', 'extra_body' => 'Tham số bổ sung'] as $field => $label) {
        if ($form[$field] !== '') {
            $decoded = json_decode($form[$field], true);
            if (!is_array($decoded)) {
                $errors[] = $label . ' phải là một đối tượng JSON hợp lệ, ví dụ {"key":"value"}.';
            }
        }
    }

    if (!$errors) {
        $data = $form;
        unset($data['api_key']);

        // Chỉ ghi đè API key khi người dùng nhập giá trị mới.
        if ($form['api_key'] !== '') {
            $data['api_key'] = crypto_encrypt($form['api_key']);
            $savedKey = $form['api_key'];
        } elseif ($isNew) {
            $data['api_key'] = '';
        }
        if (isset($_POST['clear_api_key'])) {
            $data['api_key'] = '';
            $savedKey = '';
        }

        $data['updated_at'] = now_vn();

        if ($isNew) {
            $data['created_at'] = now_vn();
            $id = db_insert('endpoints', $data);
            log_activity('endpoint_create', 'Tạo endpoint: ' . $form['name']);
            flash('success', 'Đã tạo endpoint "' . $form['name'] . '"! 🎉');
        } else {
            db_update('endpoints', $data, '`id` = ?', [$id]);
            log_activity('endpoint_update', 'Cập nhật endpoint: ' . $form['name']);
            flash('success', 'Đã lưu thay đổi.');
        }

        if ($form['is_default'] === 1) {
            db_run('UPDATE `endpoints` SET `is_default` = 0 WHERE `id` <> ?', [$id]);
        }

        redirect(isset($_POST['save_and_stay']) ? ('endpoint_edit.php?id=' . $id) : 'endpoints.php');
    }
}

admin_head(($isNew ? 'Thêm' : 'Sửa') . ' endpoint AI', 'endpoints');
?>
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error mb-2"><span class="flash-icon">😿</span><span><?= e($error) ?></span></div>
<?php endforeach; ?>

<form method="post" id="endpointForm">
  <?= csrf_field() ?>

  <!-- Thông tin chung -->
  <div class="form-section">
    <h3>📇 Thông tin chung</h3>
    <div class="form-grid">
      <div class="field">
        <label for="name">Tên hiển thị <span class="text-muted">*</span></label>
        <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required
               placeholder="vd: GPT-4o mini — nhanh & rẻ">
        <span class="hint">Tên này hiện trong hộp chọn mô hình của người dùng.</span>
      </div>
      <div class="field">
        <label for="description">Mô tả ngắn</label>
        <input type="text" id="description" name="description" value="<?= e($form['description']) ?>"
               placeholder="vd: Dùng cho câu hỏi thường ngày">
      </div>
    </div>

    <div class="form-grid form-grid-3">
      <div class="field">
        <label for="api_type">Loại API <span class="text-muted">*</span></label>
        <select id="api_type" name="api_type" required>
          <?php foreach ($types as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $form['api_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="sort_order">Thứ tự sắp xếp</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= (int)$form['sort_order'] ?>">
      </div>
      <div class="field">
        <span class="label">Trạng thái</span>
        <label class="switch mb-1">
          <input type="checkbox" name="is_active" value="1" <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track"></span><span class="text-sm">Đang bật</span>
        </label>
        <label class="switch">
          <input type="checkbox" name="is_default" value="1" <?= (int)$form['is_default'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track"></span><span class="text-sm">Đặt làm mặc định</span>
        </label>
      </div>
    </div>
  </div>

  <!-- Kết nối -->
  <div class="form-section">
    <h3>🔗 Kết nối</h3>
    <div class="field">
      <label for="base_url">URL gốc của API <span class="text-muted">*</span></label>
      <input type="url" id="base_url" name="base_url" value="<?= e($form['base_url']) ?>" required
             placeholder="https://api.openai.com/v1">
      <span class="hint" id="urlHint">
        Chỉ cần nhập URL gốc, hệ thống tự thêm đường dẫn phù hợp
        (<code class="inline-code">/chat/completions</code>, <code class="inline-code">/messages</code>…).
        Nếu bạn nhập sẵn đường dẫn đầy đủ thì hệ thống giữ nguyên.
      </span>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="api_key">API Key</label>
        <div class="password-wrap">
          <input type="password" id="api_key" name="api_key" value="" autocomplete="new-password"
                 placeholder="<?= $savedKey !== '' ? 'Đang lưu: ' . e(mask_secret($savedKey)) . ' — để trống nếu giữ nguyên' : 'sk-…' ?>">
          <button type="button" class="password-toggle" data-toggle-password="#api_key">👁️</button>
        </div>
        <span class="hint">
          Key được mã hoá AES-256 trước khi lưu vào cơ sở dữ liệu.
          <?php if ($savedKey !== ''): ?>
            <label class="switch mt-1">
              <input type="checkbox" name="clear_api_key" value="1">
              <span class="switch-track"></span><span class="text-sm">Xoá API key đang lưu</span>
            </label>
          <?php endif; ?>
        </span>
      </div>

      <div class="field">
        <label for="model">Tên mô hình (model) <span class="text-muted">*</span></label>
        <input type="text" id="model" name="model" value="<?= e($form['model']) ?>" required
               placeholder="gpt-4o-mini" list="modelList">
        <datalist id="modelList">
          <option value="gpt-4o-mini"></option>
          <option value="gpt-4o"></option>
          <option value="claude-sonnet-4-20250514"></option>
          <option value="claude-3-5-haiku-20241022"></option>
          <option value="gemini-2.0-flash"></option>
          <option value="gemini-1.5-pro"></option>
          <option value="deepseek-chat"></option>
          <option value="llama-3.3-70b-versatile"></option>
        </datalist>
      </div>
    </div>
  </div>

  <!-- Tham số sinh văn bản -->
  <div class="form-section">
    <h3>🎛️ Tham số</h3>
    <div class="form-grid form-grid-3">
      <div class="field">
        <label for="max_tokens">Token tối đa</label>
        <input type="number" id="max_tokens" name="max_tokens" value="<?= (int)$form['max_tokens'] ?>"
               min="1" max="10000000" step="1000">
        <span class="hint">Mặc định 64000. Giảm xuống nếu mô hình báo lỗi vượt giới hạn.</span>
      </div>
      <div class="field">
        <label for="timeout">Thời gian chờ (giây)</label>
        <input type="number" id="timeout" name="timeout" value="<?= (int)$form['timeout'] ?>" min="10" max="3600">
        <span class="hint">Mặc định 300s. Mô hình suy luận sâu cần nhiều thời gian hơn.</span>
      </div>
      <div class="field">
        <label for="history_limit">Số tin nhắn ngữ cảnh</label>
        <input type="number" id="history_limit" name="history_limit" value="<?= (int)$form['history_limit'] ?>" min="2" max="200">
        <span class="hint">Số tin nhắn gần nhất gửi kèm mỗi lượt hỏi.</span>
      </div>
      <div class="field">
        <label for="temperature">Temperature</label>
        <input type="number" id="temperature" name="temperature" value="<?= e(number_format((float)$form['temperature'], 2, '.', '')) ?>"
               min="0" max="2" step="0.05">
        <span class="hint">0 = bám sát dữ kiện, 1 = sáng tạo hơn.</span>
      </div>
      <div class="field">
        <label for="top_p">Top P</label>
        <input type="number" id="top_p" name="top_p" value="<?= e(number_format((float)$form['top_p'], 2, '.', '')) ?>"
               min="0" max="1" step="0.05">
      </div>
      <div class="field">
        <span class="label">Khả năng hỗ trợ</span>
        <label class="switch mb-1">
          <input type="checkbox" name="supports_stream" value="1" <?= (int)$form['supports_stream'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track"></span><span class="text-sm">⚡ Trả lời theo luồng</span>
        </label>
        <label class="switch mb-1">
          <input type="checkbox" name="supports_vision" value="1" <?= (int)$form['supports_vision'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track"></span><span class="text-sm">🖼️ Đọc được ảnh</span>
        </label>
        <label class="switch">
          <input type="checkbox" name="supports_files" value="1" <?= (int)$form['supports_files'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track"></span><span class="text-sm">📎 Nhận tệp (PDF…)</span>
        </label>
      </div>
    </div>
  </div>

  <!-- System prompt -->
  <div class="form-section">
    <h3>🧭 System prompt</h3>
    <div class="field">
      <label for="system_prompt">Chỉ dẫn hệ thống</label>
      <textarea id="system_prompt" name="system_prompt" rows="8"
                placeholder="Mô tả vai trò, giọng điệu, quy tắc trả lời…"><?= e($form['system_prompt']) ?></textarea>
      <span class="hint">
        Hệ thống tự nối thêm: bối cảnh ngày giờ Việt Nam, tên website, tên người dùng
        và nội dung các <a href="documents.php">tài liệu đi kèm</a> đang bật.
      </span>
    </div>
  </div>

  <!-- Nâng cao -->
  <div class="form-section">
    <h3>🧪 Nâng cao (tuỳ chọn)</h3>
    <div class="form-grid">
      <div class="field">
        <label for="extra_headers">Header HTTP bổ sung (JSON)</label>
        <textarea id="extra_headers" name="extra_headers" rows="4"
                  placeholder='{"HTTP-Referer":"https://web-cua-ban.com","X-Title":"Tuan Chatbot"}'><?= e($form['extra_headers']) ?></textarea>
        <span class="hint">Hữu ích với OpenRouter hoặc các cổng API yêu cầu header riêng.</span>
      </div>
      <div class="field">
        <label for="extra_body">Tham số payload bổ sung (JSON)</label>
        <textarea id="extra_body" name="extra_body" rows="4"
                  placeholder='{"reasoning_effort":"medium"}'><?= e($form['extra_body']) ?></textarea>
        <span class="hint">Được hợp nhất vào dữ liệu gửi đi, ghi đè tham số mặc định.</span>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <a class="btn btn-ghost" href="endpoints.php">← Quay lại</a>
    <button type="button" class="btn btn-soft" id="btnTest">🔍 Kiểm tra kết nối</button>
    <button type="submit" class="btn btn-ghost" name="save_and_stay" value="1">💾 Lưu &amp; tiếp tục sửa</button>
    <button type="submit" class="btn btn-primary">✅ Lưu endpoint</button>
  </div>

  <div id="testResult"></div>
</form>

<script>
(function () {
  // Gợi ý URL gốc theo loại API được chọn.
  var suggestions = {
    openai: 'https://api.openai.com/v1',
    anthropic: 'https://api.anthropic.com/v1',
    gemini: 'https://generativelanguage.googleapis.com/v1beta',
    ollama: 'http://localhost:11434'
  };
  var typeSelect = document.getElementById('api_type');
  var urlInput = document.getElementById('base_url');

  typeSelect.addEventListener('change', function () {
    var known = Object.keys(suggestions).map(function (k) { return suggestions[k]; });
    if (urlInput.value === '' || known.indexOf(urlInput.value) !== -1) {
      urlInput.value = suggestions[typeSelect.value] || '';
    }
  });

  // Kiểm tra kết nối không cần lưu trước.
  var testBtn = document.getElementById('btnTest');
  var resultBox = document.getElementById('testResult');

  testBtn.addEventListener('click', function () {
    var form = document.getElementById('endpointForm');
    var data = new FormData(form);
    data.append('endpoint_id', '<?= (int)$id ?>');

    testBtn.disabled = true;
    testBtn.innerHTML = '<span class="spin">⏳</span> Đang kiểm tra…';
    resultBox.innerHTML = '';

    fetch('endpoint_test.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': '<?= e(csrf_token()) ?>' }
    }).then(function (res) { return res.json(); }).then(function (out) {
      var ok = out.ok;
      var html = '<div class="test-result ' + (ok ? 'is-ok' : 'is-fail') + '">' +
        '<strong>' + (ok ? '✅ ' : '❌ ') + (out.message || '') + '</strong>';
      if (out.ms) html += ' <span class="badge badge-muted">' + out.ms + ' ms</span>';
      if (out.model) html += ' <span class="badge">' + out.model + '</span>';
      if (out.reply) {
        html += '<div class="test-reply"></div>';
      }
      html += '</div>';
      resultBox.innerHTML = html;
      if (out.reply) resultBox.querySelector('.test-reply').textContent = out.reply;
    }).catch(function (err) {
      resultBox.innerHTML = '<div class="test-result is-fail"><strong>❌ Lỗi gọi kiểm tra: </strong>' +
                            String(err.message) + '</div>';
    }).finally(function () {
      testBtn.disabled = false;
      testBtn.innerHTML = '🔍 Kiểm tra kết nối';
    });
  });
})();
</script>
<?php
admin_foot();
