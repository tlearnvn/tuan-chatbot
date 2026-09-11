<?php
/**
 * Lớp truy cập CSDL mỏng dựa trên PDO.
 */

/** Trả về đối tượng PDO dùng chung. */
function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Chưa cài đặt: ném ngoại lệ để trình cài đặt và lớp settings tự xử lý,
    // thay vì dừng hẳn trang.
    if ((string)cfg('db_name', '') === '') {
        throw new RuntimeException('Cơ sở dữ liệu chưa được cấu hình.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        cfg('db_host', 'localhost'),
        (int)cfg('db_port', 3306),
        cfg('db_name', ''),
        cfg('db_charset', 'utf8mb4')
    );

    try {
        $pdo = new PDO($dsn, cfg('db_user', ''), cfg('db_pass', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $ex) {
        if (cfg('debug')) {
            throw $ex;
        }
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        exit('<h1>Không kết nối được cơ sở dữ liệu</h1>'
            . '<p>Vui lòng kiểm tra lại thông tin trong <code>config/config.php</code>.</p>');
    }

    // Đồng bộ múi giờ MySQL với giờ Việt Nam để NOW() khớp với PHP.
    try {
        $offset = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('P'); // ví dụ +07:00
        $pdo->exec("SET time_zone = '{$offset}'");
    } catch (Exception $ex) {
        // Bỏ qua: ứng dụng luôn tự sinh thời gian bằng PHP.
    }

    return $pdo;
}

/** Chạy câu lệnh có tham số, trả về PDOStatement. */
function db_run($sql, $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Lấy 1 dòng. */
function db_one($sql, $params = [])
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Lấy nhiều dòng. */
function db_all($sql, $params = [])
{
    return db_run($sql, $params)->fetchAll();
}

/** Lấy 1 giá trị đơn. */
function db_value($sql, $params = [], $default = null)
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? $default : $value;
}

/** Chèn dòng mới, trả về id vừa tạo. */
function db_insert($table, array $data)
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    db_run($sql, array_values($data));
    return (int)db()->lastInsertId();
}

/** Cập nhật theo điều kiện id. */
function db_update($table, array $data, $where, array $whereParams = [])
{
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = '`' . $col . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return db_run($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

/** Kiểm tra bảng đã tồn tại chưa. */
function db_table_exists($table)
{
    try {
        db()->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        return true;
    } catch (Exception $ex) {
        return false;
    }
}
