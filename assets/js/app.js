/* =============================================================================
   Tuấn Chatbot — Tiện ích giao diện dùng chung
   ========================================================================== */
(function () {
  'use strict';

  var root = document.documentElement;

  /* ---- Giao diện sáng / tối ----------------------------------------------
     Máy chủ là nguồn dữ liệu chuẩn (lưu trong tài khoản, đồng bộ mọi thiết bị);
     localStorage chỉ để tránh nhấp nháy khi trang vừa mở.
     ---------------------------------------------------------------------- */
  function applyTheme(mode, persist) {
    root.setAttribute('data-theme', mode);
    try { localStorage.setItem('tchat-theme', mode); } catch (e) {}

    if (!persist) return;
    var base = document.body.getAttribute('data-base') || '';
    var csrf = document.body.getAttribute('data-csrf') || '';
    if (!csrf) return;
    fetch(base + '/api/preferences.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      credentials: 'same-origin',
      body: JSON.stringify({ theme: mode, csrf_token: csrf }),
    }).catch(function () { /* Không lưu được thì lần mở sau dùng lại giá trị của máy chủ. */ });
  }

  // Đồng bộ ngay khi tải trang: giá trị của máy chủ thắng giá trị đang lưu cục bộ.
  (function syncTheme() {
    var serverTheme = document.body.getAttribute('data-theme-server');
    if (!serverTheme) return;
    var stored = null;
    try { stored = localStorage.getItem('tchat-theme'); } catch (e) {}
    if (stored !== serverTheme) {
      root.setAttribute('data-theme', serverTheme);
      try { localStorage.setItem('tchat-theme', serverTheme); } catch (e) {}
    }
  })();

  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-theme-toggle]');
    if (!toggle) return;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
    toggle.animate(
      [{ transform: 'rotate(0) scale(1)' }, { transform: 'rotate(180deg) scale(1.25)' }, { transform: 'rotate(360deg) scale(1)' }],
      { duration: 480, easing: 'cubic-bezier(.34,1.56,.64,1)' }
    );
  });

  /* ---- Thông báo nổi ------------------------------------------------------ */
  function dismissFlash(el) {
    el.classList.add('is-hiding');
    setTimeout(function () { el.remove(); }, 320);
  }

  document.addEventListener('click', function (ev) {
    var close = ev.target.closest('.flash-close');
    if (close) dismissFlash(close.closest('.flash'));
  });

  document.querySelectorAll('.flash-stack .flash').forEach(function (el, i) {
    setTimeout(function () { dismissFlash(el); }, 6000 + i * 700);
  });

  /** Hiện một thông báo nổi từ JavaScript. */
  window.toast = function (type, message, timeout) {
    var stack = document.querySelector('.flash-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'flash-stack';
      document.body.appendChild(stack);
    }
    var icons = { success: '🎉', error: '😿', info: '💡', warning: '⚠️' };
    var el = document.createElement('div');
    el.className = 'flash flash-' + type;
    el.innerHTML = '<span class="flash-icon">' + (icons[type] || '💬') + '</span><span></span>' +
                   '<button type="button" class="flash-close" aria-label="Đóng">&times;</button>';
    el.querySelector('span:nth-child(2)').textContent = message;
    stack.appendChild(el);
    setTimeout(function () { dismissFlash(el); }, timeout || 5200);
    return el;
  };

  /* ---- Nút hiện/ẩn mật khẩu ---------------------------------------------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-toggle-password]');
    if (!btn) return;
    var input = document.querySelector(btn.getAttribute('data-toggle-password'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁️';
    btn.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
  });

  /* ---- Thanh đo độ mạnh mật khẩu ----------------------------------------- */
  function scorePassword(value) {
    var score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^\w\s]/.test(value)) score++;
    return Math.min(score, 5);
  }

  document.querySelectorAll('[data-strength-input]').forEach(function (input) {
    var wrap = input.closest('.field') || document;
    var bar = wrap.querySelector('[data-strength-bar] > i');
    var text = wrap.querySelector('[data-strength-text]');
    if (!bar) return;

    var labels = ['Rất yếu', 'Yếu', 'Trung bình', 'Khá', 'Mạnh', 'Rất mạnh'];
    var colors = ['#e5484d', '#e5484d', '#e08a1e', '#e0b81e', '#16a97a', '#16a97a'];

    input.addEventListener('input', function () {
      var value = input.value;
      var score = scorePassword(value);
      bar.style.width = (value ? (score / 5) * 100 : 0) + '%';
      bar.style.background = colors[score];
      if (text) {
        text.textContent = value
          ? 'Độ mạnh: ' + labels[score]
          : 'Tối thiểu 8 ký tự, có cả chữ và số.';
        text.style.color = value ? colors[score] : '';
      }
    });
  });

  /* ---- Xác nhận trước khi gửi biểu mẫu ----------------------------------- */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var message = form.getAttribute('data-confirm');
    if (!message || form.dataset.confirmed === '1') return;
    ev.preventDefault();
    if (window.confirmDialog) {
      window.confirmDialog(message).then(function (yes) {
        if (yes) { form.dataset.confirmed = '1'; form.submit(); }
      });
    } else if (window.confirm(message)) {
      form.dataset.confirmed = '1';
      form.submit();
    }
  });

  /* ---- Nút bấm cần xác nhận ---------------------------------------------- */
  document.addEventListener('click', function (ev) {
    var link = ev.target.closest('a[data-confirm]');
    if (!link) return;
    ev.preventDefault();
    var message = link.getAttribute('data-confirm');
    var go = function () { window.location.href = link.href; };
    if (window.confirmDialog) {
      window.confirmDialog(message).then(function (yes) { if (yes) go(); });
    } else if (window.confirm(message)) {
      go();
    }
  });

  /* ---- Bật/tắt một khối bằng [data-open] --------------------------------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-open]');
    if (!btn) return;
    var target = document.querySelector(btn.getAttribute('data-open'));
    if (!target) return;
    target.classList.toggle('hidden');
    if (!target.classList.contains('hidden')) {
      target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var focusable = target.querySelector('input, textarea, select');
      if (focusable) focusable.focus();
    }
  });

  /* ---- Hộp thoại xác nhận dùng chung ------------------------------------- */
  window.confirmDialog = function (message, title) {
    var modal = document.getElementById('confirmModal');
    if (!modal) return Promise.resolve(window.confirm(message));

    modal.querySelector('#confirmText').textContent = message;
    modal.querySelector('#confirmTitle').textContent = title || 'Xác nhận';
    modal.classList.add('is-open');

    return new Promise(function (resolve) {
      function finish(value) {
        modal.classList.remove('is-open');
        yes.removeEventListener('click', onYes);
        no.removeEventListener('click', onNo);
        modal.removeEventListener('click', onBackdrop);
        document.removeEventListener('keydown', onKey);
        resolve(value);
      }
      function onYes() { finish(true); }
      function onNo() { finish(false); }
      function onBackdrop(ev) { if (ev.target === modal) finish(false); }
      function onKey(ev) { if (ev.key === 'Escape') finish(false); }

      var yes = modal.querySelector('[data-confirm-yes]');
      var no = modal.querySelector('[data-confirm-no]');
      yes.addEventListener('click', onYes);
      no.addEventListener('click', onNo);
      modal.addEventListener('click', onBackdrop);
      document.addEventListener('keydown', onKey);
      yes.focus();
    });
  };

  /* ---- Sao chép vào bộ nhớ tạm ------------------------------------------- */
  window.copyText = function (text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); resolve(); } catch (e) { reject(e); }
      ta.remove();
    });
  };

  /* ---- Pháo giấy chúc mừng ----------------------------------------------- */
  window.confetti = function (count) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var colors = ['#7c5cff', '#ff5c9d', '#33d6c0', '#ffb443', '#5ca8ff'];
    var total = count || 80;
    for (var i = 0; i < total; i++) {
      var piece = document.createElement('span');
      piece.className = 'confetti-piece';
      piece.style.left = Math.random() * 100 + 'vw';
      piece.style.background = colors[i % colors.length];
      piece.style.animationDuration = (2.2 + Math.random() * 1.8) + 's';
      piece.style.animationDelay = (Math.random() * 0.6) + 's';
      piece.style.width = (6 + Math.random() * 8) + 'px';
      piece.style.height = (10 + Math.random() * 8) + 'px';
      document.body.appendChild(piece);
      (function (el) { setTimeout(function () { el.remove(); }, 4600); })(piece);
    }
  };

  /* ---- Ảnh phóng to ------------------------------------------------------- */
  document.addEventListener('click', function (ev) {
    var img = ev.target.closest('.md img, .img-attach');
    var box = document.getElementById('lightbox');
    if (!img || !box || img.closest('a')) return;
    box.querySelector('img').src = img.currentSrc || img.src;
    box.classList.add('is-open');
  });

  document.addEventListener('click', function (ev) {
    var box = document.getElementById('lightbox');
    if (!box || !box.classList.contains('is-open')) return;
    if (ev.target === box || ev.target.closest('.lightbox-close')) {
      box.classList.remove('is-open');
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var box = document.getElementById('lightbox');
    if (box) box.classList.remove('is-open');
  });

  /* ---- Ô nhập tự giãn theo nội dung -------------------------------------- */
  window.autoGrow = function (el, max) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, max || 220) + 'px';
  };
})();
