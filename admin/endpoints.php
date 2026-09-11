<?php
/**
 * Danh sách và thao tác nhanh với các endpoint AI.
 */
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $current = (int)db_value('SELECT `is_active` FROM `endpoints` WHERE `id` = ?', [$id], 0);
        db_update('endpoints', ['is_active' => $current ? 0 : 1, 'updated_at' => now_vn()], '`id` = ?', [$id]);
        flash('success', $current ? 'Đã tắt endpoint.' : 'Đã bật endpoint.');
    } elseif ($action === 'default' && $id) {
        db_run('UPDATE `endpoints` SET `is_default` = 0');
        db_update('endpoints', ['is_default' => 1, 'is_active' => 1, 'updated_at' => now_vn()], '`id` = ?', [$id]);
        flash('success', 'Đã đặt làm endpoint mặc định.');
    } elseif ($action === 'delete' && $id) {
        $name = db_value('SELECT `name` FROM `endpoints` WHERE `id` = ?', [$id], '');
        db_run('DELETE FROM `endpoints` WHERE `id` = ?', [$id]);
        log_activity('endpoint_delete', 'Xoá endpoint: ' . $name);
        flash('success', 'Đã xoá endpoint "' . $name . '".');
    } elseif ($action === 'clone' && $id) {
        $src = db_one('SELECT * FROM `endpoints` WHERE `id` = ?', [$id]);
        if ($src) {
            unset($src['id']);
            $src['name']       = $src['name'] . ' (bản sao)';
            $src['is_default'] = 0;
            $src['is_active']  = 0;
            $src['created_at'] = now_vn();
            $src['updated_at'] = now_vn();
            $newId = db_insert('endpoints', $src);
            flash('success', 'Đã nhân bản endpoint. Hãy kiểm tra lại cấu hình.');
            redirect('endpoint_edit.php?id=' . $newId);
        }
    }
    redirect('endpoints.php');
}

$endpoints = db_all('SELECT * FROM `endpoints` ORDER BY `is_default` DESC, `sort_order`, `id`');
$types     = ai_types();

admin_head('AI API Endpoint', 'endpoints');
?>
<div class="row row-between mb-2">
  <p class="text-muted mb-0" style="max-width:640px">
    Mỗi endpoint là một kết nối tới dịch vụ AI: URL, API key, mô hình, số token, thời gian chờ,
    system prompt và tài liệu đi kèm. Người dùng chọn endpoint ngay trên thanh tiêu đề khi trò chuyện.
  </p>
  <a class="btn btn-primary" href="endpoint_edit.php">➕ Thêm endpoint</a>
</div>

<?php if (!$endpoints): ?>
  <div class="table-card">
    <div class="empty">
      <span class="empty-emoji">🔌</span>
      <h3>Chưa có endpoint nào</h3>
      <p>Thêm endpoint đầu tiên để người dùng có thể bắt đầu trò chuyện.</p>
      <a class="btn btn-primary mt-1" href="endpoint_edit.php">➕ Thêm endpoint</a>
    </div>
  </div>
