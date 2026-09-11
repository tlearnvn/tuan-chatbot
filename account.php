<?php
/**
 * Quản lý tài khoản cá nhân.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

$avatarPool = ['🐣', '🦊', '🐼', '🐧', '🦄', '🐳', '🐝', '🦋', '🌻', '🍀', '🚀', '⭐', '🎈', '🍩', '🌈',
               '🎨', '🎧', '📚', '🧠', '🐰', '🐨', '🦁', '🐯', '🌺', '🔥'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    // ---- Cập nhật hồ sơ ----------------------------------------------------
    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $emoji    = trim($_POST['avatar_emoji'] ?? $user['avatar_emoji']);
        $theme    = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $errors   = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Địa chỉ email không hợp lệ.';
        }
        if (mb_strlen($fullName) > 120) {
            $errors[] = 'Họ tên quá dài (tối đa 120 ký tự).';
        }
        if (!in_array($emoji, $avatarPool, true)) {
            $emoji = $user['avatar_emoji'];
        }
        if (!$errors) {
            $dup = db_value('SELECT COUNT(*) FROM `users` WHERE `email` = ? AND `id` <> ?',
                [$email, $user['id']], 0);
            if ($dup) {
                $errors[] = 'Email này đã được tài khoản khác sử dụng.';
            }
        }
        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
        } else {
            db_update('users', [
                'full_name'    => $fullName,
                'email'        => $email,
                'avatar_emoji' => $emoji,
                'theme'        => $theme,
                'updated_at'   => now_vn(),
            ], '`id` = ?', [$user['id']]);
            log_activity('profile_update', 'Cập nhật hồ sơ cá nhân');
            flash('success', 'Đã lưu thông tin tài khoản! ✨');
        }
        redirect(base_url() . '/account.php');
    }

    // ---- Đổi mật khẩu ------------------------------------------------------
    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $errors  = [];

        if (!password_verify($current, $user['password_hash'])) {
            $errors[] = 'Mật khẩu hiện tại không đúng.';
        }
        $errors = array_merge($errors, validate_password($new));
        if ($new !== $confirm) {
            $errors[] = 'Mật khẩu mới nhập lại không khớp.';
        }
        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
        } else {
            db_update('users', [
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'updated_at'    => now_vn(),
            ], '`id` = ?', [$user['id']]);
            // Huỷ mọi phiên "ghi nhớ đăng nhập" cũ.
            db_run('DELETE FROM `remember_tokens` WHERE `user_id` = ?', [$user['id']]);
            log_activity('password_change', 'Đổi mật khẩu');
            flash('success', 'Đổi mật khẩu thành công! 🔐 Các thiết bị khác đã bị đăng xuất.');
        }
        redirect(base_url() . '/account.php');
    }

    // ---- Xoá toàn bộ lịch sử trò chuyện ------------------------------------
    if ($action === 'clear_history') {
        $convIds = db_all('SELECT `id` FROM `conversations` WHERE `user_id` = ?', [$user['id']]);
        foreach (db_all('SELECT * FROM `attachments` WHERE `user_id` = ?', [$user['id']]) as $att) {
            delete_attachment_file($att);
        }
        db_run('DELETE FROM `attachments` WHERE `user_id` = ?', [$user['id']]);
        db_run('DELETE FROM `conversations` WHERE `user_id` = ?', [$user['id']]);
        log_activity('clear_history', 'Xoá ' . count($convIds) . ' cuộc trò chuyện');
        flash('success', 'Đã xoá toàn bộ lịch sử trò chuyện của bạn.');
        redirect(base_url() . '/account.php');
    }

    // ---- Xoá tài khoản -----------------------------------------------------
    if ($action === 'delete_account') {
        $password = (string)($_POST['password'] ?? '');
        if (!password_verify($password, $user['password_hash'])) {
            flash('error', 'Mật khẩu không đúng, chưa xoá tài khoản.');
            redirect(base_url() . '/account.php');
        }
        $adminCount = (int)db_value("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'", [], 0);
        if ($user['role'] === 'admin' && $adminCount <= 1) {
            flash('error', 'Bạn là quản trị viên duy nhất nên không thể tự xoá tài khoản.');
            redirect(base_url() . '/account.php');
        }
        foreach (db_all('SELECT * FROM `attachments` WHERE `user_id` = ?', [$user['id']]) as $att) {
            delete_attachment_file($att);
        }
        db_run('DELETE FROM `users` WHERE `id` = ?', [$user['id']]);
        log_activity('delete_account', 'Người dùng tự xoá tài khoản: ' . $user['username'], null);
        auth_logout();
        session_start();
        flash('info', 'Tài khoản của bạn đã được xoá vĩnh viễn. Cảm ơn bạn đã đồng hành! 👋');
        redirect(base_url() . '/login.php');
    }
}

// ---- Thống kê ---------------------------------------------------------------
$stats = [
    'conversations' => (int)db_value('SELECT COUNT(*) FROM `conversations` WHERE `user_id` = ? AND `is_deleted` = 0', [$user['id']], 0),
    'messages'      => (int)db_value('SELECT COUNT(*) FROM `messages` WHERE `user_id` = ?', [$user['id']], 0),
    'files'         => (int)db_value('SELECT COUNT(*) FROM `attachments` WHERE `user_id` = ?', [$user['id']], 0),
    'storage'       => (int)db_value('SELECT COALESCE(SUM(`size`),0) FROM `attachments` WHERE `user_id` = ?', [$user['id']], 0),
];
$recentLogs = db_all('SELECT * FROM `activity_log` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 12', [$user['id']]);

$actionLabels = [
    'login' => '🔓 Đăng nhập', 'logout' => '🔒 Đăng xuất', 'register' => '🎉 Tạo tài khoản',
    'profile_update' => '✏️ Cập nhật hồ sơ', 'password_change' => '🔐 Đổi mật khẩu',
    'clear_history' => '🗑️ Xoá lịch sử', 'chat' => '💬 Trò chuyện', 'upload' => '📎 Tải tệp lên',
    'login_failed' => '⚠️ Đăng nhập thất bại',
];

layout_head('Tài khoản của tôi');
layout_navbar('account');
?>
<div class="page page-narrow">
  <div class="page-head">
    <h1><span class="avatar" style="width:46px;height:46px;font-size:1.5rem"><?= e($user['avatar_emoji']) ?></span>
        Tài khoản của tôi</h1>
    <p>Xin chào <strong><?= e($user['full_name'] ?: $user['username']) ?></strong> — tham gia từ <?= e(fmt_datetime($user['created_at'], 'd/m/Y')) ?></p>
  </div>

  <!-- Thống kê -->
  <div class="grid grid-3 mb-2">
    <div class="card" style="padding:1.1rem">
      <div class="text-muted text-sm">💬 Cuộc trò chuyện</div>
      <div style="font-size:1.9rem;font-weight:800"><?= number_format($stats['conversations'], 0, ',', '.') ?></div>
    </div>
    <div class="card" style="padding:1.1rem">
      <div class="text-muted text-sm">✉️ Tin nhắn</div>
      <div style="font-size:1.9rem;font-weight:800"><?= number_format($stats['messages'], 0, ',', '.') ?></div>
    </div>
    <div class="card" style="padding:1.1rem">
      <div class="text-muted text-sm">📎 Tệp (<?= e(fmt_bytes($stats['storage'])) ?>)</div>
      <div style="font-size:1.9rem;font-weight:800"><?= number_format($stats['files'], 0, ',', '.') ?></div>
    </div>
  </div>

  <div class="stack">
    <!-- Hồ sơ -->
    <div class="card">
      <h2 class="card-title">👤 Thông tin cá nhân</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">

        <div class="field">
          <span class="label">Ảnh đại diện</span>
          <div class="row" style="gap:.4rem">
            <?php foreach ($avatarPool as $emoji): ?>
              <label class="avatar-pick<?= $emoji === $user['avatar_emoji'] ? ' is-selected' : '' ?>">
                <input type="radio" name="avatar_emoji" value="<?= e($emoji) ?>"
                       <?= $emoji === $user['avatar_emoji'] ? 'checked' : '' ?>>
                <span><?= e($emoji) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label for="full_name">Họ và tên</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>">
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label>Tên đăng nhập</label>
            <input type="text" value="<?= e($user['username']) ?>" disabled>
            <span class="hint">Tên đăng nhập không thể thay đổi.</span>
          </div>
          <div class="field">
            <label for="theme">Giao diện mặc định</label>
            <select id="theme" name="theme">
              <option value="light" <?= $user['theme'] === 'light' ? 'selected' : '' ?>>☀️ Sáng</option>
              <option value="dark"  <?= $user['theme'] === 'dark' ? 'selected' : '' ?>>🌙 Tối</option>
            </select>
          </div>
        </div>

        <div class="row row-end">
          <button type="submit" class="btn btn-primary">💾 Lưu thay đổi</button>
        </div>
      </form>
    </div>

    <!-- Mật khẩu -->
    <div class="card">
      <h2 class="card-title">🔐 Đổi mật khẩu</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">

        <div class="field">
          <label for="current_password">Mật khẩu hiện tại</label>
          <div class="password-wrap">
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <button type="button" class="password-toggle" data-toggle-password="#current_password">👁️</button>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label for="new_password">Mật khẩu mới</label>
            <div class="password-wrap">
              <input type="password" id="new_password" name="new_password" required
                     autocomplete="new-password" data-strength-input>
              <button type="button" class="password-toggle" data-toggle-password="#new_password">👁️</button>
            </div>
            <div class="strength" data-strength-bar><i></i></div>
            <span class="hint" data-strength-text>Tối thiểu 8 ký tự, có chữ và số.</span>
          </div>
          <div class="field">
            <label for="confirm_password">Nhập lại mật khẩu mới</label>
            <div class="password-wrap">
              <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
              <button type="button" class="password-toggle" data-toggle-password="#confirm_password">👁️</button>
            </div>
          </div>
        </div>

        <div class="row row-end">
          <button type="submit" class="btn btn-primary">🔄 Đổi mật khẩu</button>
        </div>
      </form>
    </div>

    <!-- Hoạt động -->
    <div class="card">
      <h2 class="card-title">📜 Hoạt động gần đây</h2>
      <?php if (!$recentLogs): ?>
        <div class="empty"><span class="empty-emoji">🌙</span>Chưa có hoạt động nào được ghi nhận.</div>
      <?php else: ?>
        <div class="log-list">
          <?php foreach ($recentLogs as $log): ?>
            <div class="log-row">
              <span class="log-action"><?= e($actionLabels[$log['action']] ?? ('• ' . $log['action'])) ?></span>
              <span class="log-detail text-muted text-sm"><?= e(str_limit($log['detail'], 70)) ?></span>
              <span class="log-time text-muted text-sm" title="<?= e(fmt_datetime($log['created_at'])) ?>">
                <?= e(fmt_relative($log['created_at'])) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Vùng nguy hiểm -->
    <div class="card" style="border-color:color-mix(in srgb, var(--danger) 35%, transparent)">
      <h2 class="card-title">⚠️ Vùng nguy hiểm</h2>

      <div class="danger-row">
        <div>
          <strong>Xoá toàn bộ lịch sử trò chuyện</strong>
          <p class="text-muted text-sm mb-0">Mọi cuộc trò chuyện và tệp đính kèm của bạn sẽ bị xoá vĩnh viễn.</p>
        </div>
        <form method="post" data-confirm="Xoá toàn bộ lịch sử trò chuyện? Thao tác này không thể hoàn tác.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_history">
          <button type="submit" class="btn btn-ghost btn-sm">🗑️ Xoá lịch sử</button>
        </form>
      </div>

      <hr style="border:0;border-top:1px solid var(--border);margin:1.1rem 0">

      <div class="danger-row">
        <div>
          <strong>Xoá tài khoản vĩnh viễn</strong>
          <p class="text-muted text-sm mb-0">Toàn bộ dữ liệu của bạn sẽ biến mất và không thể khôi phục.</p>
        </div>
        <button type="button" class="btn btn-danger btn-sm" data-open="#delete-account">Xoá tài khoản</button>
      </div>

      <div id="delete-account" class="hidden mt-2">
        <form method="post" data-confirm="Bạn CHẮC CHẮN muốn xoá tài khoản? Mọi dữ liệu sẽ mất vĩnh viễn.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_account">
          <div class="field">
            <label for="del_password">Nhập mật khẩu để xác nhận</label>
            <input type="password" id="del_password" name="password" required placeholder="Mật khẩu của bạn">
          </div>
          <div class="row row-end">
            <button type="submit" class="btn btn-danger">Tôi hiểu, xoá tài khoản của tôi</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
.avatar-pick { cursor: pointer; }
.avatar-pick input { position: absolute; opacity: 0; width: 0; height: 0; }
.avatar-pick span {
  display: grid; place-items: center; width: 42px; height: 42px;
  border-radius: 13px; font-size: 1.25rem; background: var(--surface-2);
  border: 2px solid transparent;
  transition: transform .22s var(--ease-bounce), border-color .2s, background .2s;
}
.avatar-pick:hover span { transform: translateY(-3px) scale(1.08); }
.avatar-pick input:checked + span {
  border-color: var(--brand);
  background: color-mix(in srgb, var(--brand) 16%, transparent);
  transform: scale(1.06);
}
.log-list { display: flex; flex-direction: column; }
.log-row {
  display: grid; grid-template-columns: minmax(140px, auto) 1fr auto;
  gap: .8rem; align-items: center; padding: .55rem 0;
  border-bottom: 1px solid var(--border);
}
.log-row:last-child { border-bottom: 0; }
.log-action { font-weight: 620; font-size: .9rem; }
.log-detail { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.danger-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
@media (max-width: 620px) {
  .log-row { grid-template-columns: 1fr; gap: .15rem; }
  .log-time { justify-self: start; }
}
</style>
<?php
layout_foot();
