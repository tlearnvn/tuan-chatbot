# 🔧 Quy trình kỹ thuật Tuấn Chatbot

> Tài liệu dành cho lập trình viên · phiên bản **v1.1.0**
> Mọi sơ đồ trong tài liệu này viết bằng Mermaid nên GitHub hiển thị trực tiếp.

---

## Mục lục

1. [Kiến trúc tổng thể](#1-kiến-trúc-tổng-thể)
2. [Cấu trúc mã nguồn](#2-cấu-trúc-mã-nguồn)
3. [Vòng đời một request](#3-vòng-đời-một-request)
4. [Luồng phản hồi theo thời gian thực (SSE)](#4-luồng-phản-hồi-theo-thời-gian-thực-sse)
5. [Bộ chuyển đổi AI API](#5-bộ-chuyển-đổi-ai-api)
6. [Cơ sở dữ liệu](#6-cơ-sở-dữ-liệu)
7. [Lưu trữ tệp trong cơ sở dữ liệu](#7-lưu-trữ-tệp-trong-cơ-sở-dữ-liệu)
8. [Phiên đăng nhập trong cơ sở dữ liệu](#8-phiên-đăng-nhập-trong-cơ-sở-dữ-liệu)
9. [Pipeline hiển thị Markdown và LaTeX](#9-pipeline-hiển-thị-markdown-và-latex)
10. [Bảo mật](#10-bảo-mật)
11. [Nâng cấp lược đồ](#11-nâng-cấp-lược-đồ)
12. [Quy trình phát hành](#12-quy-trình-phát-hành)
13. [Công thức mở rộng](#13-công-thức-mở-rộng)

---

## 1. Kiến trúc tổng thể

Ứng dụng là một PHP truyền thống không framework: mỗi trang là một file `.php`,
dùng chung một lớp `includes/`. Không có build step, không có Composer.

```mermaid
flowchart TB
    subgraph TRINHDUYET["🖥️ Trình duyệt"]
        UI["index.php<br/>giao diện chat"]
        JS["chat.js · md.js · libs.js · app.js"]
        CDN["CDN: marked · DOMPurify<br/>KaTeX · highlight.js"]
    end

    subgraph HOSTING["🌐 Shared hosting — PHP 7.4+"]
        direction TB
        PAGES["Trang: index · login · register<br/>account · changelog · admin/*"]
        API["api/stream.php · upload.php<br/>download.php · conversations.php"]
        CORE["includes/: bootstrap · db · auth<br/>settings · ai · storage · files"]
    end

    DB[("🗄️ MySQL<br/>12 bảng<br/>tệp + phiên + chat")]
    AI["🤖 Dịch vụ AI<br/>OpenAI · Anthropic<br/>Gemini · Ollama"]

    UI --> JS
    JS -.tải thư viện.-> CDN
    JS -->|"fetch + SSE"| API
    UI --> PAGES
    PAGES --> CORE
    API --> CORE
    CORE --> DB
    CORE -->|"cURL"| AI

    style DB fill:#e8f5e9,stroke:#2f9e6e,color:#1b5e20
    style AI fill:#f3e5f5,stroke:#7c5cff,color:#4a148c
    style HOSTING fill:#fff8e1,stroke:#ffb443
    style TRINHDUYET fill:#e3f2fd,stroke:#5ca8ff
```

**Nguyên tắc thiết kế**

| Nguyên tắc | Vì sao |
|---|---|
| Không framework, không Composer | Giải nén là chạy trên mọi shared hosting |
| Không ghi tệp xuống đĩa | Shared hosting giới hạn inode rất chặt |
| Mọi truy vấn qua prepared statement | Chống SQL injection tận gốc |
| Lớp `includes/` thuần hàm, không class trừ khi cần | Dễ đọc, dễ sửa, không cần autoloader |
| Thư viện hiển thị nạp từ CDN | Gói ZIP nhẹ (168 KB) và ít inode |

---

## 2. Cấu trúc mã nguồn

```mermaid
flowchart LR
    subgraph L1["Tầng khởi động"]
        BS["bootstrap.php<br/><i>nạp cấu hình, múi giờ, session</i>"]
    end
    subgraph L2["Tầng hạ tầng"]
        HP["helpers.php<br/><i>escape, CSRF, thời gian</i>"]
        DBL["db.php<br/><i>PDO, tách SQL</i>"]
        CR["crypto.php<br/><i>AES-256 cho API key</i>"]
        SS["session_db.php<br/><i>session trong MySQL</i>"]
    end
    subgraph L3["Tầng nghiệp vụ"]
        AU["auth.php<br/><i>đăng nhập, phân quyền</i>"]
        ST["settings.php<br/><i>cấu hình website</i>"]
        SG["storage.php<br/><i>tệp theo khối</i>"]
        FI["files.php<br/><i>phân loại, trích văn bản</i>"]
        AIA["ai.php<br/><i>4 họ AI API</i>"]
        MG["migrate.php<br/><i>nâng cấp lược đồ</i>"]
        VE["versioning.php<br/><i>CHANGELOG + version</i>"]
    end
    subgraph L4["Tầng giao diện"]
        LO["layout.php"]
        PG["các trang .php"]
        AP["api/*.php"]
    end

    BS --> HP & DBL & CR & SS
    HP & DBL & CR & SS --> AU & ST & SG & FI
    SG --> FI
    FI --> AIA
    DBL --> MG & VE
    AU & ST & FI & AIA --> LO & PG & AP
```

Thứ tự nạp trong `bootstrap.php` **bắt buộc** theo đúng dãy này vì
`session_set_save_handler()` phải được gọi *trước* `session_start()`, mà bộ xử lý
session lại cần PDO:

```php
config → múi giờ → helpers → crypto → db → session_db
       → session_use_database() → session_start()
       → settings → auth → storage → files
```

---

## 3. Vòng đời một request

```mermaid
flowchart TD
    A["Request tới một trang .php"] --> B["require includes/bootstrap.php"]
    B --> C{"config/config.php<br/>tồn tại?"}
    C -->|"không"| C1["chuyển sang install.php"]
    C -->|"có"| D["đặt múi giờ Asia/Ho_Chi_Minh<br/>cho cả PHP và MySQL"]
    D --> E{"bảng sessions<br/>đã có?"}
    E -->|"có"| E1["session_set_save_handler<br/>DbSessionHandler"]
    E -->|"chưa"| E2["dùng session file<br/>mặc định của PHP"]
    E1 --> F["session_start"]
    E2 --> F
    F --> G["nạp settings từ bảng settings<br/><i>một truy vấn, cache tĩnh</i>"]
    G --> H{"require_login<br/>hoặc require_admin?"}
    H -->|"chưa đăng nhập"| H1["thử cookie tchat_remember"]
    H1 --> H2{"khôi phục được?"}
    H2 -->|"không"| H3["chuyển tới login.php?next=..."]
    H2 -->|"có"| I
    H -->|"đã đăng nhập"| I["xử lý nghiệp vụ của trang"]
    I --> J{"là POST<br/>thay đổi dữ liệu?"}
    J -->|"có"| J1{"CSRF hợp lệ?"}
    J1 -->|"không"| J2["HTTP 419<br/>Phiên đã hết hạn"]
    J1 -->|"có"| K["thao tác CSDL"]
    J -->|"không"| K
    K --> L["layout_head → nội dung → layout_foot"]

    style C1 fill:#fff3e0,stroke:#e08a1e
    style H3 fill:#fff3e0,stroke:#e08a1e
    style J2 fill:#ffebee,stroke:#e5484d
```

---

## 4. Luồng phản hồi theo thời gian thực (SSE)

`api/stream.php` là trái tim của ứng dụng. Nó vừa là proxy tới dịch vụ AI, vừa là
nơi ghi lịch sử chat.

```mermaid
sequenceDiagram
    autonumber
    participant B as Trình duyệt
    participant S as api/stream.php
    participant D as MySQL
    participant A as Dịch vụ AI

    B->>S: POST JSON — message, conversation_id,<br/>endpoint_id, attachments, csrf_token
    S->>S: require_login + csrf_require
    S->>D: kiểm tra hạn mức ngày của người dùng
    S->>D: tạo hoặc kiểm tra cuộc trò chuyện
    S->>D: INSERT tin nhắn của người dùng
    S->>D: gắn attachments vào tin nhắn
    S->>D: đọc N tin nhắn gần nhất làm ngữ cảnh
    S->>D: đọc system prompt + tài liệu đi kèm
    S->>S: session_write_close<br/><i>để request khác không phải chờ</i>
    S-->>B: SSE event start — conversationId, title, endpoint

    S->>A: POST payload theo đúng họ API (cURL, stream)
    loop mỗi khối dữ liệu nhận được
        A-->>S: chunk SSE hoặc NDJSON
        S->>S: ai_parse_event chuẩn hoá
        S-->>B: event delta — text
        S-->>B: event reasoning — text
        alt mô hình trả về ảnh
            S->>D: lưu ảnh thành attachment
            S-->>B: event file + event delta chèn liên kết
        end
        S->>S: connection_aborted? → dừng cURL
    end

    S->>S: chuyển data URI còn lại thành tệp
    S->>D: INSERT câu trả lời — content, tokens,<br/>duration_ms, status
    S->>D: cập nhật msg_count và updated_at
    S-->>B: event done — content, model, tokens, files
    S-->>B: event end
```

### Điểm kỹ thuật quan trọng

| Vấn đề | Cách xử lý |
|---|---|
| Hosting đệm đầu ra | Gửi 2 KB khoảng trắng mở màn, `X-Accel-Buffering: no`, tắt `zlib.output_compression`, `ob_end_clean()` mọi lớp đệm |
| gzip làm nghẽn luồng | `CURLOPT_ENCODING = 'identity'` khi streaming, để nén khi không streaming |
| Người dùng bấm Dừng | `connection_aborted()` trong callback ghi của cURL → trả `0` để dừng; `ignore_user_abort(true)` giúp phần đã nhận **vẫn được lưu** với `status = 'aborted'` |
| Người dùng đóng tab giữa luồng | Như trên — không bao giờ để lại tin nhắn treo |
| Khoá phiên chặn request khác | `session_write_close()` ngay trước khi phát luồng |
| Quá thời gian thực thi | `set_time_limit(timeout + 60)` |
| Lỗi giữa luồng | Ghi phần đã nhận, `status = 'error'`, kèm `error_message`; giao diện hiện nút *Thử lại* |

### Các sự kiện SSE

| Event | Dữ liệu | Ý nghĩa |
|---|---|---|
| `start` | `conversationId`, `userMessageId`, `title`, `isNew`, `endpoint` | Đã ghi tin nhắn, bắt đầu gọi AI |
| `delta` | `text` | Một mẩu nội dung mới |
| `reasoning` | `text` | Một mẩu suy luận |
| `file` | `id`, `name`, `url`, `mime`, `size`, `kind` | Đã lưu một tệp do AI trả về |
| `done` | `messageId`, `content`, `model`, `tokens`, `durationMs`, `files` | Hoàn tất, kèm nội dung cuối cùng |
| `error` | `message`, `messageId` | Lỗi — nội dung dở vẫn được lưu |
| `end` | `{}` | Đóng luồng |

> Trình duyệt nhận luồng bằng `fetch()` + `ReadableStream` chứ không dùng
> `EventSource`, vì `EventSource` chỉ hỗ trợ GET mà ta cần POST một payload JSON.

---

## 5. Bộ chuyển đổi AI API

`includes/ai.php` quy bốn họ API khác nhau về **một giao diện duy nhất**:

```php
ai_stream($endpoint, $history, $system, [
    'onDelta'     => function ($text) { ... },
    'onReasoning' => function ($text) { ... },
    'onFile'      => function ($file) { ... },
    'onUsage'     => function ($usage) { ... },
    'onMeta'      => function ($meta) { ... },
    'shouldStop'  => function () { return false; },
]);
```

```mermaid
flowchart TD
    IN["history + system prompt + endpoint"] --> RES["ai_resolve_url<br/><i>ghép URL đích</i>"]
    IN --> HDR["ai_build_headers<br/><i>xác thực theo họ API</i>"]
    IN --> BODY["ai_build_body"]

    BODY --> T{"api_type"}
    T -->|"openai"| O["messages: role + content parts<br/>image_url · file<br/>stream_options.include_usage"]
    T -->|"anthropic"| AN["messages + system riêng<br/>image · document blocks<br/>anthropic-version header"]
    T -->|"gemini"| G["contents + parts<br/>inline_data<br/>systemInstruction<br/>alt=sse"]
    T -->|"ollama"| OL["messages + images base64<br/>options.num_predict<br/>NDJSON"]

    O & AN & G & OL --> MERGE["hợp nhất extra_body<br/><i>ai_deep_merge</i>"]
    RES & HDR & MERGE --> CURL["cURL + CURLOPT_WRITEFUNCTION"]

    CURL --> PARSE{"định dạng luồng"}
    PARSE -->|"SSE"| P1["tách theo dòng trống<br/>đọc event: và data:"]
    PARSE -->|"NDJSON"| P2["tách theo từng dòng"]
    P1 & P2 --> NORM["ai_parse_event<br/><i>chuẩn hoá</i>"]
    NORM --> OUT["text · reasoning · files<br/>usage · model · error"]
    OUT --> CB["gọi callback tương ứng"]

    style OUT fill:#e8f5e9,stroke:#2f9e6e
```

### Ghép URL — `ai_resolve_url()`

Người dùng chỉ cần nhập URL gốc; hàm này tự thêm đường dẫn và **giữ nguyên** nếu
đã có sẵn đường dẫn đầy đủ:

| Loại | Nhập vào | Thành |
|---|---|---|
| `openai` | `https://api.openai.com/v1` | `…/v1/chat/completions` |
| `openai` | `https://x.com/api` | `…/api/v1/chat/completions` |
| `openai` | `…/v1/chat/completions` | giữ nguyên |
| `anthropic` | `https://api.anthropic.com/v1` | `…/v1/messages` |
| `gemini` | `https://…/v1beta` | `…/v1beta/models/{model}:streamGenerateContent?alt=sse` |
| `ollama` | `http://localhost:11434` | `…/api/chat` |

### Tệp đính kèm thành payload

```mermaid
flowchart TD
    A["Tệp đính kèm"] --> B{"size > 0 và<br/>size ≤ 18 MB?"}
    B -->|"không"| TXT
    B -->|"có"| C{"kind"}

    C -->|"image"| D{"endpoint bật<br/>supports_vision?"}
    D -->|"có"| BIN["đọc nội dung từ CSDL<br/>→ base64 → khối ảnh"]
    D -->|"không"| TXT

    C -->|"pdf"| E{"supports_files và<br/>api_type ≠ ollama?"}
    E -->|"có"| BIN
    E -->|"không"| TXT

    C -->|"audio / video"| F{"supports_files và<br/>api_type = gemini?"}
    F -->|"có"| BIN
    F -->|"không"| TXT

    C -->|"text / file"| TXT["đưa extracted_text<br/>vào phần text của prompt"]
    TXT --> G{"có extracted_text?"}
    G -->|"không"| NOTE["ghi chú: đã đính kèm tệp X,<br/>hệ thống không đọc được nội dung"]

    style BIN fill:#e8f5e9,stroke:#2f9e6e
    style TXT fill:#e3f2fd,stroke:#5ca8ff
    style NOTE fill:#fff3e0,stroke:#e08a1e
```

### Diễn giải lỗi

`ai_humanize_http_error()` và `ai_humanize_curl_error()` đổi mã lỗi thành câu
tiếng Việt kèm hướng xử lý — người quản trị không phải tra cứu:

| Mã | Thông báo |
|---|---|
| 401 | API key không hợp lệ hoặc đã hết hạn |
| 403 | API key không có quyền truy cập mô hình này |
| 404 | Không tìm thấy đường dẫn API hoặc mô hình — hãy kiểm tra lại URL và tên mô hình |
| 413 | Nội dung gửi đi quá lớn (tệp đính kèm hoặc lịch sử trò chuyện quá dài) |
| 429 | Đã vượt hạn mức gọi API. Vui lòng thử lại sau ít phút |
| 503 | Máy chủ AI đang quá tải. Vui lòng thử lại |
| `CURLE_OPERATION_TIMEOUTED` | Hết thời gian chờ sau N giây. Hãy tăng "Timeout" trong cấu hình endpoint |
| `CURLE_COULDNT_CONNECT` | Không kết nối được tới máy chủ AI. Có thể hosting đang chặn kết nối ra ngoài |

Phần chi tiết do nhà cung cấp trả về được nối vào sau chữ *"Chi tiết:"*.

---

## 6. Cơ sở dữ liệu

```mermaid
erDiagram
    users ||--o{ conversations : "sở hữu"
    users ||--o{ messages : "gửi"
    users ||--o{ attachments : "tải lên"
    users ||--o{ remember_tokens : "ghi nhớ"
    users ||--o{ activity_log : "hoạt động"
    users ||--o{ sessions : "đăng nhập"
    conversations ||--o{ messages : "chứa"
    messages ||--o{ attachments : "kèm theo"
    attachments ||--o{ attachment_chunks : "nội dung"
    endpoints ||--o{ conversations : "dùng cho"
    endpoints ||--o{ documents : "tài liệu riêng"

    users {
        int id PK
        varchar username UK
        varchar email UK
        varchar password_hash
        enum role "user, admin"
        enum status "active, locked"
        int daily_limit "0 = không giới hạn"
        varchar theme
        datetime last_login_at
    }
    conversations {
        int id PK
        int user_id FK
        int endpoint_id FK
        varchar title
        tinyint is_pinned
        int msg_count
        datetime updated_at
    }
    messages {
        int id PK
        int conversation_id FK
        enum role "user, assistant, system"
        longtext content
        longtext reasoning
        varchar model
        int prompt_tokens
        int completion_tokens
        int duration_ms
        enum status "ok, error, aborted"
        text error_message
    }
    attachments {
        int id PK
        int message_id FK
        enum direction "in, out"
        varchar original_name
        enum storage "db, file"
        varchar mime
        int size
        varchar kind
        longtext extracted_text
    }
    attachment_chunks {
        int attachment_id PK "đồng thời là khoá ngoại"
        smallint seq PK "thứ tự khối, từ 0"
        mediumblob content "một khối nội dung"
    }
    endpoints {
        int id PK
        varchar name
        varchar api_type "openai, anthropic, gemini, ollama"
        varchar base_url
        text api_key "mã hoá AES-256"
        varchar model
        int max_tokens "mặc định 64000"
        int timeout "mặc định 300"
        longtext system_prompt
        tinyint is_default
        tinyint is_active
    }
    documents {
        int id PK
        int endpoint_id FK "NULL = mọi endpoint"
        varchar title
        longtext content
        tinyint is_active
    }
    sessions {
        varchar id PK
        int user_id FK
        varchar ip
        mediumblob payload
        int last_activity
    }
    settings {
        varchar k PK
        longtext v
    }
    app_versions {
        int id PK
        varchar version UK
        datetime released_at
        text notes
    }
```

### Nguyên tắc về lược đồ

| | |
|---|---|
| **Bộ mã** | `utf8mb4` / `utf8mb4_unicode_ci` trên mọi bảng — tiếng Việt và emoji đều đúng |
| **Khoá ngoại** | `ON DELETE CASCADE` khắp nơi: xoá người dùng là sạch toàn bộ dấu vết, không cần dọn tay |
| **Blob tách riêng** | Nội dung tệp ở bảng riêng để `SELECT * FROM attachments` luôn nhẹ |
| **Múi giờ** | Ứng dụng tự sinh thời gian bằng PHP, đồng thời `SET time_zone = '+07:00'` cho mỗi kết nối |

### Bộ tách câu lệnh SQL

`db_split_sql()` không cắt đơn thuần theo dấu `;` mà hiểu chuỗi nháy đơn/kép, tên
trong backtick và cả ba kiểu chú thích. Nhờ vậy một dấu `;` nằm trong phần
`COMMENT` của cột không làm việc tạo bảng dừng giữa đường:

```sql
`storage` ENUM('db','file') NOT NULL DEFAULT 'db'
          COMMENT 'db = trong attachment_chunks; file = bản cũ trong uploads/'
--                                              ↑ dấu ; này từng làm vỡ installer
```

---

## 7. Lưu trữ tệp trong cơ sở dữ liệu

### Vì sao không ghi ra đĩa

Shared hosting thường giới hạn **inode** (số tệp + thư mục) ở mức 100.000–300.000.
Mỗi tệp người dùng tải lên, mỗi ảnh AI sinh ra và mỗi phiên đăng nhập nếu đều
thành một tệp thì sớm muộn cũng chạm giới hạn — và khi chạm, hosting **khoá luôn
việc ghi**, kể cả ghi log hay ghi session.

```mermaid
flowchart LR
    subgraph CU["❌ Cách thông thường"]
        direction TB
        C1["uploads/2026/09/abc.png"]
        C2["uploads/2026/09/def.pdf"]
        C3["…10.000 tệp"]
        C4["sess_a1b2c3…"]
        C5["…mỗi người đang online 1 tệp"]
        C6["📈 inode tăng không giới hạn"]
    end
    subgraph MOI["✅ Cách của v1.1.0"]
        direction TB
        M1["attachment_chunks<br/>mỗi tệp = N dòng blob"]
        M2["sessions<br/>mỗi phiên = 1 dòng"]
        M3["📌 inode cố định ~55 tệp mã nguồn"]
    end
    CU ~~~ MOI
    style C6 fill:#ffebee,stroke:#e5484d
    style M3 fill:#e8f5e9,stroke:#2f9e6e
```

**Đã đo thực tế:** 12 request sau khi cài đặt (8 khách + 4 lần đăng nhập) cùng
31 tệp đính kèm, 20 tin nhắn và 18 phiên → số inode giữ nguyên **74 → 74**.

### Cắt khối

Nội dung tệp không lưu thành một blob khổng lồ mà cắt thành nhiều khối nhỏ:

```mermaid
flowchart TD
    U["Tệp 25 MB người dùng tải lên"] --> T["tệp tạm của PHP<br/><i>PHP tự xoá sau request</i>"]
    T --> M["detect_mime + file_kind"]
    T --> X["extract_text_from_file<br/><i>đọc chữ để đưa vào ngữ cảnh</i>"]
    M & X --> R["INSERT attachments<br/><i>lấy id</i>"]
    R --> CS["storage_chunk_size<br/><i>= 40% của max_allowed_packet</i><br/><i>kẹp trong 64 KB … 1 MB</i>"]
    CS --> LOOP["fread từng khối → INSERT attachment_chunks"]
    LOOP --> DONE["25 khối · bộ nhớ PHP chỉ giữ 1 MB"]

    style DONE fill:#e8f5e9,stroke:#2f9e6e
```

Vì sao phải cắt:

1. **`max_allowed_packet`** của MySQL trên shared hosting thường chỉ 4–16 MB.
   Một `INSERT` chứa 25 MB sẽ bị từ chối. Kích thước khối tự tính theo giá trị thật
   của máy chủ nên không phụ thuộc cấu hình từng nơi.
2. **Bộ nhớ PHP.** Đọc và gửi theo khối giữ mức dùng bộ nhớ gần như không đổi.
   Đo thực tế với tệp 2,9 MB: đọc theo khối đỉnh **6 MB**, nạp cả tệp đỉnh **8 MB** —
   khoảng cách nới rộng theo kích thước tệp.

### Đọc lại

```mermaid
sequenceDiagram
    autonumber
    participant B as Trình duyệt
    participant D as api/download.php
    participant M as MySQL

    B->>D: GET api/download.php?id=42
    D->>D: require_login
    D->>M: SELECT * FROM attachments WHERE id=42
    D->>D: kiểm tra chủ sở hữu hoặc quyền admin
    Note over D: không phải chủ và không phải admin → 404
    D->>D: session_write_close
    D->>D: chọn Content-Disposition<br/>inline chỉ cho định dạng an toàn
    D-->>B: headers: Content-Type, Content-Length,<br/>nosniff, CSP sandbox
    loop seq = 0, 1, 2, …
        D->>M: SELECT content WHERE attachment_id=42 AND seq=?
        M-->>D: một khối
        D-->>B: echo + flush
        D->>D: connection_aborted? → dừng
    end
```

Lấy **từng khối bằng một truy vấn riêng** thay vì `ORDER BY seq` một lần, vì PDO
của MySQL mặc định nạp sẵn toàn bộ tập kết quả vào bộ nhớ — cách này đánh đổi
N truy vấn nhỏ để bộ nhớ luôn ở mức thấp.

### API của lớp lưu trữ

| Hàm | Việc |
|---|---|
| `storage_chunk_size()` | Kích thước khối, tính theo `@@max_allowed_packet` |
| `storage_put($id, $binary)` | Ghi nội dung từ bộ nhớ |
| `storage_put_from_file($id, $path)` | Ghi từ tệp trên đĩa, đọc theo khối |
| `storage_get($id, $maxBytes)` | Đọc toàn bộ (trả `null` nếu vượt ngưỡng) |
| `storage_passthru($id)` | Đẩy trực tiếp ra trình duyệt theo khối |
| `storage_size($id)` · `storage_exists($id)` | Dung lượng thật · đã có nội dung chưa |
| `storage_total_bytes()` | Tổng dung lượng toàn hệ thống |
| `storage_purge_orphans()` | Dọn khối không còn bản ghi tương ứng |

Lớp `files.php` bọc thêm một tầng để hỗ trợ cả bản ghi cũ:

```php
attachment_binary($row, $maxBytes)  // storage = 'db' → CSDL; 'file' → đọc đĩa
attachment_passthru($row)
attachment_available($row)
attachment_legacy_path($row)        // chỉ dành cho bản ghi cũ
```

---

## 8. Phiên đăng nhập trong cơ sở dữ liệu

`DbSessionHandler` hiện thực `SessionHandlerInterface`, đăng ký trong
`bootstrap.php` **trước** `session_start()`:

```mermaid
flowchart TD
    A["session_use_database()"] --> B{"bảng sessions<br/>tồn tại?"}
    B -->|"không"| C["trả false → PHP dùng session file<br/><i>giai đoạn đang cài đặt</i>"]
    B -->|"có"| D["session_set_save_handler<br/>DbSessionHandler"]
    D --> E["session_start()"]

    E --> R["read: SELECT payload<br/>quá last_activity + lifetime → coi như rỗng"]
    E --> W["write: INSERT … ON DUPLICATE KEY UPDATE<br/>tách user_id từ payload để admin xem được"]
    E --> X["destroy: DELETE theo id"]
    E --> G["gc: DELETE last_activity < cutoff"]

    style C fill:#fff3e0,stroke:#e08a1e
```

**Ba điểm đáng nói**

1. Mọi phương thức bọc `try/catch`: CSDL lỗi thì session coi như rỗng chứ không
   làm sập trang.
2. `write()` rút `user_id` từ chuỗi đã tuần tự hoá (`/user_id\|i:(\d+);/`) để trang
   *Dung lượng & phiên* hiển thị được ai đang online và **ngắt** được từng phiên.
3. Bộ xử lý **không khoá dòng**, nên nhiều request song song của cùng một người
   không chặn nhau. Đo thực tế: trong lúc một luồng SSE đang chảy, ba request
   `conversations.php` khác trả về trong **14–19 ms**.

---

## 9. Pipeline hiển thị Markdown và LaTeX

Vấn đề cốt lõi: Markdown và LaTeX tranh nhau các ký tự `_`, `*`, `\`. Nếu chạy
Markdown trước, `x_1` trong công thức biến thành chữ in nghiêng.

```mermaid
flowchart TD
    A["Nội dung thô từ AI"] --> B["protectMath()"]
    B --> B1["bỏ qua phần nằm trong khối mã<br/>và mã nội dòng"]
    B1 --> B2["tìm 4 dạng công thức: đôla đôi ·<br/>ngoặc vuông escape · ngoặc đơn escape · đôla đơn"]
    B2 --> B3{"đôla đơn có phải giá tiền?"}
    B3 -->|"chỉ gồm số và dấu phân cách,<br/>hoặc có khoảng trắng hai đầu"| B4["giữ nguyên, không phải công thức"]
    B3 -->|"công thức thật"| B5["thay bằng chỗ giữ chỗ ␟N␟<br/><i>ký tự U+241F, Markdown không đụng tới</i>"]
    B4 & B5 --> C["marked.parse()"]
    C --> C1["renderer.code → khối mã + nút Chép/Tải<br/>+ highlight.js"]
    C --> C2["renderer.table → bọc div cuộn ngang"]
    C1 & C2 --> D["restoreMath()<br/><i>␟N␟ → span/div data-tex</i>"]
    D --> E["DOMPurify.sanitize()<br/><i>lọc XSS</i>"]
    E --> F["gắn vào DOM"]
    F --> G["katex.render() cho từng data-tex"]
    G --> H["liên kết ngoài: target=_blank rel=noopener<br/>ảnh lỗi → badge thay vì icon hỏng"]

    style B5 fill:#e3f2fd,stroke:#5ca8ff
    style E fill:#ffebee,stroke:#e5484d
    style H fill:#e8f5e9,stroke:#2f9e6e
```

`protectMath()` có **16 test đơn vị** phủ các trường hợp biên: giá tiền, dấu `$`
lẻ, công thức trong khối mã, nháy đơn nhân đôi, ma trận nhiều dòng, `P(A|B)` có
dấu `|`, dấu `$` đã escape…

### Nạp thư viện có dự phòng

```mermaid
flowchart LR
    A["libs.js"] --> B["thử cdnjs.cloudflare.com"]
    B -->|"lỗi hoặc quá 9 giây"| C["thử cdn.jsdelivr.net"]
    B -->|"ok"| OK
    C -->|"ok"| OK["dispatch tchat:libs-ready"]
    C -->|"cũng lỗi"| D["toast cảnh báo<br/>nội dung vẫn đọc được dạng văn bản thuần"]
    OK --> E["md.js: rerenderAll()<br/><i>dựng lại các khối đã hiện</i>"]

    style D fill:#fff3e0,stroke:#e08a1e
    style OK fill:#e8f5e9,stroke:#2f9e6e
```

`md.js` giữ một sổ đăng ký `{node, text}` của mọi khối đã dựng, nên khi thư viện
tới muộn thì dựng lại đúng những khối đang hiển thị — người dùng chỉ thấy nội
dung "đẹp lên", không bị mất gì.

### Vẽ Markdown trong lúc đang stream

Mỗi `event: delta` không dựng lại ngay mà gom vào `requestAnimationFrame`, rồi
dựng toàn bộ nội dung đã tích luỹ và chèn con trỏ nhấp nháy. Công thức LaTeX chưa
đóng thì tạm nằm ở dạng chữ cho tới khi mẩu `$` cuối cùng tới.

---

## 10. Bảo mật

```mermaid
flowchart TB
    subgraph L1["1 · Tầng máy chủ"]
        A1[".htaccess chặn config includes sql tools"]
        A2["Options -Indexes · chặn dotfile · chặn .md .sql"]
        A3["nosniff · X-Frame-Options · Referrer-Policy"]
    end
    subgraph L2["2 · Tầng phiên"]
        B1["session HttpOnly · SameSite=Lax · Secure khi HTTPS"]
        B2["use_strict_mode · regenerate_id khi đăng nhập"]
        B3["CSRF token cho mọi thao tác ghi → HTTP 419"]
    end
    subgraph L3["3 · Tầng xác thực"]
        C1["password_hash bcrypt · tự nâng cấp thuật toán"]
        C2["chống dò: 10 lần sai/IP trong 15 phút + delay 0,4s"]
        C3["remember token: selector công khai + validator băm"]
        C4["đổi mật khẩu → huỷ mọi remember token"]
    end
    subgraph L4["4 · Tầng dữ liệu"]
        D1["100% prepared statement"]
        D2["API key mã hoá AES-256-CBC + HMAC-SHA256"]
        D3["kiểm tra chủ sở hữu ở mọi truy vấn attachment và conversation"]
    end
    subgraph L5["5 · Tầng tệp"]
        E1["chặn tuyệt đối php phtml phar sh exe… bất kể cấu hình"]
        E2["không có URL trực tiếp tới tệp — chỉ qua download.php"]
        E3["SVG coi như văn bản thuần · buộc tải xuống định dạng lạ"]
        E4["CSP sandbox + nosniff khi trả tệp"]
    end
    subgraph L6["6 · Tầng hiển thị"]
        F1["DOMPurify lọc mọi HTML do AI sinh"]
        F2["e() escape mọi biến in ra từ PHP"]
        F3["katex trust=false · strict=ignore"]
    end
    L1 --> L2 --> L3 --> L4 --> L5 --> L6
```

### Mã hoá API key

```mermaid
flowchart LR
    K["API key dạng chữ"] --> E1["IV ngẫu nhiên 16 byte"]
    E1 --> E2["AES-256-CBC<br/>khoá = sha256('enc' + app_key)"]
    E2 --> E3["HMAC-SHA256 trên IV + ciphertext<br/>khoá = sha256('mac' + app_key)"]
    E3 --> E4["base64('enc:v1:' + IV + MAC + ciphertext)"]
    E4 --> DB[("cột endpoints.api_key")]

    DB --> D1["kiểm HMAC bằng hash_equals"]
    D1 -->|"không khớp"| D2["trả chuỗi rỗng — coi như chưa có key"]
    D1 -->|"khớp"| D3["giải mã AES"]

    style D2 fill:#ffebee,stroke:#e5484d
```

`app_key` là chuỗi ngẫu nhiên 64 ký tự do trình cài đặt sinh, nằm trong
`config/config.php`. **Đổi `app_key` sau khi đã lưu key sẽ làm key cũ không giải
mã được** — khi đó chỉ cần nhập lại key.

Kiểu **encrypt-then-MAC** nghĩa là dữ liệu bị sửa trong CSDL sẽ bị phát hiện chứ
không giải mã ra rác.

---

## 11. Nâng cấp lược đồ

```mermaid
flowchart TD
    A["Mở bất kỳ trang trong admin/"] --> B["admin/_init.php gọi db_migrate()"]
    B --> C{"setting schema_version<br/>≥ DB_SCHEMA_VERSION?"}
    C -->|"có"| D["thoát ngay<br/><i>không truy vấn gì thêm</i>"]
    C -->|"chưa"| E["tạo bảng còn thiếu<br/>attachment_chunks · sessions"]
    E --> F["thêm cột còn thiếu<br/>attachments.storage"]
    F --> G["đánh dấu tệp cũ storage='file'<br/><i>vẫn đọc được bình thường</i>"]
    G --> H["ghi schema_version mới + log_activity"]

    style D fill:#e8f5e9,stroke:#2f9e6e
```

Chuyển tệp cũ sang CSDL là **thao tác tay, theo từng lô** (mặc định 25 tệp/lần) để
không vượt thời gian thực thi của PHP:

```mermaid
flowchart LR
    A["Quản trị → Dung lượng"] --> B["bấm 🚚 Chuyển tệp"]
    B --> C["SELECT … WHERE storage='file' LIMIT 25"]
    C --> D{"tệp còn trên đĩa?"}
    D -->|"có"| E["storage_put_from_file<br/>→ UPDATE storage='db'<br/>→ unlink tệp cũ"]
    D -->|"mất"| F["đánh dấu đã xử lý, size=0<br/><i>giữ bản ghi để lịch sử chat không khuyết</i>"]
    E & F --> G{"còn tệp nào?"}
    G -->|"còn"| B
    G -->|"hết"| H["xoá được hẳn thư mục uploads/"]

    style H fill:#e8f5e9,stroke:#2f9e6e
```

---

## 12. Quy trình phát hành

```mermaid
flowchart TD
    A["Sửa mã nguồn"] --> B{"tăng phiên bản bằng gì?"}
    B -->|"trang quản trị"| C["Quản trị → Phiên bản<br/>chọn patch/minor/major"]
    B -->|"dòng lệnh"| D["php tools/bump.php minor --codename='…' 'ghi chú'"]

    C & D --> E["version_write()<br/>ghi includes/version.php"]
    E --> F["changelog_prepend()<br/>chèn mục mới đầu CHANGELOG.md"]
    F --> G["INSERT app_versions"]
    G --> H["số mới hiện ngay ở chân trang<br/>và /changelog.php"]

    H --> I["git add CHANGELOG.md includes/version.php<br/>git commit -m 'chore(release): vX.Y.Z'<br/>git tag -a vX.Y.Z<br/>git push origin HEAD --tags"]
    I --> J["GitHub Action release.yml"]
    J --> K["php -l toàn bộ file"]
    K --> L{"tag khớp APP_VERSION?"}
    L -->|"không"| M["❌ dừng build"]
    L -->|"khớp"| N["php tools/build_zip.php"]
    N --> O["trích ghi chú từ CHANGELOG.md"]
    O --> P["tạo GitHub Release + gắn ZIP"]

    style M fill:#ffebee,stroke:#e5484d
    style P fill:#e8f5e9,stroke:#2f9e6e
```

`version_write()` dùng regex hẹp `'[^']*'` nên chỉ thay đúng giá trị của từng hằng
số, không ăn lan sang dòng khác.

### Đóng gói

`tools/build_zip.php` loại bỏ `.git/`, `.github/`, `dist/`, `docs/`,
`config/config.php` và các tệp rác, rồi thêm `DOC-CAI-DAT.txt` vào gói.
Kết quả khoảng **60 tệp, 168 KB** — giải nén lên hosting là chạy được.

```bash
php tools/build_zip.php                 # → dist/tuan-chatbot-<version>.zip
php tools/build_zip.php --out=/tmp/a.zip
php tools/build_zip.php --with-config   # kèm cả config — CẨN THẬN
```

---

## 13. Công thức mở rộng

### Thêm một họ AI API mới

Sửa đúng bốn chỗ trong `includes/ai.php`:

```mermaid
flowchart LR
    A["1 · ai_types()<br/>thêm khoá + nhãn"] --> B["2 · ai_default_base_url()<br/>URL gợi ý"]
    B --> C["3 · ai_resolve_url()<br/>cách ghép đường dẫn"]
    C --> D["4 · ai_build_headers()<br/>cách xác thực"]
    D --> E["5 · ai_build_body()<br/>hình dạng payload"]
    E --> F["6 · ai_parse_event()<br/>cách đọc luồng trả về"]
    F --> G["7 · ai_build_*_messages()<br/>nếu cấu trúc tin nhắn khác"]
```

Không cần sửa `api/stream.php` — nó chỉ làm việc với giao diện chuẩn hoá.

### Thêm một trang quản trị

```php
<?php
require_once __DIR__ . '/_init.php';     // đã require_admin() + db_migrate()

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    // … xử lý …
    flash('success', 'Đã lưu!');
    redirect('trang-moi.php');
}

admin_head('Tiêu đề trang', 'khoa_menu');
// … HTML …
admin_foot();
```

Rồi thêm một dòng vào `admin_menu()` trong `admin/_init.php`.

### Thêm một thiết lập website

1. Thêm giá trị mặc định vào `settings_defaults()` trong `includes/settings.php`.
2. Thêm ô nhập vào `admin/settings.php` và tên trường vào mảng `$fields`
   (nếu là checkbox thì thêm cả vào `$checkboxes`).
3. Dùng ở bất kỳ đâu: `setting('khoa_moi')`, `setting_bool(...)`, `setting_int(...)`.

### Thêm một endpoint API

Tạo `api/ten-moi.php`:

```php
<?php
require_once __DIR__ . '/_init.php';

$user = require_login(true);   // true = trả JSON thay vì chuyển hướng
api_require_method('POST');
csrf_require(true);

$giaTri = api_param('ten_tham_so', '');
// … xử lý …

json_out(['ok' => true, 'data' => $giaTri]);
```

### Chạy thử tại máy

```bash
# Máy chủ PHP có sẵn
php -d upload_max_filesize=64M -d post_max_size=80M -S 127.0.0.1:8080 -t .

# Kiểm tra cú pháp toàn bộ
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;

# Kiểm tra cú pháp JavaScript
for f in assets/js/*.js; do node --check "$f"; done
```

Bật `'debug' => true` trong `config/config.php` khi phát triển để thấy lỗi chi
tiết, và **tắt lại** khi chạy thật.

---

<p align="center">
  <a href="README.md">← Về mục lục tài liệu</a> ·
  <a href="HUONG-DAN-SU-DUNG.md">Hướng dẫn sử dụng →</a>
</p>
