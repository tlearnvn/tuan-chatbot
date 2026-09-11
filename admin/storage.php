<?php
/**
 * Dung lượng & phiên đăng nhập — theo dõi dữ liệu đang chiếm chỗ trong CSDL,
 * chuyển tệp cũ từ đĩa vào CSDL và quản lý phiên đăng nhập.
 */
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/migrate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'migrate_schema') {
        $done = db_migrate(true);
        flash('success', $done
            ? 'Đã nâng cấp lược đồ: ' . implode(' ', $done)
            : 'Lược đồ cơ sở dữ liệu đã ở bản mới nhất, không cần thay đổi gì.');

    } elseif ($action === 'migrate_files') {
        $result = db_migrate_files_to_db((int)($_POST['batch'] ?? 25));
        if ($result['moved']) {
            flash('success', 'Đã chuyển ' . $result['moved'] . ' tệp ('
                . fmt_bytes($result['bytes']) . ') từ đĩa vào cơ sở dữ liệu.');
        }
        if ($result['failed']) {
            flash('warning', 'Có ' . $result['failed'] . ' tệp không chuyển được: '
                . str_limit(implode(' · ', $result['errors']), 300));
        }
        if (!$result['moved'] && !$result['failed']) {
            flash('info', 'Không còn tệp nào nằm trên đĩa.');
        } elseif ($result['remaining']) {
            flash('info', 'Còn ' . $result['remaining'] . ' tệp — hãy bấm tiếp để chuyển phần còn lại.');
        }

    } elseif ($action === 'purge_orphans') {
        $count = storage_purge_orphans();
        flash('success', 'Đã dọn ' . number_format($count, 0, ',', '.') . ' khối dữ liệu mồ côi.');

    } elseif ($action === 'delete_attachment') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_one('SELECT * FROM `attachments` WHERE `id` = ?', [$id]);
        if ($row) {
            delete_attachment_file($row);
            db_run('DELETE FROM `attachments` WHERE `id` = ?', [$id]);
            log_activity('attachment_delete', 'Xoá tệp #' . $id . ': ' . $row['original_name']);
            flash('success', 'Đã xoá tệp "' . $row['original_name'] . '".');
        }

    } elseif ($action === 'revoke_session') {
        $sid = (string)($_POST['sid'] ?? '');
        if ($sid !== '' && $sid !== session_id()) {
            db_run('DELETE FROM `sessions` WHERE `id` = ?', [$sid]);
            log_activity('session_revoke', 'Ngắt một phiên đăng nhập');
            flash('success', 'Đã ngắt phiên đăng nhập đó.');
        } else {
            flash('warning', 'Không thể tự ngắt phiên bạn đang dùng.');
        }

    } elseif ($action === 'clean_sessions') {
        $cutoff = time() - max(3600, (int)ini_get('session.gc_maxlifetime'));
        $count  = db_run('DELETE FROM `sessions` WHERE `last_activity` < ?', [$cutoff])->rowCount();
        flash('success', 'Đã dọn ' . number_format($count, 0, ',', '.') . ' phiên hết hạn.');
    }
    redirect('storage.php');
}

// --- Thống kê dung lượng -----------------------------------------------------
$totalBytes  = storage_total_bytes();
$legacyCount = db_column_exists('attachments', 'storage')
    ? (int)db_value("SELECT COUNT(*) FROM `attachments` WHERE `storage` = 'file'", [], 0)
    : 0;

$byKind = db_all(
    'SELECT a.`kind`, COUNT(*) AS n, COALESCE(SUM(a.`size`), 0) AS bytes
     FROM `attachments` a GROUP BY a.`kind` ORDER BY bytes DESC'
);

$byUser = db_all(
    'SELECT u.`id`, u.`username`, u.`avatar_emoji`, COUNT(a.`id`) AS n,
            COALESCE(SUM(a.`size`), 0) AS bytes
     FROM `users` u JOIN `attachments` a ON a.`user_id` = u.`id`
     GROUP BY u.`id`, u.`username`, u.`avatar_emoji`
     ORDER BY bytes DESC LIMIT 10'
);

$biggest = db_all(
    'SELECT a.*, u.`username` FROM `attachments` a
     LEFT JOIN `users` u ON u.`id` = a.`user_id`
     ORDER BY a.`size` DESC LIMIT 15'
);

// Dung lượng các bảng chữ (tin nhắn, tài liệu) để nhìn được toàn cảnh.
$textBytes = [
    'messages'  => (int)db_value('SELECT COALESCE(SUM(CHAR_LENGTH(`content`) + CHAR_LENGTH(COALESCE(`reasoning`, ""))), 0) FROM `messages`', [], 0),
    'documents' => (int)db_value('SELECT COALESCE(SUM(CHAR_LENGTH(COALESCE(`content`, ""))), 0) FROM `documents`', [], 0),
    'extracted' => (int)db_value('SELECT COALESCE(SUM(CHAR_LENGTH(COALESCE(`extracted_text`, ""))), 0) FROM `attachments`', [], 0),
];

