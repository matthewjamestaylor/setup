<?php
/**
 * Drives the real public/submit.php with simulated superglobals so the full
 * pipeline (validate → PDF → encrypt → mail[log]) is exercised without relying
 * on the flaky php -S multipart parser. Apache/PHP-FPM parse multipart fine.
 *
 * Usage: php tests/submit_harness.php [scenario]
 *   scenario = valid | missing | sin9 | badfile   (default: valid)
 */

$scenario = $argv[1] ?? 'valid';
$root = dirname(__DIR__);

// --- Fixtures --------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/harness_' . bin2hex(random_bytes(3));
mkdir($tmp, 0700, true);

// signature PNG
$s = imagecreatetruecolor(360, 110);
$w = imagecolorallocate($s, 255, 255, 255);
imagefilledrectangle($s, 0, 0, 360, 110, $w);
$b = imagecolorallocate($s, 20, 20, 50);
imagesetthickness($s, 3);
imageline($s, 15, 70, 70, 25, $b); imageline($s, 70, 25, 110, 80, $b);
imageline($s, 110, 80, 170, 30, $b); imageline($s, 170, 30, 320, 60, $b);
imagepng($s, "$tmp/sig.png"); imagedestroy($s);
$sigDataUrl = 'data:image/png;base64,' . base64_encode(file_get_contents("$tmp/sig.png"));

// headshot JPG
$hs = imagecreatetruecolor(500, 620);
$bg = imagecolorallocate($hs, 220, 225, 232);
imagefilledrectangle($hs, 0, 0, 500, 620, $bg);
$f = imagecolorallocate($hs, 175, 150, 132);
imagefilledellipse($hs, 250, 270, 200, 250, $f);
imagejpeg($hs, "$tmp/hs.jpg", 85); imagedestroy($hs);

// gov PDF
file_put_contents("$tmp/gov.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

// A "bad" file: .pdf extension but not a PDF
file_put_contents("$tmp/bad.pdf", "this is not really a pdf");

// --- Form token (matches Support::makeFormToken with dev passphrase) --------
$pass = 'dev-passphrase-please-change';
$secret = hash('sha256', 'legends-onboarding-form|' . $pass);
$ts = time() - 3; // a few seconds old
$token = $ts . '.' . hash_hmac('sha256', (string) $ts, $secret);

// --- POST payload ----------------------------------------------------------
$post = [
    'form_token' => $token,
    'privacy_ack' => '1',
    'first_name' => 'Jane', 'middle_name' => 'A', 'last_name' => "O'Brien-Smith",
    'preferred_name' => 'Janie', 'pronouns' => 'she/her', 'date_of_birth' => '1996-04-12',
    'street_address' => '123 Example Street', 'unit' => '4B', 'city' => 'Toronto',
    'province' => 'Ontario', 'postal_code' => 'M5V 2T6',
    'mobile_phone' => '(416) 555-0142', 'primary_email' => 'jane@example.com',
    'ec1_name' => 'John OBrien', 'ec1_relationship' => 'Father', 'ec1_phone' => '(416) 555-0199',
    'avail_monday_enabled' => '1', 'avail_monday_start' => '17:00', 'avail_monday_end' => '23:00',
    'avail_friday_enabled' => '1', 'avail_friday_start' => '16:00', 'avail_friday_end' => '23:30',
    'desired_hours' => '32', 'allergies' => 'Peanuts',
    'sin' => '046 454 286', 'sin_issued' => '2015-01-01',
    'gov_first_name' => 'Jane', 'gov_last_name' => "O'Brien-Smith",
    'gov_doc_type' => "Ontario Driver's Licence", 'gov_doc_number' => 'O1234-56789',
    'gov_issued_by' => 'ServiceOntario', 'gov_issued_date' => '2022-03-01', 'gov_expiry_date' => '2027-04-12',
    'dd_institution_name' => 'RBC', 'dd_account_holder' => "Jane O'Brien-Smith",
    'dd_transit' => '00123', 'dd_institution_number' => '003', 'dd_account_number' => '1234567',
    'smartserve_last_name' => "O'Brien-Smith", 'smartserve_cert_id' => 'SS-99887', 'smartserve_issued' => '2024-06-01',
    'declaration_ack' => '1', 'comms_consent' => '1', 'preferred_contact' => 'text',
    'employee_name' => "Jane O'Brien-Smith", 'signature_date' => '2026-07-10',
    'signature' => $sigDataUrl,
];

$govPath = "$tmp/gov.pdf";
if ($scenario === 'missing') {
    unset($post['mobile_phone'], $post['sin'], $post['dd_transit']);
}
if ($scenario === 'sin9') {
    $post['sin'] = '900 000 008'; // 9-series (luhn-valid) → requires permit block, which we omit
}
if ($scenario === 'badfile') {
    $govPath = "$tmp/bad.pdf";
}

$files = [
    'gov_document' => ['name' => 'id.pdf', 'type' => 'application/pdf', 'tmp_name' => $govPath, 'error' => 0, 'size' => filesize($govPath)],
    'headshot' => ['name' => 'me.jpg', 'type' => 'image/jpeg', 'tmp_name' => "$tmp/hs.jpg", 'error' => 0, 'size' => filesize("$tmp/hs.jpg")],
    // Smart Serve certificate upload (regression test for the ss/fs key fix)
    'smartserve_document' => ['name' => 'smartserve.pdf', 'type' => 'application/pdf', 'tmp_name' => "$tmp/gov.pdf", 'error' => 0, 'size' => filesize("$tmp/gov.pdf")],
];

// --- Fake the request ------------------------------------------------------
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['CONTENT_LENGTH'] = '10000';
$_POST = $post;
$_FILES = $files;

fwrite(STDERR, "Scenario: {$scenario}\n");
require $root . '/public/submit.php';
