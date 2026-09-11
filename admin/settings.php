<?php
/**
 * Cấu hình chung của website: tên web, khẩu hiệu, copyright footer, giới hạn tệp…
 */
require_once __DIR__ . '/_init.php';

$fields = [
    'site_name', 'site_tagline', 'brand_emoji', 'copyright', 'welcome_message',
    'allow_register', 'maintenance', 'maintenance_note',
    'max_upload_mb', 'max_files_per_msg', 'allowed_ext', 'history_limit',
    'show_version', 'github_url', 'theme_primary', 'theme_accent', 'theme_mode',
    'suggestions', 'admin_email',
];
$checkboxes = ['allow_register', 'maintenance', 'show_version'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (($_POST['action'] ?? '') === 'reset') {
        db_run('DELETE FROM `settings`');
        settings_all(true);
        log_activity('settings_reset', 'Đưa cấu hình về mặc định');
        flash('success', 'Đã đưa toàn bộ cấu hình về giá trị mặc định.');
        redirect('settings.php');
    }

    $pairs  = [];
    $errors = [];

    foreach ($fields as $key) {
        if (in_array($key, $checkboxes, true)) {
            $pairs[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $pairs[$key] = trim((string)$_POST[$key]);
    }

    if (isset($pairs['site_name']) && $pairs['site_name'] === '') {
        $errors[] = 'Tên website không được để trống.';
    }
    if (isset($pairs['max_upload_mb'])) {
        $mb = (int)$pairs['max_upload_mb'];
        if ($mb < 1 || $mb > 512) {
            $errors[] = 'Dung lượng tệp tối đa phải nằm trong khoảng 1 – 512 MB.';
        }
        $pairs['max_upload_mb'] = (string)$mb;
    }
    if (isset($pairs['max_files_per_msg'])) {
        $pairs['max_files_per_msg'] = (string)max(1, min(50, (int)$pairs['max_files_per_msg']));
    }
    if (isset($pairs['history_limit'])) {
        $pairs['history_limit'] = (string)max(2, min(200, (int)$pairs['history_limit']));
    }
    foreach (['theme_primary', 'theme_accent'] as $key) {
        if (isset($pairs[$key]) && !preg_match('/^#[0-9a-fA-F]{6}$/', $pairs[$key])) {
            $errors[] = 'Mã màu phải ở dạng #RRGGBB.';
        }
    }
    if (isset($pairs['github_url']) && $pairs['github_url'] !== ''
        && !preg_match('#^https?://#i', $pairs['github_url'])) {
        $errors[] = 'Địa chỉ GitHub phải bắt đầu bằng http:// hoặc https://';
    }
    if (isset($pairs['allowed_ext'])) {
        $list = array_filter(array_map(function ($item) {
            return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($item)));
        }, explode(',', $pairs['allowed_ext'])));
        $blocked = array_intersect($list, ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'exe', 'htaccess']);
        if ($blocked) {
            $errors[] = 'Không thể cho phép các định dạng thực thi: ' . implode(', ', $blocked);
        }
        $pairs['allowed_ext'] = implode(',', array_unique($list));
    }

    if ($errors) {
        foreach ($errors as $error) { flash('error', $error); }
    } else {
        settings_save($pairs);
        log_activity('settings_update', 'Cập nhật cấu hình website');
        flash('success', 'Đã lưu cấu hình website! ✨');
    }
    redirect('settings.php');
}

/** Giới hạn thực tế của PHP trên hosting (để nhắc quản trị viên). */
function php_size_limit($name)
{
    $value = ini_get($name);
    if ($value === false || $value === '') {
        return 0;
    }
    $unit  = strtolower(substr($value, -1));
    $bytes = (int)$value;
    if ($unit === 'g') $bytes *= 1024 * 1024 * 1024;
    elseif ($unit === 'm') $bytes *= 1024 * 1024;
    elseif ($unit === 'k') $bytes *= 1024;
    return $bytes;
}

$phpUpload = php_size_limit('upload_max_filesize');
$phpPost   = php_size_limit('post_max_size');
$phpEffective = (int)floor(min($phpUpload ?: PHP_INT_MAX, $phpPost ?: PHP_INT_MAX) / 1024 / 1024);

