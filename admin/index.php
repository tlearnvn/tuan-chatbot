<?php
/**
 * Bảng điều khiển — tổng quan hệ thống.
 */
require_once __DIR__ . '/_init.php';

$stats = [
    'users'     => (int)db_value('SELECT COUNT(*) FROM `users`', [], 0),
    'usersNew'  => (int)db_value('SELECT COUNT(*) FROM `users` WHERE `created_at` >= ?', [date('Y-m-d 00:00:00')], 0),
    'convs'     => (int)db_value('SELECT COUNT(*) FROM `conversations`', [], 0),
    'msgs'      => (int)db_value('SELECT COUNT(*) FROM `messages`', [], 0),
    'msgsToday' => (int)db_value('SELECT COUNT(*) FROM `messages` WHERE `created_at` >= ?', [date('Y-m-d 00:00:00')], 0),
    'files'     => (int)db_value('SELECT COUNT(*) FROM `attachments`', [], 0),
    'storage'   => (int)db_value('SELECT COALESCE(SUM(`size`),0) FROM `attachments`', [], 0),
    'endpoints' => (int)db_value('SELECT COUNT(*) FROM `endpoints` WHERE `is_active` = 1', [], 0),
    'errors'    => (int)db_value("SELECT COUNT(*) FROM `messages` WHERE `status` = 'error' AND `created_at` >= ?",
                                 [date('Y-m-d 00:00:00', strtotime('-7 days'))], 0),
    'tokens'    => (int)db_value('SELECT COALESCE(SUM(`prompt_tokens` + `completion_tokens`),0) FROM `messages`', [], 0),
];

// Biểu đồ 14 ngày gần nhất.
$chartRows = db_all(
    "SELECT DATE(`created_at`) AS d, COUNT(*) AS c FROM `messages`
     WHERE `created_at` >= ? GROUP BY DATE(`created_at`) ORDER BY d",
    [date('Y-m-d 00:00:00', strtotime('-13 days'))]
);
$chartMap = [];
foreach ($chartRows as $row) {
    $chartMap[$row['d']] = (int)$row['c'];
}
$chart = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart[] = ['label' => date('d/m', strtotime($day)), 'value' => $chartMap[$day] ?? 0];
}
$chartMax = max(1, max(array_column($chart, 'value')));

$recentChats = db_all(
    'SELECT c.`id`, c.`title`, c.`msg_count`, c.`updated_at`, u.`username`, u.`avatar_emoji`
     FROM `conversations` c JOIN `users` u ON u.`id` = c.`user_id`
     WHERE c.`is_deleted` = 0 ORDER BY c.`updated_at` DESC LIMIT 8'
);

$topUsers = db_all(
    'SELECT u.`id`, u.`username`, u.`avatar_emoji`, COUNT(m.`id`) AS n
     FROM `users` u LEFT JOIN `messages` m ON m.`user_id` = u.`id`
     GROUP BY u.`id`, u.`username`, u.`avatar_emoji` ORDER BY n DESC LIMIT 6'
);

$endpoints = db_all('SELECT * FROM `endpoints` ORDER BY `is_default` DESC, `sort_order`, `id`');

admin_head('Tổng quan', 'index');
?>
<div class="stat-grid mb-2">
  <div class="stat">
    <div class="stat-icon">👥</div>
    <div class="stat-value"><?= number_format($stats['users'], 0, ',', '.') ?></div>
    <div class="stat-label">Người dùng</div>
    <div class="stat-sub">+<?= $stats['usersNew'] ?> hôm nay</div>
  </div>
  <div class="stat">
    <div class="stat-icon">💬</div>
    <div class="stat-value"><?= number_format($stats['convs'], 0, ',', '.') ?></div>
    <div class="stat-label">Cuộc trò chuyện</div>
    <div class="stat-sub"><?= number_format($stats['msgs'], 0, ',', '.') ?> tin nhắn</div>
  </div>
  <div class="stat">
    <div class="stat-icon">⚡</div>
    <div class="stat-value"><?= number_format($stats['msgsToday'], 0, ',', '.') ?></div>
    <div class="stat-label">Tin nhắn hôm nay</div>
    <div class="stat-sub"><?= number_format($stats['tokens'], 0, ',', '.') ?> token đã dùng</div>
  </div>
  <div class="stat">
    <div class="stat-icon">📎</div>
    <div class="stat-value"><?= number_format($stats['files'], 0, ',', '.') ?></div>
    <div class="stat-label">Tệp đính kèm</div>
    <div class="stat-sub"><?= e(fmt_bytes($stats['storage'])) ?> dung lượng</div>
  </div>
  <div class="stat">
    <div class="stat-icon"><?= $stats['errors'] > 0 ? '⚠️' : '✅' ?></div>
    <div class="stat-value"><?= number_format($stats['errors'], 0, ',', '.') ?></div>
    <div class="stat-label">Lỗi (7 ngày)</div>
    <div class="stat-sub"><?= $stats['endpoints'] ?> endpoint đang bật</div>
  </div>
