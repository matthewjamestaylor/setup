<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Legends\FieldMap;
use Legends\Turnstile;
use Legends\Support;

$turnstile = new Turnstile((array) cfg('turnstile', []));
$tsEnabled = $turnstile->enabled();
$siteKey   = (string) cfg('turnstile.site_key', '');
$formToken = Support::makeFormToken(time());
$honeypot  = (string) cfg('security.honeypot_field', 'company_website');
$testMode  = Support::testMode($_GET['test'] ?? null);
$testToken = Support::testToken();
$today     = date('Y-m-d');
$e = fn($s) => Support::e((string) $s);

/** Standard input field. Options: type, required, placeholder, help, autocomplete,
 *  inputmode, maxlength, min, max, value, col, validate, phone, readonly, extra. */
function field(string $name, string $label, array $o = []): void
{
    $type = $o['type'] ?? 'text';
    $req  = !empty($o['required']);
    $id   = 'f_' . $name;
    $attrs = ['id="' . $id . '"', 'name="' . htmlspecialchars($name, ENT_QUOTES) . '"', 'type="' . htmlspecialchars($type, ENT_QUOTES) . '"'];
    foreach (['placeholder', 'autocomplete', 'inputmode', 'maxlength', 'min', 'max', 'step', 'value'] as $a) {
        if (isset($o[$a]) && $o[$a] !== '') {
            $attrs[] = $a . '="' . htmlspecialchars((string) $o[$a], ENT_QUOTES) . '"';
        }
    }
    if ($req) {
        $attrs[] = 'data-required="1"';
    }
    if (!empty($o['validate'])) {
        $attrs[] = 'data-validate="' . htmlspecialchars($o['validate'], ENT_QUOTES) . '"';
    }
    if (!empty($o['phone'])) {
        $attrs[] = 'data-phone="1"';
    }
    if (!empty($o['nopaste'])) {
        $attrs[] = 'data-nopaste="1"';
        $attrs[] = 'autocomplete="off"';
        $attrs[] = 'onpaste="return false"';
        $attrs[] = 'ondrop="return false"';
    }
    if (!empty($o['readonly'])) {
        $attrs[] = 'readonly';
    }
    if (!empty($o['autocapitalize'])) {
        $attrs[] = 'autocapitalize="' . htmlspecialchars($o['autocapitalize'], ENT_QUOTES) . '"';
    }
    if (!empty($o['extra'])) {
        $attrs[] = $o['extra'];
    }
    $col = isset($o['col']) ? ' col-' . (int) $o['col'] : '';
    // A conditional field hides the WHOLE group (label + input), not just the
    // input — so a stray label never shows with no field under it.
    $wrap = !empty($o['cond']) ? ' ' . $o['cond'] . ' hidden' : '';
    echo '<div class="field' . $col . '"' . $wrap . '>';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>';
    echo '<input ' . implode(' ', $attrs) . ' aria-describedby="' . $id . '-err">';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p></div>';
}

/** <select> field. $options is value=>label. */
function selectField(string $name, string $label, array $options, array $o = []): void
{
    $req = !empty($o['required']);
    $id  = 'f_' . $name;
    $col = isset($o['col']) ? ' col-' . (int) $o['col'] : '';
    $placeholder = $o['placeholder'] ?? 'Select…';
    echo '<div class="field' . $col . '">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES) . ($req ? ' <span class="req">*</span>' : '') . '</label>';
    echo '<select id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '"' . ($req ? ' data-required="1"' : '')
        . (!empty($o['extra']) ? ' ' . $o['extra'] : '') . ' aria-describedby="' . $id . '-err">';
    echo '<option value="">' . htmlspecialchars($placeholder, ENT_QUOTES) . '</option>';
    foreach ($options as $val => $lab) {
        $sel = (($o['value'] ?? '') === (string) $val) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string) $val, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars((string) $lab, ENT_QUOTES) . '</option>';
    }
    echo '</select>';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p></div>';
}

