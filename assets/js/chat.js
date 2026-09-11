/* =============================================================================
   Tuấn Chatbot — Bộ máy trò chuyện
   Xử lý: luồng phản hồi thời gian thực, Markdown, LaTeX, tệp đính kèm,
   danh sách cuộc trò chuyện, xuất nội dung.
   ========================================================================== */
(function () {
  'use strict';

  var CFG = window.TCHAT || {};
  var API = (CFG.base || '') + '/api/';

  /* =========================================================================
     1. Trạng thái
     ====================================================================== */
  var state = {
    conversationId: CFG.activeConv || 0,
    conversations: [],
    pendingFiles: [],       // tệp đã tải lên, chờ gửi kèm
    streaming: false,
    controller: null,
    autoScroll: true,
  };

  /* =========================================================================
     2. Tham chiếu DOM
     ====================================================================== */
  var el = {
    app:        document.getElementById('chatApp'),
    convList:   document.getElementById('convList'),
    convSearch: document.getElementById('convSearch'),
    scroll:     document.getElementById('chatScroll'),
    inner:      document.getElementById('chatInner'),
    welcome:    document.getElementById('welcomeScreen'),
    composer:   document.getElementById('composer'),
    input:      document.getElementById('messageInput'),
    send:       document.getElementById('btnSend'),
    attach:     document.getElementById('btnAttach'),
    fileInput:  document.getElementById('fileInput'),
    files:      document.getElementById('composerFiles'),
    fileWarn:   document.getElementById('composerFileWarn'),
    title:      document.getElementById('chatTitle'),
    titleText:  document.getElementById('chatTitleText'),
    subtitle:   document.getElementById('chatSubtitle'),
    endpoint:   document.getElementById('endpointSelect'),
    newChat:    document.getElementById('btnNewChat'),
    toggleSide: document.getElementById('btnToggleSidebar'),
    scrollDown: document.getElementById('btnScrollBottom'),
    exportBtn:  document.getElementById('btnExportChat'),
  };

  if (!el.app) return;

  /* =========================================================================
     3. Tiện ích
     ====================================================================== */
  function esc(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function api(action, payload, method) {
    var opts = {
      method: method || 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
      credentials: 'same-origin',
    };
    if (opts.method === 'POST') {
      opts.body = JSON.stringify(Object.assign({ action: action, csrf_token: CFG.csrf }, payload || {}));
    }
    var url = API + 'conversations.php' + (opts.method === 'GET'
      ? '?' + new URLSearchParams(Object.assign({ action: action }, payload || {})).toString()
      : '');
    return fetch(url, opts).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Máy chủ trả về dữ liệu không hợp lệ (mã ' + res.status + ').');
      });
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Có lỗi xảy ra.');
      return data;
    });
  }

  function debounce(fn, wait) {
    var timer;
    return function () {
      var args = arguments, self = this;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(self, args); }, wait);
    };
  }

  /* =========================================================================
     4. Markdown + LaTeX  (bộ dựng dùng chung: assets/js/md.js)
     ====================================================================== */
  function renderMarkdown(target, text) {
    if (window.TChatMD) {
      window.TChatMD.render(target, text);
    } else {
      target.textContent = String(text == null ? '' : text);
    }
  }

  /* =========================================================================
     5. Dựng tin nhắn
     ====================================================================== */
  function fileChipHTML(file) {
    var url = (CFG.base || '') + '/' + file.url;
    if (file.kind === 'image') {
      return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="file-img-link">' +
             '<img class="img-attach" src="' + esc(url) + '" alt="' + esc(file.name) + '" loading="lazy"></a>';
    }
    return '<a class="file-chip" href="' + esc(url) + '&dl=1" target="_blank" rel="noopener" download>' +
           '<span class="file-chip-icon">' + esc(file.icon || '📎') + '</span>' +
           '<span class="file-chip-meta"><span class="file-chip-name">' + esc(file.name) + '</span>' +
           '<span class="file-chip-size">' + esc(file.sizeText || '') + '</span></span></a>';
  }

  /** Tạo khung DOM cho một tin nhắn. */
  function buildMessage(msg) {
    var isUser = msg.role === 'user';
    var wrap = document.createElement('div');
    wrap.className = 'msg msg-' + (isUser ? 'user' : 'assistant');
    if (msg.id) wrap.dataset.id = msg.id;

    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.textContent = isUser ? (CFG.user.emoji || '🙂') : (CFG.brandEmoji || '🤖');

    var body = document.createElement('div');
    body.className = 'msg-body';

    // Tệp đính kèm
    if (msg.files && msg.files.length) {
      var filesWrap = document.createElement('div');
      filesWrap.className = 'msg-files';
      filesWrap.innerHTML = msg.files.map(fileChipHTML).join('');
      body.appendChild(filesWrap);
    }

    // Khối suy luận (nếu mô hình có trả về)
    if (msg.reasoning) {
      body.appendChild(buildReasoning(msg.reasoning));
    }

    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble' + (msg.status === 'error' ? ' is-error' : '');

    var content = document.createElement('div');
    content.className = 'md';
    bubble.appendChild(content);

    if (msg.status === 'error' && msg.error) {
      content.innerHTML = '<p>⚠️ <strong>Không nhận được phản hồi</strong></p><p>' + esc(msg.error) + '</p>';
    } else if (isUser) {
      content.textContent = msg.content || '';
      content.style.whiteSpace = 'pre-wrap';
    } else {
      renderMarkdown(content, msg.content || '');
    }
    body.appendChild(bubble);

    // Dòng thông tin phụ
    var meta = document.createElement('div');
    meta.className = 'msg-meta';
    meta.appendChild(metaText(msg));

    var actions = document.createElement('div');
    actions.className = 'msg-actions';
    actions.appendChild(makeAction('📋', 'Sao chép nội dung', function () {
      window.copyText(msg.content || '').then(function () { window.toast('success', 'Đã sao chép!'); });
    }));
    if (!isUser) {
      actions.appendChild(makeAction('🔄', 'Tạo lại câu trả lời', function () { regenerate(); }));
    }
    meta.appendChild(actions);
    body.appendChild(meta);

    wrap.appendChild(avatar);
    wrap.appendChild(body);
    return wrap;
  }

  function metaText(msg) {
    var span = document.createElement('span');
    var bits = [];
    if (msg.timeText) bits.push(msg.timeText);
    if (msg.model) bits.push(msg.model);
    if (msg.durationMs) bits.push((msg.durationMs / 1000).toFixed(1) + 's');
    if (msg.tokens && (msg.tokens.prompt || msg.tokens.completion)) {
      bits.push('↑' + msg.tokens.prompt + ' ↓' + msg.tokens.completion + ' token');
    }
    if (msg.status === 'aborted') bits.push('⏹ đã dừng');
    span.textContent = bits.join(' · ');
    span.className = 'msg-meta-text';
    return span;
  }

  function makeAction(icon, title, handler) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'msg-act';
    btn.title = title;
    btn.textContent = icon;
    btn.addEventListener('click', handler);
    return btn;
  }

  function buildReasoning(text) {
    var details = document.createElement('details');
    details.className = 'reasoning';
    var summary = document.createElement('summary');
    summary.textContent = 'Quá trình suy luận của mô hình';
    var inner = document.createElement('div');
    inner.className = 'reasoning-body';
    inner.textContent = text;
    details.appendChild(summary);
    details.appendChild(inner);
    return details;
  }

  /* =========================================================================
     6. Cuộn
     ====================================================================== */
  function scrollToBottom(smooth) {
    if (!el.scroll) return;
    el.scroll.scrollTo({ top: el.scroll.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  }

  if (el.scroll) {
    el.scroll.addEventListener('scroll', function () {
      var distance = el.scroll.scrollHeight - el.scroll.scrollTop - el.scroll.clientHeight;
      state.autoScroll = distance < 120;
      if (el.scrollDown) el.scrollDown.classList.toggle('is-visible', distance > 240);
    });
  }
  if (el.scrollDown) {
    el.scrollDown.addEventListener('click', function () {
      state.autoScroll = true;
      scrollToBottom(true);
    });
  }

  function maybeScroll() {
    if (state.autoScroll) scrollToBottom(false);
  }

  /* =========================================================================
     7. Danh sách cuộc trò chuyện
     ====================================================================== */
  function loadConversations(query) {
    return api('list', { q: query || '' }, 'GET').then(function (data) {
      state.conversations = data.conversations;
      renderConversations();
    }).catch(function (err) {
      el.convList.innerHTML = '<div class="empty text-sm">😿 ' + esc(err.message) + '</div>';
    });
  }

  function renderConversations() {
    if (!el.convList) return;
    if (!state.conversations.length) {
      el.convList.innerHTML = '<div class="empty text-sm"><span class="empty-emoji">🌱</span>' +
                              'Chưa có cuộc trò chuyện nào.<br>Hãy bắt đầu nhé!</div>';
      return;
    }

    var html = '';
    var lastGroup = '';
    var pinnedDone = false;

    state.conversations.forEach(function (conv) {
      var group = conv.pinned ? '📌 Đã ghim' : conv.group;
      if (!conv.pinned && !pinnedDone) pinnedDone = true;
      if (group !== lastGroup) {
        html += '<div class="conv-group-label">' + esc(group) + '</div>';
        lastGroup = group;
      }
      html += '<div class="conv-item' + (conv.id === state.conversationId ? ' is-active' : '') + '" data-id="' + conv.id + '">' +
                '<span class="conv-icon">' + (conv.pinned ? '📌' : '💬') + '</span>' +
                '<span class="conv-title" title="' + esc(conv.title) + '">' + esc(conv.title) + '</span>' +
                '<span class="conv-actions">' +
                  '<button type="button" class="conv-act" data-act="rename" title="Đổi tên">✏️</button>' +
                  '<button type="button" class="conv-act" data-act="pin" title="' + (conv.pinned ? 'Bỏ ghim' : 'Ghim') + '">📌</button>' +
                  // Quản trị viên có thể khoá quyền tự xoá lịch sử của người dùng.
                  (CFG.canDelete
                    ? '<button type="button" class="conv-act danger" data-act="delete" title="Xoá">🗑️</button>'
                    : '') +
                '</span>' +
              '</div>';
    });
    el.convList.innerHTML = html;
  }

  if (el.convList) {
    el.convList.addEventListener('click', function (ev) {
      var item = ev.target.closest('.conv-item');
      if (!item) return;
      var id = Number(item.dataset.id);
      var actBtn = ev.target.closest('.conv-act');

      if (!actBtn) {
        openConversation(id);
        if (window.innerWidth <= 900) el.app.classList.remove('sidebar-open');
        return;
      }

      var act = actBtn.dataset.act;
      var conv = state.conversations.filter(function (c) { return c.id === id; })[0];

      if (act === 'rename') {
        var name = window.prompt('Tên mới cho cuộc trò chuyện:', conv ? conv.title : '');
        if (name && name.trim()) {
          api('rename', { id: id, title: name.trim() }).then(function () {
            loadConversations(el.convSearch ? el.convSearch.value : '');
            if (id === state.conversationId) setTitle(name.trim());
            window.toast('success', 'Đã đổi tên cuộc trò chuyện.');
          }).catch(function (e) { window.toast('error', e.message); });
        }
      } else if (act === 'pin') {
        api('pin', { id: id }).then(function () {
          loadConversations(el.convSearch ? el.convSearch.value : '');
        }).catch(function (e) { window.toast('error', e.message); });
      } else if (act === 'delete') {
        window.confirmDialog('Xoá cuộc trò chuyện "' + (conv ? conv.title : '') + '"? Thao tác này không thể hoàn tác.')
          .then(function (yes) {
            if (!yes) return;
            api('delete', { id: id }).then(function () {
              if (id === state.conversationId) startNewChat(true);
              loadConversations(el.convSearch ? el.convSearch.value : '');
              window.toast('success', 'Đã xoá cuộc trò chuyện.');
            }).catch(function (e) { window.toast('error', e.message); });
          });
      }
    });
  }

  if (el.convSearch) {
    el.convSearch.addEventListener('input', debounce(function () {
      loadConversations(el.convSearch.value);
    }, 320));
  }

  /* =========================================================================
     8. Mở / tạo cuộc trò chuyện
     ====================================================================== */
  function setTitle(title, subtitle) {
    if (el.titleText) el.titleText.textContent = title;
    if (el.subtitle && subtitle !== undefined) el.subtitle.textContent = subtitle;
    document.title = title + ' · ' + (CFG.siteName || 'Chatbot');
  }

  function clearMessages() {
    if (!el.inner) return;
    Array.prototype.slice.call(el.inner.querySelectorAll('.msg')).forEach(function (node) { node.remove(); });
  }

  function showWelcome(show) {
    if (el.welcome) el.welcome.classList.toggle('hidden', !show);
  }

  function openConversation(id) {
    if (state.streaming) {
      window.toast('warning', 'Đang trả lời, vui lòng đợi hoặc bấm dừng trước.');
      return;
    }
    state.conversationId = id;
    history.replaceState(null, '', (CFG.base || '') + '/index.php?c=' + id);
    clearMessages();
    showWelcome(false);

    el.inner.insertAdjacentHTML('beforeend',
      '<div class="empty" id="loadingMsgs"><span class="spin">⏳</span> Đang tải cuộc trò chuyện…</div>');

    api('get', { id: id }, 'GET').then(function (data) {
      var loading = document.getElementById('loadingMsgs');
      if (loading) loading.remove();

      setTitle(data.conversation.title, 'Bắt đầu ' + data.conversation.timeText);
      if (data.conversation.endpointId && el.endpoint) {
        var option = el.endpoint.querySelector('option[value="' + data.conversation.endpointId + '"]');
        if (option) el.endpoint.value = String(data.conversation.endpointId);
      }
      data.messages.forEach(function (msg) { el.inner.appendChild(buildMessage(msg)); });
      renderConversations();
      state.autoScroll = true;
      scrollToBottom(false);
    }).catch(function (err) {
      var loading = document.getElementById('loadingMsgs');
      if (loading) loading.remove();
      window.toast('error', err.message);
    });
  }

  function startNewChat(silent) {
    if (state.streaming) {
      window.toast('warning', 'Đang trả lời, vui lòng đợi hoặc bấm dừng trước.');
      return;
    }
    state.conversationId = 0;
    history.replaceState(null, '', (CFG.base || '') + '/index.php');
    clearMessages();
    showWelcome(true);
    setTitle('Cuộc trò chuyện mới', 'Sẵn sàng lắng nghe bạn');
    clearPendingFiles();
    renderConversations();
    if (!silent && el.input) el.input.focus();
  }

  if (el.newChat) el.newChat.addEventListener('click', function () { startNewChat(); });

  /* =========================================================================
     9. Tệp đính kèm
     ====================================================================== */
  function renderPendingFiles() {
    if (!el.files) return;
    if (!state.pendingFiles.length) {
      el.files.classList.add('hidden');
      el.files.innerHTML = '';
      renderFileWarnings();
      updateSendState();
      return;
    }
    el.files.classList.remove('hidden');
    el.files.innerHTML = state.pendingFiles.map(function (file, index) {
      if (file.uploading) {
        return '<span class="file-chip is-uploading">' +
               '<span class="file-chip-icon spin">⏳</span>' +
               '<span class="file-chip-meta"><span class="file-chip-name">' + esc(file.name) + '</span>' +
               '<span class="mini-bar"><i style="width:' + (file.progress || 0) + '%"></i></span></span></span>';
      }
      // Cho người dùng thấy ngay tệp nào đã đọc được nội dung, tệp nào chưa —
      // để không chờ mô hình tóm tắt một tệp mà nó chưa từng nhìn thấy.
      var note = '', cls = '';
      if (file.hasText) {
        note = ' · đã đọc nội dung';
      } else if (file.needsModel) {
        note = ' · gửi trực tiếp cho AI';
      } else if (file.note) {
        note = ' · chưa đọc được nội dung';
        cls  = ' is-warning';
      }
      return '<span class="file-chip' + cls + '"' +
             (file.note ? ' title="' + esc(file.note) + '"' : '') + '>' +
             '<span class="file-chip-icon">' + esc(cls ? '⚠️' : (file.icon || '📎')) + '</span>' +
             '<span class="file-chip-meta"><span class="file-chip-name">' + esc(file.name) + '</span>' +
             '<span class="file-chip-size">' + esc(file.sizeText || '') + esc(note) + '</span></span>' +
             '<button type="button" class="file-chip-remove" data-remove="' + index + '" title="Bỏ tệp">×</button></span>';
    }).join('');
    renderFileWarnings();
    updateSendState();
  }

  /* Dải cảnh báo dưới khung nhập: nói rõ vì sao tệp chưa đọc được và nên làm gì. */
  function renderFileWarnings() {
    if (!el.fileWarn) return;
    var bad = state.pendingFiles.filter(function (f) {
      return !f.uploading && !f.hasText && !f.needsModel && f.note;
    });
    if (!bad.length) {
      el.fileWarn.classList.add('hidden');
      el.fileWarn.innerHTML = '';
      return;
    }
    el.fileWarn.classList.remove('hidden');
    el.fileWarn.innerHTML = bad.map(function (f) {
      return '<div class="file-warn-row"><b>' + esc(f.name) + '</b> — ' + esc(f.note) + '</div>';
    }).join('') +
      '<div class="file-warn-tip">Bạn vẫn gửi được, nhưng AI sẽ trả lời là chưa đọc được tệp. ' +
      'Cách xử lý: dán nội dung trực tiếp vào khung chat, lưu tệp thành .docx/.txt, ' +
      'hoặc bật “Nhận tệp” cho endpoint để gửi nguyên bản cho AI.</div>';
  }

  function clearPendingFiles() {
    state.pendingFiles = [];
    renderPendingFiles();
  }

  if (el.files) {
    el.files.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-remove]');
      if (!btn) return;
      state.pendingFiles.splice(Number(btn.dataset.remove), 1);
      renderPendingFiles();
    });
  }

  function uploadFiles(fileList) {
    var files = Array.prototype.slice.call(fileList);
    if (!files.length) return;

    var room = (CFG.maxFiles || 10) - state.pendingFiles.length;
    if (room <= 0) {
      window.toast('warning', 'Mỗi tin nhắn chỉ đính kèm tối đa ' + (CFG.maxFiles || 10) + ' tệp.');
      return;
    }
    if (files.length > room) {
      window.toast('warning', 'Chỉ nhận thêm ' + room + ' tệp trong tin nhắn này.');
      files = files.slice(0, room);
    }

    var maxBytes = (CFG.maxUploadMb || 25) * 1024 * 1024;
    var tooBig = files.filter(function (f) { return f.size > maxBytes; });
    if (tooBig.length) {
      window.toast('error', 'Tệp vượt quá ' + (CFG.maxUploadMb || 25) + 'MB: ' +
        tooBig.map(function (f) { return f.name; }).join(', '));
      files = files.filter(function (f) { return f.size <= maxBytes; });
    }
    if (!files.length) return;

    // Thẻ tạm hiển thị tiến trình.
    var placeholders = files.map(function (f) {
      var item = { name: f.name, uploading: true, progress: 0 };
      state.pendingFiles.push(item);
      return item;
    });
    renderPendingFiles();

    var form = new FormData();
    files.forEach(function (f) { form.append('files[]', f); });
    form.append('csrf_token', CFG.csrf);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API + 'upload.php');
    xhr.setRequestHeader('X-CSRF-Token', CFG.csrf);
    xhr.withCredentials = true;

    xhr.upload.addEventListener('progress', function (ev) {
      if (!ev.lengthComputable) return;
      var percent = Math.round((ev.loaded / ev.total) * 100);
      placeholders.forEach(function (item) { item.progress = percent; });
      renderPendingFiles();
    });

    xhr.addEventListener('load', function () {
      // Gỡ các thẻ tạm.
      state.pendingFiles = state.pendingFiles.filter(function (item) {
        return placeholders.indexOf(item) === -1;
      });
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}

      if (!data || !data.ok) {
        window.toast('error', (data && data.error) || 'Tải tệp lên thất bại (mã ' + xhr.status + ').');
        renderPendingFiles();
        return;
      }
      data.files.forEach(function (f) { state.pendingFiles.push(f); });
      if (data.errors && data.errors.length) {
        data.errors.forEach(function (msg) { window.toast('warning', msg); });
      }
      if (data.files.length) {
        // Nói rõ ngay lúc tải lên nếu có tệp máy chủ chưa đọc được nội dung.
        var unread = data.files.filter(function (f) { return !f.hasText && !f.needsModel; });
        if (unread.length) {
          window.toast('warning', 'Đã đính kèm ' + data.files.length + ' tệp, nhưng ' +
            unread.length + ' tệp chưa đọc được nội dung — xem ghi chú bên dưới khung nhập.');
        } else {
          window.toast('success', 'Đã đính kèm ' + data.files.length + ' tệp.');
        }
      }
      renderPendingFiles();
    });

    xhr.addEventListener('error', function () {
      state.pendingFiles = state.pendingFiles.filter(function (item) {
        return placeholders.indexOf(item) === -1;
      });
      renderPendingFiles();
      window.toast('error', 'Lỗi mạng khi tải tệp lên.');
    });

    xhr.send(form);
  }

  if (el.attach) el.attach.addEventListener('click', function () { el.fileInput.click(); });
  if (el.fileInput) {
    el.fileInput.addEventListener('change', function () {
      uploadFiles(el.fileInput.files);
      el.fileInput.value = '';
    });
  }

  // Kéo thả tệp vào ô soạn tin
  if (el.composer) {
    ['dragenter', 'dragover'].forEach(function (type) {
      el.composer.addEventListener(type, function (ev) {
        ev.preventDefault();
        el.composer.classList.add('is-drag');
      });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      el.composer.addEventListener(type, function (ev) {
        ev.preventDefault();
        if (type === 'dragleave' && el.composer.contains(ev.relatedTarget)) return;
        el.composer.classList.remove('is-drag');
      });
    });
    el.composer.addEventListener('drop', function (ev) {
      if (ev.dataTransfer && ev.dataTransfer.files.length) uploadFiles(ev.dataTransfer.files);
    });
  }

  // Dán ảnh từ clipboard
  if (el.input) {
    el.input.addEventListener('paste', function (ev) {
      var items = ev.clipboardData && ev.clipboardData.files;
      if (items && items.length) {
        ev.preventDefault();
        uploadFiles(items);
      }
    });
  }

  /* =========================================================================
     10. Gửi tin nhắn & nhận luồng phản hồi
     ====================================================================== */
  function updateSendState() {
    if (!el.send) return;
    if (state.streaming) {
      el.send.disabled = false;
      return;
    }
    var hasText = el.input && el.input.value.trim() !== '';
    var hasFiles = state.pendingFiles.some(function (f) { return !f.uploading; });
    el.send.disabled = !hasText && !hasFiles;
  }

  if (el.input) {
    el.input.addEventListener('input', function () {
      window.autoGrow(el.input, 220);
      updateSendState();
    });
    el.input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
        ev.preventDefault();
        el.composer.requestSubmit ? el.composer.requestSubmit() : sendMessage();
      }
    });
    el.input.addEventListener('focus', function () { el.composer.classList.add('is-focus'); });
    el.input.addEventListener('blur', function () { el.composer.classList.remove('is-focus'); });
  }

  if (el.composer) {
    el.composer.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (state.streaming) { stopStreaming(); return; }
      sendMessage();
    });
  }

  document.addEventListener('click', function (ev) {
    var sug = ev.target.closest('.suggestion');
    if (!sug || !el.input) return;
    el.input.value = sug.getAttribute('data-suggestion');
    window.autoGrow(el.input, 220);
    updateSendState();
    el.input.focus();
  });

  function stopStreaming() {
    if (state.controller) {
      state.controller.abort();
    }
  }

  function regenerate() {
    if (state.streaming || !state.conversationId) return;
    // Gỡ tin nhắn cuối của trợ lý khỏi màn hình rồi yêu cầu máy chủ trả lời lại.
    var messages = el.inner.querySelectorAll('.msg-assistant');
    if (messages.length) messages[messages.length - 1].remove();
    send({ message: '', regenerate: true });
  }

  function sendMessage() {
    var text = el.input ? el.input.value.trim() : '';
    var ready = state.pendingFiles.filter(function (f) { return !f.uploading; });

    if (state.pendingFiles.some(function (f) { return f.uploading; })) {
      window.toast('warning', 'Vui lòng đợi tệp tải lên xong.');
      return;
    }
    if (!text && !ready.length) return;

    // Hiện tin nhắn của người dùng ngay lập tức.
    showWelcome(false);
    el.inner.appendChild(buildMessage({
      role: 'user',
      content: text,
      files: ready,
      timeText: new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }),
    }));

    if (el.input) {
      el.input.value = '';
      window.autoGrow(el.input, 220);
    }
    var attachmentIds = ready.map(function (f) { return f.id; });
    clearPendingFiles();
    state.autoScroll = true;
    scrollToBottom(true);

    send({ message: text, attachments: attachmentIds });
  }

  function send(payload) {
    state.streaming = true;
    state.controller = new AbortController();
    el.send.classList.add('is-stop');
    el.send.textContent = '■';
    el.send.title = 'Dừng tạo câu trả lời';
    updateSendState();

    // Khung tin nhắn của trợ lý + chỉ báo đang gõ.
    var wrap = buildMessage({ role: 'assistant', content: '' });
    var bubble = wrap.querySelector('.msg-bubble');
    var content = wrap.querySelector('.md');
    var metaBox = wrap.querySelector('.msg-meta-text');
    content.innerHTML = '<span class="typing"><i></i><i></i><i></i></span>';
    el.inner.appendChild(wrap);
    maybeScroll();

    var body = {
      message: payload.message || '',
      conversation_id: state.conversationId || 0,
      endpoint_id: el.endpoint ? Number(el.endpoint.value) : 0,
      attachments: payload.attachments || [],
      regenerate: !!payload.regenerate,
      csrf_token: CFG.csrf,
    };

    var accumulated = '';
    var reasoning = '';
    var reasoningBox = null;
    var renderPending = false;
    var firstDelta = true;

    function paint() {
      if (renderPending) return;
      renderPending = true;
      requestAnimationFrame(function () {
        renderPending = false;
        renderMarkdown(content, accumulated);
        content.insertAdjacentHTML('beforeend', '<span class="stream-caret"></span>');
        maybeScroll();
      });
    }

    fetch(API + 'stream.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
      credentials: 'same-origin',
      body: JSON.stringify(body),
      signal: state.controller.signal,
    }).then(function (res) {
      if (!res.ok) {
        return res.json().then(function (data) {
          throw new Error(data.error || ('Máy chủ trả về mã ' + res.status));
        }, function () {
          throw new Error('Máy chủ trả về mã ' + res.status + '.');
        });
      }
      if (!res.body) throw new Error('Trình duyệt của bạn không hỗ trợ nhận dữ liệu theo luồng.');

      var reader = res.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buffer = '';

      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) { finish('end'); return; }
          buffer += decoder.decode(chunk.value, { stream: true });

          var parts = buffer.split(/\r?\n\r?\n/);
          buffer = parts.pop();

          parts.forEach(function (block) {
            var eventName = 'message';
            var dataLines = [];
            block.split(/\r?\n/).forEach(function (line) {
              if (line.indexOf('event:') === 0) eventName = line.slice(6).trim();
              else if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).replace(/^ /, ''));
            });
            if (!dataLines.length) return;
            var data;
            try { data = JSON.parse(dataLines.join('\n')); } catch (e) { return; }
            handleEvent(eventName, data);
          });
          return pump();
        });
      }
      return pump();
    }).catch(function (err) {
      if (err.name === 'AbortError') {
        finish('aborted');
        return;
      }
      showError(err.message || 'Lỗi kết nối tới máy chủ.');
      finish('error');
    });

    function handleEvent(name, data) {
      if (name === 'start') {
        if (data.conversationId && !state.conversationId) {
          state.conversationId = data.conversationId;
          history.replaceState(null, '', (CFG.base || '') + '/index.php?c=' + data.conversationId);
        }
        if (data.title) setTitle(data.title, data.endpoint ? data.endpoint.name : '');
        if (data.isNew) loadConversations(el.convSearch ? el.convSearch.value : '');

      } else if (name === 'delta') {
        if (firstDelta) { firstDelta = false; content.innerHTML = ''; }
        accumulated += data.text;
        paint();

      } else if (name === 'reasoning') {
        reasoning += data.text;
        if (!reasoningBox) {
          reasoningBox = buildReasoning('');
          bubble.parentNode.insertBefore(reasoningBox, bubble);
        }
        reasoningBox.querySelector('.reasoning-body').textContent = reasoning;
        maybeScroll();

      } else if (name === 'file') {
        window.toast('success', '📦 Đã nhận tệp: ' + data.name);

      } else if (name === 'done') {
        accumulated = data.content || accumulated;
        renderMarkdown(content, accumulated);
        if (data.files && data.files.length) {
          var extras = data.files.filter(function (f) { return f.kind !== 'image'; });
          if (extras.length) {
            var box = document.createElement('div');
            box.className = 'msg-files mt-1';
            box.innerHTML = extras.map(fileChipHTML).join('');
            bubble.appendChild(box);
          }
        }
        if (metaBox) {
          metaBox.textContent = [
            data.timeText, data.model,
            data.durationMs ? (data.durationMs / 1000).toFixed(1) + 's' : '',
            (data.tokens && (data.tokens.prompt || data.tokens.completion))
              ? '↑' + data.tokens.prompt + ' ↓' + data.tokens.completion + ' token' : '',
          ].filter(Boolean).join(' · ');
        }
        if (data.messageId) wrap.dataset.id = data.messageId;
        loadConversations(el.convSearch ? el.convSearch.value : '');

      } else if (name === 'error') {
        showError(data.message);
      }
    }

    function showError(message) {
      bubble.classList.add('is-error');
      content.innerHTML = '<p>⚠️ <strong>Không nhận được phản hồi</strong></p>';
      if (accumulated) {
        var partial = document.createElement('div');
        renderMarkdown(partial, accumulated);
        content.appendChild(partial);
      }
      var note = document.createElement('p');
      note.textContent = message;
      content.appendChild(note);

      var retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn btn-soft btn-sm mt-1';
      retry.textContent = '🔄 Thử lại';
      retry.addEventListener('click', function () {
        wrap.remove();
        send(payload);
      });
      content.appendChild(retry);
      maybeScroll();
    }

    function finish(reason) {
      state.streaming = false;
      state.controller = null;
      el.send.classList.remove('is-stop');
      el.send.textContent = '➤';
      el.send.title = 'Gửi';
      updateSendState();

      var caret = content.querySelector('.stream-caret');
      if (caret) caret.remove();

      if (reason === 'aborted') {
        if (accumulated) renderMarkdown(content, accumulated);
        var tag = document.createElement('p');
        tag.className = 'text-muted text-sm mb-0';
        tag.textContent = '⏹ Bạn đã dừng câu trả lời.';
        content.appendChild(tag);
        loadConversations(el.convSearch ? el.convSearch.value : '');
      }
      if (el.input) el.input.focus();
    }
  }

  /* =========================================================================
     11. Nút trong khối mã
     ====================================================================== */
  document.addEventListener('click', function (ev) {
    var copyBtn = ev.target.closest('[data-copy-code]');
    if (copyBtn) {
      var block = copyBtn.closest('.code-block');
      window.copyText(block.getAttribute('data-code') || '').then(function () {
        copyBtn.textContent = '✅ Đã chép';
        copyBtn.classList.add('is-done');
        setTimeout(function () {
          copyBtn.textContent = '📋 Chép';
          copyBtn.classList.remove('is-done');
        }, 1800);
      });
      return;
    }

    var dlBtn = ev.target.closest('[data-download-code]');
    if (dlBtn) {
      var codeBlock = dlBtn.closest('.code-block');
      var text = codeBlock.getAttribute('data-code') || '';
      var extMap = {
        javascript: 'js', typescript: 'ts', python: 'py', php: 'php', html: 'html', css: 'css',
        json: 'json', sql: 'sql', bash: 'sh', shell: 'sh', java: 'java', csharp: 'cs',
        cpp: 'cpp', c: 'c', go: 'go', rust: 'rs', ruby: 'rb', yaml: 'yml', xml: 'xml',
        markdown: 'md', 'văn bản': 'txt',
      };
      var lang = dlBtn.getAttribute('data-ext') || 'txt';
      downloadBlob(text, 'ma-nguon-' + Date.now() + '.' + (extMap[lang] || 'txt'), 'text/plain;charset=utf-8');
    }
  });

  function downloadBlob(content, filename, mime) {
    var blob = new Blob([content], { type: mime });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
  }

  /* =========================================================================
     12. Xuất cuộc trò chuyện
     ====================================================================== */
  if (el.exportBtn) {
    el.exportBtn.addEventListener('click', function () {
      if (!state.conversationId) {
        window.toast('info', 'Chưa có nội dung nào để xuất.');
        return;
      }
      api('get', { id: state.conversationId }, 'GET').then(function (data) {
        var lines = ['# ' + data.conversation.title, '', '> Xuất từ ' + CFG.siteName +
                     ' lúc ' + new Date().toLocaleString('vi-VN') + ' (giờ Việt Nam)', ''];
        data.messages.forEach(function (msg) {
          lines.push('## ' + (msg.role === 'user' ? '🙋 Người dùng' : '🤖 Trợ lý AI') +
                     ' — ' + msg.timeText);
          if (msg.files && msg.files.length) {
            lines.push('', '**Tệp đính kèm:** ' + msg.files.map(function (f) { return f.name; }).join(', '));
          }
          lines.push('', msg.content || '', '');
        });
        var name = data.conversation.title.replace(/[^\p{L}\p{N}\-_ ]/gu, '').trim() || 'cuoc-tro-chuyen';
        downloadBlob(lines.join('\n'), name + '.md', 'text/markdown;charset=utf-8');
        window.toast('success', 'Đã tải cuộc trò chuyện về máy! 📄');
      }).catch(function (err) { window.toast('error', err.message); });
    });
  }

  /* =========================================================================
     13. Thanh bên trên di động
     ====================================================================== */
  if (el.toggleSide) {
    el.toggleSide.addEventListener('click', function () {
      if (window.innerWidth <= 900) {
        el.app.classList.toggle('sidebar-open');
      } else {
        el.app.classList.toggle('sidebar-hidden');
      }
    });
  }
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-close-sidebar]')) el.app.classList.remove('sidebar-open');
  });

  /* =========================================================================
     14. Phím tắt
     ====================================================================== */
  document.addEventListener('keydown', function (ev) {
    var mod = ev.ctrlKey || ev.metaKey;
    if (mod && ev.key.toLowerCase() === 'k') {
      ev.preventDefault();
      if (el.convSearch) el.convSearch.focus();
    } else if (mod && ev.shiftKey && ev.key.toLowerCase() === 'o') {
      ev.preventDefault();
      startNewChat();
    } else if (ev.key === 'Escape' && state.streaming) {
      stopStreaming();
    }
  });

  /* =========================================================================
     15. Khởi động
     ====================================================================== */
  loadConversations();

  if (state.conversationId) {
    openConversation(state.conversationId);
  } else {
    showWelcome(true);
  }

  if (CFG.justJoined && window.confetti) {
    setTimeout(function () { window.confetti(110); }, 350);
  }

  updateSendState();
  if (el.input && window.innerWidth > 900) el.input.focus();

  // Cảnh báo khi rời trang lúc đang nhận câu trả lời.
  window.addEventListener('beforeunload', function (ev) {
    if (state.streaming) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });
})();
