<?php
/**
 * Trang lịch sử phiên bản công khai (liên kết từ chân trang).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/versioning.php';

$entries   = changelog_entries();
$githubUrl = rtrim((string)setting('github_url'), '/');

layout_head('Lịch sử phiên bản');
layout_navbar();
?>
<div class="page page-narrow">
  <div class="page-head text-center">
    <h1 style="justify-content:center">🏷️ Lịch sử phiên bản</h1>
    <p>
      Phiên bản đang chạy: <strong>v<?= e(APP_VERSION) ?></strong> — <?= e(APP_CODENAME) ?>
      · <?= e(fmt_datetime(APP_VERSION_DATE . ' 00:00:00', 'd/m/Y')) ?>
    </p>
    <?php if ($githubUrl): ?>
      <a class="btn btn-soft btn-sm mt-1" href="<?= e($githubUrl) ?>" target="_blank" rel="noopener">
        🐙 Xem mã nguồn trên GitHub
      </a>
    <?php endif; ?>
  </div>

  <?php if (!$entries): ?>
    <div class="card">
      <div class="empty"><span class="empty-emoji">📭</span>Chưa có mục lịch sử nào.</div>
    </div>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($entries as $i => $entry): ?>
        <div class="card release<?= $i === 0 ? ' is-latest' : '' ?>">
          <div class="release-head">
            <span class="release-version">v<?= e($entry['version']) ?></span>
            <?php if ($entry['codename']): ?>
              <strong class="release-codename"><?= e($entry['codename']) ?></strong>
            <?php endif; ?>
            <?php if ($i === 0): ?><span class="badge badge-ok">mới nhất</span><?php endif; ?>
            <span class="release-date text-muted text-sm">
              <?= e($entry['date'] ? fmt_datetime($entry['date'] . ' 00:00:00', 'd/m/Y') : '') ?>
            </span>
          </div>
          <?php if ($entry['notes']): ?>
            <ul class="release-notes">
              <?php foreach ($entry['notes'] as $note): ?>
                <li><?= e($note) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-muted mb-0">Không có ghi chú.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
.release { animation: cardIn .5s var(--ease-bounce) both; }
.release.is-latest { border-color: var(--border-strong); box-shadow: var(--shadow); }
.release-head { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .7rem; }
.release-version {
  padding: .2em .8em; border-radius: 999px; font-weight: 760;
  font-variant-numeric: tabular-nums;
  background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand);
}
.release.is-latest .release-version {
  background: linear-gradient(135deg, var(--brand), var(--brand-2)); color: #fff;
}
.release-codename { font-size: 1.05rem; }
.release-date { margin-left: auto; }
.release-notes { margin: 0; padding-left: 1.3rem; color: var(--text-soft); }
.release-notes li { margin-bottom: .3rem; }
</style>
<?php
layout_foot();
