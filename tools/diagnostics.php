<?php
/**
 * Host readiness check for the Legends Global onboarding app.
 *
 * Run over SSH:   php tools/diagnostics.php
 * Or in a browser (only if you must): tools/diagnostics.php?key=XXXX
 *   The key is printed when you run it from the command line. Delete this file
 *   after setup, or leave it — it never reveals secret values.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Legends\Support;

$cli = PHP_SAPI === 'cli';

// Web access is gated by a key derived from the configured passphrase.
$pass = (string) cfg('package.passphrase', '');
$gateKey = $pass !== '' ? substr(hash('sha256', 'diag|' . $pass), 0, 12) : '';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    // Fail closed: deny web access unless a passphrase is set AND the correct
    // derived key is supplied. (An empty passphrase must NOT open the gate.)
    if ($gateKey === '' || !hash_equals($gateKey, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo "Forbidden. Run `php tools/diagnostics.php` over SSH (once the app is configured) to obtain the access key.\n";
        exit;
    }
}

$rows = [];
$fail = 0;
$warn = 0;
function check(string $label, bool $ok, string $detail = '', bool $warnOnly = false): void
{
    global $rows, $fail, $warn;
    $status = $ok ? 'PASS' : ($warnOnly ? 'WARN' : 'FAIL');
    if (!$ok) {
        $warnOnly ? $warn++ : $fail++;
    }
    $rows[] = [$status, $label, $detail];
}

// --- PHP + extensions ------------------------------------------------------
check('PHP >= 8.1', PHP_VERSION_ID >= 80100, 'Running ' . PHP_VERSION);
foreach (['zip', 'openssl', 'gd', 'mbstring', 'curl', 'fileinfo', 'iconv', 'json'] as $ext) {
    $isWarn = in_array($ext, ['curl', 'gd'], true);
    check("ext: {$ext}", extension_loaded($ext), $isWarn && !extension_loaded($ext) ? 'recommended' : '', $isWarn);
}
check('ZipArchive AES-256', defined('ZipArchive::EM_AES_256'),
    defined('ZipArchive::EM_AES_256') ? 'native encrypted ZIP' : 'will fall back to OpenSSL envelope', true);

// --- Upload / POST limits --------------------------------------------------
$umf = ini_get('upload_max_filesize');
$pms = ini_get('post_max_size');
function toBytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}
check('upload_max_filesize >= 8M', toBytes((string) $umf) >= 8 * 1024 * 1024, "current: {$umf}", true);
check('post_max_size >= 25M', toBytes((string) $pms) >= 25 * 1024 * 1024, "current: {$pms}", true);
check('max_file_uploads >= 8', (int) ini_get('max_file_uploads') >= 8, 'current: ' . ini_get('max_file_uploads'), true);
check('temp dir writable', is_writable(sys_get_temp_dir()), sys_get_temp_dir());

// --- Configuration ---------------------------------------------------------
$hasConfig = is_file(dirname(__DIR__) . '/config/config.php');
check('config/config.php present', $hasConfig, $hasConfig ? '' : 'copy config/config.sample.php → config/config.php');
check('package passphrase set', $pass !== '', $pass === '' ? 'set package.passphrase' : 'set (' . strlen($pass) . ' chars)');

$transport = strtolower((string) cfg('mail.transport', 'smtp'));
check('mail transport', in_array($transport, ['smtp', 'log'], true), $transport);
if ($transport === 'smtp') {
    foreach (['host', 'username', 'password', 'from_email'] as $k) {
        check("mail.{$k} set", (string) cfg("mail.{$k}", '') !== '', '');
    }
} else {
    check('mail.log_dir writable', is_writable((string) cfg('mail.log_dir', sys_get_temp_dir())) || @mkdir((string) cfg('mail.log_dir'), 0700, true), (string) cfg('mail.log_dir'));
}
check('HR recipient set', (string) cfg('hr.email', '') !== '', '(configured)');

$tsEnabled = (bool) cfg('turnstile.enabled', false);
check('Turnstile keys', !$tsEnabled || ((string) cfg('turnstile.site_key', '') !== '' && (string) cfg('turnstile.secret', '') !== ''),
    $tsEnabled ? '' : 'disabled', !$tsEnabled);

// --- Output ----------------------------------------------------------------
$out = "\nLegends Global — Onboarding host diagnostics\n" . str_repeat('=', 52) . "\n";
foreach ($rows as [$s, $label, $detail]) {
    $out .= sprintf("[%-4s] %-28s %s\n", $s, $label, $detail);
}
$out .= str_repeat('-', 52) . "\n";
$out .= $fail === 0
    ? ($warn === 0 ? "All checks passed. Ready to go.\n" : "Ready, with {$warn} warning(s) to review.\n")
    : "{$fail} check(s) FAILED — resolve before going live.\n";
if ($cli && $gateKey !== '') {
    $out .= "Browser access key: {$gateKey}\n";
}
echo $cli ? $out : $out;
