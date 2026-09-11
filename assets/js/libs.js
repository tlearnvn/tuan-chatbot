/* =============================================================================
   Tuấn Chatbot — Nạp thư viện hiển thị (Markdown / LaTeX / tô màu mã)

   Mỗi thư viện được thử lần lượt trên nhiều CDN. Nếu CDN thứ nhất bị chặn
   (tường lửa, nhà mạng, hosting nội bộ…) thì tự chuyển sang CDN dự phòng.
   Kết quả trả về qua Promise window.TCHAT_LIBS để chat.js dựng lại nội dung
   sau khi thư viện sẵn sàng.
   ========================================================================== */
(function () {
  'use strict';

  /** Bảng thư viện: tên biến toàn cục + đường dẫn trên từng CDN (thử theo thứ tự). */
  var LIBS = [
    {
      global: 'marked',
      paths: [
        'https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.2/marked.min.js',
        'https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js',
      ],
    },
    {
      global: 'DOMPurify',
      paths: [
        'https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js',
        'https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js',
      ],
    },
    {
      global: 'hljs',
      optional: true,
      paths: [
        'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js',
        'https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@11.9.0/highlight.min.js',
      ],
      css: [
        'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css',
        'https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@11.9.0/styles/atom-one-dark.min.css',
      ],
    },
    {
      global: 'katex',
      paths: [
        'https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.11/katex.min.js',
        'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js',
      ],
      css: [
        'https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.11/katex.min.css',
        'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css',
      ],
    },
  ];

  var LOAD_TIMEOUT = 9000;

  /** Nạp một thẻ <script>, trả Promise. */
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var el = document.createElement('script');
      var timer = setTimeout(function () {
        el.remove();
        reject(new Error('timeout'));
      }, LOAD_TIMEOUT);

      el.src = src;
      el.async = false;
      el.crossOrigin = 'anonymous';
      el.onload = function () { clearTimeout(timer); resolve(src); };
      el.onerror = function () { clearTimeout(timer); el.remove(); reject(new Error('error')); };
      document.head.appendChild(el);
    });
  }

  /** Nạp một thẻ <link rel=stylesheet>, trả Promise (không chặn nếu lỗi). */
  function loadStyle(href) {
    return new Promise(function (resolve, reject) {
      var el = document.createElement('link');
      var timer = setTimeout(function () { reject(new Error('timeout')); }, LOAD_TIMEOUT);
      el.rel = 'stylesheet';
      el.href = href;
      el.crossOrigin = 'anonymous';
      el.onload = function () { clearTimeout(timer); resolve(href); };
      el.onerror = function () { clearTimeout(timer); el.remove(); reject(new Error('error')); };
      document.head.appendChild(el);
    });
  }

  /** Thử lần lượt các URL cho tới khi một URL nạp được. */
  function loadFirstAvailable(urls, loader) {
    var index = 0;
    function attempt() {
      if (index >= urls.length) {
        return Promise.reject(new Error('Đã thử hết CDN'));
      }
      return loader(urls[index++]).catch(attempt);
    }
    return attempt();
  }

  /** Nạp một thư viện (kèm CSS nếu có). */
  function loadLib(lib) {
    if (window[lib.global]) {
      return Promise.resolve({ name: lib.global, ok: true, cached: true });
    }
    var jobs = [loadFirstAvailable(lib.paths, loadScript)];
    if (lib.css) {
      // CSS lỗi thì vẫn tiếp tục — chỉ ảnh hưởng thẩm mỹ.
      jobs.push(loadFirstAvailable(lib.css, loadStyle).catch(function () { return null; }));
    }
    return Promise.all(jobs)
      .then(function () { return { name: lib.global, ok: !!window[lib.global] }; })
      .catch(function () { return { name: lib.global, ok: false, optional: !!lib.optional }; });
  }

  window.TCHAT_LIBS = Promise.all(LIBS.map(loadLib)).then(function (results) {
    var failed = results.filter(function (r) { return !r.ok && !r.optional; });
    var status = {
      marked:    !!window.marked,
      purify:    !!window.DOMPurify,
      katex:     !!window.katex,
      hljs:      !!window.hljs,
      failed:    failed.map(function (r) { return r.name; }),
    };

    if (failed.length) {
      // Cảnh báo một lần, ghi rõ hậu quả để người dùng hiểu chuyện gì xảy ra.
      var missing = [];
      if (!status.marked) missing.push('định dạng Markdown');
      if (!status.katex)  missing.push('công thức LaTeX');
      if (missing.length && window.toast) {
        window.toast('warning',
          'Không tải được thư viện hiển thị (' + missing.join(', ') + '). ' +
          'Nội dung vẫn đọc được ở dạng văn bản thuần. Hãy kiểm tra kết nối tới CDN.',
          9000);
      }
    }

    document.dispatchEvent(new CustomEvent('tchat:libs-ready', { detail: status }));
    return status;
  });
})();
