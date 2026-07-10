<?php
/**
 * Decrypt an OpenSSL-fallback package (*.zip.enc) produced by Packager when a
 * host lacks native AES-ZIP support.
 *
 * Usage:
 *   php tools/decrypt.php path/to/file.zip.enc [output.zip]
 *
 * You will be prompted for the passphrase (or set PACKAGE_PASSPHRASE / the
 * config value). The output is a normal ZIP you can open anywhere.
 *
 * Envelope format:  "LGENC1\n" | salt(16) | iv(12) | tag(16) | ciphertext
 * Key: PBKDF2-SHA256(passphrase, salt, 200000, 32).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$in = $argv[1] ?? '';
if ($in === '' || !is_file($in)) {
    fwrite(STDERR, "Usage: php tools/decrypt.php <file.zip.enc> [output.zip]\n");
    exit(1);
}
$out = $argv[2] ?? preg_replace('/\.enc$/', '', $in);
if ($out === $in) {
    $out .= '.zip';
}

$pass = getenv('PACKAGE_PASSPHRASE') ?: '';
if ($pass === '') {
    // Try config, then prompt.
    $cfgFile = dirname(__DIR__) . '/config/config.php';
    if (is_file($cfgFile)) {
        define('APP_BOOTSTRAPPED', true);
        function env($k, $d = null) { $v = getenv($k); return ($v === false || $v === '') ? $d : $v; }
        function env_bool($k, $d = false) { $v = getenv($k); return $v === false ? $d : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true); }
        $cfg = require $cfgFile;
        $pass = (string) ($cfg['package']['passphrase'] ?? '');
    }
}
if ($pass === '') {
    fwrite(STDOUT, 'Passphrase: ');
    $pass = trim((string) fgets(STDIN));
}

$raw = (string) file_get_contents($in);
if (substr($raw, 0, 7) !== "LGENC1\n") {
    fwrite(STDERR, "Not a recognised LGENC1 envelope.\n");
    exit(1);
}
$body = substr($raw, 7);
$salt = substr($body, 0, 16);
$iv   = substr($body, 16, 12);
$tag  = substr($body, 28, 16);
$ct   = substr($body, 44);

$key   = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
$plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
if ($plain === false) {
    fwrite(STDERR, "Decryption failed — wrong passphrase or corrupted file.\n");
    exit(2);
}
file_put_contents($out, $plain);
fwrite(STDOUT, "Decrypted → {$out} (" . strlen($plain) . " bytes)\n");
