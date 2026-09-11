<?php
/**
 * Quản lý người dùng.
 */
require_once __DIR__ . '/_init.php';

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $target = $id ? db_one('SELECT * FROM `users` WHERE `id` = ?', [$id]) : null;

    /** Không cho phép hạ quyền/khoá/xoá quản trị viên cuối cùng. */
    $adminCount = (int)db_value("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `status` = 'active'", [], 0);

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $errors   = array_merge(validate_username($username), validate_password($password));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ.';
        }
        if (!$errors && db_value('SELECT COUNT(*) FROM `users` WHERE `username` = ? OR `email` = ?',
                [$username, $email], 0)) {
            $errors[] = 'Tên đăng nhập hoặc email đã tồn tại.';
        }
        if ($errors) {
            foreach ($errors as $error) { flash('error', $error); }
        } else {
            db_insert('users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name'     => trim($_POST['full_name'] ?? ''),
                'avatar_emoji'  => random_avatar_emoji(),
                'avatar_color'  => setting('theme_primary'),
                'role'          => $role,
                'status'        => 'active',
                'daily_limit'   => max(0, (int)($_POST['daily_limit'] ?? 0)),
                'created_at'    => now_vn(),
                'updated_at'    => now_vn(),
            ]);
            log_activity('admin_user_create', 'Tạo tài khoản: ' . $username . ' (' . $role . ')');
            flash('success', 'Đã tạo tài khoản "' . $username . '"! 🎉');
        }

    } elseif ($target && $action === 'role') {
        $newRole = $target['role'] === 'admin' ? 'user' : 'admin';
        if ($newRole === 'user' && $target['role'] === 'admin' && $adminCount <= 1) {
            flash('error', 'Không thể hạ quyền quản trị viên cuối cùng.');
        } else {
            db_update('users', ['role' => $newRole, 'updated_at' => now_vn()], '`id` = ?', [$id]);
            log_activity('admin_user_role', 'Đổi quyền ' . $target['username'] . ' → ' . $newRole);
            flash('success', 'Đã đổi quyền của "' . $target['username'] . '" thành ' . $newRole . '.');
        }

    } elseif ($target && $action === 'status') {
        if ((int)$target['id'] === (int)$me['id']) {
            flash('error', 'Bạn không thể tự khoá tài khoản của mình.');
        } elseif ($target['status'] === 'active' && $target['role'] === 'admin' && $adminCount <= 1) {
            flash('error', 'Không thể khoá quản trị viên cuối cùng.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'locked' : 'active';
            db_update('users', ['status' => $newStatus, 'updated_at' => now_vn()], '`id` = ?', [$id]);
            if ($newStatus === 'locked') {
                db_run('DELETE FROM `remember_tokens` WHERE `user_id` = ?', [$id]);
            }
            log_activity('admin_user_status', $target['username'] . ' → ' . $newStatus);
            flash('success', 'Đã ' . ($newStatus === 'locked' ? 'khoá' : 'mở khoá') . ' tài khoản "' . $target['username'] . '".');
        }

    } elseif ($target && $action === 'limit') {
        $limit = max(0, (int)($_POST['daily_limit'] ?? 0));
        db_update('users', ['daily_limit' => $limit, 'updated_at' => now_vn()], '`id` = ?', [$id]);
        flash('success', 'Đã đặt hạn mức ' . ($limit ?: 'không giới hạn') . ' cho "' . $target['username'] . '".');

    } elseif ($target && $action === 'reset_password') {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $errors = validate_password($newPassword);
        if ($errors) {
            foreach ($errors as $error) { flash('error', $error); }
        } else {
            db_update('users', [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'updated_at'    => now_vn(),
            ], '`id` = ?', [$id]);
            db_run('DELETE FROM `remember_tokens` WHERE `user_id` = ?', [$id]);
            log_activity('admin_user_password', 'Đặt lại mật khẩu cho ' . $target['username']);
            flash('success', 'Đã đặt lại mật khẩu cho "' . $target['username'] . '".');
        }

    } elseif ($target && $action === 'delete') {
        if ((int)$target['id'] === (int)$me['id']) {
            flash('error', 'Bạn không thể tự xoá tài khoản đang dùng.');
        } elseif ($target['role'] === 'admin' && $adminCount <= 1) {
            flash('error', 'Không thể xoá quản trị viên cuối cùng.');
        } else {
            foreach (db_all('SELECT * FROM `attachments` WHERE `user_id` = ?', [$id]) as $att) {
                delete_attachment_file($att);
            }
            db_run('DELETE FROM `users` WHERE `id` = ?', [$id]);
            log_activity('admin_user_delete', 'Xoá tài khoản: ' . $target['username']);
            flash('success', 'Đã xoá tài khoản "' . $target['username'] . '" cùng toàn bộ dữ liệu.');
        }
    }
    redirect('users.php');
}

