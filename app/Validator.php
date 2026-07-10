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
        // --- SIN beginning with 9 → temporary resident permit block -------
        $sin = $this->data['sin'] ?? '';
        if ($sin !== '' && $sin[0] === '9') {
            foreach (['sin_expiry', 'permit_number', 'permit_issued', 'permit_expiry'] as $k) {
                if (($this->data[$k] ?? '') === '') {
                    $this->fail($k, FieldMap::label($k) . ' is required because your SIN begins with 9.');
                }
            }
        } else {
            // Not a 9-series SIN: clear the permit block so it is never emailed.
            foreach (['sin_expiry', 'permit_number', 'permit_issued', 'permit_expiry', 'ircc_letter_id', 'permit_restrictions'] as $k) {
                $this->data[$k] = '';
            }
        }

        // --- Availability: enabled day requires valid start < end ----------
        foreach (FieldMap::DAYS as $day => $label) {
            $on = ($this->data["avail_{$day}_enabled"] ?? '') === '1';
            if (!$on) {
                $this->data["avail_{$day}_start"] = '';
                $this->data["avail_{$day}_end"]   = '';
                continue;
            }
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

        // --- Emergency contact 2: partial → require name + phone -----------
        $ec2 = ['ec2_name', 'ec2_relationship', 'ec2_phone', 'ec2_email'];
        $ec2HasData = false;
        foreach ($ec2 as $k) {
            if (($this->data[$k] ?? '') !== '') {
                $ec2HasData = true;
            }
        }
        if ($ec2HasData) {
            foreach (['ec2_name', 'ec2_phone'] as $k) {
                if (($this->data[$k] ?? '') === '') {
                    $this->fail($k, FieldMap::label($k) . ' is required for the secondary contact.');
                }
            }
        }

        // --- Certifications: N/A clears; otherwise partial data requires
        //     the core fields.
        foreach (FieldMap::CERTS as $key => $meta) {
            $na = ($this->data["{$key}_not_applicable"] ?? '') === '1';
            $groupKeys = ["{$key}_first_name", "{$key}_middle_name", "{$key}_last_name", "{$key}_cert_id", "{$key}_issued", "{$key}_expiry"];
            if ($meta['provider']) {
                $groupKeys[] = "{$key}_provider";
            }

            if ($na) {
                foreach ($groupKeys as $k) {
                    $this->data[$k] = '';
                }
                continue;
            }

            $hasData = false;
            foreach ($groupKeys as $k) {
                if (($this->data[$k] ?? '') !== '') {
                    $hasData = true;
                }
            }
            if (!$hasData) {
                // Whole section left blank and not marked N/A → treat as skipped.
                continue;
            }
            // Section is being provided → require identifying core fields.
            $req = ["{$key}_last_name", "{$key}_cert_id", "{$key}_issued"];
            if ($meta['provider']) {
                $req[] = "{$key}_provider";
            }
            foreach ($req as $k) {
                if (($this->data[$k] ?? '') === '') {
                    $this->fail($k, FieldMap::label($k) . ' is required (or mark this certification Not Applicable).');
                }
            }
        }
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
            $required = (bool) $meta['required'];

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
