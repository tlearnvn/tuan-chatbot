# Lịch sử phiên bản

Mọi thay đổi đáng chú ý của dự án được ghi lại tại đây.
Dự án tuân theo [Semantic Versioning](https://semver.org/lang/vi/): `MAJOR.MINOR.PATCH`.

Số phiên bản được hiển thị ở chân trang mọi trang web và có thể tăng tự động
trong **Quản trị → Phiên bản** hoặc bằng lệnh `php tools/bump.php patch "ghi chú"`.

## [1.1.4] — 2026-09-11

- Chân trang chỉ hiện số phiên bản, bỏ tên mã cho gọn; tên mã vẫn xem được ở trang Lịch sử phiên bản và khi rê chuột vào huy hiệu phiên bản.
- Thêm thiết lập Liên kết ở chân trang: chọn GitHub, Facebook, cả hai, hoặc không hiện — biểu tượng và chữ tự đổi theo.
- Thêm ô Địa chỉ Facebook trong Quản trị → Cấu hình web, cạnh ô Địa chỉ GitHub.
- Liên kết mạng xã hội ở chân trang nay hiện độc lập với thiết lập hiện số phiên bản (trước đây tắt số phiên bản là mất luôn liên kết).

## [1.1.3] — 2026-09-11 · _Đọc Được Tệp_

- Sửa lỗi tệp đính kèm PDF không tới được mô hình: viết lại bộ đọc PDF (includes/pdf.php) để tra bảng /ToUnicode của phông nhúng — nay đọc được tiếng Việt trong PDF in từ Chrome, Word, Google Docs, LaTeX, kể cả PDF 1.5 có luồng đối tượng /ObjStm.
- Bộ đọc PDF cũ chỉ hiểu chuỗi literal nên trả về rỗng với hầu hết PDF hiện nay; mô hình không nhận được nội dung nào rồi tự nghĩ ra câu trả lời không liên quan.
- Đọc thêm nội dung OpenDocument (odt, ods, odp), EPUB, RTF, Office 97-2003 (doc, xls, ppt), và liệt kê danh sách tệp bên trong tệp nén ZIP.
- HTML nay được bỏ thẻ trước khi đưa vào ngữ cảnh nên đỡ tốn token.
- Không bao giờ để mô hình đoán nội dung tệp nữa: khi nội dung không tới được mô hình, hệ thống gửi kèm thông báo nêu đúng lý do và cấm suy đoán theo tên tệp.
- Giao diện báo ngay tình trạng từng tệp lúc tải lên: đã đọc được nội dung, gửi trực tiếp cho AI, hay chưa đọc được (kèm cách xử lý).
- Thêm thiết lập Cách gửi tệp PDF cho từng endpoint: tự chọn, luôn gửi nguyên tệp base64, hoặc luôn gửi chữ đã rút.
- PDF có chữ nay gửi dạng văn bản cho họ OpenAI vì nhiều cổng trung gian âm thầm bỏ qua khối type file; cách này vừa chắc tới được mô hình vừa nhẹ hơn base64 hơn 100 lần.
- PDF trên 6MB đã rút được chữ thì gửi chữ để tránh lỗi HTTP 413 do base64 phình thêm 1/3 dung lượng.
- Thêm công cụ tự kiểm tra php tools/selftest.php với 45 phép thử cho toàn bộ phần đọc nội dung tệp.

## [1.1.2] — 2026-09-11

- Thêm thiết lập ẩn tên mô hình AI: khi tắt, tên mô hình (vd deepseek/deepseek-v4-pro) bị loại khỏi mọi dữ liệu gửi ra trình duyệt nên không đọc được qua mã nguồn trang; quản trị viên vẫn thấy và cơ sở dữ liệu vẫn lưu tên thật.
- Sửa lỗi công tắc bật/tắt bị vỡ hoàn toàn trong trang sửa endpoint: quy tắc .field > label ép display:block khiến track mất hộp và núm trắng đè lên chữ.
- Chuẩn hoá lại kiểu công tắc: track rõ hơn khi tắt, có viền trong, hiệu ứng hover, viền focus, và nhóm .switch-group cho các công tắc xếp dọc.

## [1.1.1] — 2026-09-11

- Thêm tài liệu đầy đủ trong docs/: Hướng dẫn sử dụng kèm 31 ảnh minh hoạ chụp từ ứng dụng thật, và Quy trình kỹ thuật kèm 19 sơ đồ Mermaid.
- Sửa lỗi chân trang (dòng bản quyền và số phiên bản) bị đẩy ra ngoài khung nhìn ở màn hình trò chuyện.
- Sửa lỗi tên người dùng bị cắt quá sớm ở chân thanh bên.
- Sửa lỗi install.php trả về HTTP 500 khi trình duyệt còn cookie ghi nhớ đăng nhập của lần cài trước.

## [1.1.0] — 2026-09-11 · _Không Inode_

- Chuyển toàn bộ nội dung tệp đính kèm vào MySQL (bảng attachment_chunks), cắt thành nhiều khối theo max_allowed_packet — ứng dụng không còn ghi tệp nào xuống đĩa.
- Chuyển phiên đăng nhập của PHP vào MySQL (bảng sessions) thay cho file session trên đĩa.
- Nhờ vậy số inode của hosting luôn cố định ở khoảng 55 tệp mã nguồn, dù có bao nhiêu người dùng hay tệp tải lên.
- Thêm trang Quản trị → Dung lượng: theo dõi dung lượng database theo loại tệp và người dùng, xem/ngắt phiên đăng nhập, xoá tệp lớn, dọn dữ liệu mồ côi.
- Thêm cơ chế nâng cấp lược đồ tự động và công cụ chuyển tệp cũ từ uploads/ vào database theo từng lô.
- Đọc và gửi tệp theo từng khối nên bộ nhớ PHP luôn ở mức thấp dù tệp lớn.
- Bỏ yêu cầu thư mục uploads/ ghi được; trình cài đặt không kiểm tra mục này nữa.
- Sửa bộ tách câu lệnh SQL: nay hiểu chuỗi, tên trong backtick và các kiểu chú thích nên dấu chấm phẩy trong COMMENT của cột không làm hỏng việc tạo bảng.
- Đóng phiên sớm khi phát luồng và khi tải tệp để request khác của cùng người dùng không phải chờ.

## [1.0.0] — 2026-09-11 · _Khởi Nguyên_

- Phát hành phiên bản đầu tiên của Tuấn Chatbot.
- Giao diện tiếng Việt tươi vui với nền gradient động, hiệu ứng mượt, chế độ sáng/tối.
- Đăng ký, đăng nhập, ghi nhớ đăng nhập 30 ngày và trang quản lý tài khoản cá nhân.
- Trò chuyện với AI có phản hồi theo luồng (streaming) thời gian thực qua Server-Sent Events.
- Hiển thị đầy đủ Markdown, bảng, khối mã có tô màu và công thức LaTeX bằng KaTeX.
- Thư viện hiển thị có CDN dự phòng: nếu CDN thứ nhất bị chặn thì tự chuyển sang CDN khác, và nội dung vẫn đọc được ở dạng văn bản thuần nếu cả hai đều lỗi.
- Giao diện sáng/tối lưu theo tài khoản nên đồng bộ trên mọi thiết bị.
- Tải lên mọi loại tệp: ảnh, PDF, Office, âm thanh, video, mã nguồn; tự trích xuất văn bản để đưa vào ngữ cảnh.
- Nhận và tải xuống tệp do AI trả về (ảnh sinh bởi mô hình, data URI nhúng trong câu trả lời).
- Lưu toàn bộ cuộc trò chuyện, tin nhắn, tệp đính kèm và số token vào cơ sở dữ liệu MySQL.
- Hỗ trợ 4 họ AI API: OpenAI (và mọi dịch vụ tương thích), Anthropic, Google Gemini, Ollama.
- Cấu hình từng endpoint: URL, API key (mã hoá AES-256), mô hình, token (mặc định 64000), timeout (mặc định 300s), temperature, system prompt, header và payload bổ sung.
- Tài liệu đi kèm nạp vào system prompt, áp dụng chung hoặc riêng cho từng endpoint.
- Khu vực quản trị: tổng quan có biểu đồ, xem toàn bộ lịch sử chat, quản lý người dùng và hạn mức, nhật ký hoạt động.
- Tuỳ chỉnh tên website, khẩu hiệu, emoji thương hiệu, màu sắc và dòng bản quyền ở chân trang.
- Toàn bộ thời gian dùng giờ Việt Nam (UTC+7) ở cả PHP và MySQL.
- Trình cài đặt 5 bước tự kiểm tra máy chủ, tạo bảng và tài khoản quản trị.
- Công cụ đóng gói ZIP để tải lên shared hosting và GitHub Action tự tạo Release theo tag.
