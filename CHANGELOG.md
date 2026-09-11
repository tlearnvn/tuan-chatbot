# Lịch sử phiên bản

Mọi thay đổi đáng chú ý của dự án được ghi lại tại đây.
Dự án tuân theo [Semantic Versioning](https://semver.org/lang/vi/): `MAJOR.MINOR.PATCH`.

Số phiên bản được hiển thị ở chân trang mọi trang web và có thể tăng tự động
trong **Quản trị → Phiên bản** hoặc bằng lệnh `php tools/bump.php patch "ghi chú"`.

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
