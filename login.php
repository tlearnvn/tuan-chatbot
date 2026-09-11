<?php
/**
 * Trang đăng nhập.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect(base_url() . '/index.php');
}

$errors   = [];
$username = '';
$next     = $_GET['next'] ?? ($_POST['next'] ?? '');
// Chỉ cho phép chuyển hướng nội bộ.
if ($next !== '' && (strpos($next, '//') === 0 || preg_match('#^[a-z]+://#i', $next))) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($username === '' || $password === '') {
        $errors[] = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    } elseif (!login_throttle_check($username)) {
        $errors[] = 'Bạn đã thử sai quá nhiều lần. Vui lòng chờ 15 phút rồi thử lại.';
    } else {
        $user = db_one(
            'SELECT * FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$username, $username]
        );
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
            log_activity('login_failed', 'Thử đăng nhập với: ' . str_limit($username, 60), null);
            usleep(400000); // làm chậm dò mật khẩu
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'Tài khoản của bạn đang bị khoá. Vui lòng liên hệ quản trị viên.';
        } else {
            // Nâng cấp thuật toán băm nếu cần.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_run('UPDATE `users` SET `password_hash` = ? WHERE `id` = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            auth_login_user($user['id'], $remember);
            flash('success', 'Chào mừng trở lại, ' . ($user['full_name'] ?: $user['username']) . '! 🎉');
            redirect($next !== '' ? $next : (base_url() . '/index.php'));
        }
    }
}

layout_head('Đăng nhập');
layout_navbar('login');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-head">
      <span class="auth-emoji"><?= e(setting('brand_emoji')) ?></span>
      <h1>Chào mừng trở lại!</h1>
      <p>Đăng nhập để tiếp tục trò chuyện cùng <?= e(site_name()) ?></p>
    </div>

    <?php foreach ($errors as $error): ?>
      <div class="flash flash-error mb-2"><span class="flash-icon">😿</span><span><?= e($error) ?></span></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="field">
        <label for="username">Tên đăng nhập hoặc email</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>"
               autocomplete="username" required autofocus placeholder="vd: tuannguyen hoặc tuan@email.com">
      </div>

      <div class="field">
        <label for="password">Mật khẩu</label>
        <div class="password-wrap">
          <input type="password" id="password" name="password" autocomplete="current-password"
                 required placeholder="••••••••">
          <button type="button" class="password-toggle" data-toggle-password="#password" aria-label="Hiện mật khẩu">👁️</button>
        </div>
      </div>

      <label class="switch mb-2">
        <input type="checkbox" name="remember" value="1" checked>
        <span class="switch-track"></span>
        <span class="text-sm">Ghi nhớ đăng nhập trong 30 ngày</span>
      </label>

      <button type="submit" class="btn btn-primary btn-block btn-lg">🚀 Đăng nhập</button>
    </form>

    <?php if (setting_bool('allow_register')): ?>
      <div class="auth-divider">hoặc</div>
      <p class="auth-alt">
        Chưa có tài khoản? <a href="<?= e(base_url()) ?>/register.php"><strong>Đăng ký miễn phí →</strong></a>
      </p>
    <?php endif; ?>
  </div>
</div>
<?php
layout_foot();