$sessions   = db_table_exists('sessions') ? session_list_active(100) : [];
$sessionAll = db_table_exists('sessions') ? (int)db_value('SELECT COUNT(*) FROM `sessions`', [], 0) : 0;
$packet     = (int)db_value('SELECT @@max_allowed_packet', [], 0);

admin_head('Dung lượng & phiên', 'storage');
?>
<p class="text-muted mb-2" style="max-width:820px">
  Ứng dụng <strong>không ghi tệp nào xuống đĩa</strong>: tệp đính kèm nằm trong bảng
  <code class="inline-code">attachment_chunks</code>, phiên đăng nhập nằm trong bảng
  <code class="inline-code">sessions</code>. Nhờ vậy số inode của hosting luôn cố định
  (chỉ khoảng 55 tệp mã nguồn) dù có bao nhiêu người dùng. Bù lại, hãy theo dõi
  dung lượng <em>database</em> ở đây.
</p>

<div class="stat-grid mb-2">
  <div class="stat">
    <div class="stat-icon">📦</div>
    <div class="stat-value"><?= e(fmt_bytes($totalBytes)) ?></div>
    <div class="stat-label">Tệp đính kèm trong CSDL</div>
    <div class="stat-sub"><?= number_format((int)db_value('SELECT COUNT(*) FROM `attachments`', [], 0), 0, ',', '.') ?> tệp</div>
  </div>
  <div class="stat">
    <div class="stat-icon">💬</div>
    <div class="stat-value"><?= e(fmt_bytes($textBytes['messages'])) ?></div>
    <div class="stat-label">Nội dung tin nhắn</div>
    <div class="stat-sub">văn bản chat + suy luận</div>
  </div>
  <div class="stat">
    <div class="stat-icon">📚</div>
    <div class="stat-value"><?= e(fmt_bytes($textBytes['documents'] + $textBytes['extracted'])) ?></div>
    <div class="stat-label">Tài liệu &amp; văn bản trích xuất</div>
    <div class="stat-sub">nạp vào ngữ cảnh AI</div>
  </div>
  <div class="stat">
    <div class="stat-icon">🔑</div>
    <div class="stat-value"><?= number_format(count($sessions), 0, ',', '.') ?></div>
    <div class="stat-label">Phiên đang hoạt động</div>
    <div class="stat-sub"><?= number_format($sessionAll, 0, ',', '.') ?> bản ghi tổng cộng</div>
  </div>
  <div class="stat">
    <div class="stat-icon">🧩</div>
    <div class="stat-value"><?= e(fmt_bytes(storage_chunk_size())) ?></div>
    <div class="stat-label">Kích thước mỗi khối</div>
    <div class="stat-sub">max_allowed_packet <?= e(fmt_bytes($packet)) ?></div>
  </div>
</div>

<?php if ($legacyCount > 0): ?>
  <div class="form-section" style="border-color:color-mix(in srgb, var(--warn) 45%, transparent)">
    <h3>📁 Còn <?= $legacyCount ?> tệp nằm trên đĩa (bản cũ)</h3>
    <p class="text-muted">
      Đây là tệp được tải lên trước khi hệ thống chuyển sang lưu trong cơ sở dữ liệu.
      Chuyển chúng vào CSDL để giải phóng inode, sau đó có thể xoá hẳn thư mục
      <code class="inline-code">uploads/</code>.
    </p>
    <form method="post" class="row" style="gap:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="migrate_files">
      <label class="text-sm">Mỗi lần chuyển
        <input type="number" name="batch" value="25" min="1" max="200" style="width:90px;display:inline-block">
        tệp
      </label>
      <button type="submit" class="btn btn-primary btn-sm">🚚 Chuyển tệp vào cơ sở dữ liệu</button>
    </form>
  </div>
<?php endif; ?>

