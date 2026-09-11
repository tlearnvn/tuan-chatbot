<?php
/**
 * Nhật ký hoạt động của hệ thống.
 */
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (($_POST['action'] ?? '') === 'clear') {
        $days = max(0, (int)($_POST['days'] ?? 30));
        if ($days === 0) {
            db_run('DELETE FROM `activity_log`');
            flash('success', 'Đã xoá toàn bộ nhật ký.');
        } else {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
            $count  = db_run('DELETE FROM `activity_log` WHERE `created_at` < ?', [$cutoff])->rowCount();
            flash('success', 'Đã xoá ' . number_format($count, 0, ',', '.') . ' dòng nhật ký cũ hơn ' . $days . ' ngày.');
        }
        log_activity('logs_clear', 'Dọn nhật ký (' . $days . ' ngày)');
    }
    redirect('logs.php');
}

$labels = [
    'login'                => ['🔓', 'Đăng nhập'],
    'login_failed'         => ['⚠️', 'Đăng nhập thất bại'],
    'logout'               => ['🔒', 'Đăng xuất'],
    'register'             => ['🎉', 'Tạo tài khoản'],
    'profile_update'       => ['✏️', 'Cập nhật hồ sơ'],
    'password_change'      => ['🔐', 'Đổi mật khẩu'],
    'clear_history'        => ['🗑️', 'Xoá lịch sử'],
    'delete_account'       => ['❌', 'Xoá tài khoản'],
    'upload'               => ['📎', 'Tải tệp lên'],
    'endpoint_create'      => ['➕', 'Tạo endpoint'],
    'endpoint_update'      => ['⚙️', 'Sửa endpoint'],
    'endpoint_delete'      => ['🗑️', 'Xoá endpoint'],
    'endpoint_test'        => ['🔍', 'Kiểm tra endpoint'],
    'document_create'      => ['📚', 'Thêm tài liệu'],
    'document_update'      => ['📝', 'Sửa tài liệu'],
    'document_delete'      => ['🗑️', 'Xoá tài liệu'],
    'settings_update'      => ['⚙️', 'Cập nhật cấu hình'],
    'settings_reset'       => ['♻️', 'Đặt lại cấu hình'],
    'admin_user_create'    => ['👤', 'Tạo người dùng'],
    'admin_user_role'      => ['👑', 'Đổi quyền'],
    'admin_user_status'    => ['🔏', 'Đổi trạng thái'],
    'admin_user_password'  => ['🔑', 'Đặt lại mật khẩu'],
    'admin_user_delete'    => ['❌', 'Xoá người dùng'],
    'admin_chat_delete'    => ['🗑️', 'Xoá cuộc trò chuyện'],
    'logs_clear'           => ['♻️', 'Dọn nhật ký'],
    'install'              => ['🚀', 'Cài đặt hệ thống'],
    'version_bump'         => ['🏷️', 'Cập nhật phiên bản'],
];

$perPage = 50;
$page    = max(1, (int)($_GET['p'] ?? 1));
$action  = trim($_GET['action'] ?? '');
$q       = trim($_GET['q'] ?? '');

$where  = ['1 = 1'];
$params = [];
if ($action !== '') {
    $where[]  = 'l.`action` = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where[]  = '(l.`detail` LIKE ? OR l.`ip` LIKE ? OR u.`username` LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

$total  = (int)db_value('SELECT COUNT(*) FROM `activity_log` l
                         LEFT JOIN `users` u ON u.`id` = l.`user_id` WHERE ' . $whereSql, $params, 0);
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all(
    'SELECT l.*, u.`username`, u.`avatar_emoji` FROM `activity_log` l
     LEFT JOIN `users` u ON u.`id` = l.`user_id`
     WHERE ' . $whereSql . '
     ORDER BY l.`id` DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
    $params
);

$actionCounts = db_all('SELECT `action`, COUNT(*) AS n FROM `activity_log` GROUP BY `action` ORDER BY n DESC');

admin_head('Nhật ký', 'logs');
?>
<form class="filters" method="get">
  <div class="field field-grow">
    <label for="q">Tìm trong chi tiết, IP hoặc tên người dùng</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Từ khoá…">
  </div>
  <div class="field">
    <label for="action">Loại hoạt động</label>
    <select id="action" name="action">
      <option value="">Tất cả</option>
      <?php foreach ($actionCounts as $item): ?>
        <option value="<?= e($item['action']) ?>" <?= $action === $item['action'] ? 'selected' : '' ?>>
          <?= e(($labels[$item['action']][1] ?? $item['action']) . ' (' . $item['n'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><button type="submit" class="btn btn-primary">🔍 Lọc</button></div>
  <?php if ($q !== '' || $action !== ''): ?>
    <div class="field"><a class="btn btn-ghost" href="logs.php">Xoá lọc</a></div>
  <?php endif; ?>
</form>

<div class="table-card">
  <div class="table-card-head">
    <h2>📜 <?= number_format($total, 0, ',', '.') ?> dòng nhật ký</h2>
    <form method="post" class="row" style="gap:.4rem" data-confirm="Xoá các dòng nhật ký cũ?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear">
      <select name="days" style="width:auto;padding:.4em 2.2em .4em .8em;font-size:.86rem">
        <option value="90">Cũ hơn 90 ngày</option>
        <option value="30" selected>Cũ hơn 30 ngày</option>
        <option value="7">Cũ hơn 7 ngày</option>
        <option value="0">Toàn bộ</option>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">♻️ Dọn nhật ký</button>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><span class="empty-emoji">🌙</span>Chưa có dòng nhật ký nào khớp điều kiện.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>#</th><th>Hoạt động</th><th>Người dùng</th><th>Chi tiết</th><th>IP</th><th>Thời điểm</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php $meta = $labels[$row['action']] ?? ['•', $row['action']]; ?>
          <tr>
            <td class="cell-muted"><?= (int)$row['id'] ?></td>
            <td class="cell-strong"><?= $meta[0] ?> <?= e($meta[1]) ?></td>
            <td>
              <?php if ($row['username']): ?>
                <span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($row['avatar_emoji']) ?></span>
                <?= e($row['username']) ?>
              <?php else: ?>
                <span class="cell-muted">khách</span>
              <?php endif; ?>
            </td>
            <td class="cell-muted" title="<?= e($row['detail']) ?>"><?= e(str_limit($row['detail'], 80)) ?></td>
            <td class="cell-muted"><code class="inline-code"><?= e($row['ip']) ?></code></td>
            <td class="cell-muted" title="<?= e($row['user_agent']) ?>"><?= e(fmt_datetime($row['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= admin_pager($total, $perPage, $page, array_filter(['q' => $q, 'action' => $action])) ?>
  <?php endif; ?>
</div>
<?php
admin_foot();
