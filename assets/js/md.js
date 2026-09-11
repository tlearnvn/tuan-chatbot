/* =============================================================================
   Tuấn Chatbot — Bộ dựng Markdown + LaTeX

   Dùng chung cho màn hình trò chuyện và trang xem lại đoạn chat của quản trị.
   Quy trình: tách công thức LaTeX ra khỏi văn bản → dựng Markdown → lọc HTML
   bằng DOMPurify → dựng công thức bằng KaTeX.

   API: window.TChatMD.render(node, text)   — dựng nội dung vào một phần tử
        window.TChatMD.rerenderAll()        — dựng lại toàn bộ (khi thư viện tới muộn)
   ========================================================================== */
(function () {
  'use strict';

  function esc(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------- 1. Tách và phục hồi công thức LaTeX ------------------------- */
  var mathStore = [];

  function pushMath(tex, display) {
    mathStore.push({ tex: tex, display: display });
    // Ký tự U+241F hiếm gặp trong văn bản thật nên Markdown không đụng tới.
    return '␟' + (mathStore.length - 1) + '␟';
  }

  /**
   * Thay mọi công thức LaTeX bằng chỗ giữ chỗ, để Markdown không hiểu sai
   * các ký tự _ * \ bên trong công thức. Bỏ qua phần nằm trong khối mã.
   */
  function protectMath(src) {
    mathStore = [];
    var out = '';
    var i = 0;
    var n = src.length;

    while (i < n) {
      // Khối mã ```…```
      if (src.startsWith('```', i)) {
        var fenceEnd = src.indexOf('```', i + 3);
        if (fenceEnd === -1) { out += src.slice(i); break; }
        out += src.slice(i, fenceEnd + 3);
        i = fenceEnd + 3;
        continue;
      }
      // Mã nội dòng `…`
      if (src[i] === '`') {
        var tickEnd = src.indexOf('`', i + 1);
        if (tickEnd === -1) { out += src.slice(i); break; }
        out += src.slice(i, tickEnd + 1);
        i = tickEnd + 1;
        continue;
      }
      // $$…$$ — công thức riêng dòng
      if (src.startsWith('$$', i)) {
        var dd = src.indexOf('$$', i + 2);
        if (dd !== -1) {
          out += pushMath(src.slice(i + 2, dd), true);
          i = dd + 2;
          continue;
        }
      }
      // \[…\] — công thức riêng dòng
      if (src.startsWith('\\[', i)) {
        var br = src.indexOf('\\]', i + 2);
        if (br !== -1) {
          out += pushMath(src.slice(i + 2, br), true);
          i = br + 2;
          continue;
        }
      }
      // \(…\) — công thức trong dòng
      if (src.startsWith('\\(', i)) {
        var pr = src.indexOf('\\)', i + 2);
        if (pr !== -1) {
          out += pushMath(src.slice(i + 2, pr), false);
          i = pr + 2;
          continue;
        }
      }
      // $…$ — công thức trong dòng, nhưng tránh nhận nhầm giá tiền ($5, 20$)
      if (src[i] === '$') {
        var close = -1;
        for (var j = i + 1; j < n; j++) {
          if (src[j] === '\\') { j++; continue; }
          if (src[j] === '\n' && src[j + 1] === '\n') break;   // qua đoạn mới thì thôi
          if (src[j] === '$') { close = j; break; }
        }
        if (close > i + 1) {
          var body = src.slice(i + 1, close);
          var isMoney = /^[\d.,]+$/.test(body);        // "$1.000$" → tiền, không phải công thức
          var padded  = /^\s|\s$/.test(body);          // "$ 5 $"  → khoảng trắng hai đầu
          if (!isMoney && !padded && body.trim() !== '') {
            out += pushMath(body, false);
            i = close + 1;
            continue;
          }
        }
      }
      out += src[i];
      i++;
    }
    return out;
  }

  /** Đưa công thức trở lại HTML dưới dạng thẻ chờ KaTeX dựng. */
  function restoreMath(html) {
    return html.replace(/␟(\d+)␟/g, function (_, index) {
      var item = mathStore[Number(index)];
      if (!item) return '';
      var display = item.display;
      var tag = display ? 'div' : 'span';
      return '<' + tag + ' class="' + (display ? 'math-block' : 'math-inline') +
             '" data-tex="' + esc(item.tex) + '"></' + tag + '>';
    });
  }

  /* ---------- 2. Cấu hình marked ----------------------------------------- */
  var markedReady = false;

  function setupMarked() {
    if (markedReady || typeof window.marked === 'undefined') {
      return;
    }
    markedReady = true;

    var renderer = new window.marked.Renderer();

    renderer.code = function (code, infostring) {
      // marked từ v13 truyền vào một token thay vì các tham số rời.
      if (code && typeof code === 'object') {
        infostring = code.lang;
        code = code.text;
      }
      var lang = (infostring || '').match(/\S*/)[0] || '';
      var body = String(code == null ? '' : code);
      var highlighted = esc(body);

      if (window.hljs) {
        try {
          highlighted = (lang && window.hljs.getLanguage(lang))
            ? window.hljs.highlight(body, { language: lang }).value
            : window.hljs.highlightAuto(body).value;
        } catch (e) {
          highlighted = esc(body);
        }
      }

      return '<div class="code-block" data-code="' + esc(body) + '">' +
             '<div class="code-head"><span class="code-lang">' + esc(lang || 'văn bản') + '</span>' +
             '<button type="button" class="code-btn" data-copy-code>📋 Chép</button>' +
             '<button type="button" class="code-btn" data-download-code data-ext="' + esc(lang || 'txt') + '">⬇️ Tải</button>' +
             '</div><pre><code class="hljs language-' + esc(lang) + '">' + highlighted + '</code></pre></div>';
    };

    renderer.table = function (header, body) {
      if (header && typeof header === 'object') {
        var fallback = window.marked.Renderer.prototype.table.call(this, header);
        return '<div class="table-scroll">' + fallback + '</div>';
      }
      return '<div class="table-scroll"><table><thead>' + header +
             '</thead><tbody>' + body + '</tbody></table></div>';
    };

    window.marked.setOptions({
      renderer: renderer, gfm: true, breaks: true, headerIds: false, mangle: false,
    });
  }

  /* ---------- 3. Dựng nội dung -------------------------------------------- */
  var registry = [];

  function render(target, text, isRerender) {
    if (!target) return;
    setupMarked();

    var source = String(text == null ? '' : text);

    if (!isRerender) {
      var slot = null;
      for (var i = 0; i < registry.length; i++) {
        if (registry[i].node === target) { slot = registry[i]; break; }
      }
      if (slot) {
        slot.text = source;
      } else {
        registry.push({ node: target, text: source });
        if (registry.length > 400) {
          registry = registry.filter(function (item) { return item.node.isConnected; });
        }
      }
    }

    var html;
    if (window.marked) {
      html = restoreMath(window.marked.parse(protectMath(source)));
    } else {
      // Chưa có thư viện: hiển thị văn bản thuần, vẫn đọc được.
      html = '<p>' + esc(source).replace(/\n/g, '<br>') + '</p>';
    }

    if (window.DOMPurify) {
      html = window.DOMPurify.sanitize(html, {
        ADD_ATTR: ['target', 'rel', 'data-tex', 'data-code', 'data-copy-code',
                   'data-download-code', 'data-ext'],
      });
    } else if (!window.marked) {
      // Không có cả marked lẫn DOMPurify → giữ nguyên văn bản, tuyệt đối không chèn HTML thô.
      target.textContent = source;
      return;
    }

    target.innerHTML = html;
    renderMath(target);
    decorateLinks(target);
    decorateImages(target);
  }

  /** Dựng các công thức đã đánh dấu bằng KaTeX. */
  function renderMath(scope) {
    if (!window.katex) {
      // Không có KaTeX: hiện lại công thức ở dạng chữ để không mất nội dung.
      scope.querySelectorAll('.math-inline, .math-block').forEach(function (node) {
        var tex = node.getAttribute('data-tex') || '';
        var wrap = node.classList.contains('math-block') ? '$$' : '$';
        node.textContent = wrap + tex + wrap;
      });
      return;
    }
    scope.querySelectorAll('.math-inline, .math-block').forEach(function (node) {
      var tex = node.getAttribute('data-tex') || '';
      try {
        window.katex.render(tex, node, {
          displayMode: node.classList.contains('math-block'),
          throwOnError: false,
          output: 'html',
          strict: 'ignore',
          trust: false,
        });
      } catch (e) {
        var wrap = node.classList.contains('math-block') ? '$$' : '$';
        node.textContent = wrap + tex + wrap;
      }
    });
  }

  /** Liên kết ngoài mở tab mới và an toàn. */
  function decorateLinks(scope) {
    scope.querySelectorAll('a[href]').forEach(function (a) {
      if (/^https?:/i.test(a.getAttribute('href') || '')) {
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      }
    });
  }

  /** Ảnh lỗi thì thay bằng thông báo nhẹ nhàng thay vì icon hỏng. */
  function decorateImages(scope) {
    scope.querySelectorAll('img').forEach(function (img) {
      img.loading = 'lazy';
      img.addEventListener('error', function () {
        var note = document.createElement('span');
        note.className = 'badge badge-warn';
        note.textContent = '🖼️ Không tải được ảnh' +
          (img.getAttribute('alt') ? ': ' + img.getAttribute('alt') : '');
        img.replaceWith(note);
      }, { once: true });
    });
  }

  function rerenderAll() {
    markedReady = false;   // cấu hình lại bộ dựng với thư viện vừa nạp được
    registry.forEach(function (item) {
      if (item.node.isConnected) {
        render(item.node, item.text, true);
      }
    });
  }

  document.addEventListener('tchat:libs-ready', rerenderAll);

  window.TChatMD = {
    render: render,
    rerenderAll: rerenderAll,
    protectMath: protectMath,
  };
})();
