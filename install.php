<?php
/**
 * Trình cài đặt — chạy một lần khi mới tải mã nguồn lên hosting.
 *
 * Các bước: kiểm tra máy chủ → kết nối MySQL → tạo bảng → tạo tài khoản quản trị
 *           → cấu hình AI đầu tiên → hoàn tất.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$configFile   = APP_ROOT . '/config/config.php';
$configExists = file_exists($configFile);

// Nếu đã cài xong (có config + có quản trị viên) thì khoá trình cài đặt lại.
$hasAdmin = false;
if ($configExists) {
    try {
        $hasAdmin = db_table_exists('users')
            && (int)db_value("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'", [], 0) > 0;
    } catch (Exception $ex) {
        $hasAdmin = false;
    }
}

// Phiên cài đặt đang chạy vẫn được đi tiếp các bước còn lại, miễn là người đang
// thao tác chính là quản trị viên vừa được tạo.
$installUnlocked  = !empty($_SESSION['install_in_progress']) && is_admin();
$alreadyInstalled = $hasAdmin && !$installUnlocked;

$step   = max(1, min(5, (int)($_GET['step'] ?? 1)));
$errors = [];
$notice = '';

/** Danh sách yêu cầu hệ thống. */
function install_requirements()
{
    $configDir = APP_ROOT . '/config';
    return [
        ['PHP phiên bản 7.4 trở lên', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION, true],
        ['Phần mở rộng PDO MySQL',    extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'có' : 'thiếu', true],
        ['Phần mở rộng cURL',         function_exists('curl_init'), function_exists('curl_init') ? 'có' : 'thiếu', true],
        ['Phần mở rộng mbstring',     extension_loaded('mbstring'), extension_loaded('mbstring') ? 'có' : 'thiếu', true],
        ['Phần mở rộng JSON',         function_exists('json_encode'), 'có', true],
        ['Phần mở rộng OpenSSL',      function_exists('openssl_encrypt'), function_exists('openssl_encrypt') ? 'có' : 'thiếu', false],
        ['Phần mở rộng ZipArchive',   class_exists('ZipArchive'), class_exists('ZipArchive') ? 'có' : 'thiếu', false],
        ['Phần mở rộng fileinfo',     function_exists('finfo_open'), function_exists('finfo_open') ? 'có' : 'thiếu', false],
        ['Thư mục config/ ghi được',  is_writable($configDir), is_writable($configDir) ? 'ghi được' : 'không ghi được', true],
        // Không cần thư mục uploads: tệp đính kèm và phiên đăng nhập đều nằm
        // trong cơ sở dữ liệu nên ứng dụng không ghi tệp nào xuống đĩa.
    ];
}

/**
 * Chạy toàn bộ lệnh trong sql/schema.sql.
 * Bộ tách câu lệnh (db_split_sql) hiểu chuỗi và chú thích nên dấu chấm phẩy
 * nằm trong phần COMMENT của cột không làm câu lệnh bị cắt sai.
 */
function install_run_schema(PDO $pdo)
{
    return db_run_sql_file(APP_ROOT . '/sql/schema.sql', $pdo);
}

