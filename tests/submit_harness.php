<?php
/**
 * Drives the real public/submit.php with simulated superglobals (new data
 * model). Runs in test mode via APP_ENV=development, which skips Turnstile and
 * the time-trap and routes mail to the log/test recipient.
 *
 * Usage: php tests/submit_harness.php [valid|missing|sin9]
 *
 * NOTE: run with config/config.php moved aside so the env vars below take
 * effect (see tests/run_e2e.sh).
 */

$scenario = $argv[1] ?? 'valid';
$root = dirname(__DIR__);

putenv('APP_ENV=development');
putenv('APP_DEBUG=true');
putenv('PACKAGE_PASSPHRASE=dev-passphrase-please-change');
putenv('MAIL_TRANSPORT=log');
putenv('MAIL_LOG_DIR=' . $root . '/storage/mail');
putenv('MAIL_FROM_EMAIL=no-reply@example.test');
putenv('HR_EMAIL=hr@example.test');
putenv('TURNSTILE_ENABLED=false');
putenv('MIN_SUBMIT_SECONDS=0');

$tmp = sys_get_temp_dir() . '/harness_' . bin2hex(random_bytes(3));
mkdir($tmp, 0700, true);

// fixtures
$s = imagecreatetruecolor(360, 110); imagefilledrectangle($s, 0, 0, 360, 110, imagecolorallocate($s, 255, 255, 255));
imageline($s, 15, 70, 320, 60, imagecolorallocate($s, 20, 20, 50)); imagepng($s, "$tmp/sig.png"); imagedestroy($s);
$sigDataUrl = 'data:image/png;base64,' . base64_encode(file_get_contents("$tmp/sig.png"));
$hs = imagecreatetruecolor(500, 620); imagefilledrectangle($hs, 0, 0, 500, 620, imagecolorallocate($hs, 220, 225, 232));
imagefilledellipse($hs, 250, 270, 200, 250, imagecolorallocate($hs, 175, 150, 132)); imagejpeg($hs, "$tmp/hs.jpg", 85); imagedestroy($hs);
file_put_contents("$tmp/doc.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

$future = (new DateTimeImmutable('+3 years'))->format('Y-m-d');

$post = [
    'privacy_ack' => '1',
    'first_name' => 'Jane', 'middle_name' => 'A', 'last_name' => "O'Brien-Smith",
    'preferred_name' => 'Janie', 'pronouns' => 'she/her', 'date_of_birth' => '1996-04-12',
    'street_address' => '123 King St W', 'unit' => '4B', 'city' => 'Toronto', 'province' => 'Ontario', 'postal_code' => 'M5V 2T6',
    'mobile_phone' => '+1 (416) 555-0142', 'primary_email' => 'jane@example.com',
    'contacts' => [
        ['first_name' => 'John', 'last_name' => 'OBrien', 'relationship' => 'parent', 'phone' => '+1 (416) 555-0199', 'phone_device' => 'mobile', 'phone_location' => 'home', 'email' => 'john@example.com', 'email_location' => 'home'],
        ['first_name' => 'Mary', 'last_name' => 'Smith', 'relationship' => 'sibling', 'phone' => '+1 (647) 555-0123', 'phone_device' => 'mobile', 'phone_location' => 'work', 'email' => 'mary@example.com', 'email_location' => 'work'],
    ],
    'avail_monday_enabled' => '1', 'avail_monday_start' => '17:00', 'avail_monday_end' => '23:00',
    'avail_friday_enabled' => '1', 'avail_friday_start' => '16:00', 'avail_friday_end' => '23:30',
    'desired_hours' => '32', 'availability_comments' => 'Prefer evenings.',
    'trips_has' => 'yes', 'trips_details' => 'Family trip Dec 20–27.',
    'allergies_has' => 'yes', 'allergies_details' => 'Peanuts', 'medical_has' => 'no',
    'sin' => '046 454 286',
    'gov_first_name' => 'Jane', 'gov_last_name' => "O'Brien-Smith", 'gov_doc_type' => 'drivers_licence',
    'gov_doc_number' => 'S1234-56789-01234', 'gov_expiry_date' => $future,
    'dd_bank' => 'rbc', 'dd_account_holder' => "Jane O'Brien-Smith", 'dd_institution_number' => '999',
    'dd_transit' => '00123', 'dd_account_number' => '1234567', 'dd_account_confirm' => '1234567',
    'smartserve_has' => 'yes', 'smartserve_first_name' => 'Jane', 'smartserve_last_name' => "O'Brien-Smith",
    'smartserve_cert_id' => 'SS-99887', 'smartserve_issued' => '2024-06-01', 'smartserve_expiry' => '2029-06-01',
    'foodsafety_has' => 'no', 'jhsc1_has' => 'no', 'jhsc2_has' => 'no',
    'declaration_ack' => '1', 'comms_consent' => '1', 'preferred_contact' => 'email',
    'employee_name' => "Jane O'Brien-Smith", 'signature_date' => date('Y-m-d'),
    'signature' => $sigDataUrl,
];

$files = [
    'headshot' => ['name' => 'me.jpg', 'type' => 'image/jpeg', 'tmp_name' => "$tmp/hs.jpg", 'error' => 0, 'size' => filesize("$tmp/hs.jpg")],
    'gov_document' => ['name' => 'id.pdf', 'type' => 'application/pdf', 'tmp_name' => "$tmp/doc.pdf", 'error' => 0, 'size' => filesize("$tmp/doc.pdf")],
    'sin_document' => ['name' => 'sin.pdf', 'type' => 'application/pdf', 'tmp_name' => "$tmp/doc.pdf", 'error' => 0, 'size' => filesize("$tmp/doc.pdf")],
    'dd_document' => ['name' => 'vc.pdf', 'type' => 'application/pdf', 'tmp_name' => "$tmp/doc.pdf", 'error' => 0, 'size' => filesize("$tmp/doc.pdf")],
    'smartserve_document' => ['name' => 'ss.pdf', 'type' => 'application/pdf', 'tmp_name' => "$tmp/doc.pdf", 'error' => 0, 'size' => filesize("$tmp/doc.pdf")],
];

if ($scenario === 'missing') {
    unset($post['mobile_phone'], $post['sin'], $post['dd_account_number']);
}
if ($scenario === 'sin9') {
    $post['sin'] = '900000008';
}
if ($scenario === 'hugephoto') {
    // A PNG whose header claims 20000x20000 (400 MP): getimagesize() trusts
    // the header, so this exercises the decode-bomb guard without the memory.
    $png = "\x89PNG\r\n\x1a\n"
        . pack('N', 13) . 'IHDR' . pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00" . pack('N', 0)
        . pack('N', 0) . 'IDAT' . pack('N', 0)
        . pack('N', 0) . 'IEND' . pack('N', 0xAE426082);
    file_put_contents("$tmp/huge.png", $png);
    $files['headshot'] = ['name' => 'huge.png', 'type' => 'image/png', 'tmp_name' => "$tmp/huge.png", 'error' => 0, 'size' => filesize("$tmp/huge.png")];
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['CONTENT_LENGTH'] = '10000';
$_POST = $post;
$_FILES = $files;

fwrite(STDERR, "Scenario: {$scenario}\n");
require $root . '/public/submit.php';
