<?php
/**
 * Quản lý phiên bản: xem lịch sử, tăng phiên bản (bump) và đồng bộ lên GitHub.
 */
require_once __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/includes/versioning.php';

$gitInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'bump') {
        $type     = in_array($_POST['type'] ?? 'patch', ['major', 'minor', 'patch'], true) ? $_POST['type'] : 'patch';
        $codename = trim($_POST['codename'] ?? '');
        $notes    = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['notes'] ?? ''))));

        $result = version_bump($type, $notes, $codename);
        if ($result['ok']) {
            log_activity('version_bump', 'Tăng phiên bản ' . APP_VERSION . ' → ' . $result['version']
                . ' (' . $type . ')');
            flash('success', 'Đã tăng phiên bản lên v' . $result['version']
                . '! Chân trang và CHANGELOG.md đã được cập nhật. 🏷️');
            flash('info', 'Hãy commit & push CHANGELOG.md cùng includes/version.php lên GitHub để lịch sử được lưu lại.');
        } else {
            flash('error', $result['error']);
        }
        redirect('version.php');
    }

    if ($action === 'sync') {
        $count = version_sync_db();
        flash('success', 'Đã đồng bộ ' . $count . ' phiên bản từ CHANGELOG.md vào cơ sở dữ liệu.');
        redirect('version.php');
    }
}

$entries   = changelog_entries();
$dbVersions = db_table_exists('app_versions')
    ? db_all('SELECT * FROM `app_versions` ORDER BY `released_at` DESC, `id` DESC LIMIT 100')
    : [];

$githubUrl = rtrim((string)setting('github_url'), '/');
$writable  = [
    'version'   => is_writable(version_file()),
    'changelog' => !file_exists(changelog_file()) || is_writable(changelog_file()),
];

admin_head('Phiên bản', 'version');
?>
<div class="grid grid-2 mb-2">
  <div class="card">
    <h3 class="card-title">🏷️ Phiên bản hiện tại</h3>
    <div style="font-size:2.4rem;font-weight:840;letter-spacing:-.02em;line-height:1.1">
      v<?= e(APP_VERSION) ?>
    </div>
    <p class="text-muted mb-2">
      <strong><?= e(APP_CODENAME) ?></strong> · phát hành <?= e(fmt_datetime(APP_VERSION_DATE . ' 00:00:00', 'd/m/Y')) ?>
    </p>
    <div class="row" style="gap:.4rem">
      <a class="btn btn-ghost btn-sm" href="<?= e(base_url()) ?>/changelog.php" target="_blank">📄 Trang lịch sử công khai</a>
      <?php if ($githubUrl): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e($githubUrl) ?>/releases" target="_blank" rel="noopener">🐙 Releases trên GitHub</a>
      <?php endif; ?>
    </div>
    <?php if (!$writable['version'] || !$writable['changelog']): ?>
      <div class="test-result is-fail mt-2">
        ⚠️ Thiếu quyền ghi:
        <?= !$writable['version'] ? '<code class="inline-code">includes/version.php</code>' : '' ?>
        <?= !$writable['changelog'] ? '<code class="inline-code">CHANGELOG.md</code>' : '' ?>.
        Hãy đặt quyền 664 cho các file này để dùng được chức năng tăng phiên bản.
      </div>
    <?php endif; ?>
  </div>

  <div class="form-section" style="margin:0">
    <h3>⬆️ Tăng phiên bản</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bump">

      <div class="field">
        <span class="label">Mức tăng</span>
        <div class="check-list">
          <label class="check-item">
            <input type="radio" name="type" value="patch" checked>
            <span><strong>Patch</strong> → v<?= e(version_next(APP_VERSION, 'patch')) ?><br>
              <small class="text-muted">Sửa lỗi, tinh chỉnh nhỏ</small></span>
          </label>
          <label class="check-item">
            <input type="radio" name="type" value="minor">
            <span><strong>Minor</strong> → v<?= e(version_next(APP_VERSION, 'minor')) ?><br>
              <small class="text-muted">Thêm tính năng mới</small></span>
          </label>
          <label class="check-item">
            <input type="radio" name="type" value="major">
            <span><strong>Major</strong> → v<?= e(version_next(APP_VERSION, 'major')) ?><br>
              <small class="text-muted">Thay đổi lớn, có thể không tương thích</small></span>
          </label>
        </div>
      </div>

      <div class="field">
        <label for="codename">Tên mã (tuỳ chọn)</label>
        <input type="text" id="codename" name="codename" placeholder="vd: Mùa Thu Hà Nội" maxlength="60">
      </div>

      <div class="field">
        <label for="notes">Ghi chú thay đổi (mỗi dòng một mục)</label>
        <textarea id="notes" name="notes" rows="6"
                  placeholder="Thêm hỗ trợ tải lên tệp âm thanh&#10;Sửa lỗi hiển thị công thức LaTeX trong bảng&#10;Cải thiện tốc độ tải danh sách trò chuyện"></textarea>
      </div>

      <div class="row row-end">
        <button type="submit" class="btn btn-primary">🚀 Tăng phiên bản &amp; ghi lịch sử</button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-2">
  <h3 class="card-title">🐙 Đưa lịch sử lên GitHub</h3>
  <p class="text-muted">
    Sau khi tăng phiên bản, hai file <code class="inline-code">includes/version.php</code> và
    <code class="inline-code">CHANGELOG.md</code> đã được cập nhật trên máy chủ. Chạy các lệnh sau
    ở bản sao mã nguồn để đẩy lên GitHub và tạo tag phiên bản:
  </p>
  <div class="code-block">
    <div class="code-head"><span class="code-lang">bash</span></div>
    <pre><code>git add CHANGELOG.md includes/version.php
