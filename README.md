# 🤖 Tuấn Chatbot

Chatbot AI viết bằng **PHP + HTML + CSS thuần** (không cần Composer, không cần Node.js),
giao diện tiếng Việt tươi vui, chạy được ngay trên **shared hosting** chỉ bằng cách giải nén và cài đặt.

> **Toàn bộ dữ liệu nằm trong MySQL** — tệp đính kèm, ảnh AI sinh ra và cả phiên đăng nhập.
> Ứng dụng không ghi tệp nào xuống đĩa (ngoài `config/config.php` lúc cài đặt), nên **số inode
> luôn cố định ở khoảng 55 tệp mã nguồn** dù có bao nhiêu người dùng hay bao nhiêu tệp tải lên.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479a1) ![License](https://img.shields.io/badge/license-MIT-green)

---

## ✨ Tính năng

### Trò chuyện
- **Phản hồi theo luồng (streaming)** thời gian thực qua Server-Sent Events — chữ hiện dần như đang gõ.
- **Markdown đầy đủ**: tiêu đề, danh sách, bảng có thể cuộn ngang, trích dẫn, khối mã tô màu kèm nút chép/tải.
- **Công thức LaTeX** dựng bằng KaTeX: `$...$` trong dòng, `$$...$$` riêng dòng, cả `\(...\)` và `\[...\]`.
- **Hình ảnh** hiển thị trực tiếp trong câu trả lời, bấm để xem phóng to.
- **Khối suy luận** riêng cho các mô hình reasoning (DeepSeek R1, Claude thinking, Gemini thinking…).
- Dừng giữa luồng, tạo lại câu trả lời, sao chép, xuất cuộc trò chuyện ra file Markdown.
- Ghim, đổi tên, tìm kiếm và xoá cuộc trò chuyện; tìm cả trong nội dung tin nhắn.

### Tệp
- Tải lên **mọi loại tệp**: ảnh, PDF, Word/Excel/PowerPoint, văn bản, mã nguồn, âm thanh, video, nén.
- **Lưu trong cơ sở dữ liệu**, cắt thành nhiều khối nhỏ (tự chọn theo `max_allowed_packet`) nên vừa không tốn inode, vừa đọc/ghi tệp lớn mà bộ nhớ PHP chỉ giữ đúng một khối.
- Kéo–thả vào ô soạn tin, dán ảnh trực tiếp từ clipboard, hiển thị tiến trình tải lên.
- Tự **trích xuất văn bản** từ txt/md/csv/json/code, docx/xlsx/pptx (đọc XML trong ZIP) và PDF (giải nén stream) để đưa vào ngữ cảnh.
- Ảnh và PDF được gửi trực tiếp cho mô hình dưới dạng base64 nếu endpoint hỗ trợ.
- **Nhận tệp do AI trả về**: ảnh sinh bởi mô hình (Gemini inlineData, OpenRouter images) và mọi data URI nhúng trong câu trả lời đều được lưu thành tệp tải xuống được.

### Tài khoản
- Đăng ký, đăng nhập bằng tên đăng nhập **hoặc** email, ghi nhớ đăng nhập 30 ngày.
- Trang tài khoản: đổi ảnh đại diện (emoji), họ tên, email, giao diện mặc định, đổi mật khẩu.
- Xem thống kê cá nhân và nhật ký hoạt động; xoá lịch sử hoặc xoá tài khoản.
- Mật khẩu băm bằng `password_hash()`, chống dò mật khẩu, CSRF token trên mọi biểu mẫu.

### Quản trị
- **Tổng quan**: biểu đồ 14 ngày, số người dùng, tin nhắn, token, dung lượng tệp, số lỗi.
- **Lịch sử chat toàn hệ thống**: lọc theo người dùng, từ khoá, khoảng ngày; xem lại nguyên văn (kèm ảnh, tệp, LaTeX) và in ra PDF.
- **Quản lý người dùng**: tạo, nâng/hạ quyền, khoá, đặt lại mật khẩu, đặt hạn mức tin nhắn mỗi ngày, xoá.
- **Nhật ký hoạt động** đầy đủ kèm IP, có công cụ dọn dẹp.
- **Cấu hình web**: tên website, khẩu hiệu, emoji thương hiệu, màu chính/màu nhấn, dòng bản quyền ở chân trang, câu gợi ý, chế độ bảo trì, bật/tắt đăng ký, giới hạn tệp.

### AI API Endpoint
Hỗ trợ 4 họ API, khai báo bao nhiêu endpoint cũng được và người dùng chọn ngay trên thanh tiêu đề:

| Loại | Dịch vụ tiêu biểu |
|---|---|
| `openai` | OpenAI, OpenRouter, Groq, DeepSeek, Together, Azure-style, vLLM, LM Studio |
| `anthropic` | Anthropic Claude (Messages API) |
| `gemini` | Google Generative Language API |
| `ollama` | Máy chủ Ollama nội bộ (`/api/chat`) |

Mỗi endpoint cấu hình được: **URL**, **API key** (mã hoá AES-256-CBC + HMAC trước khi lưu),
**mô hình**, **token tối đa** (mặc định `64000`), **timeout** (mặc định `300s`), temperature, top-p,
**system prompt**, **tài liệu đi kèm**, số tin nhắn ngữ cảnh, header HTTP bổ sung và payload bổ sung (JSON).
Có nút **Kiểm tra kết nối** báo lỗi bằng tiếng Việt dễ hiểu, chạy được ngay cả khi chưa lưu.

### Dung lượng & phiên (Quản trị → Dung lượng)
- Xem dung lượng database đang bị chiếm bởi tệp đính kèm, nội dung chat, tài liệu — chia theo loại tệp và theo người dùng.
- Danh sách **phiên đăng nhập** đang hoạt động kèm IP, thiết bị; ngắt từng phiên hoặc dọn phiên hết hạn.
- Xoá tệp lớn không cần thiết, dọn dữ liệu mồ côi.
- Nếu nâng cấp từ bản cũ: nút **chuyển tệp từ `uploads/` vào database** theo từng lô, sau đó xoá hẳn thư mục đó.

### Khác
- Toàn bộ thời gian theo **giờ Việt Nam (UTC+7)** — cả PHP lẫn `time_zone` của MySQL.
- **Lịch sử phiên bản**: số phiên bản hiện ở chân trang, trang `/changelog.php` công khai, tăng phiên bản ngay trong trang quản trị hoặc bằng CLI, GitHub Action tự tạo Release theo tag.
- Giao diện **responsive** hoàn toàn, hỗ trợ **sáng/tối**, tôn trọng `prefers-reduced-motion`, in ấn gọn gàng.

---

## 🚀 Cài đặt trên shared hosting

1. **Tạo database MySQL** trong cPanel/DirectAdmin (bộ mã `utf8mb4`), ghi lại tên DB, user, mật khẩu.
2. **Giải nén** file `tuan-chatbot-x.y.z.zip` vào `public_html/` (hoặc thư mục con nếu muốn).
3. Cấp quyền ghi: `config/` → **755**. Muốn dùng chức năng tăng phiên bản trong trang quản trị
   thì thêm `CHANGELOG.md` và `includes/version.php` → **664**.
   Không cần thư mục nào khác ghi được — tệp đính kèm nằm trong database.
4. Mở `https://ten-mien-cua-ban/install.php` và làm theo 5 bước của trình cài đặt.
5. **Xoá `install.php`** sau khi cài xong.
6. Vào **Quản trị → AI API Endpoint** để thêm/sửa kết nối AI.

### Yêu cầu máy chủ

| Thành phần | Mức tối thiểu | Ghi chú |
|---|---|---|
| PHP | 7.4+ | Đã thử trên 7.4 → 8.4 |
| MySQL / MariaDB | 5.7+ / 10.2+ | Cần `utf8mb4` |
| `pdo_mysql`, `curl`, `mbstring`, `json` | bắt buộc | |
| `openssl` | rất nên có | mã hoá API key |
| `zip`, `fileinfo` | nên có | đọc docx/xlsx/pptx, dò MIME |

> Nếu hosting chặn kết nối ra ngoài (outbound), cần nhờ nhà cung cấp mở cURL tới tên miền của dịch vụ AI.

---

## 🗂️ Cấu trúc dự án

```
├── index.php              # Màn hình trò chuyện
├── login.php  register.php  logout.php  account.php
├── changelog.php          # Lịch sử phiên bản công khai
├── install.php            # Trình cài đặt 5 bước (xoá sau khi cài)
├── admin/                 # Khu vực quản trị
│   ├── index.php          # Tổng quan + biểu đồ
│   ├── endpoints.php  endpoint_edit.php  endpoint_test.php
│   ├── documents.php      # Tài liệu đi kèm
│   ├── chats.php  chat_view.php
│   ├── users.php  storage.php  settings.php  logs.php  version.php
├── api/                   # Điểm cuối AJAX / SSE
│   ├── stream.php         # Phát luồng phản hồi AI
│   ├── conversations.php  upload.php  download.php
├── includes/
│   ├── bootstrap.php      # Nạp cấu hình, session, múi giờ
│   ├── ai.php             # Bộ chuyển đổi 4 họ AI API
│   ├── db.php  auth.php  settings.php  files.php
│   ├── crypto.php         # Mã hoá API key
│   ├── storage.php        # Lưu nội dung tệp trong CSDL (cắt khối)
│   ├── session_db.php     # Session lưu trong CSDL
│   ├── migrate.php        # Nâng cấp lược đồ + chuyển tệp cũ vào CSDL
│   ├── versioning.php     # Đọc/ghi CHANGELOG + version
│   └── layout.php  helpers.php  version.php
├── assets/css/            # app.css, admin.css
├── assets/js/             # app.js, chat.js
├── config/config.sample.php
├── sql/schema.sql
└── tools/bump.php  tools/build_zip.php
```

Không có thư mục `uploads/` — nội dung tệp nằm ở bảng `attachment_chunks`.

---

## 🏷️ Quản lý phiên bản

**Trong trang quản trị** — *Quản trị → Phiên bản*: chọn mức tăng (patch/minor/major), nhập ghi chú,
hệ thống tự ghi `includes/version.php`, thêm mục vào `CHANGELOG.md` và lưu vào bảng `app_versions`.

**Bằng dòng lệnh:**

```bash
php tools/bump.php patch "Sửa lỗi hiển thị LaTeX trong bảng" "Tăng tốc tải danh sách chat"
php tools/bump.php minor --codename="Mùa Thu" "Thêm hỗ trợ Ollama"
php tools/bump.php major --git          # bump + commit + tag
```

**Trên GitHub:** đẩy tag `v*` lên, workflow `.github/workflows/release.yml` sẽ tự đóng gói ZIP
và tạo Release kèm ghi chú lấy từ `CHANGELOG.md`.

```bash
git add CHANGELOG.md includes/version.php
git commit -m "chore(release): v1.0.1"
git tag -a v1.0.1 -m "Phiên bản 1.0.1"
git push origin HEAD --tags
```

---

## 📦 Đóng gói để tải lên hosting

```bash
php tools/build_zip.php
# → dist/tuan-chatbot-1.0.0.zip
```

Bản ZIP đã loại bỏ `.git/`, `config/config.php`, tệp trong `uploads/` và các file rác,
nên giải nén trực tiếp lên hosting là chạy được.

---

## 🔐 Bảo mật

- Mật khẩu băm bằng `password_hash()` (bcrypt), tự nâng cấp thuật toán khi đăng nhập.
- CSRF token cho mọi biểu mẫu và mọi lệnh gọi API ghi dữ liệu.
- Toàn bộ truy vấn dùng **prepared statement** của PDO.
- API key mã hoá **AES-256-CBC + HMAC-SHA256** bằng `app_key` trong `config/config.php`.
- Tệp nằm trong database nên **không tồn tại URL trực tiếp** tới tệp: mọi tệp chỉ ra ngoài qua
  `api/download.php` sau khi kiểm tra quyền sở hữu, kèm `Content-Disposition: attachment`,
  `nosniff` và CSP `sandbox` cho định dạng không an toàn. Không có tệp nào trên đĩa để bị thực thi.
- Chặn tuyệt đối các phần mở rộng thực thi (`php`, `phtml`, `sh`, `exe`…) dù cấu hình có mở rộng thế nào.
- Nội dung AI trả về được lọc bằng **DOMPurify** trước khi chèn vào DOM.
- `config/`, `includes/`, `sql/`, `tools/`, `data/` bị chặn truy cập từ web.

> ⚠️ Đổi `app_key` sau khi đã lưu API key sẽ khiến các key cũ không giải mã được — hãy nhập lại key.

---

## 🧰 Khắc phục sự cố

| Hiện tượng | Cách xử lý |
|---|---|
| Chữ không hiện dần mà ra một lần | Hosting đang đệm đầu ra. Thử tắt gzip cho `api/stream.php`, hoặc tắt "Trả lời theo luồng" trong cấu hình endpoint. |
| `Hết thời gian chờ sau 300 giây` | Tăng **Timeout** của endpoint và `max_execution_time` của PHP. |
| `Không kết nối được tới máy chủ AI` | Hosting chặn outbound — nhờ nhà cung cấp mở cURL. |
| Tải tệp lớn báo lỗi | Tăng `upload_max_filesize` và `post_max_size` (xem gợi ý trong *Cấu hình web*). |
| `Không lưu được nội dung tệp vào cơ sở dữ liệu` | `max_allowed_packet` của MySQL quá nhỏ. Hệ thống tự cắt khối theo giá trị này, nhưng nếu dưới 1MB hãy nhờ hosting tăng lên. |
| Database gần hết quota | Quản trị → **Dung lượng**: xem tệp lớn nhất, xoá bớt; hoặc giảm *Dung lượng tối đa mỗi tệp* trong Cấu hình web. |
| Hay bị đăng xuất | Bảng `sessions` bị dọn quá sớm. Tăng `session.gc_maxlifetime` của PHP. |
| Lỗi 413 khi gửi ảnh | Ảnh base64 làm payload phồng lên; giảm kích thước ảnh hoặc số tin nhắn ngữ cảnh. |
| PDF không đọc được nội dung | PDF dạng ảnh scan không rút được text — hãy bật "Nhận tệp" để gửi PDF trực tiếp cho mô hình. |
| Không ghi được phiên bản | Đặt quyền 664 cho `includes/version.php` và `CHANGELOG.md`. |

---

## 📄 Giấy phép

MIT — tự do dùng, sửa và phân phối.

Thư viện bên thứ ba nạp qua CDN: [marked](https://marked.js.org),
[DOMPurify](https://github.com/cure53/DOMPurify), [KaTeX](https://katex.org),
[highlight.js](https://highlightjs.org).
