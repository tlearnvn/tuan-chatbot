-- =============================================================================
--  Tuấn Chatbot — Lược đồ cơ sở dữ liệu MySQL
--  Bộ mã: utf8mb4 (hỗ trợ đầy đủ tiếng Việt + emoji)
--  Múi giờ: ứng dụng luôn ghi thời gian theo giờ Việt Nam (UTC+7)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Người dùng
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(120) NOT NULL DEFAULT '',
  `avatar_emoji`  VARCHAR(16)  NOT NULL DEFAULT '🐣',
  `avatar_color`  VARCHAR(20)  NOT NULL DEFAULT '#7c5cff',
  `role`          ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status`        ENUM('active','locked') NOT NULL DEFAULT 'active',
  `theme`         VARCHAR(10)  NOT NULL DEFAULT 'light',
  `daily_limit`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = không giới hạn',
  `last_login_at` DATETIME     NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Ghi nhớ đăng nhập
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `selector`   CHAR(32)     NOT NULL,
  `hashed_val` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Điểm cuối AI (AI API Endpoint)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `endpoints` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(120) NOT NULL,
  `description`      VARCHAR(255) NOT NULL DEFAULT '',
  `api_type`         VARCHAR(32)  NOT NULL DEFAULT 'openai' COMMENT 'openai | anthropic | gemini | ollama',
  `base_url`         VARCHAR(500) NOT NULL DEFAULT '',
  `api_key`          TEXT         NULL COMMENT 'Được mã hoá AES-256',
  `model`            VARCHAR(190) NOT NULL DEFAULT '',
  `max_tokens`       INT UNSIGNED NOT NULL DEFAULT 64000,
  `timeout`          INT UNSIGNED NOT NULL DEFAULT 300,
  `temperature`      DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  `top_p`            DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  `system_prompt`    LONGTEXT     NULL,
  `extra_headers`    TEXT         NULL COMMENT 'JSON object',
  `extra_body`       TEXT         NULL COMMENT 'JSON object hợp nhất vào payload',
  `supports_vision`  TINYINT(1)   NOT NULL DEFAULT 1,
  `supports_files`   TINYINT(1)   NOT NULL DEFAULT 1,
  `supports_stream`  TINYINT(1)   NOT NULL DEFAULT 1,
  `history_limit`    INT UNSIGNED NOT NULL DEFAULT 20,
  `is_default`       TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_endpoints_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tài liệu đi kèm (nạp vào system prompt)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documents` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint_id` INT UNSIGNED NULL COMMENT 'NULL = áp dụng cho mọi endpoint',
  `title`       VARCHAR(190) NOT NULL,
  `file_name`   VARCHAR(190) NOT NULL DEFAULT '',
  `mime`        VARCHAR(120) NOT NULL DEFAULT 'text/plain',
  `size`        INT UNSIGNED NOT NULL DEFAULT 0,
  `content`     LONGTEXT     NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_endpoint` (`endpoint_id`, `is_active`),
  CONSTRAINT `fk_documents_endpoint` FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Cuộc trò chuyện
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `endpoint_id` INT UNSIGNED NULL,
  `title`       VARCHAR(255) NOT NULL DEFAULT 'Cuộc trò chuyện mới',
  `is_pinned`   TINYINT(1)   NOT NULL DEFAULT 0,
  `is_deleted`  TINYINT(1)   NOT NULL DEFAULT 0,
  `msg_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv_user` (`user_id`, `is_deleted`, `updated_at`),
  KEY `idx_conv_updated` (`updated_at`),
  CONSTRAINT `fk_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_endpoint` FOREIGN KEY (`endpoint_id`) REFERENCES `endpoints` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tin nhắn
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `role`            ENUM('user','assistant','system') NOT NULL,
  `content`         LONGTEXT     NULL,
  `reasoning`       LONGTEXT     NULL,
  `model`           VARCHAR(190) NOT NULL DEFAULT '',
  `endpoint_id`     INT UNSIGNED NULL,
  `prompt_tokens`   INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms`     INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('ok','error','aborted','streaming') NOT NULL DEFAULT 'ok',
  `error_message`   TEXT         NULL,
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv` (`conversation_id`, `id`),
  KEY `idx_msg_user` (`user_id`, `created_at`),
  KEY `idx_msg_created` (`created_at`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tệp đính kèm (cả tệp người dùng gửi lên lẫn tệp AI trả về)
--
-- Chỉ lưu phần thông tin (metadata) ở bảng này để mọi truy vấn liệt kê đều nhẹ.
-- Nội dung nhị phân nằm ở bảng `attachment_chunks` bên dưới — không ghi file
-- nào xuống đĩa, nhờ vậy không tốn inode của hosting.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED NULL,
  `message_id`      INT UNSIGNED NULL,
  `direction`       ENUM('in','out') NOT NULL DEFAULT 'in',
  `original_name`   VARCHAR(255) NOT NULL,
  `storage`         ENUM('db','file') NOT NULL DEFAULT 'db'
                    COMMENT 'db = nội dung trong attachment_chunks; file = bản cũ nằm trong uploads/',
  `stored_name`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Chỉ dùng cho bản ghi cũ storage = file',
  `mime`            VARCHAR(160) NOT NULL DEFAULT 'application/octet-stream',
  `size`            INT UNSIGNED NOT NULL DEFAULT 0,
  `kind`            VARCHAR(20)  NOT NULL DEFAULT 'file' COMMENT 'image | pdf | text | audio | video | file',
  `extracted_text`  LONGTEXT     NULL,
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_message` (`message_id`),
  KEY `idx_att_user` (`user_id`, `created_at`),
  KEY `idx_att_conv` (`conversation_id`),
  CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Nội dung nhị phân của tệp, cắt thành nhiều khối nhỏ
--
-- Cắt khối để không phụ thuộc vào `max_allowed_packet` của MySQL (nhiều hosting
-- chỉ cho 4–16MB) và để đọc/ghi tệp lớn mà không ngốn bộ nhớ PHP.
-- Kích thước khối do storage_chunk_size() tự tính theo cấu hình máy chủ.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachment_chunks` (
  `attachment_id` INT UNSIGNED   NOT NULL,
  `seq`           SMALLINT UNSIGNED NOT NULL COMMENT 'Thứ tự khối, bắt đầu từ 0',
  `content`       MEDIUMBLOB     NOT NULL,
  PRIMARY KEY (`attachment_id`, `seq`),
  CONSTRAINT `fk_chunk_attachment` FOREIGN KEY (`attachment_id`)
    REFERENCES `attachments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Phiên đăng nhập (thay cho file session trên đĩa)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`            VARCHAR(128) NOT NULL,
  `user_id`       INT UNSIGNED NULL,
  `ip`            VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
  `payload`       MEDIUMBLOB   NULL,
  `last_activity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix timestamp, dùng để dọn phiên hết hạn',
  PRIMARY KEY (`id`),
  KEY `idx_sessions_activity` (`last_activity`),
  KEY `idx_sessions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Thiết lập chung của website
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k`          VARCHAR(64) NOT NULL,
  `v`          LONGTEXT    NULL,
  `updated_at` DATETIME    NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Nhật ký hoạt động
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(60)  NOT NULL,
  `detail`     TEXT         NULL,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`, `created_at`),
  KEY `idx_log_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Lịch sử phiên bản ứng dụng
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_versions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`     VARCHAR(20)  NOT NULL,
  `released_at` DATETIME     NOT NULL,
  `notes`       TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
