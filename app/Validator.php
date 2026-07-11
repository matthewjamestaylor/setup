<?php

declare(strict_types=1);

namespace Legends;

/**
 * Authoritative server-side validation.
 *
 * Consumes raw $_POST / $_FILES, returns a structured result:
 *   [
 *     'ok'        => bool,
 *     'errors'    => [ fieldKey => message, ... ],
 *     'data'      => [ fieldKey => cleanScalar, ... ],
 *     'files'     => [ PreparedFile, ... ],   // processed uploads (in workDir)
 *     'signature' => ?string,                 // path to signature PNG in workDir
 *   ]
 *
 * A PreparedFile is:
 *   ['field'=>..,'label'=>..,'kind'=>'doc|image','path'=>..,'ext'=>..,'mime'=>..,'size'=>int]
 */
final class Validator
{
    private array $errors = [];
    private array $data   = [];
    private array $files  = [];
    private ?string $signature = null;

    public function __construct(private array $uploadCfg, private string $workDir)
    {
    }

    public function validate(array $post, array $filesInput): array
    {
        $this->validateScalars($post);
        $this->applyConditionalRules($post);
        $this->processUploads($filesInput);
        $this->processSignature($post);

        return [
            'ok'        => $this->errors === [],
            'errors'    => $this->errors,
            'data'      => $this->data,
            'files'     => $this->files,
            'signature' => $this->signature,
        ];
    }

