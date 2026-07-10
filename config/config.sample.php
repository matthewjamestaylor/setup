<?php
/**
 * Legends Global – New Hire Onboarding
 * -------------------------------------------------------------------------
 * CONFIGURATION SAMPLE
 *
 * 1. Copy this file to  config/config.php
 * 2. Fill in the real values (SMTP credentials, HR recipient, passphrase,
 *    Turnstile keys).
 * 3. config/config.php is git-ignored and must NEVER be committed.
 *
 * Every value can also be supplied through an environment variable of the
 * same UPPER_SNAKE_CASE name (handy on hosts that expose env vars); the
 * environment wins over the literals below.
 * -------------------------------------------------------------------------
 */

if (!defined('APP_BOOTSTRAPPED')) {
    // Direct web access to a config file must never execute or leak anything.
    http_response_code(404);
    exit;
}

return [

    // ----- Application -----------------------------------------------------
    'app' => [
        'name'     => 'Legends Global – Employee Information',
        'env'      => env('APP_ENV', 'production'),   // 'production' | 'development'
        'debug'    => env_bool('APP_DEBUG', false),   // NEVER true in production
        'timezone' => 'America/Toronto',
    ],

    // ----- Where the packaged submission is emailed ------------------------
    'hr' => [
        'email' => env('HR_EMAIL', 'hr@rclegends.ca'),   // HR head's inbox
        'name'  => env('HR_NAME', 'Human Resources'),
    ],

    // ----- Outbound SMTP (Namecheap Private Email / cPanel mail) ------------
    // Namecheap Private Email:  host mail.privateemail.com, port 465 (ssl)
    //                           or 587 (tls). Username is the full address.
    // cPanel local mail:        host mail.<yourdomain>, port 465/587.
    'mail' => [
        // 'smtp' actually sends. 'log' composes the full message (with the
        // encrypted attachment) and writes it to 'log_dir' as an .eml file
        // WITHOUT sending — use it to test/preview before adding credentials.
        'transport'  => env('MAIL_TRANSPORT', 'smtp'),
        'log_dir'    => env('MAIL_LOG_DIR', __DIR__ . '/../storage/mail'),

        'host'       => env('MAIL_HOST', 'mail.privateemail.com'),
        'port'       => (int) env('MAIL_PORT', '465'),
        'secure'     => env('MAIL_SECURE', 'ssl'),      // 'ssl' (465) | 'tls' (587)
        'username'   => env('MAIL_USERNAME', 'no-reply@rclegends.ca'),
        'password'   => env('MAIL_PASSWORD', ''),       // <-- app/mailbox password
        'from_email' => env('MAIL_FROM_EMAIL', 'no-reply@rclegends.ca'),
        'from_name'  => env('MAIL_FROM_NAME', 'Legends Global Onboarding'),
        // Optional Reply-To so HR can reach the new hire directly. Leave blank
        // to fall back to the submitter's own email at send time.
        'reply_to'   => env('MAIL_REPLY_TO', ''),
        // Optional blind copy of every submission (e.g. an onboarding archive).
        'bcc'        => env('MAIL_BCC', ''),
        // Verify the TLS certificate of the SMTP server. Keep TRUE. Only set
        // false as a last resort on a mis-configured shared host.
        'verify_tls' => env_bool('MAIL_VERIFY_TLS', true),
    ],

    // ----- Encryption of the emailed package -------------------------------
    // The PDF + all uploaded documents are bundled into a single AES-256
    // encrypted ZIP. HR opens it with this passphrase (share it out-of-band,
    // e.g. in person or by phone – NEVER in the same channel as the email).
    // Use a long, random passphrase. Generate one with:
    //     php -r "echo bin2hex(random_bytes(12)).PHP_EOL;"
    'package' => [
        'passphrase'      => env('PACKAGE_PASSPHRASE', ''),   // REQUIRED
        'filename_prefix' => 'LegendsGlobal-NewHire',
    ],

    // ----- Cloudflare Turnstile (bot protection) ---------------------------
    // Create a free widget at https://dash.cloudflare.com/?to=/:account/turnstile
    // Set enabled=false only for local testing.
    'turnstile' => [
        'enabled'  => env_bool('TURNSTILE_ENABLED', true),
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret'   => env('TURNSTILE_SECRET', ''),
    ],

    // ----- Upload limits ---------------------------------------------------
    // Shared-host SMTP typically rejects messages larger than ~25 MB, so keep
    // the total comfortably under that after base64 overhead.
    'uploads' => [
        'max_bytes_per_file' => 8 * 1024 * 1024,    // 8 MB per document
        'max_bytes_total'    => 18 * 1024 * 1024,   // 18 MB across all documents
        // Photos/scans larger than this are downscaled to keep the email small
        // while staying legible.
        'image_max_dimension' => 2200,              // px (long edge)
        'image_jpeg_quality'  => 82,
        'allowed_doc_ext'   => ['pdf', 'png', 'jpg', 'jpeg'],
        'allowed_image_ext' => ['png', 'jpg', 'jpeg'],
    ],

    // ----- Anti-spam (in addition to Turnstile) ----------------------------
    'security' => [
        'honeypot_field'    => 'company_website', // hidden; bots fill it → reject
        'min_submit_seconds' => 8,                // faster than this ⇒ bot
    ],
];
