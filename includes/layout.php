<?php
/**
 * Khung giao diện dùng chung (phần đầu và phần chân trang).
 */

/**
 * In phần <head> và mở <body>.
 *
 * @param string $title  Tiêu đề trang
 * @param array  $opts   ['body_class' => ..., 'css' => [...], 'blobs' => bool, 'narrow' => bool]
 */
function layout_head($title, array $opts = [])
{
    $base      = base_url();
    $user      = current_user();
    $bodyClass = $opts['body_class'] ?? '';
    $mode      = $user['theme'] ?? ($_COOKIE['tchat_theme'] ?? setting('theme_mode', 'light'));
    $mode      = in_array($mode, ['light', 'dark'], true) ? $mode : 'light';
    $primary   = setting('theme_primary');
    $accent    = setting('theme_accent');
    $siteName  = site_name();
    $fullTitle = $title ? ($title . ' · ' . $siteName) : $siteName;
    ?><!DOCTYPE html>
<html lang="vi" data-theme="<?= e($mode) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($primary) ?>">
<meta name="description" content="<?= e(setting('site_tagline')) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e($fullTitle) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">' . setting('brand_emoji') . '</text></svg>') ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<?php foreach (($opts['css'] ?? []) as $css): ?>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/<?= e($css) ?>?v=<?= e(APP_VERSION) ?>">
<?php endforeach; ?>
<style>
:root{
  --brand: <?= e($primary) ?>;
  --brand-2: <?= e($accent) ?>;
}
</style>
<script>
// Đặt theme trước khi vẽ để tránh nhấp nháy.
(function(){try{var t=localStorage.getItem('tchat-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e($base) ?>" data-csrf="<?= e(csrf_token()) ?>"
      <?= $user ? 'data-theme-server="' . e($mode) . '"' : '' ?>>
<div class="bg-decor" aria-hidden="true">
  <span class="blob blob-1"></span>
  <span class="blob blob-2"></span>
  <span class="blob blob-3"></span>
  <span class="blob blob-4"></span>
  <span class="grid-glow"></span>
</div>
<?= flash_render() ?>
<?php
}

/** In phần chân trang và đóng tài liệu. */
function layout_foot(array $opts = [])
{
    $base = base_url();
    ?>
<footer class="app-footer<?= !empty($opts['compact']) ? ' app-footer-compact' : '' ?>">
  <div class="footer-inner">
    <span class="footer-copy"><?= e(setting('copyright')) ?></span>
    <?php if (setting_bool('show_version')): ?>
      <span class="footer-sep">•</span>
      <a class="footer-version" href="<?= e($base) ?>/changelog.php" title="Xem lịch sử phiên bản">
        <span class="version-dot"></span>v<?= e(APP_VERSION) ?>
        <span class="version-code"><?= e(APP_CODENAME) ?></span>
      </a>
      <?php if (setting('github_url')): ?>
        <span class="footer-sep">•</span>
        <a class="footer-gh" href="<?= e(setting('github_url')) ?>" target="_blank" rel="noopener">
          <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
          GitHub
        </a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</footer>
<script src="<?= e($base) ?>/assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
<?php foreach (($opts['js'] ?? []) as $js): ?>
<script src="<?= e($base) ?>/assets/js/<?= e($js) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Thanh điều hướng trên cùng (dùng cho các trang không phải màn hình chat). */
function layout_navbar($active = '')
{
    $base = base_url();
    $user = current_user();
    ?>
<header class="topbar">
  <a class="brand" href="<?= e($base) ?>/index.php">
    <span class="brand-emoji"><?= e(setting('brand_emoji')) ?></span>
    <span class="brand-text">
      <strong><?= e(site_name()) ?></strong>
      <small><?= e(setting('site_tagline')) ?></small>
    </span>
  </a>
  <nav class="topnav">
    <?php if ($user): ?>
      <a class="topnav-link<?= $active === 'chat' ? ' is-active' : '' ?>" href="<?= e($base) ?>/index.php">💬 Trò chuyện</a>
      <a class="topnav-link<?= $active === 'account' ? ' is-active' : '' ?>" href="<?= e($base) ?>/account.php">👤 Tài khoản</a>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="topnav-link<?= $active === 'admin' ? ' is-active' : '' ?>" href="<?= e($base) ?>/admin/index.php">🛠️ Quản trị</a>
      <?php endif; ?>
      <button type="button" class="icon-btn" data-theme-toggle title="Đổi giao diện sáng/tối">🌗</button>
      <a class="topnav-link topnav-logout" href="<?= e($base) ?>/logout.php?csrf=<?= e(csrf_token()) ?>">Đăng xuất</a>
    <?php else: ?>
      <button type="button" class="icon-btn" data-theme-toggle title="Đổi giao diện sáng/tối">🌗</button>
      <a class="topnav-link" href="<?= e($base) ?>/login.php">Đăng nhập</a>
      <?php if (setting_bool('allow_register')): ?>
        <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/register.php">Đăng ký</a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
</header>
<?php
}