</div>

<!-- Biểu đồ -->
<div class="table-card mb-2">
  <div class="table-card-head">
    <h2>📈 Lượng tin nhắn 14 ngày gần nhất</h2>
    <span class="text-muted text-sm">Giờ Việt Nam (UTC+7)</span>
  </div>
  <div style="padding:1.2rem">
    <div class="mini-chart">
      <?php foreach ($chart as $point): ?>
        <div class="mini-bar-wrap" title="<?= e($point['label']) ?>: <?= $point['value'] ?> tin nhắn">
          <div class="mini-bar-value"><?= $point['value'] ?: '' ?></div>
          <div class="mini-bar-col" style="height:<?= max(3, round($point['value'] / $chartMax * 100)) ?>%"></div>
          <div class="mini-bar-label"><?= e($point['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-2">
  <!-- Endpoint -->
  <div class="table-card">
    <div class="table-card-head">
      <h2>🔌 Endpoint AI</h2>
      <a class="btn btn-soft btn-sm" href="endpoints.php">Quản lý →</a>
    </div>
    <?php if (!$endpoints): ?>
      <div class="empty">
        <span class="empty-emoji">🔌</span>
        Chưa có endpoint nào.<br>
        <a class="btn btn-primary btn-sm mt-1" href="endpoint_edit.php">➕ Thêm endpoint đầu tiên</a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Tên</th><th>Loại</th><th>Mô hình</th><th>Trạng thái</th></tr></thead>
          <tbody>
          <?php foreach ($endpoints as $ep): ?>
            <tr>
              <td class="cell-strong">
                <a href="endpoint_edit.php?id=<?= (int)$ep['id'] ?>"><?= e($ep['name']) ?></a>
                <?php if ((int)$ep['is_default'] === 1): ?><span class="badge">mặc định</span><?php endif; ?>
              </td>
              <td class="cell-muted"><?= e($ep['api_type']) ?></td>
              <td class="cell-muted"><?= e(str_limit($ep['model'], 28)) ?></td>
              <td>
                <?php if ((int)$ep['is_active'] === 1): ?>
                  <span class="badge badge-ok">● Đang bật</span>
                <?php else: ?>
                  <span class="badge badge-muted">○ Tắt</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Người dùng tích cực -->
  <div class="table-card">
    <div class="table-card-head">
      <h2>🏆 Người dùng tích cực</h2>
      <a class="btn btn-soft btn-sm" href="users.php">Xem tất cả →</a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Thành viên</th><th class="num">Tin nhắn</th></tr></thead>
        <tbody>
        <?php foreach ($topUsers as $u): ?>
          <tr>
            <td><span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($u['avatar_emoji']) ?></span>
                <a href="users.php?q=<?= e(urlencode($u['username'])) ?>"><?= e($u['username']) ?></a></td>
            <td class="num"><?= number_format((int)$u['n'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Chat gần đây -->
<div class="table-card">
  <div class="table-card-head">
    <h2>🕒 Cuộc trò chuyện gần đây</h2>
    <a class="btn btn-soft btn-sm" href="chats.php">Xem toàn bộ lịch sử →</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tiêu đề</th><th>Người dùng</th><th class="num">Tin nhắn</th><th>Cập nhật</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recentChats as $chat): ?>
        <tr>
          <td class="cell-strong"><?= e(str_limit($chat['title'], 60)) ?></td>
          <td><span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($chat['avatar_emoji']) ?></span> <?= e($chat['username']) ?></td>
          <td class="num"><?= (int)$chat['msg_count'] ?></td>
          <td class="cell-muted" title="<?= e(fmt_datetime($chat['updated_at'])) ?>"><?= e(fmt_relative($chat['updated_at'])) ?></td>
          <td class="cell-actions"><a class="btn btn-ghost btn-sm" href="chat_view.php?id=<?= (int)$chat['id'] ?>">Xem</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentChats): ?>
        <tr><td colspan="5"><div class="empty"><span class="empty-emoji">🌱</span>Chưa có cuộc trò chuyện nào.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.mini-chart { display: flex; align-items: flex-end; gap: .4rem; height: 180px; }
.mini-bar-wrap { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; gap: .25rem; }
.mini-bar-value { font-size: .7rem; color: var(--text-muted); font-weight: 650; }
.mini-bar-col {
  width: 100%; max-width: 42px; border-radius: 8px 8px 3px 3px;
  background: linear-gradient(180deg, var(--brand), var(--brand-2));
  transition: filter .2s; animation: growBar .7s var(--ease-bounce) both;
}
.mini-bar-wrap:hover .mini-bar-col { filter: brightness(1.15); }
.mini-bar-label { font-size: .68rem; color: var(--text-muted); white-space: nowrap; }
@keyframes growBar { from { transform: scaleY(0); transform-origin: bottom; } to { transform: none; } }
</style>
<?php
admin_foot();