$perPage = 25;
$page    = max(1, (int)($_GET['p'] ?? 1));
$q       = trim($_GET['q'] ?? '');
$role    = $_GET['role'] ?? '';

$where  = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[]  = '(`username` LIKE ? OR `email` LIKE ? OR `full_name` LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($role, ['user', 'admin'], true)) {
    $where[]  = '`role` = ?';
    $params[] = $role;
}
$whereSql = implode(' AND ', $where);

$total  = (int)db_value('SELECT COUNT(*) FROM `users` WHERE ' . $whereSql, $params, 0);
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all(
    'SELECT u.*,
            (SELECT COUNT(*) FROM `conversations` c WHERE c.`user_id` = u.`id` AND c.`is_deleted` = 0) AS conv_count,
            (SELECT COUNT(*) FROM `messages` m WHERE m.`user_id` = u.`id`) AS msg_count
     FROM `users` u WHERE ' . $whereSql . '
     ORDER BY u.`created_at` DESC
     LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
    $params
);

admin_head('Người dùng', 'users');
?>
<form class="filters" method="get">
  <div class="field field-grow">
    <label for="q">Tìm theo tên đăng nhập, email hoặc họ tên</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Từ khoá…">
  </div>
  <div class="field">
    <label for="role">Quyền</label>
    <select id="role" name="role">
      <option value="">Tất cả</option>
      <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Quản trị viên</option>
      <option value="user"  <?= $role === 'user' ? 'selected' : '' ?>>Thành viên</option>
    </select>
  </div>
  <div class="field"><button type="submit" class="btn btn-primary">🔍 Lọc</button></div>
  <div class="field"><button type="button" class="btn btn-soft" data-open="#createUser">➕ Thêm người dùng</button></div>
</form>

<div id="createUser" class="form-section hidden">
  <h3>➕ Tạo tài khoản mới</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <div class="field">
        <label for="c_username">Tên đăng nhập *</label>
        <input type="text" id="c_username" name="username" required pattern="[a-zA-Z0-9_.]{3,50}">
      </div>
      <div class="field">
        <label for="c_email">Email *</label>
        <input type="email" id="c_email" name="email" required>
      </div>
      <div class="field">
        <label for="c_full">Họ và tên</label>
        <input type="text" id="c_full" name="full_name">
      </div>
      <div class="field">
        <label for="c_pass">Mật khẩu *</label>
        <div class="password-wrap">
          <input type="password" id="c_pass" name="password" required data-strength-input>
          <button type="button" class="password-toggle" data-toggle-password="#c_pass">👁️</button>
        </div>
        <div class="strength" data-strength-bar><i></i></div>
        <span class="hint" data-strength-text>Tối thiểu 8 ký tự, có chữ và số.</span>
      </div>
      <div class="field">
        <label for="c_role">Quyền</label>
        <select id="c_role" name="role">
          <option value="user">Thành viên</option>
          <option value="admin">Quản trị viên</option>
        </select>
      </div>
      <div class="field">
        <label for="c_limit">Hạn mức tin nhắn / ngày</label>
        <input type="number" id="c_limit" name="daily_limit" value="0" min="0">
        <span class="hint">0 = không giới hạn.</span>
      </div>
    </div>
    <div class="row row-end">
      <button type="submit" class="btn btn-primary">Tạo tài khoản</button>
    </div>
  </form>
</div>