git commit -m "chore(release): v<?= e(APP_VERSION) ?>"
git tag -a v<?= e(APP_VERSION) ?> -m "Phiên bản <?= e(APP_VERSION) ?> — <?= e(APP_CODENAME) ?>"
git push origin HEAD --tags</code></pre>
  </div>
  <p class="text-muted text-sm mt-1 mb-0">
    Khi tag <code class="inline-code">v*</code> được đẩy lên, GitHub Action
    <code class="inline-code">.github/workflows/release.yml</code> sẽ tự đóng gói file ZIP
    và tạo Release kèm ghi chú lấy từ CHANGELOG.md.
  </p>
</div>

<div class="table-card">
  <div class="table-card-head">
    <h2>📜 Lịch sử phiên bản (<?= count($entries) ?>)</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync">
      <button type="submit" class="btn btn-ghost btn-sm">♻️ Đồng bộ vào CSDL</button>
    </form>
  </div>

  <?php if (!$entries): ?>
    <div class="empty"><span class="empty-emoji">📭</span>Chưa đọc được mục nào từ CHANGELOG.md.</div>
  <?php else: ?>
    <div style="padding:1.1rem 1.3rem">
      <?php foreach ($entries as $i => $entry): ?>
        <div class="ver-entry<?= $i === 0 ? ' is-current' : '' ?>">
          <div class="ver-badge">v<?= e($entry['version']) ?></div>
          <div class="ver-body">
            <div class="ver-head">
              <?php if ($entry['codename']): ?><strong><?= e($entry['codename']) ?></strong> · <?php endif; ?>
              <span class="text-muted text-sm"><?= e($entry['date'] ? fmt_datetime($entry['date'] . ' 00:00:00', 'd/m/Y') : '') ?></span>
              <?php if ($i === 0): ?><span class="badge badge-ok">hiện hành</span><?php endif; ?>
              <?php if ($githubUrl): ?>
                <a class="text-sm" href="<?= e($githubUrl) ?>/releases/tag/v<?= e($entry['version']) ?>"
                   target="_blank" rel="noopener">🔗 GitHub</a>
              <?php endif; ?>
            </div>
            <?php if ($entry['notes']): ?>
              <ul class="ver-notes">
                <?php foreach ($entry['notes'] as $note): ?>
                  <li><?= e($note) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($dbVersions): ?>
  <div class="table-card mt-2">
    <div class="table-card-head"><h2>🗄️ Bản ghi trong cơ sở dữ liệu</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Phiên bản</th><th>Thời điểm ghi nhận</th><th>Ghi chú</th></tr></thead>
        <tbody>
        <?php foreach ($dbVersions as $row): ?>
          <tr>
            <td class="cell-strong">v<?= e($row['version']) ?></td>
            <td class="cell-muted"><?= e(fmt_datetime($row['released_at'])) ?></td>
            <td class="cell-muted"><?= e(str_limit(str_replace("\n", ' · ', (string)$row['notes']), 110)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<style>
.ver-entry { display: flex; gap: 1rem; padding-bottom: 1.2rem; position: relative; }
.ver-entry::before {
  content: ""; position: absolute; left: 39px; top: 34px; bottom: 0; width: 2px;
  background: var(--border);
}
.ver-entry:last-child::before { display: none; }
.ver-badge {
  flex: 0 0 auto; width: 80px; text-align: center;
  padding: .3rem .1rem; border-radius: 999px;
  background: color-mix(in srgb, var(--brand) 11%, transparent);
  color: var(--brand); font-weight: 720; font-size: .84rem;
  font-variant-numeric: tabular-nums; height: fit-content;
}
.ver-entry.is-current .ver-badge {
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  color: #fff; box-shadow: var(--shadow-brand);
}
.ver-head { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .3rem; }
.ver-notes { margin: 0; padding-left: 1.2rem; color: var(--text-soft); font-size: .91rem; }
.ver-notes li { margin-bottom: .18rem; }
</style>
<?php
admin_foot();