// =============================================================================
//  Xử lý các bước
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $action = $_POST['action'] ?? '';

    // ---- Bước 2: kết nối MySQL & ghi config --------------------------------
    if ($action === 'db') {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string)($_POST['db_pass'] ?? '');

        if ($dbName === '' || $dbUser === '') {
            $errors[] = 'Vui lòng nhập tên cơ sở dữ liệu và tên người dùng MySQL.';
        } else {
            try {
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Ghi file cấu hình.
                $appKey = bin2hex(random_bytes(32));
                $tpl = "<?php\n"
                     . "/**\n * Cấu hình Tuấn Chatbot — được tạo tự động bởi install.php lúc "
                     . date('H:i d/m/Y') . " (giờ Việt Nam).\n */\n\n"
                     . "return [\n"
                     . "    'db_host'    => " . var_export($dbHost, true) . ",\n"
                     . "    'db_port'    => " . var_export($dbPort, true) . ",\n"
                     . "    'db_name'    => " . var_export($dbName, true) . ",\n"
                     . "    'db_user'    => " . var_export($dbUser, true) . ",\n"
                     . "    'db_pass'    => " . var_export($dbPass, true) . ",\n"
                     . "    'db_charset' => 'utf8mb4',\n\n"
                     . "    // Khoá mã hoá API key — KHÔNG đổi sau khi đã lưu key.\n"
                     . "    'app_key'    => " . var_export($appKey, true) . ",\n\n"
                     . "    'timezone'   => 'Asia/Ho_Chi_Minh',\n\n"
                     . "    // Thư mục tệp cũ — chỉ để đọc lại dữ liệu từ bản trước khi\n"
                     . "    // chuyển sang lưu tệp trong cơ sở dữ liệu. Bản mới không ghi vào đây.\n"
                     . "    'upload_dir' => 'uploads',\n"
                     . "    'debug'      => false,\n"
                     . "];\n";

                if (@file_put_contents($configFile, $tpl) === false) {
                    $errors[] = 'Không ghi được file config/config.php. Hãy đặt quyền 755 cho thư mục config/.';
                } else {
                    @chmod($configFile, 0644);
                    // Nạp lại cấu hình vừa ghi để các bước sau dùng được.
                    $GLOBALS['APP_CONFIG'] = require $configFile;
                    install_run_schema($pdo);
                    $_SESSION['install_db_ok'] = true;
                    redirect('install.php?step=3');
                }
            } catch (PDOException $ex) {
                $errors[] = 'Không kết nối được MySQL: ' . $ex->getMessage();
            } catch (Exception $ex) {
                $errors[] = 'Lỗi khi tạo bảng: ' . $ex->getMessage();
            }
        }
        $step = 2;
    }

    // ---- Bước 3: tạo tài khoản quản trị ------------------------------------
    if ($action === 'admin') {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $password  = (string)($_POST['password'] ?? '');
        $siteName  = trim($_POST['site_name'] ?? APP_NAME_DEFAULT);
        $copyright = trim($_POST['copyright'] ?? '');

        $errors = array_merge($errors, validate_username($username), validate_password($password));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ.';
        }
        if ($siteName === '') {
            $errors[] = 'Vui lòng nhập tên website.';
        }

        if (!$errors) {
            try {
                if ((int)db_value('SELECT COUNT(*) FROM `users` WHERE `username` = ? OR `email` = ?',
                        [$username, $email], 0) > 0) {
                    $errors[] = 'Tên đăng nhập hoặc email này đã tồn tại trong cơ sở dữ liệu.';
                } else {
                    $userId = db_insert('users', [
                        'username'      => $username,
                        'email'         => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'full_name'     => $fullName,
                        'avatar_emoji'  => '👑',
                        'avatar_color'  => '#7c5cff',
                        'role'          => 'admin',
                        'status'        => 'active',
                        'created_at'    => now_vn(),
                        'updated_at'    => now_vn(),
                    ]);

                    settings_save([
                        'site_name'   => $siteName,
                        'copyright'   => $copyright !== '' ? $copyright
                                        : ('© ' . date('Y') . ' ' . $siteName . ' — Mọi quyền được bảo lưu'),
                        'admin_email' => $email,
                    ]);

                    // Ghi nhận phiên bản khởi tạo.
                    try {
                        db_run('INSERT IGNORE INTO `app_versions` (`version`, `released_at`, `notes`) VALUES (?, ?, ?)',
                            [APP_VERSION, now_vn(), 'Cài đặt lần đầu']);
                    } catch (Exception $ex) {}

                    log_activity('install', 'Cài đặt hệ thống, tạo quản trị viên: ' . $username, $userId);
                    auth_login_user($userId, false);
                    $_SESSION['install_in_progress'] = true;
                    redirect('install.php?step=4');
                }
            } catch (Exception $ex) {
                $errors[] = 'Lỗi khi tạo tài khoản: ' . $ex->getMessage();
            }
        }
        $step = 3;
    }

    // ---- Bước 4: endpoint AI đầu tiên --------------------------------------
    if ($action === 'endpoint') {
        require_once APP_ROOT . '/includes/ai.php';

        if (!empty($_POST['skip'])) {
            redirect('install.php?step=5');
        }

        $name     = trim($_POST['name'] ?? '');
        $apiType  = $_POST['api_type'] ?? 'openai';
        $baseUrl  = trim($_POST['base_url'] ?? '');
        $apiKey   = trim($_POST['api_key'] ?? '');
        $model    = trim($_POST['model'] ?? '');

        if ($name === '')    { $errors[] = 'Vui lòng đặt tên cho endpoint.'; }
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            $errors[] = 'URL gốc phải bắt đầu bằng http:// hoặc https://';
        }
        if ($model === '')   { $errors[] = 'Vui lòng nhập tên mô hình.'; }
        if (!array_key_exists($apiType, ai_types())) { $errors[] = 'Loại API không hợp lệ.'; }

        if (!$errors) {
            db_insert('endpoints', [
                'name'            => $name,
                'description'     => 'Endpoint được tạo khi cài đặt',
                'api_type'        => $apiType,
                'base_url'        => $baseUrl,
                'api_key'         => crypto_encrypt($apiKey),
                'model'           => $model,
                'max_tokens'      => 64000,
                'timeout'         => 300,
                'temperature'     => 1.00,
                'top_p'           => 1.00,
                'system_prompt'   => "Bạn là trợ lý AI thân thiện, trả lời bằng tiếng Việt rõ ràng, chính xác và có cấu trúc.\n"
                                   . "Khi trình bày công thức toán, hãy dùng LaTeX. Khi viết mã, hãy dùng khối mã có ghi tên ngôn ngữ.",
                'supports_vision' => 1,
                'supports_files'  => 1,
                'supports_stream' => 1,
                'history_limit'   => 20,
                'is_default'      => 1,
                'is_active'       => 1,
                'created_at'      => now_vn(),
                'updated_at'      => now_vn(),
            ]);
            log_activity('endpoint_create', 'Tạo endpoint khi cài đặt: ' . $name);
            redirect('install.php?step=5');
        }
        $step = 4;
    }
}

