<?php
/**
 * Khởi động khu vực quản trị + khung giao diện riêng.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/ai.php';

$adminUser = require_admin();

/** Các mục trong menu quản trị. */
function admin_menu()
{
    return [
        'index'     => ['🏠', 'Tổng quan',       'index.php'],
        'endpoints' => ['🔌', 'AI API Endpoint', 'endpoints.php'],
        'documents' => ['📚', 'Tài liệu đi kèm', 'documents.php'],
        'chats'     => ['💬', 'Lịch sử chat',    'chats.php'],
        'users'     => ['👥', 'Người dùng',      'users.php'],
        'settings'  => ['⚙️', 'Cấu hình web',    'settings.php'],
        'logs'      => ['📜', 'Nhật ký',         'logs.php'],
        'version'   => ['🏷️', 'Phiên bản',       'version.php'],
    ];
}

/** Mở khung trang quản trị. */
function admin_head($title, $active = 'index')
{
    layout_head($title . ' · Quản trị', ['body_class' => 'is-admin', 'css' => ['admin.css']]);
    $base = base_url();
    ?>
<div class="admin-shell">
  <aside class="admin-side">
    <a class="sidebar-brand" href="<?= e($base) ?>/index.php">
      <span class="brand-emoji"><?= e(setting('brand_emoji')) ?></span>
      <span><strong><?= e(site_name()) ?></strong><small>Bảng điều khiển</small></span>
    </a>
    <nav class="admin-nav">
      <?php foreach (admin_menu() as $key => $item): ?>
        <a class="admin-nav-link<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($item[2]) ?>">
          <span class="admin-nav-icon"><?= $item[0] ?></span><?= e($item[1]) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-side-foot">
      <a class="btn btn-ghost btn-sm btn-block" href="<?= e($base) ?>/index.php">← Về trang trò chuyện</a>
    </div>
  </aside>

  <main class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="icon-btn" id="adminMenuBtn" title="Menu">☰</button>
      <h1 class="admin-page-title"><?= e($title) ?></h1>
      <div class="row" style="gap:.4rem">
        <button type="button" class="icon-btn" data-theme-toggle title="Đổi giao diện">🌗</button>
        <a class="user-chip" href="<?= e($base) ?>/account.php">
          <span class="avatar avatar-sm"><?= e(current_user()['avatar_emoji']) ?></span>
          <span class="user-meta"><strong><?= e(current_user()['username']) ?></strong></span>
        </a>
      </div>
    </header>
    <div class="admin-body">
    <?php
}

/** Đóng khung trang quản trị. */
function admin_foot($js = [])
{
    ?>
    </div>
  </main>
</div>
<div class="modal-backdrop" id="confirmModal">
  <div class="modal">
    <h3 id="confirmTitle">Xác nhận</h3>
    <p id="confirmText">Bạn có chắc không?</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-confirm-no>Huỷ</button>
      <button type="button" class="btn btn-danger" data-confirm-yes>Đồng ý</button>
    </div>
  </div>
</div>
<script>
document.getElementById('adminMenuBtn').addEventListener('click', function () {
  document.querySelector('.admin-shell').classList.toggle('side-open');
});
</script>
    <?php
    layout_foot(['compact' => true, 'js' => $js]);
}

/** Phân trang đơn giản. */
function admin_pager($total, $perPage, $current, $baseQuery = [])
{
    $pages = (int)ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pager">';
    $window = 2;
    $render = function ($page, $label = null, $class = '') use ($baseQuery, $current) {
        $query = array_merge($baseQuery, ['p' => $page]);
        return '<a class="pager-link ' . $class . ($page === $current ? ' is-active' : '') . '" href="?'
             . e(http_build_query($query)) . '">' . e($label ?? $page) . '</a>';
    };

    if ($current > 1) {
        $html .= $render($current - 1, '‹ Trước');
    }
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $current) <= $window) {
            $html .= $render($i);
        } elseif (abs($i - $current) === $window + 1) {
            $html .= '<span class="pager-gap">…</span>';
        }
    }
    if ($current < $pages) {
        $html .= $render($current + 1, 'Sau ›');
    }
    return $html . '</nav>';
}
