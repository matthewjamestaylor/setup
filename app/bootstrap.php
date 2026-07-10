<?php
/**
 * Application bootstrap.
 *
 * Included by every front-controller (public/index.php, public/submit.php).
 * Loads configuration, wires helpers, and provides a tiny PSR-4-ish loader
 * for the app/ classes plus the bundled vendor libraries – so the host needs
 * no Composer.
 */

declare(strict_types=1);

define('APP_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));           // repository root
define('APP_PATH', APP_ROOT . '/app');
define('VENDOR_PATH', APP_ROOT . '/vendor');
define('CONFIG_PATH', APP_ROOT . '/config');

// ---------------------------------------------------------------------------
// Environment helpers (used inside config files too)
// ---------------------------------------------------------------------------
function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

// ---------------------------------------------------------------------------
// Load configuration (config/config.php, falling back to the sample so the
// app still boots – but refuses to send – before real config is in place)
// ---------------------------------------------------------------------------
$configFile = CONFIG_PATH . '/config.php';
if (!is_file($configFile)) {
    $configFile = CONFIG_PATH . '/config.sample.php';
}
/** @var array $CONFIG */
$CONFIG = require $configFile;
$GLOBALS['CONFIG'] = $CONFIG;

/** Fetch a dotted config value: cfg('mail.host'). */
function cfg(string $path, $default = null)
{
    $node = $GLOBALS['CONFIG'];
    foreach (explode('.', $path) as $seg) {
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
        } else {
            return $default;
        }
    }
    return $node;
}

// ---------------------------------------------------------------------------
// Runtime settings
// ---------------------------------------------------------------------------
date_default_timezone_set((string) cfg('app.timezone', 'America/Toronto'));

// Debug output is only ever honoured in a non-production environment, so a
// stray app.debug=true in a production config can never leak internals.
$isDebug = (bool) cfg('app.debug', false) && cfg('app.env', 'production') !== 'production';
$GLOBALS['CONFIG']['app']['debug'] = $isDebug;
error_reporting(E_ALL);
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('log_errors', '1');

// The app persists nothing; make sure a stray session cookie is never set.
// (No session_start() is ever called.)

// ---------------------------------------------------------------------------
// Class autoloading
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    // App classes (namespace Legends\...)
    if (str_starts_with($class, 'Legends\\')) {
        $rel = str_replace('\\', '/', substr($class, strlen('Legends\\')));
        $file = APP_PATH . '/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
    // Bundled PHPMailer
    if (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $short = substr($class, strlen('PHPMailer\\PHPMailer\\'));
        $file = VENDOR_PATH . '/PHPMailer/src/' . $short . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

// ---------------------------------------------------------------------------
// FPDF (procedural, no namespace) – load eagerly so the FPDF class exists.
// ---------------------------------------------------------------------------
if (!class_exists('FPDF')) {
    define('FPDF_FONTPATH', VENDOR_PATH . '/fpdf/font/');
    require VENDOR_PATH . '/fpdf/fpdf.php';
}