// Không cho nhảy bước khi chưa có config.
if (!$configExists && $step > 2) {
    $step = 1;
}

// Tới bước cuối là kết thúc phiên cài đặt: mở lại trang này sẽ thấy thông báo
// "đã cài đặt" thay vì chạy lại trình cài đặt.
if ($step === 5 && $hasAdmin) {
    unset($_SESSION['install_in_progress']);
}

require_once __DIR__ . '/includes/layout.php';
layout_head('Cài đặt hệ thống');
?>
<div class="page page-narrow">
  <div class="page-head text-center">
    <h1 style="justify-content:center">🚀 Cài đặt <?= e(APP_NAME_DEFAULT) ?></h1>
    <p>Phiên bản v<?= e(APP_VERSION) ?> · Giờ Việt Nam (UTC+7)</p>
  </div>

  <?php if ($alreadyInstalled): ?>
    <div class="card text-center">
      <span class="empty-emoji">✅</span>
      <h2>Hệ thống đã được cài đặt</h2>
      <p class="text-muted">
        Để bảo đảm an toàn, hãy <strong>xoá file <code class="inline-code">install.php</code></strong>
        khỏi hosting. Nếu muốn cài lại từ đầu, hãy xoá file
        <code class="inline-code">config/config.php</code> rồi truy cập lại trang này.
      </p>
      <div class="row" style="justify-content:center">
        <a class="btn btn-primary" href="index.php">💬 Vào trang trò chuyện</a>
        <a class="btn btn-ghost" href="admin/index.php">🛠️ Khu vực quản trị</a>
      </div>
    </div>
    <?php layout_foot(); exit; ?>
  <?php endif; ?>

  <!-- Thanh tiến trình -->
  <div class="wizard-steps">
    <?php
    $stepLabels = [1 => 'Kiểm tra', 2 => 'Cơ sở dữ liệu', 3 => 'Quản trị viên', 4 => 'Kết nối AI', 5 => 'Hoàn tất'];
    foreach ($stepLabels as $num => $label): ?>
      <div class="wizard-step<?= $num === $step ? ' is-active' : ($num < $step ? ' is-done' : '') ?>">
        <span class="wizard-dot"><?= $num < $step ? '✓' : $num ?></span>
        <span class="wizard-label"><?= e($label) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="flash flash-error mb-2"><span class="flash-icon">😿</span><span><?= e($error) ?></span></div>
  <?php endforeach; ?>

  <?php if ($step === 1): ?>
    <!-- ===================== BƯỚC 1 ===================== -->
    <div class="card">
      <h2 class="card-title">🔎 Kiểm tra máy chủ</h2>
      <?php
      $reqs = install_requirements();
      $blocked = false;
      foreach ($reqs as $req) {
          if ($req[3] && !$req[1]) { $blocked = true; }
      }
      ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Yêu cầu</th><th>Hiện trạng</th><th>Kết quả</th></tr></thead>
          <tbody>
          <?php foreach ($reqs as $req): ?>
            <tr>
              <td><?= e($req[0]) ?> <?= $req[3] ? '' : '<span class="badge badge-muted">tuỳ chọn</span>' ?></td>
              <td class="cell-muted"><?= e($req[2]) ?></td>
              <td><?= $req[1] ? '<span class="badge badge-ok">✅ OK</span>'
                             : ($req[3] ? '<span class="badge badge-danger">❌ Thiếu</span>'
                                        : '<span class="badge badge-warn">⚠️ Nên có</span>') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($blocked): ?>
        <div class="test-result is-fail mt-2">
          ❌ Máy chủ còn thiếu thành phần bắt buộc. Hãy liên hệ nhà cung cấp hosting để bật các
          phần mở rộng còn thiếu, hoặc cấp quyền ghi cho thư mục, rồi tải lại trang này.
        </div>
        <div class="row mt-2"><a class="btn btn-ghost" href="install.php?step=1">🔄 Kiểm tra lại</a></div>
      <?php else: ?>
        <div class="test-result is-ok mt-2">✅ Máy chủ đáp ứng đầy đủ yêu cầu. Hãy tiếp tục bước sau.</div>
        <div class="row row-end mt-2">
          <a class="btn btn-primary" href="install.php?step=2">Tiếp tục →</a>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($step === 2): ?>
    <!-- ===================== BƯỚC 2 ===================== -->
    <div class="card">
      <h2 class="card-title">🗄️ Kết nối cơ sở dữ liệu MySQL</h2>
      <p class="text-muted">
        Hãy tạo một database MySQL trống trong cPanel/DirectAdmin của hosting, rồi nhập thông tin
        vào đây. Hệ thống sẽ tự tạo toàn bộ bảng cần thiết.
      </p>
      <form method="post">
        <input type="hidden" name="action" value="db">
        <div class="form-grid" style="display:grid;gap:0 1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
          <div class="field">
            <label for="db_host">Máy chủ MySQL</label>
            <input type="text" id="db_host" name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>" required>
            <span class="hint">Thường là <code class="inline-code">localhost</code>.</span>
          </div>
          <div class="field">
            <label for="db_port">Cổng</label>
            <input type="number" id="db_port" name="db_port" value="<?= e($_POST['db_port'] ?? 3306) ?>" required>
          </div>
          <div class="field">
            <label for="db_name">Tên database</label>
            <input type="text" id="db_name" name="db_name" value="<?= e($_POST['db_name'] ?? '') ?>" required
                   placeholder="vd: user_chatbot">
          </div>
          <div class="field">
            <label for="db_user">Người dùng MySQL</label>
            <input type="text" id="db_user" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>" required>
          </div>
        </div>
        <div class="field">
          <label for="db_pass">Mật khẩu MySQL</label>
          <div class="password-wrap">
            <input type="password" id="db_pass" name="db_pass" value="<?= e($_POST['db_pass'] ?? '') ?>">
            <button type="button" class="password-toggle" data-toggle-password="#db_pass">👁️</button>
          </div>
        </div>
        <div class="row row-between mt-2">
          <a class="btn btn-ghost" href="install.php?step=1">← Quay lại</a>
          <button type="submit" class="btn btn-primary">Kết nối &amp; tạo bảng →</button>
        </div>
      </form>
    </div>

  <?php elseif ($step === 3): ?>
    <!-- ===================== BƯỚC 3 ===================== -->
    <div class="card">
      <h2 class="card-title">👑 Tạo tài khoản quản trị viên</h2>
      <p class="text-muted">Tài khoản này có toàn quyền: xem lịch sử chat, cấu hình AI API, quản lý người dùng.</p>
      <form method="post">
        <input type="hidden" name="action" value="admin">
        <div class="form-grid" style="display:grid;gap:0 1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
          <div class="field">
            <label for="site_name">Tên website</label>
            <input type="text" id="site_name" name="site_name"
                   value="<?= e($_POST['site_name'] ?? APP_NAME_DEFAULT) ?>" required>
          </div>
          <div class="field">
            <label for="copyright">Dòng bản quyền ở chân trang</label>
            <input type="text" id="copyright" name="copyright"
                   value="<?= e($_POST['copyright'] ?? ('© ' . date('Y') . ' ' . APP_NAME_DEFAULT . ' — Mọi quyền được bảo lưu')) ?>">
          </div>
          <div class="field">
            <label for="username">Tên đăng nhập</label>
            <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>"
                   required pattern="[a-zA-Z0-9_.]{3,50}">
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="full_name">Họ và tên</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="password">Mật khẩu</label>
            <div class="password-wrap">
              <input type="password" id="password" name="password" required data-strength-input>
              <button type="button" class="password-toggle" data-toggle-password="#password">👁️</button>
            </div>
            <div class="strength" data-strength-bar><i></i></div>
            <span class="hint" data-strength-text>Tối thiểu 8 ký tự, có cả chữ và số.</span>
          </div>
        </div>
        <div class="row row-end mt-2">
          <button type="submit" class="btn btn-primary">Tạo tài khoản →</button>
        </div>
      </form>
    </div>

  <?php elseif ($step === 4): ?>
    <!-- ===================== BƯỚC 4 ===================== -->
    <?php require_once APP_ROOT . '/includes/ai.php'; ?>
    <div class="card">
      <h2 class="card-title">🔌 Kết nối AI đầu tiên</h2>
      <p class="text-muted">
        Nhập thông tin dịch vụ AI bạn muốn dùng. Có thể bỏ qua bước này và cấu hình sau
        trong khu vực quản trị.
      </p>
      <form method="post">
        <input type="hidden" name="action" value="endpoint">
        <div class="form-grid" style="display:grid;gap:0 1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
          <div class="field">
            <label for="name">Tên hiển thị</label>
            <input type="text" id="name" name="name" value="<?= e($_POST['name'] ?? 'Trợ lý chính') ?>" required>
          </div>
          <div class="field">
            <label for="api_type">Loại API</label>
            <select id="api_type" name="api_type" required>
              <?php foreach (ai_types() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($_POST['api_type'] ?? 'openai') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="base_url">URL gốc của API</label>
            <input type="url" id="base_url" name="base_url"
                   value="<?= e($_POST['base_url'] ?? 'https://api.openai.com/v1') ?>" required>
          </div>
          <div class="field">
            <label for="model">Tên mô hình</label>
            <input type="text" id="model" name="model" value="<?= e($_POST['model'] ?? 'gpt-4o-mini') ?>" required>
          </div>
        </div>
        <div class="field">
          <label for="api_key">API Key</label>
          <div class="password-wrap">
            <input type="password" id="api_key" name="api_key" value="" placeholder="sk-…">
            <button type="button" class="password-toggle" data-toggle-password="#api_key">👁️</button>
          </div>
          <span class="hint">Được mã hoá AES-256 trước khi lưu vào cơ sở dữ liệu.</span>
        </div>
        <div class="row row-between mt-2">
          <button type="submit" class="btn btn-ghost" name="skip" value="1">Bỏ qua, cấu hình sau</button>
          <button type="submit" class="btn btn-primary">Lưu &amp; hoàn tất →</button>
        </div>
      </form>
    </div>

  <?php else: ?>
    <!-- ===================== BƯỚC 5 ===================== -->
    <div class="card text-center">
      <span class="empty-emoji">🎉</span>
      <h2>Cài đặt hoàn tất!</h2>
      <p class="text-muted">
        <?= e(site_name()) ?> đã sẵn sàng hoạt động. Bạn đang đăng nhập với quyền quản trị viên.
      </p>

      <div class="test-result is-fail" style="text-align:left">
        <strong>⚠️ Việc cần làm ngay để bảo mật:</strong>
        <ol style="margin:.5rem 0 0;padding-left:1.3rem">
          <li>Xoá file <code class="inline-code">install.php</code> khỏi hosting.</li>
          <li>Bảo đảm thư mục <code class="inline-code">config/</code> không truy cập được từ web (đã có <code class="inline-code">.htaccess</code> sẵn).</li>
          <li>Bật HTTPS cho tên miền (Let's Encrypt miễn phí trong cPanel).</li>
        </ol>
      </div>

      <div class="row mt-2" style="justify-content:center">
        <a class="btn btn-primary btn-lg" href="index.php">💬 Bắt đầu trò chuyện</a>
        <a class="btn btn-ghost" href="admin/index.php">🛠️ Khu vực quản trị</a>
      </div>
    </div>
    <script>window.addEventListener('load', function(){ if (window.confetti) window.confetti(130); });</script>
  <?php endif; ?>
</div>

<style>
.wizard-steps {
  display: flex; align-items: center; justify-content: space-between;
  gap: .3rem; margin-bottom: 1.6rem; position: relative;
}
.wizard-step { display: flex; flex-direction: column; align-items: center; gap: .35rem; flex: 1; position: relative; }
.wizard-step:not(:last-child)::after {
  content: ""; position: absolute; top: 17px; left: 60%; right: -40%; height: 2px;
  background: var(--border); z-index: 0;
}
.wizard-step.is-done:not(:last-child)::after { background: var(--brand); }
.wizard-dot {
  width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center;
  background: var(--surface-solid); border: 2px solid var(--border);
  font-weight: 720; font-size: .9rem; color: var(--text-muted); z-index: 1;
  transition: all .3s var(--ease-bounce);
}
.wizard-step.is-active .wizard-dot {
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  border-color: transparent; color: #fff; transform: scale(1.12);
  box-shadow: var(--shadow-brand);
}
.wizard-step.is-done .wizard-dot { background: var(--brand); border-color: transparent; color: #fff; }
.wizard-label { font-size: .78rem; color: var(--text-muted); text-align: center; font-weight: 600; }
.wizard-step.is-active .wizard-label { color: var(--brand); }
@media (max-width: 560px) { .wizard-label { display: none; } }
</style>
<?php
layout_foot();
