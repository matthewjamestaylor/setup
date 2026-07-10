<?php

/**
 * POST endpoint. Validates, packages, encrypts, and emails a submission.
 * Always responds with JSON. Persists nothing: uploaded documents live only
 * in a per-request working directory that is shredded in the finally block.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Legends\Validator;
use Legends\PdfBuilder;
use Legends\Packager;
use Legends\Mailer;
use Legends\Turnstile;
use Legends\Support;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $payload, int $code = 200): never
{
    http_response_code($code);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"formError":"A server error occurred. Please try again."}';
    }
    echo $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'formError' => 'Invalid request method.'], 405);
}

// A payload larger than post_max_size arrives with empty $_POST/$_FILES.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_POST === [] && $_FILES === [] && $contentLength > 0) {
    respond([
        'ok' => false,
        'formError' => 'Your submission was too large for the server to accept. Please upload smaller or compressed documents and try again.',
    ], 413);
}

// --- Honeypot: a hidden field only a bot would fill. Silently accept & drop.
$honeypot = (string) cfg('security.honeypot_field', 'company_website');
if (trim((string) ($_POST[$honeypot] ?? '')) !== '') {
    respond(['ok' => true, 'reference' => strtoupper(Support::token(3))]);
}

// --- Time trap: HMAC-signed form-open timestamp.
$minSeconds = (int) cfg('security.min_submit_seconds', 8);
if (!Support::checkFormToken((string) ($_POST['form_token'] ?? ''), $minSeconds, 21600, time())) {
    respond([
        'ok' => false,
        'formError' => 'Your session expired, or the form was submitted unusually fast. Please review your details and submit again.',
    ], 400);
}

// --- Bot verification (Cloudflare Turnstile).
$turnstile = new Turnstile((array) cfg('turnstile', []));
if (!$turnstile->verify($_POST['cf-turnstile-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null)) {
    respond([
        'ok' => false,
        'formError' => 'Bot verification failed. Please complete the verification checkbox and try again.',
    ], 400);
}

// --- Refuse to run with an unconfigured/placeholder passphrase in production,
//     which would otherwise "encrypt" PII under a publicly-known secret.
$passphrase = (string) cfg('package.passphrase', '');
if (cfg('app.env', 'production') === 'production'
    && ($passphrase === '' || $passphrase === 'dev-passphrase-please-change')) {
    error_log('[onboarding] refusing submission: package.passphrase is empty or a placeholder.');
    respond(['ok' => false, 'formError' => 'This form is not fully configured yet. Please contact Human Resources.'], 503);
}

// --- Validate + build + send (nothing is persisted).
// Prefer the configured/ system temp dir; fall back to the app's storage/tmp
// (helps hosts whose open_basedir excludes the system temp directory).
$tmpBase = (string) cfg('uploads.tmp_dir', '');
if ($tmpBase === '' || !is_dir($tmpBase) || !is_writable($tmpBase)) {
    $sys = sys_get_temp_dir();
    $tmpBase = ($sys && is_writable($sys)) ? $sys : APP_ROOT . '/storage/tmp';
}
$tmpBase = rtrim($tmpBase, '/');
// Defence-in-depth: clear any working dirs orphaned by killed workers (>1h old).
Support::sweepStale($tmpBase . '/lg_onb_*', 3600);

$workDir = $tmpBase . '/lg_onb_' . Support::token(8);
if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    respond(['ok' => false, 'formError' => 'A temporary server error occurred. Please try again.'], 500);
}

// Guarantee the working directory (which briefly holds SIN, banking, and ID
// documents) is shredded no matter how the request ends. respond() calls
// exit(), which bypasses `finally`, so a shutdown function is the only
// reliable place for this cleanup.
register_shutdown_function(static function () use ($workDir): void {
    Support::shredDir($workDir);
});

try {
    $validator = new Validator((array) cfg('uploads', []), $workDir);
    $result = $validator->validate($_POST, $_FILES);

    if (!$result['ok']) {
        respond(['ok' => false, 'errors' => $result['errors']], 422);
    }

    $meta = [
        'reference'      => strtoupper(Support::token(3)),
        'timestamp'      => date('Y-m-d H:i T'),
        'reference_date' => date('Y-m-d'),
    ];

    $pdfPath = (new PdfBuilder($result['data'], $result['files'], $result['signature'], $meta))->render($workDir);

    $package = (new Packager(
        (string) cfg('package.passphrase', ''),
        (string) cfg('package.filename_prefix', 'LegendsGlobal-NewHire')
    ))->build($result['data'], $pdfPath, $result['files'], $meta, $workDir);

    (new Mailer((array) cfg('mail', []), (array) cfg('hr', [])))->send($package, $result['data'], $meta);

    respond(['ok' => true, 'reference' => $meta['reference']]);
} catch (\Throwable $e) {
    error_log('[onboarding] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = cfg('app.debug', false)
        ? ('Error: ' . $e->getMessage())
        : 'We could not process your submission right now. Please try again in a few minutes, or contact Human Resources directly.';
    respond(['ok' => false, 'formError' => $msg], 500);
}