admin_head('Cấu hình web', 'settings');
?>
<form method="post">
  <?= csrf_field() ?>

  <div class="form-section">
    <h3>🏷️ Thương hiệu</h3>
    <div class="form-grid">
      <div class="field">
        <label for="site_name">Tên website <span class="text-muted">*</span></label>
        <input type="text" id="site_name" name="site_name" value="<?= e(setting('site_name')) ?>" required maxlength="80">
        <span class="hint">Hiện trên tiêu đề trang, thanh bên và trang đăng nhập.</span>
      </div>
      <div class="field">
        <label for="brand_emoji">Emoji thương hiệu</label>
        <input type="text" id="brand_emoji" name="brand_emoji" value="<?= e(setting('brand_emoji')) ?>" maxlength="8">
        <span class="hint">Dùng làm logo và favicon, ví dụ 🤖 🐣 🚀 🌟</span>
      </div>
    </div>
    <div class="field">
      <label for="site_tagline">Khẩu hiệu</label>
      <input type="text" id="site_tagline" name="site_tagline" value="<?= e(setting('site_tagline')) ?>" maxlength="160">
    </div>
    <div class="field">
      <label for="copyright">Dòng bản quyền ở chân trang</label>
      <input type="text" id="copyright" name="copyright" value="<?= e(setting('copyright')) ?>" maxlength="255">
      <span class="hint">Ví dụ: © <?= date('Y') ?> Công ty ABC. Mọi quyền được bảo lưu.</span>
    </div>
    <div class="field">
      <label for="welcome_message">Lời chào ở màn hình bắt đầu</label>
      <textarea id="welcome_message" name="welcome_message" rows="2" maxlength="400"><?= e(setting('welcome_message')) ?></textarea>
    </div>
    <div class="field">
      <label for="suggestions">Câu gợi ý (mỗi dòng một câu, hiện 4 câu đầu)</label>
      <textarea id="suggestions" name="suggestions" rows="5"><?= e(setting('suggestions')) ?></textarea>
    </div>
  </div>

  <div class="form-section">
    <h3>🎨 Màu sắc &amp; giao diện</h3>
    <div class="form-grid form-grid-3">
      <div class="field">
        <label for="theme_primary">Màu chính</label>
        <div class="row" style="gap:.5rem;flex-wrap:nowrap">
          <input type="color" id="theme_primary_picker" value="<?= e(setting('theme_primary')) ?>"
                 style="width:52px;padding:.2rem;height:44px">
          <input type="text" id="theme_primary" name="theme_primary" value="<?= e(setting('theme_primary')) ?>"
                 pattern="#[0-9a-fA-F]{6}">
        </div>
      </div>
      <div class="field">
        <label for="theme_accent">Màu nhấn</label>
        <div class="row" style="gap:.5rem;flex-wrap:nowrap">
          <input type="color" id="theme_accent_picker" value="<?= e(setting('theme_accent')) ?>"
                 style="width:52px;padding:.2rem;height:44px">
          <input type="text" id="theme_accent" name="theme_accent" value="<?= e(setting('theme_accent')) ?>"
                 pattern="#[0-9a-fA-F]{6}">
        </div>
      </div>
      <div class="field">
        <label for="theme_mode">Giao diện mặc định</label>
        <select id="theme_mode" name="theme_mode">
          <option value="light" <?= setting('theme_mode') === 'light' ? 'selected' : '' ?>>☀️ Sáng</option>
          <option value="dark"  <?= setting('theme_mode') === 'dark' ? 'selected' : '' ?>>🌙 Tối</option>
        </select>
      </div>
    </div>
    <div class="row" style="gap:.6rem">
      <label class="switch">
        <input type="checkbox" name="show_version" value="1" <?= setting_bool('show_version') ? 'checked' : '' ?>>
        <span class="switch-track"></span><span class="text-sm">Hiện số phiên bản ở chân trang</span>
      </label>
    </div>
    <div class="field mt-2">
      <label for="github_url">Địa chỉ GitHub của dự án</label>
      <input type="url" id="github_url" name="github_url" value="<?= e(setting('github_url')) ?>"
             placeholder="https://github.com/tai-khoan/ten-repo">
      <span class="hint">Hiện thành liên kết GitHub ở chân trang, cạnh số phiên bản.</span>
    </div>
  </div>

  <div class="form-section">
    <h3>🔐 Truy cập</h3>
    <div class="row" style="gap:1.2rem;flex-wrap:wrap">
      <label class="switch">
        <input type="checkbox" name="allow_register" value="1" <?= setting_bool('allow_register') ? 'checked' : '' ?>>
        <span class="switch-track"></span><span class="text-sm">Cho phép khách tự đăng ký tài khoản</span>
      </label>
      <label class="switch">
        <input type="checkbox" name="maintenance" value="1" <?= setting_bool('maintenance') ? 'checked' : '' ?>>
        <span class="switch-track"></span><span class="text-sm">Bật chế độ bảo trì (chỉ quản trị viên dùng được)</span>
      </label>
    </div>
    <div class="field mt-2">
      <label for="maintenance_note">Thông báo khi bảo trì</label>
      <input type="text" id="maintenance_note" name="maintenance_note" value="<?= e(setting('maintenance_note')) ?>">
    </div>
    <div class="field">
      <label for="admin_email">Email liên hệ quản trị</label>
      <input type="email" id="admin_email" name="admin_email" value="<?= e(setting('admin_email')) ?>">
    </div>
  </div>

  <div class="form-section">
    <h3>📎 Tệp &amp; ngữ cảnh</h3>
    <div class="form-grid form-grid-3">
      <div class="field">
        <label for="max_upload_mb">Dung lượng tối đa mỗi tệp (MB)</label>
        <input type="number" id="max_upload_mb" name="max_upload_mb" value="<?= setting_int('max_upload_mb', 25) ?>" min="1" max="512">
        <?php if ($phpEffective > 0): ?>
          <span class="hint">Giới hạn thực tế của hosting hiện là <strong><?= $phpEffective ?>MB</strong>
            (<code class="inline-code">upload_max_filesize</code> / <code class="inline-code">post_max_size</code>).
            Đặt cao hơn mức này sẽ không có tác dụng.</span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="max_files_per_msg">Số tệp tối đa mỗi tin nhắn</label>
        <input type="number" id="max_files_per_msg" name="max_files_per_msg"
               value="<?= setting_int('max_files_per_msg', 10) ?>" min="1" max="50">
      </div>
      <div class="field">
        <label for="history_limit">Số tin nhắn ngữ cảnh mặc định</label>
        <input type="number" id="history_limit" name="history_limit" value="<?= setting_int('history_limit', 20) ?>" min="2" max="200">
        <span class="hint">Endpoint có thể ghi đè giá trị này.</span>
      </div>
    </div>
    <div class="field">
      <label for="allowed_ext">Định dạng tệp được phép (cách nhau bởi dấu phẩy)</label>
      <textarea id="allowed_ext" name="allowed_ext" rows="3"><?= e(setting('allowed_ext')) ?></textarea>
      <span class="hint">Các định dạng có thể thực thi (php, sh, exe…) luôn bị chặn vì lý do an toàn.</span>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">💾 Lưu cấu hình</button>
  </div>