    // ------------------------------------------------------------------ //
    // Scalar fields
    // ------------------------------------------------------------------ //
    private function validateScalars(array $post): void
    {
        foreach (FieldMap::fields() as $key => $spec) {
            $raw = $post[$key] ?? '';
            if (is_array($raw)) {
                $raw = '';
            }
            $rule = $spec['rule'];
            $max  = $spec['len'] ?? 200;
            $val  = Support::clean((string) $raw, $rule === 'textarea' ? 4000 : 300);
            if ($rule !== 'textarea') {
                $val = Support::oneLine($val);
            }

            $required = (bool) ($spec['required'] ?? false);

            // Empty handling (conditional requirements applied later).
            if ($val === '' && $rule !== 'checkbox') {
                if ($required) {
                    $this->fail($key, FieldMap::label($key) . ' is required.');
                }
                $this->data[$key] = '';
                continue;
            }

            switch ($rule) {
                case 'checkbox':
                    $this->data[$key] = ($raw === '1' || $raw === 'on' || $raw === 'true') ? '1' : '';
                    if ($required && $this->data[$key] !== '1') {
                        $this->fail($key, FieldMap::label($key) . ' must be acknowledged.');
                    }
                    break;

                case 'name':
                    if (!preg_match("/^[\p{L}][\p{L}\p{M}\s'.\-]{0,}$/u", $val)) {
                        $this->fail($key, FieldMap::label($key) . ' contains invalid characters.');
                    }
                    $this->store($key, $val, $max);
                    break;

                case 'text':
                case 'textarea':
                    $this->store($key, $val, $max);
                    break;

                case 'email':
                    $val = mb_strtolower($val);
                    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $this->fail($key, FieldMap::label($key) . ' is not a valid email address.');
                    }
                    $this->store($key, $val, 190);
                    break;

                case 'tel':
                    $d = Support::phoneDigits($val);
                    if (strlen($d) < 10 || strlen($d) > 15) {
                        $this->fail($key, FieldMap::label($key) . ' must be a valid phone number.');
                    }
                    $this->data[$key] = Support::formatPhone($val);
                    break;

                case 'postal':
                    $p = strtoupper(str_replace(' ', '', $val));
                    if (!preg_match('/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$/', $p)) {
                        $this->fail($key, 'Postal Code must be a valid Canadian code (e.g. K1A 0B1).');
                    }
                    $this->data[$key] = substr($p, 0, 3) . ' ' . substr($p, 3, 3);
                    break;

                case 'date':
                    if (!Support::parseDate($val)) {
                        $this->fail($key, FieldMap::label($key) . ' must be a valid date (YYYY-MM-DD).');
                    }
                    $this->data[$key] = $val;
                    break;

                case 'dob':
                    $dt = Support::parseDate($val);
                    if (!$dt) {
                        $this->fail($key, 'Date of Birth must be a valid date (YYYY-MM-DD).');
                    } else {
                        $now = new \DateTimeImmutable('today');
                        $age = (int) $dt->diff($now)->y;
                        if ($dt > $now) {
                            $this->fail($key, 'Date of Birth cannot be in the future.');
                        } elseif ($age < 14) {
                            $this->fail($key, 'Employees must be at least 14 years old.');
                        } elseif ($age > 100) {
                            $this->fail($key, 'Please check the Date of Birth.');
                        }
                    }
                    $this->data[$key] = $val;
                    break;

                case 'sin':
                    $d = preg_replace('/\D+/', '', $val) ?? '';
                    if (strlen($d) !== 9 || !Support::luhn($d)) {
                        $this->fail($key, 'Social Insurance Number must be a valid 9-digit SIN.');
                    }
                    // Store digits only; display formatting happens in the PDF.
                    $this->data[$key] = $d;
                    break;

                case 'digits':
                    $d = preg_replace('/\D+/', '', $val) ?? '';
                    $need = $spec['digits'];
                    $okLen = is_array($need)
                        ? (strlen($d) >= $need[0] && strlen($d) <= $need[1])
                        : (strlen($d) === $need);
                    if (!$okLen) {
                        $desc = is_array($need) ? "{$need[0]}–{$need[1]} digits" : "{$need} digits";
                        $this->fail($key, FieldMap::label($key) . " must be {$desc}.");
                    }
                    $this->data[$key] = $d;
                    break;

                case 'time':
                    if (Support::parseTime($val) === null) {
                        $this->fail($key, FieldMap::label($key) . ' must be a valid time (HH:MM).');
                    }
                    $this->data[$key] = $val;
                    break;

                case 'hours':
                    if (!is_numeric($val) || (float) $val < 0 || (float) $val > 168) {
                        // Never do arithmetic on a non-numeric string (PHP 8 TypeError).
                        $this->fail($key, 'Desired hours must be between 0 and 168.');
                        $this->data[$key] = '';
                    } else {
                        $this->data[$key] = (string) (0 + $val);
                    }
                    break;

                case 'select':
                    $opts = $spec['options'] ?? [];
                    if (!array_key_exists($val, $opts)) {
                        $this->fail($key, 'Please choose a valid ' . FieldMap::label($key) . '.');
                    }
                    $this->data[$key] = $val;
                    break;

                case 'yesno':
                    $v = strtolower($val);
                    if (!in_array($v, ['yes', 'no'], true)) {
                        $this->fail($key, 'Please answer ' . FieldMap::label($key) . '.');
                        $this->data[$key] = '';
                    } else {
                        $this->data[$key] = $v;
                    }
                    break;

                default:
                    $this->store($key, $val, $max);
            }
        }
    }

    // ------------------------------------------------------------------ //
    // Conditional (cross-field) requirements
    // ------------------------------------------------------------------ //
    private function applyConditionalRules(array $post): void
    {
        $today = new \DateTimeImmutable('today');

        // --- Pronouns: "other" requires the specify field -----------------
        if (($this->data['pronouns'] ?? '') === 'other') {
            if (($this->data['pronouns_other'] ?? '') === '') {
                $this->fail('pronouns_other', 'Please specify your pronouns.');
            }
        } else {
            $this->data['pronouns_other'] = '';
        }

        // --- Yes/No detail gates ------------------------------------------
        foreach ([
            ['trips_has', 'trips_details', 'Please describe your upcoming trips or time off.'],
            ['allergies_has', 'allergies_details', 'Please describe your allergies.'],
            ['medical_has', 'medical_details', 'Please describe your medical conditions.'],
        ] as [$has, $details, $msg]) {
            if (($this->data[$has] ?? '') === 'yes') {
                if (($this->data[$details] ?? '') === '') {
                    $this->fail($details, $msg);
                }
            } else {
                $this->data[$details] = '';
            }
        }

        // --- SIN beginning with 9 → permit block; IRCC only if expired -----
        $sin = $this->data['sin'] ?? '';
        $isNine = $sin !== '' && ($sin[0] ?? '') === '9';
        if ($isNine) {
            foreach (['sin_issued', 'sin_expiry', 'permit_type', 'permit_number', 'permit_issued', 'permit_expiry'] as $k) {
                if (($this->data[$k] ?? '') === '') {
                    $this->fail($k, FieldMap::label($k) . ' is required because your SIN begins with 9.');
                }
            }
            $exp = Support::parseDate($this->data['permit_expiry'] ?? '');
            $permitExpired = $exp !== null && $exp < $today;
            if ($permitExpired) {
                if (($this->data['ircc_letter_id'] ?? '') === '') {
                    $this->fail('ircc_letter_id', 'IRCC Letter ID is required because your permit is expired.');
                }
            } else {
                $this->data['ircc_letter_id'] = '';
            }
        } else {
            foreach (['sin_issued', 'sin_expiry', 'permit_type', 'permit_number', 'permit_issued', 'permit_expiry', 'ircc_letter_id'] as $k) {
                $this->data[$k] = '';
            }
        }

        // --- Government ID expiry required if the selected type renews ------
        $idType = $this->data['gov_doc_type'] ?? '';
        $renews = FieldMap::ID_TYPES[$idType]['renews'] ?? false;
        if ($renews) {
            $exp = Support::parseDate($this->data['gov_expiry_date'] ?? '');
            if (($this->data['gov_expiry_date'] ?? '') === '') {
                $this->fail('gov_expiry_date', 'Expiry Date is required for this ID type.');
            } elseif ($exp !== null && $exp < $today) {
                $this->fail('gov_expiry_date', 'This ID appears to be expired — please provide current, valid identification.');
            }
        } else {
            $this->data['gov_expiry_date'] = '';
        }

        // --- Direct deposit: bank/institution + confirm-account match ------
        $bank = $this->data['dd_bank'] ?? '';
        if ($bank === 'other') {
            if (($this->data['dd_bank_other'] ?? '') === '') {
                $this->fail('dd_bank_other', 'Please enter the name of your financial institution.');
            }
        } else {
            $this->data['dd_bank_other'] = '';
            $inst = FieldMap::BANKS[$bank]['institution'] ?? null;
            if ($inst !== null) {
                // Authoritative: set the canonical institution number server-side.
                $this->data['dd_institution_number'] = $inst;
                unset($this->errors['dd_institution_number']);
            }
        }
        if (($this->data['dd_account_number'] ?? '') !== '' && ($this->data['dd_account_confirm'] ?? '') !== ''
            && $this->data['dd_account_number'] !== $this->data['dd_account_confirm']) {
            $this->fail('dd_account_confirm', 'The account numbers do not match — please re-enter.');
        }

        // --- Availability: enabled day requires start<end; ≥1 day required --
        $anyDay = false;
        foreach (FieldMap::DAYS as $day => $label) {
            $on = ($this->data["avail_{$day}_enabled"] ?? '') === '1';
            if (!$on) {
                $this->data["avail_{$day}_start"] = '';
                $this->data["avail_{$day}_end"]   = '';
                continue;
            }
            $anyDay = true;
            $s = $this->data["avail_{$day}_start"] ?? '';
            $e = $this->data["avail_{$day}_end"] ?? '';
            if ($s === '' || $e === '') {
                $this->fail("avail_{$day}_start", "Enter a start and end time for {$label}, or unmark it.");
                continue;
            }
            $sm = Support::parseTime($s);
            $em = Support::parseTime($e);
            if ($sm !== null && $em !== null && $em <= $sm) {
                $this->fail("avail_{$day}_end", "{$label} end time must be after the start time.");
            }
        }
        if (!$anyDay) {
            $this->fail('availability', 'Please select at least one day of availability.');
        }

        // --- Certifications: Yes → require details+doc; age-gate Smart Serve -
        $age = $this->ageFromDob($this->data['date_of_birth'] ?? '');
        foreach (FieldMap::CERTS as $key => $meta) {
            if (!empty($meta['age_gated']) && $age !== null && $age < ($meta['min_age'] ?? 18)) {
                // Under the minimum age: skip this certification entirely.
                $this->data["{$key}_has"] = 'na';
                foreach ($this->certGroupKeys($key, $meta) as $k) {
                    $this->data[$k] = '';
                }
                continue;
            }
            if (($this->data["{$key}_has"] ?? '') === 'yes') {
                $req = ["{$key}_first_name", "{$key}_last_name", "{$key}_cert_id", "{$key}_issued", "{$key}_expiry"];
                if (!empty($meta['provider'])) {
                    $req[] = "{$key}_provider";
                }
                foreach ($req as $k) {
                    if (($this->data[$k] ?? '') === '') {
                        $this->fail($k, FieldMap::label($k) . ' is required.');
                    }
                }
            } else {
                foreach ($this->certGroupKeys($key, $meta) as $k) {
                    $this->data[$k] = '';
                }
            }
        }

        // --- Emergency contacts (repeatable) ------------------------------
        $this->validateContacts($post);
    }

    // ------------------------------------------------------------------ //
    // Emergency contacts (repeatable array: $post['contacts'][i][field])
    // ------------------------------------------------------------------ //
    private function validateContacts(array $post): void
    {
        $contacts = $post['contacts'] ?? [];
        if (!is_array($contacts)) {
            $contacts = [];
        }

        $ownPhones = array_filter([
            Support::phoneDigits($this->data['mobile_phone'] ?? ''),
            Support::phoneDigits($this->data['home_phone'] ?? ''),
            Support::phoneDigits($this->data['other_phone'] ?? ''),
        ]);
        $ownEmails = array_filter([
            mb_strtolower($this->data['primary_email'] ?? ''),
            mb_strtolower($this->data['secondary_email'] ?? ''),
        ]);

        $tmpl  = FieldMap::contactFields();
        $clean = [];
        $index = 0;

        foreach ($contacts as $c) {
            if (!is_array($c) || $index >= FieldMap::MAX_CONTACTS) {
                continue;
            }
            $isPrimary = $index === 0;

            $row = [];
            $anyData = false;
            foreach ($tmpl as $fk => $spec) {
                $raw = Support::oneLine(Support::clean((string) ($c[$fk] ?? ''), 200));
                [$cv, $err] = $this->checkValue($spec['rule'], $raw, $spec);
                if ($err !== null) {
                    $this->fail("contacts.{$index}.{$fk}", $spec['label'] . ' ' . $err . '.');
                }
                $row[$fk] = $cv;
                if ($cv !== '') {
                    $anyData = true;
                }
            }

            // A blank added row (not primary) is simply dropped.
            if (!$isPrimary && !$anyData) {
                continue;
            }

            $reqKeys = ['first_name', 'last_name', 'relationship', 'phone', 'phone_device', 'phone_location'];
            if ($isPrimary) {
                $reqKeys[] = 'email';
                $reqKeys[] = 'email_location';
            }
            foreach ($reqKeys as $rk) {
                if (($row[$rk] ?? '') === '') {
                    $this->fail("contacts.{$index}.{$rk}", $tmpl[$rk]['label'] . ' is required' . ($isPrimary ? ' for the primary contact' : '') . '.');
                }
            }
            if (($row['relationship'] ?? '') === 'other' && ($row['relationship_other'] ?? '') === '') {
                $this->fail("contacts.{$index}.relationship_other", 'Please specify the relationship.');
            }
            if (($row['email'] ?? '') !== '' && ($row['email_location'] ?? '') === '') {
                $this->fail("contacts.{$index}.email_location", 'Please choose Home or Work for the email.');
            }

            // Must not be the employee's own contact info.
            $cp = Support::phoneDigits($row['phone'] ?? '');
            if ($cp !== '' && in_array($cp, $ownPhones, true)) {
                $this->fail("contacts.{$index}.phone", "This matches your own phone number — please provide someone else's.");
            }
            $ce = mb_strtolower($row['email'] ?? '');
            if ($ce !== '' && in_array($ce, $ownEmails, true)) {
                $this->fail("contacts.{$index}.email", "This matches your own email — please provide someone else's.");
            }

            $clean[$index] = $row;
            $index++;
        }

        if ($index === 0) {
            $this->fail('contacts.0.first_name', 'At least one emergency contact is required.');
        }
        $this->data['contacts'] = $clean;
    }

    /** Lightweight value validator for repeatable fields. Returns [clean, ?error]. */
    private function checkValue(string $rule, string $val, array $spec): array
    {
        if ($val === '') {
            return ['', null];
        }
        switch ($rule) {
            case 'name':
                if (!preg_match("/^[\p{L}][\p{L}\p{M}\s'.\-]{0,}$/u", $val)) {
                    return [$val, 'contains invalid characters'];
                }
                return [mb_substr($val, 0, $spec['len'] ?? 80), null];
            case 'email':
                $v = mb_strtolower($val);
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [$v, null] : [$v, 'is not a valid email address'];
            case 'tel':
                $d = Support::phoneDigits($val);
                return (strlen($d) >= 10 && strlen($d) <= 15) ? [Support::formatPhone($val), null] : [$val, 'must be a valid phone number'];
            case 'select':
                $opts = $spec['options'] ?? [];
                return array_key_exists($val, $opts) ? [$val, null] : [$val, 'is not a valid choice'];
            default:
                return [mb_substr($val, 0, $spec['len'] ?? 100), null];
        }
    }

    private function ageFromDob(string $dob): ?int
    {
        $dt = Support::parseDate($dob);
        return $dt ? (int) $dt->diff(new \DateTimeImmutable('today'))->y : null;
    }

    private function certGroupKeys(string $key, array $meta): array
    {
        $keys = ["{$key}_first_name", "{$key}_middle_name", "{$key}_last_name", "{$key}_cert_id", "{$key}_issued", "{$key}_expiry"];
        if (!empty($meta['provider'])) {
            $keys[] = "{$key}_provider";
        }
        return $keys;
    }

    /** Effective (conditional) requiredness for a file field. */
    private function isFileRequired(string $field, array $meta): bool
    {
        $sin = $this->data['sin'] ?? '';
        $isNine = $sin !== '' && ($sin[0] ?? '') === '9';
        if ($field === 'permit_document') {
            return $isNine;
        }
        if ($field === 'ircc_document') {
            if (!$isNine) {
                return false;
            }
            $exp = Support::parseDate($this->data['permit_expiry'] ?? '');
            return $exp !== null && $exp < new \DateTimeImmutable('today');
        }
        foreach (FieldMap::CERTS as $ck => $_cm) {
            if ($field === "{$ck}_document") {
                return ($this->data["{$ck}_has"] ?? '') === 'yes';
            }
        }
        return (bool) $meta['required'];
    }

    // ------------------------------------------------------------------ //
    // Uploads
    // ------------------------------------------------------------------ //
    private function processUploads(array $filesInput): void
    {
        $perFile  = (int) $this->uploadCfg['max_bytes_per_file'];
        $total    = (int) $this->uploadCfg['max_bytes_total'];
        $running  = 0;

        foreach (FieldMap::FILES as $field => $meta) {
            $f = $filesInput[$field] ?? null;
            $required = $this->isFileRequired($field, $meta);

            if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $this->fail($field, $meta['label'] . ' is required.');
                }
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $this->fail($field, $meta['label'] . ' failed to upload (try a smaller file).');
                continue;
            }
            $tmp = (string) $f['tmp_name'];
            // In web context the file must be a genuine HTTP upload.
            if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
                $this->fail($field, $meta['label'] . ' upload could not be verified.');
                continue;
            }
            $size = (int) ($f['size'] ?? @filesize($tmp) ?: 0);
            if ($size <= 0) {
                $this->fail($field, $meta['label'] . ' appears to be empty.');
                continue;
            }
            if ($size > $perFile) {
                $this->fail($field, $meta['label'] . ' is too large (max ' . Support::bytesHuman($perFile) . ').');
                continue;
            }

            $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $allowed = $meta['kind'] === 'image'
                ? $this->uploadCfg['allowed_image_ext']
                : $this->uploadCfg['allowed_doc_ext'];
            if (!in_array($ext, $allowed, true)) {
                $this->fail($field, $meta['label'] . ' must be one of: ' . implode(', ', $allowed) . '.');
                continue;
            }

            $mime = $this->detectMime($tmp);
            $isPdf   = $mime === 'application/pdf';
            $isImage = str_starts_with($mime, 'image/') && in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true);

            if ($meta['kind'] === 'image' && !$isImage) {
                $this->fail($field, $meta['label'] . ' must be a real image (PNG or JPG).');
                continue;
            }
            if ($meta['kind'] === 'doc' && !$isPdf && !$isImage) {
                $this->fail($field, $meta['label'] . ' must be a PDF or image.');
                continue;
            }

            // Prepare the stored copy in the working directory.
            $prepared = $isPdf
                ? $this->storePdf($field, $tmp, $ext)
                : $this->storeImage($field, $tmp, $meta['kind']);

            if ($prepared === null) {
                $this->fail($field, $meta['label'] . ' could not be processed. Please re-save and try again.');
                continue;
            }

            $running += $prepared['size'];
            if ($running > $total) {
                @unlink($prepared['path']);
                $this->fail($field, 'The combined size of your uploaded documents exceeds ' . Support::bytesHuman($total) . '. Please upload smaller/compressed files.');
                continue;
            }

            $prepared['field'] = $field;
            $prepared['label'] = $meta['label'];
            $prepared['kind']  = $meta['kind'];
            $this->files[$field] = $prepared;
        }
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = finfo_file($fi, $path) ?: '';
                finfo_close($fi);
                if ($m !== '') {
                    return strtolower($m);
                }
            }
        }
        $info = @getimagesize($path);
        if ($info && !empty($info['mime'])) {
            return strtolower($info['mime']);
        }
        $head = (string) @file_get_contents($path, false, null, 0, 5);
        if (str_starts_with($head, '%PDF-')) {
            return 'application/pdf';
        }
        return 'application/octet-stream';
    }

    private function storePdf(string $field, string $tmp, string $ext): ?array
    {
        $head = (string) @file_get_contents($tmp, false, null, 0, 5);
        if (!str_starts_with($head, '%PDF-')) {
            return null;
        }
        $dest = $this->workDir . '/' . $field . '.pdf';
        if (!@copy($tmp, $dest)) {
            return null;
        }
        return ['path' => $dest, 'ext' => 'pdf', 'mime' => 'application/pdf', 'size' => (int) filesize($dest)];
    }

    private function storeImage(string $field, string $tmp, string $kind): ?array
    {
        if (!function_exists('imagecreatetruecolor')) {
            // GD unavailable: store the original bytes with the correct label.
            $info = @getimagesize($tmp);
            [$ext, $mime] = match ($info[2] ?? IMAGETYPE_JPEG) {
                IMAGETYPE_PNG => ['png', 'image/png'],
                IMAGETYPE_GIF => ['gif', 'image/gif'],
                default       => ['jpg', 'image/jpeg'],
            };
            $dest = $this->workDir . '/' . $field . '.' . $ext;
            if (!@copy($tmp, $dest)) {
                return null;
            }
            return ['path' => $dest, 'ext' => $ext, 'mime' => $mime, 'size' => (int) filesize($dest)];
        }

        $info = @getimagesize($tmp);
        if (!$info) {
            return null;
        }
        [$w, $h] = $info;
        $type = $info[2];
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
            default        => null,
        };
        if (!$src) {
            return null;
        }

        $maxDim  = (int) $this->uploadCfg['image_max_dimension'];
        $quality = (int) $this->uploadCfg['image_jpeg_quality'];
        $scale = min(1.0, $maxDim / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // Flatten transparency onto white for JPEG output.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $dest = $this->workDir . '/' . $field . '.jpg';
        $ok = imagejpeg($dst, $dest, $quality);
        imagedestroy($dst);
        if (!$ok) {
            return null;
        }
        return ['path' => $dest, 'ext' => 'jpg', 'mime' => 'image/jpeg', 'size' => (int) filesize($dest)];
    }

    // ------------------------------------------------------------------ //
    // Drawn signature (data URL → PNG in workDir)
    // ------------------------------------------------------------------ //
    private function processSignature(array $post): void
    {
        $raw = (string) ($post['signature'] ?? '');
        if ($raw === '') {
            $this->fail('signature', 'A signature is required.');
            return;
        }
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $raw, $m)) {
            $this->fail('signature', 'The signature could not be read. Please sign again.');
            return;
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 200 || strlen($bin) > 600 * 1024) {
            $this->fail('signature', 'The signature is invalid or too large. Please sign again.');
            return;
        }
        // PNG magic number.
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            $this->fail('signature', 'The signature is not a valid image. Please sign again.');
            return;
        }
        $dest = $this->workDir . '/signature.png';
        if (@file_put_contents($dest, $bin) === false) {
            $this->fail('signature', 'The signature could not be saved. Please try again.');
            return;
        }
        $this->signature = $dest;
    }

    // ------------------------------------------------------------------ //
    private function store(string $key, string $val, int $max): void
    {
        $this->data[$key] = mb_substr($val, 0, $max);
    }

    private function fail(string $key, string $msg): void
    {
        // Keep the first error per field.
        if (!isset($this->errors[$key])) {
            $this->errors[$key] = $msg;
        }
    }
}
