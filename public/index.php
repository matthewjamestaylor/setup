<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Legends\FieldMap;
use Legends\Turnstile;
use Legends\Support;

$turnstile   = new Turnstile((array) cfg('turnstile', []));
$tsEnabled   = $turnstile->enabled();
$siteKey     = (string) cfg('turnstile.site_key', '');
$formToken   = Support::makeFormToken(time());
$honeypot    = (string) cfg('security.honeypot_field', 'company_website');
$days        = FieldMap::DAYS;
$contacts    = FieldMap::CONTACT_METHODS;
$certs       = FieldMap::CERTS;
$today       = date('Y-m-d');
$e           = fn($s) => Support::e((string) $s);

/**
 * Render a labelled input group.
 * @param array $o  type, required, placeholder, help, autocomplete, inputmode,
 *                  pattern, maxlength, min, max, value, col
 */
function field(string $name, string $label, array $o = []): void
{
    $type = $o['type'] ?? 'text';
    $req  = !empty($o['required']);
    $id   = 'f_' . $name;
    $attrs = [
        'id="' . $id . '"',
        'name="' . htmlspecialchars($name, ENT_QUOTES) . '"',
        'type="' . htmlspecialchars($type, ENT_QUOTES) . '"',
    ];
    foreach (['placeholder', 'autocomplete', 'inputmode', 'pattern', 'maxlength', 'min', 'max', 'step', 'value'] as $a) {
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
    if (!empty($o['readonly'])) {
        $attrs[] = 'readonly';
    }
    if (!empty($o['autocapitalize'])) {
        $attrs[] = 'autocapitalize="' . htmlspecialchars($o['autocapitalize'], ENT_QUOTES) . '"';
    }
    $colClass = isset($o['col']) ? ' col-' . (int) $o['col'] : '';
    echo '<div class="field' . $colClass . '">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES)
        . ($req ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="opt">optional</span>') . '</label>';
    echo '<input ' . implode(' ', $attrs) . ' aria-describedby="' . $id . '-err">';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p>';
    echo '</div>';
}

function textareaField(string $name, string $label, array $o = []): void
{
    $req = !empty($o['required']);
    $id = 'f_' . $name;
    echo '<div class="field col-3">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES)
        . ($req ? ' <span class="req">*</span>' : ' <span class="opt">optional</span>') . '</label>';
    echo '<textarea id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" rows="' . (int) ($o['rows'] ?? 3) . '"'
        . ' maxlength="' . (int) ($o['maxlength'] ?? 800) . '"'
        . ($req ? ' data-required="1"' : '')
        . ' aria-describedby="' . $id . '-err"'
        . (!empty($o['placeholder']) ? ' placeholder="' . htmlspecialchars($o['placeholder'], ENT_QUOTES) . '"' : '')
        . '></textarea>';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p>';
    echo '</div>';
}

function fileField(string $name, string $label, array $o = []): void
{
    $req = !empty($o['required']);
    $accept = $o['accept'] ?? '.pdf,.png,.jpg,.jpeg';
    $id = 'f_' . $name;
    echo '<div class="field col-3 filefield" data-file="' . $name . '">';
    echo '<label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES)
        . ($req ? ' <span class="req">*</span>' : ' <span class="opt">optional</span>') . '</label>';
    echo '<div class="filerow">';
    echo '<input id="' . $id . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" type="file" accept="'
        . htmlspecialchars($accept, ENT_QUOTES) . '"' . ($req ? ' data-required="1"' : '')
        . ' aria-describedby="' . $id . '-err">';
    echo '</div>';
    echo '<p class="filename" data-filename></p>';
    if (!empty($o['help'])) {
        echo '<p class="help">' . htmlspecialchars($o['help'], ENT_QUOTES) . '</p>';
    }
    echo '<p class="err" id="' . $id . '-err" role="alert"></p>';
    echo '</div>';
}

$steps = [
    'Welcome', 'Personal', 'Emergency', 'Availability', 'Medical',
    'Work Authorization', 'Direct Deposit', 'Headshot', 'Certifications', 'Review & Sign',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Employee Information · Legends Global</title>
<meta name="description" content="Secure new-hire information form for Legends Global.">
<link rel="preconnect" href="https://challenges.cloudflare.com">
<link rel="stylesheet" href="assets/css/app.css?v=1">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='13' fill='none' stroke='%23111' stroke-width='5'/></svg>">
<?php if ($tsEnabled): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</head>
<body data-turnstile="<?= $tsEnabled ? '1' : '0' ?>" data-steps='<?= htmlspecialchars(json_encode($steps, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>

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
  <!-- honeypot: hidden from humans -->
  <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="<?= $e($honeypot) ?>" tabindex="-1" autocomplete="off"></label></div>

  <!-- ============ STEP 1: WELCOME + PRIVACY ============ -->
  <section class="step" data-step="0" aria-label="Welcome">
    <h1>Welcome to Legends Global</h1>
    <p class="lead">Please complete this secure form to provide the information we need to set up your employment. It takes about 10&ndash;15 minutes. Have your <strong>SIN</strong>, <strong>banking details</strong> (or a void cheque), <strong>government ID</strong>, and a <strong>headshot</strong> ready.</p>
    <div class="notice">
      <h3>Your information is protected</h3>
      <p>Nothing you enter is stored on this website. When you submit, your details are packaged into a single <strong>encrypted file</strong> and emailed directly to Human Resources &mdash; then discarded from this server.</p>
    </div>
    <div class="privacy-box">
      <h3>Privacy Notice, Purpose of Collection, and Consent</h3>
      <p>The personal information collected on this form is required for employment administration purposes, including hiring, onboarding, payroll, scheduling, workplace safety, regulatory compliance, and the administration of employee benefits and programs. Information is collected under the authority of applicable Ontario employment and workplace legislation, including the <em>Employment Standards Act, 2000</em> and the <em>Occupational Health and Safety Act</em>, and is handled in accordance with applicable privacy legislation and internal policies. Access is limited to authorized personnel. To ask questions, or to request access or correction, please contact Human Resources.</p>
    </div>
    <label class="check big" for="f_privacy_ack">
      <input type="checkbox" id="f_privacy_ack" name="privacy_ack" value="1" data-required="1">
      <span>I acknowledge that I have read and understand this Privacy Notice and consent to the collection, use, and storage of my personal information for the purposes described above. <span class="req">*</span></span>
    </label>
    <p class="err" id="f_privacy_ack-err" role="alert"></p>
  </section>

  <!-- ============ STEP 2: PERSONAL ============ -->
  <section class="step" data-step="1" hidden aria-label="Personal information">
    <h2>Personal Information</h2>
    <div class="grid">
      <?php
        field('first_name', 'First Name (Legal)', ['required' => true, 'autocomplete' => 'given-name', 'col' => 1, 'validate' => 'name']);
        field('middle_name', 'Middle Name (Legal)', ['autocomplete' => 'additional-name', 'col' => 1, 'validate' => 'name']);
        field('last_name', 'Last Name (Legal)', ['required' => true, 'autocomplete' => 'family-name', 'col' => 1, 'validate' => 'name']);
        field('preferred_name', 'Preferred Name', ['col' => 1]);
        field('pronouns', 'Pronouns', ['placeholder' => 'e.g. she/her', 'col' => 1]);
        field('date_of_birth', 'Date of Birth', ['type' => 'date', 'required' => true, 'max' => $today, 'col' => 1, 'validate' => 'date']);
        field('street_address', 'Home Street Address', ['required' => true, 'autocomplete' => 'address-line1', 'col' => 2]);
        field('unit', 'Unit / Apartment', ['autocomplete' => 'address-line2', 'col' => 1]);
        field('city', 'City', ['required' => true, 'autocomplete' => 'address-level2', 'col' => 1]);
        field('province', 'Province', ['required' => true, 'value' => 'Ontario', 'autocomplete' => 'address-level1', 'col' => 1]);
        field('postal_code', 'Postal Code', ['required' => true, 'placeholder' => 'K1A 0B1', 'autocomplete' => 'postal-code', 'autocapitalize' => 'characters', 'maxlength' => 7, 'col' => 1, 'validate' => 'postal']);
        field('mobile_phone', 'Mobile Phone', ['type' => 'tel', 'required' => true, 'autocomplete' => 'mobile tel', 'col' => 1, 'validate' => 'tel']);
        field('home_phone', 'Home Phone', ['type' => 'tel', 'col' => 1, 'validate' => 'tel']);
        field('other_phone', 'Other Phone', ['type' => 'tel', 'col' => 1, 'validate' => 'tel']);
        field('primary_email', 'Primary Email Address', ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'col' => 2, 'validate' => 'email']);
        field('secondary_email', 'Secondary Email Address', ['type' => 'email', 'col' => 1, 'validate' => 'email']);
      ?>
    </div>
  </section>

  <!-- ============ STEP 3: EMERGENCY ============ -->
  <section class="step" data-step="2" hidden aria-label="Emergency contacts">
    <h2>Emergency Contacts</h2>
    <h3 class="subhead">Primary Contact</h3>
    <div class="grid">
      <?php
        field('ec1_name', 'Name', ['required' => true, 'col' => 1, 'validate' => 'name']);
        field('ec1_relationship', 'Relationship', ['required' => true, 'placeholder' => 'e.g. Parent', 'col' => 1]);
        field('ec1_phone', 'Phone', ['type' => 'tel', 'required' => true, 'col' => 1, 'validate' => 'tel']);
        field('ec1_email', 'Email', ['type' => 'email', 'col' => 1, 'validate' => 'email']);
      ?>
    </div>
    <h3 class="subhead">Secondary Contact <span class="opt">optional</span></h3>
    <div class="grid">
      <?php
        field('ec2_name', 'Name', ['col' => 1, 'validate' => 'name']);
        field('ec2_relationship', 'Relationship', ['col' => 1]);
        field('ec2_phone', 'Phone', ['type' => 'tel', 'col' => 1, 'validate' => 'tel']);
        field('ec2_email', 'Email', ['type' => 'email', 'col' => 1, 'validate' => 'email']);
      ?>
    </div>
  </section>

  <!-- ============ STEP 4: AVAILABILITY ============ -->
  <section class="step" data-step="3" hidden aria-label="Availability">
    <h2>General Availability</h2>
    <p class="lead">On a general week-to-week basis, indicate when you are typically available to work. Toggle a day on and enter your available hours.</p>
    <div class="avail-grid">
      <?php foreach ($days as $key => $label): ?>
      <div class="avail-day" data-day="<?= $e($key) ?>">
        <label class="daytoggle avail-<?= $e($key) ?>">
          <input type="checkbox" name="avail_<?= $e($key) ?>_enabled" value="1" data-day-toggle="<?= $e($key) ?>">
          <span><?= $e($label) ?></span>
        </label>
        <div class="daytimes" hidden>
          <label>Start <input type="time" name="avail_<?= $e($key) ?>_start" data-day-time="<?= $e($key) ?>"></label>
          <label>End <input type="time" name="avail_<?= $e($key) ?>_end" data-day-time="<?= $e($key) ?>"></label>
        </div>
        <p class="err" id="avail_<?= $e($key) ?>_start-err" role="alert"></p>
        <p class="err" id="avail_<?= $e($key) ?>_end-err" role="alert"></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="grid">
      <?php field('desired_hours', 'Desired number of hours per week', ['type' => 'number', 'min' => 0, 'max' => 168, 'col' => 1]); ?>
      <?php textareaField('availability_comments', 'Comments / Additional Information', ['rows' => 3, 'maxlength' => 1000, 'placeholder' => 'Anything HR should know about your availability']); ?>
    </div>
  </section>

  <!-- ============ STEP 5: MEDICAL ============ -->
  <section class="step" data-step="4" hidden aria-label="Medical and safety">
    <h2>Medical &amp; Safety Information</h2>
    <p class="lead">Collected solely for workplace safety, emergency response, and accommodation. Please do not provide unrelated medical information. Leave blank or write &ldquo;None&rdquo; if not applicable.</p>
    <div class="grid">
      <?php
        textareaField('allergies', 'Allergies', ['rows' => 2, 'maxlength' => 800]);
        textareaField('medical_conditions', 'Medical Conditions', ['rows' => 2, 'maxlength' => 800]);
      ?>
    </div>
  </section>

  <!-- ============ STEP 6: WORK AUTHORIZATION + GOV ID ============ -->
  <section class="step" data-step="5" hidden aria-label="Work authorization and identification">
    <h2>Work Authorization</h2>
    <div class="grid">
      <?php
        field('sin', 'Social Insurance Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 11, 'placeholder' => '000 000 000', 'col' => 1, 'validate' => 'sin', 'autocomplete' => 'off']);
        field('sin_issued', 'SIN Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
        field('sin_expiry', 'SIN Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
      ?>
    </div>
    <div id="permit-block" class="conditional" hidden>
      <div class="cond-note">Because your SIN begins with <strong>9</strong>, please complete your work/study permit details below.</div>
      <div class="grid">
        <?php
          field('permit_number', 'Work / Study Permit Number', ['col' => 1]);
          field('permit_issued', 'Permit Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
          field('permit_expiry', 'Permit Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
          field('ircc_letter_id', 'IRCC Letter ID', ['col' => 1]);
          textareaField('permit_restrictions', 'Restrictions and Other Information', ['rows' => 2, 'maxlength' => 600]);
        ?>
      </div>
    </div>

    <h2 class="mt">Government Issued Identification</h2>
    <p class="lead">Enter the details exactly as they appear on the document, then upload a clear scan or photo.</p>
    <label class="check" for="gov_same"><input type="checkbox" id="gov_same" data-copy-name="gov"> <span>My name on this ID is the same as my legal name above</span></label>
    <div class="grid">
      <?php
        field('gov_first_name', 'First Name (Legal)', ['required' => true, 'col' => 1, 'validate' => 'name']);
        field('gov_middle_name', 'Middle Name (Legal)', ['col' => 1, 'validate' => 'name']);
        field('gov_last_name', 'Last Name (Legal)', ['required' => true, 'col' => 1, 'validate' => 'name']);
        field('gov_doc_type', 'Document Type', ['required' => true, 'placeholder' => "e.g. Ontario Driver's Licence, Passport", 'col' => 2]);
        field('gov_doc_number', 'Document ID Number', ['required' => true, 'col' => 1]);
        field('gov_issued_by', 'Issued By', ['required' => true, 'placeholder' => 'e.g. ServiceOntario', 'col' => 1]);
        field('gov_issued_date', 'Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
        field('gov_expiry_date', 'Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
      ?>
      <?php fileField('gov_document', 'Upload Government ID (PDF, PNG, or JPG)', ['required' => true, 'help' => 'Clear and legible. Screenshots or altered documents may be rejected.']); ?>
    </div>
  </section>

  <!-- ============ STEP 7: DIRECT DEPOSIT ============ -->
  <section class="step" data-step="6" hidden aria-label="Direct deposit">
    <h2>Direct Deposit Details</h2>
    <p class="lead">Provide the information as it appears on a <strong>void cheque</strong> or a Direct Deposit Authorization Form from your bank. Uploading the void cheque / bank form is strongly recommended.</p>
    <div class="grid">
      <?php
        field('dd_institution_name', 'Name of Financial Institution', ['required' => true, 'col' => 1]);
        field('dd_account_holder', "Account Holder's Name", ['required' => true, 'col' => 1, 'validate' => 'name']);
        field('dd_transit', 'Transit Number (5 digits)', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 5, 'col' => 1, 'validate' => 'digits5']);
        field('dd_institution_number', 'Institution Number (3 digits)', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 3, 'col' => 1, 'validate' => 'digits3']);
        field('dd_account_number', 'Account Number', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 17, 'col' => 1, 'validate' => 'account']);
      ?>
      <?php fileField('dd_document', 'Upload void cheque / direct deposit form (PDF or image)', ['help' => 'Download the PDF from your online banking where possible.']); ?>
    </div>
  </section>

  <!-- ============ STEP 8: HEADSHOT ============ -->
  <section class="step" data-step="7" hidden aria-label="Headshot">
    <h2>Headshot Photograph</h2>
    <p class="lead">Please provide a passport-style photo against a plain white background: no hats, scarves, or sunglasses. Look straight at the camera with a neutral, professional expression.</p>
    <div class="grid">
      <?php fileField('headshot', 'Upload headshot (PNG or JPG)', ['required' => true, 'accept' => '.png,.jpg,.jpeg', 'help' => 'A recent, clear photo. Large photos are automatically resized.']); ?>
      <div class="field col-3"><div class="headshot-preview" id="headshotPreview" hidden><img alt="Headshot preview"></div></div>
    </div>
  </section>

  <!-- ============ STEP 9: CERTIFICATIONS ============ -->
  <section class="step" data-step="8" hidden aria-label="Certifications">
    <h2>Certifications</h2>
    <p class="lead">If you hold any of the following, please provide the details. If a certification does not apply to you, mark it <strong>Not Applicable</strong>.</p>
    <?php foreach ($certs as $key => $meta): ?>
    <fieldset class="cert" data-cert="<?= $e($key) ?>">
      <legend><?= $e($meta['label']) ?></legend>
      <label class="check na"><input type="checkbox" name="<?= $e($key) ?>_not_applicable" value="1" data-cert-na="<?= $e($key) ?>"> <span>Not Applicable</span></label>
      <div class="cert-body">
        <div class="grid">
          <?php
            field("{$key}_first_name", 'First Name (Legal)', ['col' => 1, 'validate' => 'name']);
            field("{$key}_middle_name", 'Middle Name (Legal)', ['col' => 1, 'validate' => 'name']);
            field("{$key}_last_name", 'Last Name (Legal)', ['col' => 1, 'validate' => 'name']);
            field("{$key}_cert_id", 'Certificate ID Number', ['col' => 1]);
            field("{$key}_issued", 'Issued Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
            field("{$key}_expiry", 'Expiry Date', ['type' => 'date', 'col' => 1, 'validate' => 'date']);
            if ($meta['provider']) {
                field("{$key}_provider", 'Training Provider', ['col' => 2]);
            }
            fileField("{$key}_document", 'Upload certificate (PDF preferred)', ['help' => 'A digital PDF from your certification account is best.']);
          ?>
        </div>
      </div>
    </fieldset>
    <?php endforeach; ?>
  </section>

  <!-- ============ STEP 10: REVIEW & SIGN ============ -->
  <section class="step" data-step="9" hidden aria-label="Review and sign">
    <h2>Declaration &amp; Signature</h2>

    <div class="review" id="reviewSummary" aria-live="polite"></div>

    <div class="privacy-box">
      <p>I certify that all information provided on this form and in any supporting documentation is true, complete, and accurate to the best of my knowledge. I understand that providing false, misleading, or incomplete information may result in delays in onboarding, payroll processing, or scheduling, or corrective action up to and including termination of employment. I understand that it is my responsibility to promptly notify Human Resources of any changes to my personal information, banking information, work-authorization status, contact information, or certifications.</p>
    </div>
    <label class="check" for="f_declaration_ack"><input type="checkbox" id="f_declaration_ack" name="declaration_ack" value="1" data-required="1"> <span>I certify the above declaration is true and accurate. <span class="req">*</span></span></label>
    <p class="err" id="f_declaration_ack-err" role="alert"></p>

    <h3 class="subhead">Electronic Communication Consent</h3>
    <p class="lead">Legends Global communicates important employment information electronically, including scheduling, payroll notices, policy updates, and training requirements.</p>
    <label class="check" for="f_comms_consent"><input type="checkbox" id="f_comms_consent" name="comms_consent" value="1" data-required="1"> <span>I consent to receiving employment-related communications by email and/or text message using the contact information I have provided. <span class="req">*</span></span></label>
    <p class="err" id="f_comms_consent-err" role="alert"></p>

    <div class="grid">
      <div class="field col-1">
        <label for="f_preferred_contact">Preferred Method of Contact <span class="req">*</span></label>
        <select id="f_preferred_contact" name="preferred_contact" data-required="1" aria-describedby="f_preferred_contact-err">
          <option value="">Select…</option>
          <?php foreach ($contacts as $val => $lab): ?>
          <option value="<?= $e($val) ?>"><?= $e($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="err" id="f_preferred_contact-err" role="alert"></p>
      </div>
      <?php
        field('employee_name', 'Your Full Legal Name (typed)', ['required' => true, 'col' => 1, 'validate' => 'name']);
        field('signature_date', 'Date', ['type' => 'date', 'required' => true, 'value' => $today, 'readonly' => true, 'col' => 1]);
      ?>
    </div>

    <div class="field col-3 sigfield">
      <label>Signature <span class="req">*</span></label>
      <div class="sigpad-wrap">
        <canvas id="signaturePad" class="sigpad" width="600" height="180" role="img" tabindex="0" aria-label="Signature drawing area" aria-describedby="f_signature-err"></canvas>
        <button type="button" class="btn-ghost sig-clear" id="sigClear">Clear</button>
      </div>
      <p class="help">Sign above using your mouse or finger.</p>
      <input type="hidden" name="signature" id="signatureInput">
      <p class="err" id="f_signature-err" role="alert"></p>
    </div>

    <?php if ($tsEnabled): ?>
    <div class="turnstile-wrap">
      <div class="cf-turnstile" data-sitekey="<?= $e($siteKey) ?>" data-theme="light"></div>
      <p class="err" id="turnstile-err" role="alert"></p>
    </div>
    <?php endif; ?>

    <div class="submit-note">By submitting, your information is encrypted and emailed securely to Human Resources. Nothing is stored on this website.</div>
  </section>

  <!-- ============ NAV ============ -->
  <div class="nav">
    <button type="button" class="btn-ghost" id="btnBack" hidden>Back</button>
    <div class="nav-spacer"></div>
    <button type="button" class="btn" id="btnNext">Continue</button>
    <button type="submit" class="btn btn-primary" id="btnSubmit" hidden>Submit securely</button>
  </div>
</form>

<!-- Success -->
<div class="overlay" id="successOverlay" hidden>
  <div class="success-card">
    <div class="success-icon">✓</div>
    <h2>Submission received</h2>
    <p>Thank you. Your information has been encrypted and sent securely to Human Resources.</p>
    <p class="ref">Reference: <strong id="successRef"></strong></p>
    <p class="muted">You may now close this window. Please keep your reference number for your records.</p>
  </div>
</div>

<!-- Sending -->
<div class="overlay" id="sendingOverlay" hidden>
  <div class="success-card">
    <div class="spinner"></div>
    <h2>Encrypting &amp; sending…</h2>
    <p class="muted">This can take a moment while your documents are packaged securely.</p>
  </div>
</div>
</main>

<footer class="foot">
  <span>Legends Global · Confidential</span>
  <span>Need help? Contact Human Resources.</span>
</footer>

<script src="assets/js/app.js?v=1" defer></script>
</body>
</html>
