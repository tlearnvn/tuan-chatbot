<?php
/**
 * Mẫu cấu hình — hãy copy file này thành config/config.php rồi điền thông tin,
 * hoặc chạy install.php để trình cài đặt tự tạo giúp bạn.
 */

return [
    // ---- Kết nối MySQL ----
    'db_host'    => 'localhost',
    'db_port'    => 3306,
    'db_name'    => 'ten_database',
    'db_user'    => 'ten_user',
    'db_pass'    => 'mat_khau',
    'db_charset' => 'utf8mb4',

    // ---- Bảo mật ----
    // Chuỗi ngẫu nhiên 64 ký tự, dùng để mã hoá API key lưu trong CSDL.
    // ĐỔI chuỗi này trước khi dùng thật. Đổi sau khi đã lưu key sẽ làm hỏng key cũ.
    'app_key'    => 'thay-bang-chuoi-ngau-nhien-that-dai-cang-tot-0123456789abcdef',

    // ---- Múi giờ (mặc định giờ Việt Nam) ----
    'timezone'   => 'Asia/Ho_Chi_Minh',

    // ---- Thư mục tệp CŨ (chỉ để đọc lại) ----
    // Từ phiên bản 1.1.0, nội dung tệp được lưu trong cơ sở dữ liệu để không tốn
    // inode của hosting. Giá trị này chỉ dùng để đọc lại tệp của bản cũ.
    'upload_dir' => 'uploads',

    // ---- Gỡ lỗi: bật khi phát triển, TẮT khi chạy thật ----
    'debug'      => false,
];
