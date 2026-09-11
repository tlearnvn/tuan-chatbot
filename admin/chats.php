<?php
/**
 * Xem toàn bộ lịch sử trò chuyện của mọi người dùng.
 */
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        foreach (db_all('SELECT * FROM `attachments` WHERE `conversation_id` = ?', [$id]) as $att) {
            delete_attachment_file($att);
        }
        db_run('DELETE FROM `conversations` WHERE `id` = ?', [$id]);
        log_activity('admin_chat_delete', 'Xoá cuộc trò chuyện #' . $id);
        flash('success', 'Đã xoá cuộc trò chuyện #' . $id . '.');
    }
    redirect('chats.php' . (!empty($_POST['back']) ? ('?' . $_POST['back']) : ''));
}

$perPage = 25;
$page    = max(1, (int)($_GET['p'] ?? 1));
$q       = trim($_GET['q'] ?? '');
$userId  = (int)($_GET['user'] ?? 0);
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');

$where  = ['c.`is_deleted` = 0'];
$params = [];

if ($q !== '') {
    $where[] = '(c.`title` LIKE ? OR EXISTS (
                    SELECT 1 FROM `messages` m WHERE m.`conversation_id` = c.`id` AND m.`content` LIKE ?))';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($userId > 0) {
    $where[]  = 'c.`user_id` = ?';
    $params[] = $userId;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[]  = 'c.`updated_at` >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[]  = 'c.`updated_at` <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$total = (int)db_value('SELECT COUNT(*) FROM `conversations` c WHERE ' . $whereSql, $params, 0);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all(
    'SELECT c.*, u.`username`, u.`avatar_emoji`, e.`name` AS endpoint_name
     FROM `conversations` c
     JOIN `users` u ON u.`id` = c.`user_id`
     LEFT JOIN `endpoints` e ON e.`id` = c.`endpoint_id`
     WHERE ' . $whereSql . '
     ORDER BY c.`updated_at` DESC
     LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
    $params
);

$users = db_all('SELECT `id`, `username` FROM `users` ORDER BY `username`');
$queryBase = array_filter(['q' => $q, 'user' => $userId ?: '', 'from' => $from, 'to' => $to]);

admin_head('Lịch sử chat', 'chats');
?>
<form class="filters" method="get">
  <div class="field field-grow">
    <label for="q">Tìm trong tiêu đề và nội dung</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Từ khoá…">
  </div>
  <div class="field">
    <label for="user">Người dùng</label>
    <select id="user" name="user">
      <option value="">Tất cả</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="from">Từ ngày</label>
    <input type="date" id="from" name="from" value="<?= e($from) ?>">
  </div>
  <div class="field">
    <label for="to">Đến ngày</label>
    <input type="date" id="to" name="to" value="<?= e($to) ?>">
  </div>
  <div class="field">
    <button type="submit" class="btn btn-primary">🔍 Lọc</button>
  </div>
  <?php if ($queryBase): ?>
    <div class="field"><a class="btn btn-ghost" href="chats.php">Xoá lọc</a></div>
  <?php endif; ?>
</form>

<div class="table-card">
  <div class="table-card-head">
    <h2>💬 <?= number_format($total, 0, ',', '.') ?> cuộc trò chuyện</h2>
    <span class="text-muted text-sm">Trang <?= $page ?>/<?= $pages ?> · giờ Việt Nam</span>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><span class="empty-emoji">🔍</span>Không tìm thấy cuộc trò chuyện nào khớp điều kiện.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>#</th><th>Tiêu đề</th><th>Người dùng</th><th>Endpoint</th>
              <th class="num">Tin nhắn</th><th>Tạo lúc</th><th>Cập nhật</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="cell-muted"><?= (int)$row['id'] ?></td>
            <td class="cell-strong">
              <a href="chat_view.php?id=<?= (int)$row['id'] ?>"><?= e(str_limit($row['title'], 64)) ?></a>
              <?php if ((int)$row['is_pinned'] === 1): ?> 📌<?php endif; ?>
            </td>
            <td>
              <span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($row['avatar_emoji']) ?></span>
              <a href="?user=<?= (int)$row['user_id'] ?>"><?= e($row['username']) ?></a>
            </td>
            <td class="cell-muted"><?= e($row['endpoint_name'] ?? '—') ?></td>
            <td class="num"><?= (int)$row['msg_count'] ?></td>
            <td class="cell-muted"><?= e(fmt_datetime($row['created_at'], 'H:i d/m/Y')) ?></td>
            <td class="cell-muted" title="<?= e(fmt_datetime($row['updated_at'])) ?>"><?= e(fmt_relative($row['updated_at'])) ?></td>
            <td class="cell-actions">
              <div class="row" style="gap:.25rem;justify-content:flex-end;flex-wrap:nowrap">
                <a class="btn btn-ghost btn-sm" href="chat_view.php?id=<?= (int)$row['id'] ?>">Xem</a>
                <form method="post" style="display:inline"
                      data-confirm="Xoá vĩnh viễn cuộc trò chuyện #<?= (int)$row['id'] ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="back" value="<?= e(http_build_query($queryBase)) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= admin_pager($total, $perPage, $page, $queryBase) ?>
  <?php endif; ?>
</div>
<?php
admin_foot();