<?php else: ?>
  <div class="table-card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Tên</th><th>Loại API</th><th>Mô hình</th>
            <th class="num">Token</th><th class="num">Timeout</th>
            <th>Khả năng</th><th>Trạng thái</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($endpoints as $ep): ?>
          <tr>
            <td class="cell-strong">
              <a href="endpoint_edit.php?id=<?= (int)$ep['id'] ?>"><?= e($ep['name']) ?></a>
              <?php if ((int)$ep['is_default'] === 1): ?>
                <span class="badge">⭐ mặc định</span>
              <?php endif; ?>
              <?php if ($ep['description']): ?>
                <div class="cell-muted"><?= e(str_limit($ep['description'], 60)) ?></div>
              <?php endif; ?>
            </td>
            <td class="cell-muted"><?= e(explode(' ', $types[$ep['api_type']] ?? $ep['api_type'])[0]) ?></td>
            <td class="cell-muted"><code class="inline-code"><?= e(str_limit($ep['model'], 26)) ?></code></td>
            <td class="num"><?= number_format((int)$ep['max_tokens'], 0, ',', '.') ?></td>
            <td class="num"><?= (int)$ep['timeout'] ?>s</td>
            <td>
              <?php if ((int)$ep['supports_vision'] === 1): ?><span class="badge badge-muted" title="Đọc được ảnh">🖼️</span><?php endif; ?>
              <?php if ((int)$ep['supports_files'] === 1): ?><span class="badge badge-muted" title="Nhận tệp">📎</span><?php endif; ?>
              <?php if ((int)$ep['supports_stream'] === 1): ?><span class="badge badge-muted" title="Trả lời theo luồng">⚡</span><?php endif; ?>
            </td>
            <td>
              <?php if ((int)$ep['is_active'] === 1): ?>
                <span class="badge badge-ok">● Bật</span>
              <?php else: ?>
                <span class="badge badge-muted">○ Tắt</span>
              <?php endif; ?>
            </td>
            <td class="cell-actions">
              <div class="row" style="gap:.25rem;justify-content:flex-end;flex-wrap:nowrap">
                <a class="btn btn-ghost btn-sm" href="endpoint_edit.php?id=<?= (int)$ep['id'] ?>">Sửa</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                  <input type="hidden" name="action" value="toggle">
                  <button type="submit" class="btn btn-ghost btn-sm" title="Bật/tắt"><?= (int)$ep['is_active'] === 1 ? '⏸' : '▶' ?></button>
                </form>
                <?php if ((int)$ep['is_default'] !== 1): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                    <input type="hidden" name="action" value="default">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Đặt làm mặc định">⭐</button>
                  </form>
                <?php endif; ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                  <input type="hidden" name="action" value="clone">
                  <button type="submit" class="btn btn-ghost btn-sm" title="Nhân bản">⧉</button>
                </form>
                <form method="post" style="display:inline"
                      data-confirm="Xoá endpoint &quot;<?= e($ep['name']) ?>&quot;? Các cuộc trò chuyện cũ vẫn được giữ lại.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button type="submit" class="btn btn-ghost btn-sm is-danger" title="Xoá">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-2">
  <h3 class="card-title">💡 Gợi ý cấu hình nhanh</h3>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Dịch vụ</th><th>Loại API</th><th>URL gốc</th><th>Mô hình ví dụ</th></tr></thead>
      <tbody>
        <tr><td>OpenAI</td><td>openai</td><td><code class="inline-code">https://api.openai.com/v1</code></td><td><code class="inline-code">gpt-4o-mini</code></td></tr>
        <tr><td>Anthropic Claude</td><td>anthropic</td><td><code class="inline-code">https://api.anthropic.com/v1</code></td><td><code class="inline-code">claude-sonnet-4-20250514</code></td></tr>
        <tr><td>Google Gemini</td><td>gemini</td><td><code class="inline-code">https://generativelanguage.googleapis.com/v1beta</code></td><td><code class="inline-code">gemini-2.0-flash</code></td></tr>
        <tr><td>OpenRouter</td><td>openai</td><td><code class="inline-code">https://openrouter.ai/api/v1</code></td><td><code class="inline-code">openai/gpt-4o-mini</code></td></tr>
        <tr><td>DeepSeek</td><td>openai</td><td><code class="inline-code">https://api.deepseek.com/v1</code></td><td><code class="inline-code">deepseek-chat</code></td></tr>
        <tr><td>Groq</td><td>openai</td><td><code class="inline-code">https://api.groq.com/openai/v1</code></td><td><code class="inline-code">llama-3.3-70b-versatile</code></td></tr>
        <tr><td>Ollama (máy nội bộ)</td><td>ollama</td><td><code class="inline-code">http://localhost:11434</code></td><td><code class="inline-code">llama3.1</code></td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php
admin_foot();