<div class="table-card">
  <div class="table-card-head">
    <h2>👥 <?= number_format($total, 0, ',', '.') ?> người dùng</h2>
    <span class="text-muted text-sm">Trang <?= $page ?>/<?= $pages ?></span>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><span class="empty-emoji">🔍</span>Không tìm thấy người dùng nào.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Thành viên</th><th>Quyền</th><th>Trạng thái</th>
              <th class="num">Chat</th><th class="num">Tin nhắn</th><th class="num">Hạn mức</th>
              <th>Đăng nhập gần nhất</th><th>Tham gia</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <div class="row" style="gap:.5rem;flex-wrap:nowrap">
                <span class="avatar avatar-sm"><?= e($row['avatar_emoji']) ?></span>
                <span>
                  <span class="cell-strong"><?= e($row['username']) ?></span>
                  <?php if ((int)$row['id'] === (int)$me['id']): ?><span class="badge">bạn</span><?php endif; ?>
                  <div class="cell-muted"><?= e($row['email']) ?></div>
                  <?php if ($row['full_name']): ?><div class="cell-muted"><?= e($row['full_name']) ?></div><?php endif; ?>
                </span>
              </div>
            </td>
            <td>
              <?php if ($row['role'] === 'admin'): ?>
                <span class="badge">👑 Quản trị</span>
              <?php else: ?>
                <span class="badge badge-muted">Thành viên</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['status'] === 'active'): ?>
                <span class="badge badge-ok">● Hoạt động</span>
              <?php else: ?>
                <span class="badge badge-danger">🔒 Đã khoá</span>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int)$row['conv_count'] ?></td>
            <td class="num"><?= number_format((int)$row['msg_count'], 0, ',', '.') ?></td>
            <td class="num"><?= (int)$row['daily_limit'] ?: '∞' ?></td>
            <td class="cell-muted"><?= $row['last_login_at'] ? e(fmt_relative($row['last_login_at'])) : '—' ?></td>
            <td class="cell-muted"><?= e(fmt_datetime($row['created_at'], 'd/m/Y')) ?></td>
            <td class="cell-actions">
              <details class="row-menu">
                <summary class="btn btn-ghost btn-sm">⋯</summary>
                <div class="row-menu-body">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="action" value="role">
                    <button type="submit" class="menu-item">
                      <?= $row['role'] === 'admin' ? '⬇️ Hạ thành Thành viên' : '⬆️ Nâng thành Quản trị' ?>
                    </button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="action" value="status">
                    <button type="submit" class="menu-item">
                      <?= $row['status'] === 'active' ? '🔒 Khoá tài khoản' : '🔓 Mở khoá' ?>
                    </button>
                  </form>
                  <form method="post" class="menu-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="action" value="limit">
                    <label class="text-sm">Hạn mức/ngày
                      <input type="number" name="daily_limit" value="<?= (int)$row['daily_limit'] ?>" min="0">
                    </label>
                    <button type="submit" class="menu-item">💾 Lưu hạn mức</button>
                  </form>
                  <form method="post" class="menu-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <label class="text-sm">Mật khẩu mới
                      <input type="text" name="new_password" placeholder="Ít nhất 8 ký tự">
                    </label>
                    <button type="submit" class="menu-item">🔑 Đặt lại mật khẩu</button>
                  </form>
                  <form method="post" data-confirm="Xoá vĩnh viễn tài khoản &quot;<?= e($row['username']) ?>&quot; và toàn bộ dữ liệu?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="menu-item is-danger">🗑️ Xoá tài khoản</button>
                  </form>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= admin_pager($total, $perPage, $page, array_filter(['q' => $q, 'role' => $role])) ?>
  <?php endif; ?>
</div>

<style>
.row-menu { position: relative; display: inline-block; }
.row-menu > summary { list-style: none; cursor: pointer; }
.row-menu > summary::-webkit-details-marker { display: none; }
.row-menu-body {
  position: absolute; right: 0; top: calc(100% + 6px); z-index: 30;
  width: 250px; padding: .5rem; text-align: left;
  background: var(--surface-solid); border: 1px solid var(--border);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
  animation: cardIn .22s var(--ease-bounce) both;
}
.menu-item {
  display: block; width: 100%; text-align: left; padding: .45rem .6rem;
  border: 0; background: none; border-radius: 9px; cursor: pointer;
  font: inherit; font-size: .87rem; color: var(--text-soft);
}
.menu-item:hover { background: color-mix(in srgb, var(--brand) 11%, transparent); color: var(--brand); }
.menu-item.is-danger:hover { background: color-mix(in srgb, var(--danger) 12%, transparent); color: var(--danger); }
.menu-form { padding: .35rem .6rem; border-top: 1px solid var(--border); margin-top: .25rem; }
.menu-form label { display: block; color: var(--text-muted); font-size: .78rem; }
.menu-form input { margin-top: .2rem; padding: .4em .6em; font-size: .85rem; }
.menu-form .menu-item { padding-left: 0; padding-right: 0; }
</style>
<?php
admin_foot();
