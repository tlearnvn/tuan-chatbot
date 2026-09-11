<?php
/**
 * Mã hoá đối xứng cho dữ liệu nhạy cảm (API key) lưu trong CSDL.
 * Dùng AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC).
 */

/** Khoá dẫn xuất từ app_key trong config. */
function crypto_keys()
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $master = (string)cfg('app_key', 'insecure-default-key');
    $keys = [
        'enc' => hash('sha256', 'enc|' . $master, true),
        'mac' => hash('sha256', 'mac|' . $master, true),
    ];
    return $keys;
}

/** Mã hoá chuỗi. Trả về chuỗi base64 có tiền tố "enc:v1:". */
function crypto_encrypt($plain)
{
    if ($plain === null || $plain === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        // Shared hosting hiếm khi thiếu openssl, nhưng vẫn cần đường lui.
        return 'raw:' . base64_encode($plain);
    }
    $keys   = crypto_keys();
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt((string)$plain, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return 'raw:' . base64_encode($plain);
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $keys['mac'], true);
    return 'enc:v1:' . base64_encode($iv . $mac . $cipher);
}

/** Giải mã chuỗi đã mã hoá bằng crypto_encrypt(). */
function crypto_decrypt($payload)
{
    if ($payload === null || $payload === '') {
        return '';
    }
    if (strpos($payload, 'raw:') === 0) {
        return (string)base64_decode(substr($payload, 4));
    }
    if (strpos($payload, 'enc:v1:') !== 0) {
        // Dữ liệu cũ chưa mã hoá — trả nguyên trạng.
        return (string)$payload;
    }
    $blob = base64_decode(substr($payload, 7), true);
    if ($blob === false || strlen($blob) < 49) {
        return '';
    }
    $keys   = crypto_keys();
    $iv     = substr($blob, 0, 16);
    $mac    = substr($blob, 16, 32);
    $cipher = substr($blob, 48);

    $expected = hash_hmac('sha256', $iv . $cipher, $keys['mac'], true);
    if (!hash_equals($expected, $mac)) {
        return '';
    }
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/** Che bớt API key khi hiển thị trong trang quản trị. */
function mask_secret($secret)
{
    $secret = (string)$secret;
    $len = strlen($secret);
    if ($len === 0) {
        return '(chưa đặt)';
    }
    if ($len <= 10) {
        return str_repeat('•', $len);
    }
    return substr($secret, 0, 5) . str_repeat('•', 8) . substr($secret, -4);
}