/** Yes/No radio pair; reveals a target when Yes. */
function yesnoField(string $name, string $label, array $o = []): void
{
    $req = $o['required'] ?? true;
    $reveal = $o['reveal'] ?? '';
    echo '<div class="field col-3 yesno" data-yesno="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    echo '<span class="ynlabel">' . htmlspecialchars($label, ENT_QUOTES) . ($req ? ' <span class="req">*</span>' : '') . '</span>';
    echo '<div class="ynrow">';
    foreach (['yes' => 'Yes', 'no' => 'No'] as $v => $lab) {
        echo '<label class="ynopt"><input type="radio" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . $v . '"'
            . ($req ? ' data-required="1"' : '') . ($reveal ? ' data-reveal="' . htmlspecialchars($reveal, ENT_QUOTES) . '"' : '')
            . '> <span>' . $lab . '</span></label>';
    }
    echo '</div><p class="err" id="f_' . $name . '-err" role="alert"></p></div>';
}

function textareaField(string $name, string $label, array $o = []): void
{
    $req = !empty($o['required']);
    $id = 'f_' . $name;
    echo '<div class="field col-3">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES) . ($req ? ' <span class="req">*</span>' : '') . '</label>';
    echo '<textarea id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" rows="' . (int) ($o['rows'] ?? 3) . '" maxlength="' . (int) ($o['maxlength'] ?? 800) . '"'
        . ($req ? ' data-required="1"' : '') . ' aria-describedby="' . $id . '-err"'
        . (!empty($o['placeholder']) ? ' placeholder="' . htmlspecialchars($o['placeholder'], ENT_QUOTES) . '"' : '') . '></textarea>';
    echo '<p class="err" id="' . $id . '-err" role="alert"></p></div>';
}

function fileField(string $name, string $label, array $o = []): void
{
    $req = !empty($o['required']);
    $accept = $o['accept'] ?? '.pdf,.png,.jpg,.jpeg';
    $id = 'f_' . $name;
    echo '<div class="field col-3 filefield" data-file="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES) . ($req ? ' <span class="req">*</span>' : '') . '</label>';
    echo '<input id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" type="file" accept="' . htmlspecialchars($accept, ENT_QUOTES) . '"'
        . ($req ? ' data-required="1"' : '') . ' aria-describedby="' . $id . '-err">';
    echo '<p class="filename" data-filename></p>';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p></div>';
}

/** Render the fields for a single emergency contact (idx 0 real; template uses __I__). */
function contactBlock($idx, bool $primary): void
{
    $p = "contacts[{$idx}]";
    $ri = is_int($idx) ? (string) $idx : $idx;
    echo '<fieldset class="contact" data-contact="' . $ri . '">';
    echo '<legend>' . ($primary ? 'Primary Contact' : 'Additional Contact') . ' <button type="button" class="btn-link remove-contact"' . ($primary ? ' hidden' : '') . '>Remove</button></legend>';
    echo '<div class="grid">';
    echo '<div class="field col-1"><label>First Name <span class="req">*</span></label><input type="text" name="' . $p . '[first_name]" data-required="1" data-validate="name" data-cf="first_name"><p class="err" data-err="first_name"></p></div>';
    echo '<div class="field col-1"><label>Last Name <span class="req">*</span></label><input type="text" name="' . $p . '[last_name]" data-required="1" data-validate="name" data-cf="last_name"><p class="err" data-err="last_name"></p></div>';
    echo '<div class="field col-1"><label>Relationship <span class="req">*</span></label><select name="' . $p . '[relationship]" data-required="1" data-cf="relationship" data-rel>';
    echo '<option value="">Select…</option>';
    foreach (FieldMap::RELATIONSHIPS as $v => $l) {
        echo '<option value="' . $v . '">' . htmlspecialchars($l, ENT_QUOTES) . '</option>';
    }
    echo '</select><p class="err" data-err="relationship"></p></div>';
    echo '<div class="field col-3 rel-other" hidden><label>Please specify relationship <span class="req">*</span></label><input type="text" name="' . $p . '[relationship_other]" data-cf="relationship_other"><p class="err" data-err="relationship_other"></p></div>';
    echo '<div class="field col-1"><label>Phone <span class="req">*</span></label><input type="tel" name="' . $p . '[phone]" data-required="1" data-validate="tel" data-phone="1" data-cf="phone"><p class="err" data-err="phone"></p></div>';
    echo '<div class="field col-1"><label>Phone Device <span class="req">*</span></label><select name="' . $p . '[phone_device]" data-required="1" data-cf="phone_device"><option value="">Select…</option>';
    foreach (FieldMap::PHONE_DEVICES as $v => $l) {
        echo '<option value="' . $v . '">' . $l . '</option>';
    }
    echo '</select><p class="err" data-err="phone_device"></p></div>';
    echo '<div class="field col-1"><label>Phone Type <span class="req">*</span></label><select name="' . $p . '[phone_location]" data-required="1" data-cf="phone_location"><option value="">Select…</option>';
    foreach (FieldMap::CONTACT_LOCATIONS as $v => $l) {
        echo '<option value="' . $v . '">' . $l . '</option>';
    }
    echo '</select><p class="err" data-err="phone_location"></p></div>';
    echo '<div class="field col-2"><label>Email <span class="req">*</span></label><input type="email" name="' . $p . '[email]" data-required="1" data-validate="email" data-cf="email"><p class="err" data-err="email"></p></div>';
    echo '<div class="field col-1"><label>Email Type <span class="req">*</span></label><select name="' . $p . '[email_location]" data-required="1" data-cf="email_location"><option value="">Select…</option>';
    foreach (FieldMap::CONTACT_LOCATIONS as $v => $l) {
        echo '<option value="' . $v . '">' . $l . '</option>';
    }
    echo '</select><p class="err" data-err="email_location"></p></div>';
    echo '</div></fieldset>';
}

