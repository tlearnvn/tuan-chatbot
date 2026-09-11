<?php
/**
 * Trang đăng ký tài khoản.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect(base_url() . '/index.php');
}

if (!setting_bool('allow_register')) {
    layout_head('Đăng ký tạm khoá');
    layout_navbar();
    echo '<div class="auth-wrap"><div class="auth-card text-center">'
       . '<span class="auth-emoji">🔒</span>'
       . '<h1>Đăng ký đang tạm khoá</h1>'
       . '<p class="text-muted">Quản trị viên hiện không mở đăng ký tài khoản mới. '
       . 'Vui lòng liên hệ quản trị viên để được cấp tài khoản.</p>'
       . '<a class="btn btn-primary mt-2" href="' . e(base_url()) . '/login.php">Về trang đăng nhập</a>'
       . '</div></div>';
    layout_foot();
    exit;
}

$errors = [];
$form   = ['username' => '', 'email' => '', 'full_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $form['username']  = trim($_POST['username'] ?? '');
    $form['email']     = trim($_POST['email'] ?? '');
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $password          = (string)($_POST['password'] ?? '');
    $password2         = (string)($_POST['password2'] ?? '');

    $errors = array_merge($errors, validate_username($form['username']));

    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Địa chỉ email không hợp lệ.';
    }
    if (mb_strlen($form['full_name']) > 120) {
        $errors[] = 'Họ tên quá dài.';
    }
    $errors = array_merge($errors, validate_password($password));
    if ($password !== $password2) {
        $errors[] = 'Hai lần nhập mật khẩu không khớp nhau.';
    }

    if (!$errors) {
        $taken = db_one('SELECT `username`, `email` FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$form['username'], $form['email']]);
        if ($taken) {
            if (strcasecmp($taken['username'], $form['username']) === 0) {
                $errors[] = 'Tên đăng nhập này đã có người sử dụng.';
            } else {
                $errors[] = 'Email này đã được đăng ký.';
            }
        }
    }

    if (!$errors) {
        // Người dùng đầu tiên của hệ thống mặc định là quản trị viên.
        $isFirst = (int)db_value('SELECT COUNT(*) FROM `users`', [], 0) === 0;

        $userId = db_insert('users', [
            'username'      => $form['username'],
            'email'         => $form['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name'     => $form['full_name'],
            'avatar_emoji'  => random_avatar_emoji(),
            'avatar_color'  => setting('theme_primary'),
            'role'          => $isFirst ? 'admin' : 'user',
            'status'        => 'active',
            'theme'         => setting('theme_mode', 'light'),
            'created_at'    => now_vn(),
            'updated_at'    => now_vn(),
        ]);

        log_activity('register', 'Tài khoản mới: ' . $form['username'], $userId);
        auth_login_user($userId, true);

        $_SESSION['just_registered'] = true;
        flash('success', 'Tạo tài khoản thành công! Chào mừng bạn đến với ' . site_name() . ' 🎉');
        if ($isFirst) {
            flash('info', 'Bạn là người dùng đầu tiên nên được cấp quyền Quản trị viên. Hãy vào mục Quản trị để cấu hình AI API nhé!');
        }
        redirect(base_url() . '/index.php');
    }
}

layout_head('Đăng ký');
layout_navbar('register');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-head">
      <span class="auth-emoji">🎈</span>
      <h1>Tạo tài khoản mới</h1>
      <p>Chỉ mất 30 giây để bắt đầu trò chuyện cùng AI</p>
    </div>

    <?php foreach ($errors as $error): ?>
      <div class="flash flash-error mb-2"><span class="flash-icon">😿</span><span><?= e($error) ?></span></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label for="full_name">Họ và tên</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>"
               autocomplete="name" placeholder="Nguyễn Văn A" autofocus>
      </div>

      <div class="field">
        <label for="username">Tên đăng nhập <span class="text-muted">*</span></label>
        <input type="text" id="username" name="username" value="<?= e($form['username']) ?>"
               autocomplete="username" required placeholder="nguyenvana"
               pattern="[a-zA-Z0-9_.]{3,50}">
        <span class="hint">3–50 ký tự, chỉ gồm chữ không dấu, số, dấu chấm và gạch dưới.</span>
      </div>

      <div class="field">
        <label for="email">Email <span class="text-muted">*</span></label>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>"
               autocomplete="email" required placeholder="ban@email.com">
      </div>

      <div class="field">
        <label for="password">Mật khẩu <span class="text-muted">*</span></label>
        <div class="password-wrap">
          <input type="password" id="password" name="password" autocomplete="new-password"
                 required placeholder="Ít nhất 8 ký tự" data-strength-input>
          <button type="button" class="password-toggle" data-toggle-password="#password" aria-label="Hiện mật khẩu">👁️</button>
        </div>
        <div class="strength" data-strength-bar><i></i></div>
        <span class="hint" data-strength-text>Cần tối thiểu 8 ký tự, có cả chữ và số.</span>
      </div>

      <div class="field">
        <label for="password2">Nhập lại mật khẩu <span class="text-muted">*</span></label>
        <div class="password-wrap">
          <input type="password" id="password2" name="password2" autocomplete="new-password"
                 required placeholder="••••••••">
          <button type="button" class="password-toggle" data-toggle-password="#password2" aria-label="Hiện mật khẩu">👁️</button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg">✨ Tạo tài khoản</button>
    </form>

    <div class="auth-divider">hoặc</div>
    <p class="auth-alt">
      Đã có tài khoản? <a href="<?= e(base_url()) ?>/login.php"><strong>Đăng nhập ngay →</strong></a>
    </p>
  </div>
</div>
<?php
layout_foot();
