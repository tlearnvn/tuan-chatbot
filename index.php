<?php
/**
 * Màn hình trò chuyện chính.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ai.php';

$user = require_login();

if (setting_bool('maintenance') && $user['role'] !== 'admin') {
    layout_head('Đang bảo trì');
    layout_navbar();
    echo '<div class="auth-wrap"><div class="auth-card text-center">'
       . '<span class="auth-emoji">🛠️</span><h1>Hệ thống đang bảo trì</h1>'
       . '<p class="text-muted">' . e(setting('maintenance_note')) . '</p></div></div>';
    layout_foot();
    exit;
}

$base        = base_url();
$endpoints   = endpoint_list_active();
$activeConv  = isset($_GET['c']) ? (int)$_GET['c'] : 0;
$suggestions = setting_suggestions();
$maxUploadMb = setting_int('max_upload_mb', 25);
$justJoined  = !empty($_SESSION['just_registered']);
unset($_SESSION['just_registered']);

// Nếu có ?c= thì kiểm tra quyền sở hữu.
if ($activeConv) {
    $owns = db_value('SELECT COUNT(*) FROM `conversations` WHERE `id` = ? AND `user_id` = ? AND `is_deleted` = 0',
        [$activeConv, $user['id']], 0);
    if (!$owns) {
        $activeConv = 0;
    }
}

$bootstrapData = [
    'base'          => $base,
    'csrf'          => csrf_token(),
    'user'          => [
        'id'     => (int)$user['id'],
        'name'   => $user['full_name'] ?: $user['username'],
        'emoji'  => $user['avatar_emoji'],
        'isAdmin'=> $user['role'] === 'admin',
    ],
    'endpoints'     => array_map(function ($ep) {
        return [
            'id'      => (int)$ep['id'],
            'name'    => $ep['name'],
            'model'   => filter_model_name($ep['model']),
            'desc'    => $ep['description'],
            'vision'  => (int)$ep['supports_vision'] === 1,
            'files'   => (int)$ep['supports_files'] === 1,
            'default' => (int)$ep['is_default'] === 1,
        ];
    }, $endpoints),
    'activeConv'    => $activeConv,
    'maxUploadMb'   => $maxUploadMb,
    'maxFiles'      => setting_int('max_files_per_msg', 10),
    'allowedExt'    => allowed_extensions(),
    'brandEmoji'    => setting('brand_emoji'),
    'welcome'       => setting('welcome_message'),
    'siteName'      => site_name(),
    'justJoined'    => $justJoined,
];

layout_head('Trò chuyện', ['body_class' => 'is-chat']);
?>
<!-- Thư viện hiển thị Markdown / LaTeX / tô màu mã nguồn (có CDN dự phòng) -->
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<script src="<?= e($base) ?>/assets/js/libs.js?v=<?= e(APP_VERSION) ?>"></script>

<div class="chat-app" id="chatApp">
  <div class="sidebar-backdrop" data-close-sidebar></div>

  <!-- ============ Thanh bên ============ -->
  <aside class="sidebar">
    <div class="sidebar-head">
      <a class="sidebar-brand" href="<?= e($base) ?>/index.php">
        <span class="brand-emoji"><?= e(setting('brand_emoji')) ?></span>
        <span>
          <strong><?= e(site_name()) ?></strong>
          <small><?= e(str_limit(setting('site_tagline'), 34)) ?></small>
        </span>
      </a>
      <button type="button" class="new-chat-btn" id="btnNewChat">
        <span class="plus">＋</span> Cuộc trò chuyện mới
      </button>
    </div>

    <div class="conv-search">
      <input type="search" id="convSearch" placeholder="Tìm cuộc trò chuyện…" aria-label="Tìm cuộc trò chuyện">
    </div>

    <div class="conv-list" id="convList">
      <div class="empty text-sm"><span class="spin">⏳</span> Đang tải…</div>
    </div>

    <div class="sidebar-foot">
      <a class="user-chip" href="<?= e($base) ?>/account.php" title="Quản lý tài khoản">
        <span class="avatar"><?= e($user['avatar_emoji']) ?></span>
        <span class="user-meta">
          <strong><?= e($user['full_name'] ?: $user['username']) ?></strong>
          <small><?= $user['role'] === 'admin' ? '👑 Quản trị viên' : 'Thành viên' ?></small>
        </span>
      </a>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="icon-btn" href="<?= e($base) ?>/admin/index.php" title="Khu vực quản trị">🛠️</a>
      <?php endif; ?>
      <button type="button" class="icon-btn" data-theme-toggle title="Đổi giao diện sáng/tối">🌗</button>
      <a class="icon-btn is-danger" href="<?= e($base) ?>/logout.php?csrf=<?= e(csrf_token()) ?>" title="Đăng xuất">⏻</a>
    </div>
  </aside>

  <!-- ============ Vùng trò chuyện ============ -->
  <main class="chat-main">
    <header class="chat-header">
      <button type="button" class="icon-btn" id="btnToggleSidebar" title="Ẩn/hiện danh sách">☰</button>
      <div class="chat-title" id="chatTitle">
        <span id="chatTitleText">Cuộc trò chuyện mới</span>
        <small id="chatSubtitle">Sẵn sàng lắng nghe bạn</small>
      </div>

      <?php if ($endpoints): ?>
        <div class="model-picker">
          <select id="endpointSelect" aria-label="Chọn mô hình AI">
            <?php foreach ($endpoints as $ep): ?>
              <option value="<?= (int)$ep['id'] ?>" <?= (int)$ep['is_default'] === 1 ? 'selected' : '' ?>>
                <?= e($ep['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <button type="button" class="icon-btn" id="btnExportChat" title="Tải cuộc trò chuyện về máy">⬇️</button>
    </header>

    <?php if (!$endpoints): ?>
      <div class="chat-scroll">
        <div class="chat-inner">
          <div class="card text-center">
            <span class="empty-emoji">🔌</span>
            <h2>Chưa có endpoint AI nào được cấu hình</h2>
            <?php if ($user['role'] === 'admin'): ?>
              <p class="text-muted">Hãy vào khu vực quản trị để thêm URL, API key và mô hình cho hệ thống.</p>
              <a class="btn btn-primary mt-1" href="<?= e($base) ?>/admin/endpoints.php">⚙️ Cấu hình AI API ngay</a>
            <?php else: ?>
              <p class="text-muted">Vui lòng liên hệ quản trị viên để được cấu hình kết nối AI.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="chat-scroll" id="chatScroll">
        <div class="chat-inner" id="chatInner">
          <!-- Màn hình chào -->
          <div class="welcome" id="welcomeScreen">
            <span class="welcome-emoji"><?= e(setting('brand_emoji')) ?></span>
            <h1>Xin chào, <?= e(explode(' ', trim($user['full_name'] ?: $user['username']))[count(explode(' ', trim($user['full_name'] ?: $user['username']))) - 1]) ?>!</h1>
            <p><?= e(setting('welcome_message')) ?></p>
            <?php if ($suggestions): ?>
              <div class="suggestions">
                <?php
                $icons = ['💡', '✍️', '📊', '🧩', '🎯', '🔍'];
                foreach (array_slice($suggestions, 0, 4) as $i => $text): ?>
                  <button type="button" class="suggestion" data-suggestion="<?= e($text) ?>">
                    <span><?= e($icons[$i % count($icons)]) ?></span><?= e($text) ?>
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <button type="button" class="scroll-bottom-btn" id="btnScrollBottom" title="Xuống cuối">⬇</button>

      <!-- Ô soạn tin -->
      <div class="composer-wrap">
        <form class="composer" id="composer" autocomplete="off">
          <div class="composer-files hidden" id="composerFiles"></div>
          <div class="composer-row">
            <button type="button" class="icon-btn" id="btnAttach" title="Đính kèm tệp (tối đa <?= (int)$maxUploadMb ?>MB)">📎</button>
            <label class="sr-only" for="messageInput">Nội dung tin nhắn</label>
            <textarea id="messageInput" rows="1" placeholder="Nhập tin nhắn… (Enter để gửi, Shift+Enter để xuống dòng)"></textarea>
            <button type="submit" class="send-btn" id="btnSend" title="Gửi" disabled>➤</button>
          </div>
          <input type="file" id="fileInput" multiple class="hidden"
                 accept="<?= e('.' . implode(',.', allowed_extensions())) ?>">
        </form>
        <div class="composer-hint">
          <span>Nhấn <span class="kbd">Enter</span> để gửi · <span class="kbd">Shift</span>+<span class="kbd">Enter</span> để xuống dòng</span>
          <span class="hint-right">Nội dung do AI tạo ra có thể chưa chính xác — hãy kiểm chứng khi cần.</span>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<!-- Xem ảnh phóng to -->
<div class="lightbox" id="lightbox">
  <button type="button" class="lightbox-close" aria-label="Đóng">✕</button>
  <img src="" alt="Ảnh phóng to">
</div>

<!-- Hộp thoại xác nhận -->
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

<script>window.TCHAT = <?= json_encode($bootstrapData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php
layout_foot(['compact' => true, 'js' => ['md.js', 'chat.js']]);