</form>

<div class="form-section mt-2" style="border-color:color-mix(in srgb, var(--danger) 35%, transparent)">
  <h3>⚠️ Đưa về mặc định</h3>
  <div class="row row-between">
    <p class="text-muted mb-0">Xoá mọi tuỳ chỉnh và dùng lại giá trị mặc định của hệ thống.
      Không ảnh hưởng tới người dùng, endpoint hay lịch sử chat.</p>
    <form method="post" data-confirm="Đưa toàn bộ cấu hình website về mặc định?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset">
      <button type="submit" class="btn btn-danger btn-sm">Đặt lại cấu hình</button>
    </form>
  </div>
</div>

<div class="card mt-2">
  <h3 class="card-title">ℹ️ Thông tin hệ thống</h3>
  <div class="table-wrap">
    <table class="table">
      <tbody>
        <tr><td>Phiên bản ứng dụng</td><td class="cell-strong">v<?= e(APP_VERSION) ?> — <?= e(APP_CODENAME) ?> (<?= e(APP_VERSION_DATE) ?>)</td></tr>
        <tr><td>Phiên bản PHP</td><td><?= e(PHP_VERSION) ?></td></tr>
        <tr><td>Múi giờ</td><td><?= e(APP_TZ) ?> — hiện tại <?= e(fmt_datetime(now_vn())) ?></td></tr>
        <tr><td>Máy chủ MySQL</td><td><?= e(db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></td></tr>
        <tr><td>cURL</td><td><?= function_exists('curl_init') ? '✅ Có (' . e(curl_version()['version']) . ')' : '❌ Không có — bắt buộc phải bật' ?></td></tr>
        <tr><td>OpenSSL</td><td><?= function_exists('openssl_encrypt') ? '✅ Có' : '⚠️ Không có — API key sẽ không được mã hoá' ?></td></tr>
        <tr><td>ZipArchive</td><td><?= class_exists('ZipArchive') ? '✅ Có (đọc được docx/xlsx/pptx)' : '⚠️ Không có' ?></td></tr>
        <tr><td>Thư mục uploads</td><td><?= is_writable(upload_path()) ? '✅ Ghi được' : '❌ Không ghi được — hãy đặt quyền 755' ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
['theme_primary', 'theme_accent'].forEach(function (name) {
  var picker = document.getElementById(name + '_picker');
  var text = document.getElementById(name);
  if (!picker || !text) return;
  picker.addEventListener('input', function () { text.value = picker.value; });
  text.addEventListener('input', function () {
    if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
  });
});
</script>
<?php
admin_foot();