/** Certification section (yes/no gate → fields + upload). */
function certSection(string $key, array $meta): void
{
    $label = $meta['label'];
    echo '<fieldset class="cert" data-cert="' . $key . '"' . (!empty($meta['age_gated']) ? ' data-age-gated="' . (int) ($meta['min_age'] ?? 18) . '"' : '') . '>';
    echo '<legend>' . htmlspecialchars($label, ENT_QUOTES) . '</legend>';
    echo '<div class="field col-3 yesno"><span class="ynlabel">Do you hold this certification? <span class="req">*</span></span><div class="ynrow">';
    echo '<label class="ynopt"><input type="radio" name="' . $key . '_has" value="yes" data-cert-has="' . $key . '"> <span>Yes</span></label>';
    echo '<label class="ynopt"><input type="radio" name="' . $key . '_has" value="no" data-cert-has="' . $key . '"> <span>No</span></label>';
    echo '</div><p class="err" id="f_' . $key . '_has-err" role="alert"></p></div>';
    echo '<p class="cert-recommend" hidden>Smart Serve is strongly recommended and encouraged — without it your available work areas and shifts will be limited.</p>';
    echo '<div class="cert-body" hidden><div class="grid">';
    field("{$key}_first_name", 'First Name (Legal)', ['col' => 1, 'validate' => 'name']);
    field("{$key}_middle_name", 'Middle Name (Legal)', ['col' => 1, 'validate' => 'name']);
    field("{$key}_last_name", 'Last Name (Legal)', ['col' => 1, 'validate' => 'name']);
    field("{$key}_cert_id", 'Certificate ID Number', ['col' => 1]);
    field("{$key}_issued", 'Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
    field("{$key}_expiry", 'Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
    if (!empty($meta['provider'])) {
        field("{$key}_provider", 'Training Provider', ['col' => 2]);
    }
    fileField("{$key}_document", 'Upload certificate (PDF preferred)', ['help' => 'A digital PDF from your certification account is best.']);
    echo '</div></div></fieldset>';
}

$steps = ['Welcome', 'Personal', 'Emergency', 'Availability', 'Medical', 'Work Authorization', 'Government ID', 'Direct Deposit', 'Headshot', 'Certifications', 'Review & Sign'];

// Bank institution map for JS auto-fill.
$bankInst = [];
foreach (FieldMap::BANKS as $k => $m) {
    $bankInst[$k] = $m['institution'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Employee Information · Legends Global</title>
<link rel="preconnect" href="https://challenges.cloudflare.com">
<link rel="stylesheet" href="assets/css/app.css?v=8">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='13' fill='none' stroke='%23111' stroke-width='5'/></svg>">
<?php if ($tsEnabled): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</head>
<body data-turnstile="<?= $tsEnabled ? '1' : '0' ?>"
      data-test="<?= $testMode ? '1' : '0' ?>"
      data-steps='<?= htmlspecialchars(json_encode($steps, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
      data-banks='<?= htmlspecialchars(json_encode($bankInst), ENT_QUOTES) ?>'
      data-today="<?= $e($today) ?>">

<?php if ($testMode): ?>
<div class="testbar">⚡ TEST MODE — submissions are marked <strong>[TEST]</strong> and routed to the test inbox. <button type="button" id="fillTest" class="btn-test">Fill test data</button></div>
<?php endif; ?>

<header class="topbar">
  <div class="brand">
    <svg class="logo" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="14" fill="none" stroke="currentColor" stroke-width="6"/><circle cx="30" cy="10" r="3" fill="currentColor"/></svg>
    <span class="brand-name"><strong>LEGENDS</strong><em>GLOBAL</em></span>
  </div>
  <div class="brand-title">EMPLOYEE INFORMATION</div>
</header>

<div class="progress-wrap" aria-hidden="true">
  <div class="progress"><div class="progress-bar" id="progressBar"></div></div>
  <div class="progress-label"><span id="stepName">Welcome</span><span id="stepCount">Step 1 of <?= count($steps) ?></span></div>
</div>

<main class="wrap">
<form id="onboarding-form" novalidate autocomplete="on" enctype="multipart/form-data" action="submit.php" method="post">
  <input type="hidden" name="form_token" value="<?= $e($formToken) ?>">
  <?php if ($testMode): ?><input type="hidden" name="test_token" value="<?= $e($testToken) ?>"><?php endif; ?>
  <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="<?= $e($honeypot) ?>" tabindex="-1" autocomplete="off"></label></div>

  <!-- STEP 1: WELCOME -->
  <section class="step" data-step="0" aria-label="Welcome">
    <h1>Welcome to Legends Global</h1>
    <p class="lead">Please complete this secure form to provide the information we need to set up your employment. Have your <strong>SIN</strong> &amp; proof of SIN, <strong>government ID</strong>, <strong>banking details</strong> (void cheque), and a way to take a <strong>headshot</strong> ready.</p>
    <div class="notice"><h3>Your information is protected</h3><p>Nothing you enter is stored on this website. On submit, your details are packaged into a single <strong>encrypted file</strong> and emailed directly to Human Resources, then discarded from this server.</p></div>
    <div class="privacy-box"><h3>Privacy Notice, Purpose of Collection, and Consent</h3><p>The personal information collected on this form is required for employment administration purposes, including hiring, onboarding, payroll, scheduling, workplace safety, regulatory compliance, and the administration of employee benefits and programs. Information is collected under the authority of applicable Ontario legislation, including the <em>Employment Standards Act, 2000</em> and the <em>Occupational Health and Safety Act</em>, and is handled per applicable privacy legislation. Access is limited to authorized personnel. To ask questions or request access/correction, contact Human Resources.</p></div>
    <label class="check big" for="f_privacy_ack"><input type="checkbox" id="f_privacy_ack" name="privacy_ack" value="1" data-required="1"> <span>I acknowledge that I have read and understand this Privacy Notice and consent to the collection, use, and storage of my personal information for the purposes described above. <span class="req">*</span></span></label>
    <p class="err" id="f_privacy_ack-err" role="alert"></p>
  </section>

  <!-- STEP 2: PERSONAL -->
  <section class="step" data-step="1" hidden aria-label="Personal information">
    <h2>Personal Information</h2>

    <h3 class="subhead">Legal Name &amp; Identity</h3>
    <div class="grid">
      <?php
      field('first_name', 'First Name (Legal)', ['required' => true, 'autocomplete' => 'given-name', 'col' => 1, 'validate' => 'name']);
      field('middle_name', 'Middle Name (Legal)', ['autocomplete' => 'additional-name', 'col' => 1, 'validate' => 'name']);
      field('last_name', 'Last Name (Legal)', ['required' => true, 'autocomplete' => 'family-name', 'col' => 1, 'validate' => 'name']);
      field('preferred_name', 'Preferred Name', ['col' => 1]);
      selectField('pronouns', 'Pronouns', FieldMap::PRONOUNS, ['col' => 1, 'extra' => 'data-pronouns']);
      field('date_of_birth', 'Date of Birth', ['type' => 'date', 'required' => true, 'max' => $today, 'col' => 1, 'validate' => 'date']);
      field('pronouns_other', 'Specify your pronouns', ['col' => 1, 'cond' => 'data-pronouns-other']);
      ?>
    </div>

    <h3 class="subhead">Home Address</h3>
    <div class="grid">
      <?php
      field('street_address', 'Street Address', ['required' => true, 'autocomplete' => 'address-line1', 'col' => 2, 'help' => 'Include your unit/apartment number below — missing unit numbers are the #1 cause of returned mail.']);
      field('unit', 'Unit / Apartment', ['autocomplete' => 'address-line2', 'col' => 1]);
      field('city', 'City', ['required' => true, 'autocomplete' => 'address-level2', 'col' => 1]);
      field('province', 'Province', ['required' => true, 'value' => 'Ontario', 'autocomplete' => 'address-level1', 'col' => 1]);
      field('postal_code', 'Postal Code', ['required' => true, 'placeholder' => 'K1A 0B1', 'autocomplete' => 'postal-code', 'autocapitalize' => 'characters', 'maxlength' => 7, 'col' => 1, 'validate' => 'postal']);
      ?>
    </div>

    <h3 class="subhead">Contact</h3>
    <div class="grid">
      <?php
      field('mobile_phone', 'Mobile Phone', ['type' => 'tel', 'required' => true, 'autocomplete' => 'mobile tel', 'col' => 1, 'validate' => 'tel', 'phone' => true, 'placeholder' => '+1 (416) 555-0142']);
      field('home_phone', 'Home Phone', ['type' => 'tel', 'col' => 1, 'validate' => 'tel', 'phone' => true]);
      field('other_phone', 'Other Phone', ['type' => 'tel', 'col' => 1, 'validate' => 'tel', 'phone' => true]);
      ?>
    </div>
    <div class="grid two">
      <?php
      field('primary_email', 'Primary Email Address', ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'col' => 1, 'validate' => 'email']);
      field('secondary_email', 'Secondary Email Address', ['type' => 'email', 'col' => 1, 'validate' => 'email']);
      ?>
    </div>
  </section>

  <!-- STEP 3: EMERGENCY -->
  <section class="step" data-step="2" hidden aria-label="Emergency contacts">
    <h2>Emergency Contacts</h2>
    <p class="lead">Provide at least one emergency contact. You can add more with the button below.</p>
    <div id="contacts"><?php contactBlock(0, true); ?></div>
    <button type="button" class="btn-ghost" id="addContact">+ Add another contact</button>
    <p class="err" id="contacts-err" role="alert"></p>
  </section>

  <!-- STEP 4: AVAILABILITY -->
  <section class="step" data-step="3" hidden aria-label="Availability">
    <h2>General Availability</h2>
    <p class="lead">Select each day you can work and enter your available hours. At least one day is required.</p>
    <div class="avail-grid">
      <?php foreach (FieldMap::DAYS as $key => $label): ?>
      <div class="avail-day" data-day="<?= $e($key) ?>">
        <label class="daytoggle avail-<?= $e($key) ?>"><input type="checkbox" name="avail_<?= $e($key) ?>_enabled" value="1" data-day-toggle="<?= $e($key) ?>"> <span><?= $e($label) ?></span></label>
        <div class="daytimes" hidden>
          <label>Start <input type="time" name="avail_<?= $e($key) ?>_start" data-day-time="<?= $e($key) ?>"></label>
          <label>End <input type="time" name="avail_<?= $e($key) ?>_end" data-day-time="<?= $e($key) ?>"></label>
        </div>
        <p class="err" id="f_avail_<?= $e($key) ?>_start-err" role="alert"></p>
        <p class="err" id="f_avail_<?= $e($key) ?>_end-err" role="alert"></p>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="err" id="availability-err" role="alert"></p>
    <div class="grid">
      <?php field('desired_hours', 'Desired number of hours per week', ['type' => 'number', 'min' => 0, 'max' => 168, 'col' => 1]); ?>
      <?php textareaField('availability_comments', 'Comments / Additional Information', ['rows' => 2, 'maxlength' => 1000, 'placeholder' => 'Anything HR should know about your availability']); ?>
    </div>
    <h3 class="subhead">Upcoming Trips / Time Off</h3>
    <div class="grid">
      <?php yesnoField('trips_has', 'Do you have any upcoming trips or planned time off?', ['reveal' => 'trips_details']); ?>
      <div class="reveal" data-reveal-for="trips_details" hidden><div class="grid"><?php textareaField('trips_details', 'Please provide the dates and details', ['rows' => 2, 'maxlength' => 800]); ?></div></div>
    </div>
  </section>

  <!-- STEP 5: MEDICAL -->
  <section class="step" data-step="4" hidden aria-label="Medical and safety">
    <h2>Medical &amp; Safety Information</h2>
    <p class="lead">Collected solely for workplace safety, emergency response, and accommodation. Please do not provide unrelated medical information.</p>
    <div class="grid">
      <?php yesnoField('allergies_has', 'Do you have any allergies?', ['reveal' => 'allergies_details']); ?>
      <div class="reveal" data-reveal-for="allergies_details" hidden><div class="grid"><?php textareaField('allergies_details', 'Please describe your allergies', ['rows' => 2, 'maxlength' => 800]); ?></div></div>
      <?php yesnoField('medical_has', 'Do you have any medical conditions we should be aware of?', ['reveal' => 'medical_details']); ?>
      <div class="reveal" data-reveal-for="medical_details" hidden><div class="grid"><?php textareaField('medical_details', 'Please describe', ['rows' => 2, 'maxlength' => 800]); ?></div></div>
    </div>
  </section>

  <!-- STEP 6: WORK AUTHORIZATION -->
  <section class="step" data-step="5" hidden aria-label="Work authorization">
    <h2>Work Authorization</h2>
    <div class="grid">
      <?php
      field('sin', 'Social Insurance Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 11, 'placeholder' => '000 000 000', 'col' => 1, 'validate' => 'sin', 'autocomplete' => 'off', 'extra' => 'data-sin']);
      fileField('sin_document', 'Proof of SIN (upload)', ['required' => true, 'help' => 'A SIN confirmation letter or record. You can review/download yours from Service Canada.']);
      ?>
      <div class="field col-3"><p class="help"><a href="https://www.canada.ca/en/employment-social-development/services/my-account/sin.html" target="_blank" rel="noopener">Check or download your SIN from Service Canada →</a></p></div>
    </div>
    <div id="permit-block" class="conditional" hidden>
      <div class="cond-note">Because your SIN begins with <strong>9</strong>, please provide your SIN dates and work/study permit details.</div>
      <div class="grid">
        <?php
        field('sin_issued', 'SIN Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
        field('sin_expiry', 'SIN Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
        selectField('permit_type', 'Permit Type', FieldMap::PERMIT_TYPES, ['col' => 1]);
        field('permit_number', 'Permit Number', ['col' => 1]);
        field('permit_issued', 'Permit Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
        field('permit_expiry', 'Permit Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date', 'extra' => 'data-permit-expiry']);
        fileField('permit_document', 'Upload your permit', []);
        ?>
      </div>
      <div id="ircc-block" class="conditional" hidden>
        <div class="cond-note">Your permit appears to be <strong>expired</strong> — please provide your IRCC letter (proof of maintained/implied status).</div>
        <div class="grid">
          <?php
          field('ircc_letter_id', 'IRCC Letter ID', ['col' => 1]);
          fileField('ircc_document', 'Upload your IRCC letter', []);
          ?>
        </div>
      </div>
    </div>
  </section>

  <!-- STEP 7: GOVERNMENT ID -->
  <section class="step" data-step="6" hidden aria-label="Government ID">
    <h2>Government Issued Identification</h2>
    <p class="lead">Enter the details exactly as they appear on the document, then upload a clear scan or photo.</p>
    <label class="check" for="gov_same"><input type="checkbox" id="gov_same" data-copy-name="gov"> <span>My name on this ID is the same as my legal name</span></label>
    <div class="grid">
      <?php
      field('gov_first_name', 'First Name (Legal)', ['required' => true, 'col' => 1, 'validate' => 'name']);
      field('gov_middle_name', 'Middle Name (Legal)', ['col' => 1, 'validate' => 'name']);
      field('gov_last_name', 'Last Name (Legal)', ['required' => true, 'col' => 1, 'validate' => 'name']);
      selectField('gov_doc_type', 'Document Type', FieldMap::idTypeOptions(), ['required' => true, 'col' => 2, 'extra' => 'data-idtype']);
      field('gov_doc_number', 'Document ID Number', ['required' => true, 'col' => 1]);
      field('gov_expiry_date', 'Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date', 'extra' => 'data-gov-expiry']);
      fileField('gov_document', 'Upload Government ID (PDF, PNG, or JPG)', ['required' => true, 'help' => 'Clear and legible. Screenshots or altered documents may be rejected.']);
      ?>
    </div>
  </section>

  <!-- STEP 8: DIRECT DEPOSIT -->
  <section class="step" data-step="7" hidden aria-label="Direct deposit">
    <h2>Direct Deposit</h2>
    <p class="lead">Provide the details as they appear on a <strong>void cheque</strong> or a direct-deposit form from your bank, and upload it.</p>
    <div class="grid">
      <?php
      selectField('dd_bank', 'Financial Institution', FieldMap::bankOptions(), ['required' => true, 'col' => 2, 'extra' => 'data-bank']);
      field('dd_bank_other', 'Institution Name', ['required' => true, 'col' => 1, 'cond' => 'data-bank-other']);
      field('dd_account_holder', "Account Holder's Name", ['required' => true, 'col' => 3, 'validate' => 'name']);
      ?>
    </div>
    <p class="help numbers-note">The three numbers below appear on your void cheque, in the order shown.</p>
    <div class="grid">
      <?php
      field('dd_transit', 'Transit Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 5, 'col' => 1, 'validate' => 'digits5', 'placeholder' => '5 digits']);
      field('dd_institution_number', 'Institution Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 3, 'col' => 1, 'validate' => 'digits3', 'extra' => 'data-institution', 'placeholder' => '3 digits', 'help' => 'Auto-filled for major banks.']);
      field('dd_account_number', 'Account Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 12, 'col' => 1, 'validate' => 'account', 'extra' => 'data-account', 'placeholder' => '7–12 digits']);
      field('dd_account_confirm', 'Confirm Account Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 12, 'col' => 1, 'validate' => 'account', 'nopaste' => true, 'extra' => 'data-account-confirm', 'help' => 'Re-type it — paste and autofill are disabled to catch typos.']);
      fileField('dd_document', 'Upload void cheque / direct deposit form', ['required' => true, 'help' => 'Download the PDF from your online banking where possible.']);
      ?>
    </div>
  </section>

  <!-- STEP 9: HEADSHOT -->
  <section class="step" data-step="8" hidden aria-label="Headshot">
    <h2>Headshot Photograph</h2>
    <p class="lead">A passport-style photo: plain background, face the camera, neutral expression. No hats, sunglasses, or glare.</p>
    <div class="headshot-tool" id="headshotTool">
      <div class="cam-wrap">
        <video id="camVideo" playsinline muted hidden></video>
        <canvas id="camCanvas" hidden></canvas>
        <div class="cam-guide" id="camGuide" hidden></div>
        <div class="cam-placeholder" id="camPlaceholder">Use your camera to take a headshot, or upload one below.</div>
      </div>
      <div class="cam-controls">
        <button type="button" class="btn-ghost" id="camStart">Start camera</button>
        <button type="button" class="btn" id="camCapture" hidden>Capture</button>
        <button type="button" class="btn-ghost" id="camRetake" hidden>Retake</button>
      </div>
      <div class="headshot-dos">
        <span class="do">✓ Plain background</span><span class="do">✓ Face the camera</span>
        <span class="dont">✕ No hats</span><span class="dont">✕ No sunglasses</span><span class="dont">✕ No glare</span>
      </div>
    </div>
    <div class="grid">
      <?php fileField('headshot', 'Or upload a headshot (PNG or JPG)', ['required' => true, 'accept' => '.png,.jpg,.jpeg', 'help' => 'Large photos are automatically resized.']); ?>
      <div class="field col-3"><div class="headshot-preview" id="headshotPreview" hidden><img alt="Headshot preview"></div></div>
    </div>
  </section>

  <!-- STEP 10: CERTIFICATIONS -->
  <section class="step" data-step="9" hidden aria-label="Certifications">
    <h2>Certifications</h2>
    <p class="lead">Tell us which certifications you hold. If you hold one, its details and a copy are required.</p>
    <?php foreach (FieldMap::CERTS as $key => $meta) {
        certSection($key, $meta);
    } ?>
  </section>

  <!-- STEP 11: REVIEW & SIGN -->
  <section class="step" data-step="10" hidden aria-label="Review and sign">
    <h2>Review &amp; Sign</h2>
    <div class="review" id="reviewSummary" aria-live="polite"></div>
    <div class="privacy-box"><p>I certify that all information provided is true, complete, and accurate to the best of my knowledge. I understand that providing false, misleading, or incomplete information may result in delays or corrective action up to and including termination. I understand it is my responsibility to promptly notify Human Resources of any changes to my personal, banking, work-authorization, contact, or certification information.</p></div>
    <label class="check" for="f_declaration_ack"><input type="checkbox" id="f_declaration_ack" name="declaration_ack" value="1" data-required="1"> <span>I certify the above declaration is true and accurate. <span class="req">*</span></span></label>
    <p class="err" id="f_declaration_ack-err" role="alert"></p>
    <h3 class="subhead">Electronic Communication Consent</h3>
    <label class="check" for="f_comms_consent"><input type="checkbox" id="f_comms_consent" name="comms_consent" value="1" data-required="1"> <span>I consent to receiving employment-related communications by email using the contact information I have provided. <span class="req">*</span></span></label>
    <p class="err" id="f_comms_consent-err" role="alert"></p>
    <div class="grid">
      <?php
      selectField('preferred_contact', 'Preferred Method of Contact', FieldMap::CONTACT_METHODS, ['required' => true, 'col' => 1]);
      field('employee_name', 'Your Full Legal Name (typed)', ['required' => true, 'col' => 1, 'validate' => 'name']);
      field('signature_date', 'Date', ['type' => 'date', 'required' => true, 'value' => $today, 'readonly' => true, 'col' => 1]);
      ?>
    </div>
    <div class="field col-3 sigfield">
      <label>Signature <span class="req">*</span></label>
      <div class="sigpad-wrap"><canvas id="signaturePad" class="sigpad" width="600" height="180" role="img" tabindex="0" aria-label="Signature drawing area" aria-describedby="f_signature-err"></canvas><button type="button" class="btn-ghost sig-clear" id="sigClear">Clear</button></div>
      <p class="help">Sign above using your mouse or finger.</p>
      <input type="hidden" name="signature" id="signatureInput">
      <p class="err" id="f_signature-err" role="alert"></p>
    </div>
    <?php if ($tsEnabled): ?>
    <div class="turnstile-wrap"><div class="cf-turnstile" data-sitekey="<?= $e($siteKey) ?>" data-theme="light"></div><p class="err" id="turnstile-err" role="alert"></p></div>
    <?php endif; ?>
    <div class="submit-note">By submitting, your information is encrypted and emailed securely to Human Resources. Nothing is stored on this website.</div>
  </section>

  <div class="nav">
    <button type="button" class="btn-ghost" id="btnBack" hidden>Back</button>
    <div class="nav-spacer"></div>
    <button type="button" class="btn" id="btnNext">Continue</button>
    <button type="submit" class="btn btn-primary" id="btnSubmit" hidden>Submit securely</button>
  </div>
</form>

<template id="contact-template"><?php contactBlock('__I__', false); ?></template>

<div class="overlay" id="successOverlay" hidden><div class="success-card"><div class="success-icon">✓</div><h2>Submission received</h2><p>Thank you. Your information has been encrypted and sent securely to Human Resources.</p><p class="ref">Reference: <strong id="successRef"></strong></p><p class="muted">You may now close this window.</p></div></div>
<div class="overlay" id="sendingOverlay" hidden><div class="success-card"><div class="spinner"></div><h2>Encrypting &amp; sending…</h2><p class="muted">This can take a moment while your documents are packaged securely.</p></div></div>
</main>

<footer class="foot"><span>Legends Global · Confidential</span><span>Need help? Contact Human Resources.</span></footer>
<script src="assets/js/app.js?v=8" defer></script>
</body>
</html>
