<?php
/**
 * Quản lý tài liệu đi kèm — nội dung được nối vào system prompt của endpoint.
 */
require_once __DIR__ . '/_init.php';

$endpoints = db_all('SELECT `id`, `name` FROM `endpoints` ORDER BY `sort_order`, `id`');
$editId    = (int)($_GET['edit'] ?? 0);
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = db_value('SELECT `title` FROM `documents` WHERE `id` = ?', [$id], '');
        db_run('DELETE FROM `documents` WHERE `id` = ?', [$id]);
        log_activity('document_delete', 'Xoá tài liệu: ' . $title);
        flash('success', 'Đã xoá tài liệu.');
        redirect('documents.php');
    }

    if ($action === 'toggle') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = (int)db_value('SELECT `is_active` FROM `documents` WHERE `id` = ?', [$id], 0);
        db_update('documents', ['is_active' => $current ? 0 : 1, 'updated_at' => now_vn()], '`id` = ?', [$id]);
        redirect('documents.php');
    }

    // ---- Lưu / cập nhật ----------------------------------------------------
    $id         = (int)($_POST['id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $endpointId = (int)($_POST['endpoint_id'] ?? 0);
    $content    = (string)($_POST['content'] ?? '');
    $isActive   = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $fileName   = '';
    $mime       = 'text/plain';

    // Nếu có tệp tải lên thì trích nội dung văn bản từ tệp.
    if (!empty($_FILES['doc_file']['tmp_name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $tmp      = $_FILES['doc_file']['tmp_name'];
        $origName = safe_filename($_FILES['doc_file']['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $mime     = detect_mime($tmp, mime_from_ext($ext));
        $kind     = file_kind($mime, $ext);

        if ($kind === 'image' || $kind === 'audio' || $kind === 'video') {
            $errors[] = 'Tài liệu đi kèm cần là tệp văn bản, PDF hoặc Office (docx/xlsx/pptx).';
        } else {
            $extracted = extract_text_from_file($tmp, $kind, $ext, 400000);
            if (trim($extracted) === '') {
                $errors[] = 'Không đọc được nội dung văn bản từ tệp "' . $origName
                          . '". Với PDF dạng ảnh scan, hãy dán nội dung thủ công.';
            } else {
                $content  = $extracted;
                $fileName = $origName;
                if ($title === '') {
                    $title = pathinfo($origName, PATHINFO_FILENAME);
                }
            }
        }
    }

    if ($title === '') {
        $errors[] = 'Vui lòng nhập tiêu đề tài liệu.';
    }
    if (trim($content) === '') {
        $errors[] = 'Nội dung tài liệu không được để trống.';
    }

    if (!$errors) {
        $data = [
            'endpoint_id' => $endpointId > 0 ? $endpointId : null,
            'title'       => mb_substr($title, 0, 190),
            'mime'        => $mime,
            'size'        => strlen($content),
            'content'     => $content,
            'is_active'   => $isActive,
            'sort_order'  => $sortOrder,
            'updated_at'  => now_vn(),
        ];
        if ($fileName !== '') {
            $data['file_name'] = $fileName;
        }

        if ($id > 0) {
            db_update('documents', $data, '`id` = ?', [$id]);
            log_activity('document_update', 'Cập nhật tài liệu: ' . $title);
            flash('success', 'Đã cập nhật tài liệu "' . $title . '".');
        } else {
            $data['file_name'] = $fileName;
            $data['created_at'] = now_vn();
            db_insert('documents', $data);
            log_activity('document_create', 'Thêm tài liệu: ' . $title);
            flash('success', 'Đã thêm tài liệu "' . $title . '"! 📚');
        }
        redirect('documents.php');
    }
}

$editDoc = $editId ? db_one('SELECT * FROM `documents` WHERE `id` = ?', [$editId]) : null;
$docs    = db_all(
    'SELECT d.*, e.`name` AS endpoint_name FROM `documents` d
     LEFT JOIN `endpoints` e ON e.`id` = d.`endpoint_id`
     ORDER BY d.`sort_order`, d.`id` DESC'
);
$totalChars = (int)db_value('SELECT COALESCE(SUM(CHAR_LENGTH(`content`)),0) FROM `documents` WHERE `is_active` = 1', [], 0);

admin_head('Tài liệu đi kèm', 'documents');
?>
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error mb-2"><span class="flash-icon">😿</span><span><?= e($error) ?></span></div>
<?php endforeach; ?>

<p class="text-muted mb-2" style="max-width:760px">
  Nội dung các tài liệu đang bật sẽ được nối vào system prompt mỗi lượt hỏi, giúp AI trả lời dựa trên
  dữ liệu riêng của bạn (nội quy, bảng giá, giáo trình…). Tài liệu gán cho một endpoint cụ thể chỉ áp dụng
  cho endpoint đó; để trống thì áp dụng cho mọi endpoint.
  <br><strong>Tổng dung lượng đang bật: <?= number_format($totalChars, 0, ',', '.') ?> ký tự</strong>
  (≈ <?= number_format((int)round($totalChars / 3.5), 0, ',', '.') ?> token — càng nhiều thì mỗi lượt hỏi càng tốn token).
</p>

<div class="grid" style="grid-template-columns:minmax(0,1fr);gap:1.2rem">
  <!-- Biểu mẫu -->
  <div class="form-section">
    <h3><?= $editDoc ? '✏️ Sửa tài liệu' : '➕ Thêm tài liệu mới' ?></h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editDoc ? (int)$editDoc['id'] : 0 ?>">

      <div class="form-grid form-grid-3">
        <div class="field">
          <label for="title">Tiêu đề <span class="text-muted">*</span></label>
          <input type="text" id="title" name="title" value="<?= e($editDoc['title'] ?? '') ?>"
                 placeholder="vd: Nội quy trung tâm 2026">
        </div>
        <div class="field">
          <label for="endpoint_id">Áp dụng cho</label>
          <select id="endpoint_id" name="endpoint_id">
            <option value="0">🌐 Mọi endpoint</option>
            <?php foreach ($endpoints as $ep): ?>
              <option value="<?= (int)$ep['id'] ?>"
                <?= ($editDoc && (int)$editDoc['endpoint_id'] === (int)$ep['id']) ? 'selected' : '' ?>>
                <?= e($ep['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="sort_order">Thứ tự</label>
          <input type="number" id="sort_order" name="sort_order" value="<?= (int)($editDoc['sort_order'] ?? 0) ?>">
        </div>
      </div>

      <div class="field">
        <label for="doc_file">Tải tệp lên (txt, md, csv, json, pdf, docx, xlsx, pptx…)</label>
        <input type="file" id="doc_file" name="doc_file"
               accept=".txt,.md,.csv,.json,.xml,.html,.pdf,.docx,.xlsx,.pptx,.log,.yml,.yaml">
        <span class="hint">Hệ thống tự trích xuất phần văn bản của tệp và điền vào khung nội dung bên dưới.</span>
      </div>

      <div class="field">
        <label for="content">Nội dung tài liệu <span class="text-muted">*</span></label>
        <textarea id="content" name="content" rows="12"
                  placeholder="Dán nội dung tài liệu vào đây…"><?= e($editDoc['content'] ?? '') ?></textarea>
        <span class="hint">Có thể để trống nếu bạn tải tệp lên ở trên.</span>
      </div>

      <label class="switch mb-2">
        <input type="checkbox" name="is_active" value="1" <?= (!$editDoc || (int)$editDoc['is_active'] === 1) ? 'checked' : '' ?>>
        <span class="switch-track"></span><span>Đang sử dụng</span>
      </label>

      <div class="row row-end">
        <?php if ($editDoc): ?>
          <a class="btn btn-ghost" href="documents.php">Huỷ</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= $editDoc ? '💾 Lưu thay đổi' : '📚 Thêm tài liệu' ?></button>
      </div>
    </form>
  </div>

  <!-- Danh sách -->
  <div class="table-card">
    <div class="table-card-head">
      <h2>📚 Danh sách tài liệu (<?= count($docs) ?>)</h2>
    </div>
    <?php if (!$docs): ?>
      <div class="empty"><span class="empty-emoji">📭</span>Chưa có tài liệu nào.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Tiêu đề</th><th>Áp dụng</th><th class="num">Ký tự</th><th>Trạng thái</th><th>Cập nhật</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($docs as $doc): ?>
            <tr>
              <td class="cell-strong">
                <?= e($doc['title']) ?>
                <?php if ($doc['file_name']): ?>
                  <div class="cell-muted">📄 <?= e($doc['file_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cell-muted"><?= $doc['endpoint_id'] ? e($doc['endpoint_name'] ?? '—') : '🌐 Mọi endpoint' ?></td>
              <td class="num"><?= number_format(mb_strlen((string)$doc['content']), 0, ',', '.') ?></td>
              <td>
                <?php if ((int)$doc['is_active'] === 1): ?>
                  <span class="badge badge-ok">● Đang dùng</span>
                <?php else: ?>
                  <span class="badge badge-muted">○ Tắt</span>
                <?php endif; ?>
              </td>
              <td class="cell-muted"><?= e(fmt_relative($doc['updated_at'])) ?></td>
              <td class="cell-actions">
                <div class="row" style="gap:.25rem;justify-content:flex-end;flex-wrap:nowrap">
                  <a class="btn btn-ghost btn-sm" href="documents.php?edit=<?= (int)$doc['id'] ?>">Sửa</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm"><?= (int)$doc['is_active'] === 1 ? '⏸' : '▶' ?></button>
                  </form>
                  <form method="post" style="display:inline" data-confirm="Xoá tài liệu &quot;<?= e($doc['title']) ?>&quot;?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
admin_foot();