<div class="grid grid-2 mb-2">
  <div class="table-card">
    <div class="table-card-head"><h2>📊 Theo loại tệp</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Loại</th><th class="num">Số tệp</th><th class="num">Dung lượng</th></tr></thead>
        <tbody>
        <?php foreach ($byKind as $row): ?>
          <tr>
            <td><?= e(file_icon($row['kind'])) ?> <?= e($row['kind']) ?></td>
            <td class="num"><?= number_format((int)$row['n'], 0, ',', '.') ?></td>
            <td class="num"><?= e(fmt_bytes($row['bytes'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$byKind): ?>
          <tr><td colspan="3"><div class="empty">Chưa có tệp nào.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="table-card">
    <div class="table-card-head"><h2>👥 Theo người dùng</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Thành viên</th><th class="num">Số tệp</th><th class="num">Dung lượng</th></tr></thead>
        <tbody>
        <?php foreach ($byUser as $row): ?>
          <tr>
            <td><span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($row['avatar_emoji']) ?></span> <?= e($row['username']) ?></td>
            <td class="num"><?= number_format((int)$row['n'], 0, ',', '.') ?></td>
            <td class="num"><?= e(fmt_bytes($row['bytes'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$byUser): ?>
          <tr><td colspan="3"><div class="empty">Chưa có dữ liệu.</div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="table-card mb-2">
  <div class="table-card-head">
    <h2>🐘 Tệp lớn nhất</h2>
    <form method="post" data-confirm="Dọn các khối dữ liệu không còn bản ghi tệp tương ứng?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purge_orphans">
      <button type="submit" class="btn btn-ghost btn-sm">♻️ Dọn dữ liệu mồ côi</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tên tệp</th><th>Người gửi</th><th>Nguồn</th><th>Nơi lưu</th>
                 <th class="num">Dung lượng</th><th>Thời điểm</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($biggest as $row): ?>
        <?php $ext = strtolower(pathinfo($row['original_name'], PATHINFO_EXTENSION)); ?>
        <tr>
          <td class="cell-strong"><?= e(file_icon($row['kind'], $ext)) ?> <?= e(str_limit($row['original_name'], 44)) ?></td>
          <td class="cell-muted"><?= e($row['username'] ?? '—') ?></td>
          <td class="cell-muted"><?= $row['direction'] === 'out' ? '🤖 AI trả về' : '🙋 Người dùng' ?></td>
          <td><?= ($row['storage'] ?? 'db') === 'db'
                ? '<span class="badge badge-ok">🗄️ CSDL</span>'
                : '<span class="badge badge-warn">📁 Đĩa</span>' ?></td>
          <td class="num"><?= e(fmt_bytes($row['size'])) ?></td>
          <td class="cell-muted"><?= e(fmt_relative($row['created_at'])) ?></td>
          <td class="cell-actions">
            <form method="post" data-confirm="Xoá tệp &quot;<?= e($row['original_name']) ?>&quot;?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_attachment">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm">🗑️</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$biggest): ?>
        <tr><td colspan="7"><div class="empty"><span class="empty-emoji">📭</span>Chưa có tệp nào.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="table-card mb-2">
  <div class="table-card-head">
    <h2>🔑 Phiên đăng nhập</h2>
    <form method="post" data-confirm="Dọn các phiên đã hết hạn?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clean_sessions">
      <button type="submit" class="btn btn-ghost btn-sm">♻️ Dọn phiên hết hạn</button>
    </form>
  </div>
  <?php if (!$sessions): ?>
    <div class="empty"><span class="empty-emoji">🌙</span>Không có phiên nào đang hoạt động.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Thành viên</th><th>IP</th><th>Thiết bị</th>
                   <th class="num">Kích thước</th><th>Hoạt động cuối</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $row): ?>
          <?php $isMe = $row['id'] === session_id(); ?>
          <tr>
            <td>
              <?php if ($row['username']): ?>
                <span class="avatar avatar-sm" style="display:inline-grid;vertical-align:middle"><?= e($row['avatar_emoji']) ?></span>
                <?= e($row['username']) ?>
                <?php if ($row['role'] === 'admin'): ?><span class="badge">👑</span><?php endif; ?>
              <?php else: ?>
                <span class="cell-muted">khách (chưa đăng nhập)</span>
              <?php endif; ?>
              <?php if ($isMe): ?><span class="badge badge-ok">phiên này</span><?php endif; ?>
            </td>
            <td class="cell-muted"><code class="inline-code"><?= e($row['ip']) ?></code></td>
            <td class="cell-muted" title="<?= e($row['user_agent']) ?>"><?= e(str_limit($row['user_agent'], 40)) ?></td>
            <td class="num"><?= e(fmt_bytes((int)$row['payload_size'])) ?></td>
            <td class="cell-muted"><?= e(fmt_relative(date('Y-m-d H:i:s', (int)$row['last_activity']))) ?></td>
            <td class="cell-actions">
              <?php if (!$isMe): ?>
                <form method="post" data-confirm="Ngắt phiên đăng nhập này?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke_session">
                  <input type="hidden" name="sid" value="<?= e($row['id']) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">⏻ Ngắt</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="form-section">
  <h3>🛠️ Lược đồ cơ sở dữ liệu</h3>
  <div class="row row-between">
    <p class="text-muted mb-0">
      Lược đồ hiện tại: <strong>v<?= e(setting('schema_version', '0')) ?></strong>
      / mã nguồn yêu cầu <strong>v<?= DB_SCHEMA_VERSION ?></strong>.
      Hệ thống tự nâng cấp khi bạn mở khu vực quản trị; nút bên cạnh để kiểm tra lại thủ công.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="migrate_schema">
      <button type="submit" class="btn btn-soft btn-sm">🔍 Kiểm tra &amp; nâng cấp</button>
    </form>
  </div>
</div>
<?php
admin_foot();
