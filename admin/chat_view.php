<?php
/**
 * Xem lại toàn bộ nội dung một cuộc trò chuyện (dành cho quản trị viên).
 */
require_once __DIR__ . '/_init.php';

$id   = (int)($_GET['id'] ?? 0);
$conv = db_one(
    'SELECT c.*, u.`username`, u.`full_name`, u.`avatar_emoji`, e.`name` AS endpoint_name
     FROM `conversations` c
     JOIN `users` u ON u.`id` = c.`user_id`
     LEFT JOIN `endpoints` e ON e.`id` = c.`endpoint_id`
     WHERE c.`id` = ? LIMIT 1',
    [$id]
);

if (!$conv) {
    flash('error', 'Không tìm thấy cuộc trò chuyện.');
    redirect('chats.php');
}

$messages = db_all('SELECT * FROM `messages` WHERE `conversation_id` = ? ORDER BY `id` ASC', [$id]);
$msgIds   = array_map(function ($m) { return (int)$m['id']; }, $messages);
$attMap   = [];
if ($msgIds) {
    $in = implode(',', array_fill(0, count($msgIds), '?'));
    foreach (db_all('SELECT * FROM `attachments` WHERE `message_id` IN (' . $in . ') ORDER BY `id`', $msgIds) as $att) {
        $attMap[(int)$att['message_id']][] = $att;
    }
}

$totalTokens = 0;
foreach ($messages as $m) {
    $totalTokens += (int)$m['prompt_tokens'] + (int)$m['completion_tokens'];
}

admin_head('Xem cuộc trò chuyện #' . $id, 'chats');
?>
<!-- Cùng bộ thư viện với màn hình chat để hiển thị Markdown, LaTeX, mã nguồn -->
<script src="<?= e(base_url()) ?>/assets/js/libs.js?v=<?= e(APP_VERSION) ?>"></script>

<div class="row row-between mb-2">
  <div>
    <h2 class="mb-0"><?= e($conv['title']) ?></h2>
    <p class="text-muted mb-0 text-sm">
      <span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($conv['avatar_emoji']) ?></span>
      <strong><?= e($conv['username']) ?></strong>
      <?= $conv['full_name'] ? '(' . e($conv['full_name']) . ')' : '' ?>
      · <?= count($messages) ?> tin nhắn
      · <?= number_format($totalTokens, 0, ',', '.') ?> token
      · Endpoint: <?= e($conv['endpoint_name'] ?? '—') ?>
      · Tạo <?= e(fmt_datetime($conv['created_at'])) ?>
    </p>
  </div>
  <div class="row" style="gap:.4rem">
    <a class="btn btn-ghost" href="chats.php">← Danh sách</a>
    <button type="button" class="btn btn-soft" onclick="window.print()">🖨️ In / lưu PDF</button>
  </div>
</div>

<div class="card">
  <?php if (!$messages): ?>
    <div class="empty"><span class="empty-emoji">📭</span>Cuộc trò chuyện này chưa có tin nhắn nào.</div>
  <?php else: ?>
    <div class="transcript">
      <?php foreach ($messages as $msg): ?>
        <?php $isUser = $msg['role'] === 'user'; ?>
        <div class="t-msg t-msg-<?= $isUser ? 'user' : 'assistant' ?>">
          <div class="msg-avatar<?= $isUser ? '' : ' is-ai' ?>"
               style="<?= $isUser ? '' : 'background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff' ?>">
            <?= $isUser ? e($conv['avatar_emoji']) : e(setting('brand_emoji')) ?>
          </div>
          <div class="t-body">
            <?php $atts = $attMap[(int)$msg['id']] ?? []; ?>
            <?php if ($atts): ?>
              <div class="msg-files">
                <?php foreach ($atts as $att): ?>
                  <?php $ext = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION)); ?>
                  <?php if ($att['kind'] === 'image'): ?>
                    <a href="<?= e(base_url()) ?>/api/download.php?id=<?= (int)$att['id'] ?>" target="_blank" rel="noopener">
                      <img class="img-attach" src="<?= e(base_url()) ?>/api/download.php?id=<?= (int)$att['id'] ?>"
                           alt="<?= e($att['original_name']) ?>" loading="lazy">
                    </a>
                  <?php else: ?>
                    <a class="file-chip" href="<?= e(base_url()) ?>/api/download.php?id=<?= (int)$att['id'] ?>&dl=1">
                      <span class="file-chip-icon"><?= e(file_icon($att['kind'], $ext)) ?></span>
                      <span class="file-chip-meta">
                        <span class="file-chip-name"><?= e($att['original_name']) ?></span>
                        <span class="file-chip-size"><?= e(fmt_bytes($att['size'])) ?>
                          <?= $att['direction'] === 'out' ? ' · AI trả về' : '' ?></span>
                      </span>
                    </a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($msg['reasoning']): ?>
              <details class="reasoning">
                <summary>Quá trình suy luận của mô hình</summary>
                <div class="reasoning-body"><?= e($msg['reasoning']) ?></div>
              </details>
            <?php endif; ?>

            <div class="msg-bubble<?= $msg['status'] === 'error' ? ' is-error' : '' ?>"
                 style="<?= $isUser ? 'background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;border-color:transparent' : '' ?>">
              <?php if ($isUser): ?>
                <div class="md" style="white-space:pre-wrap"><?= e($msg['content']) ?></div>
              <?php else: ?>
                <div class="md" data-markdown><?= e($msg['content']) ?></div>
              <?php endif; ?>
              <?php if ($msg['status'] === 'error' && $msg['error_message']): ?>
                <p class="text-sm mb-0" style="color:var(--danger)">⚠️ <?= e($msg['error_message']) ?></p>
              <?php endif; ?>
            </div>

            <div class="msg-meta">
              <?= e(fmt_datetime($msg['created_at'])) ?>
              <?= $msg['model'] ? ' · ' . e($msg['model']) : '' ?>
              <?= (int)$msg['duration_ms'] ? ' · ' . number_format((int)$msg['duration_ms'] / 1000, 1, ',', '.') . 's' : '' ?>
              <?php if ((int)$msg['prompt_tokens'] || (int)$msg['completion_tokens']): ?>
                · ↑<?= (int)$msg['prompt_tokens'] ?> ↓<?= (int)$msg['completion_tokens'] ?> token
              <?php endif; ?>
              <?php if ($msg['status'] === 'aborted'): ?> · ⏹ đã dừng<?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script src="<?= e(base_url()) ?>/assets/js/md.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
// Dựng lại Markdown + LaTeX cho các tin nhắn của trợ lý (nội dung nằm sẵn ở dạng text).
(function () {
  function renderAll() {
    document.querySelectorAll('[data-markdown]').forEach(function (node) {
      if (node.dataset.raw === undefined) node.dataset.raw = node.textContent;
      window.TChatMD.render(node, node.dataset.raw);
    });
  }
  if (window.TChatMD) renderAll();
  else document.addEventListener('DOMContentLoaded', renderAll);
})();
</script>

<?php
admin_foot();
