<?php

declare(strict_types=1);

namespace Legends;

/**
 * Builds the branded "Employee Information" summary PDF that mirrors the
 * Legends Global paper form. Extends the bundled FPDF with a small ellipse
 * add-on (for the logo mark) and a set of layout helpers.
 *
 * Usage:
 *   $path = (new PdfBuilder($data, $files, $signaturePath, $meta))->render($workDir);
 */
final class PdfBuilder extends \FPDF
{
    // Brand palette
    private const INK      = [17, 17, 17];
    private const GREY     = [120, 120, 120];
    private const BAR_BG   = [222, 222, 222];
    private const RULE     = [200, 200, 200];
    private const BOX_BG   = [247, 247, 247];

    /** Pastel day colours, matching the paper form. */
    private const DAY_COLORS = [
        'monday'    => [248, 203, 203],
        'tuesday'   => [230, 204, 240],
        'wednesday' => [204, 224, 245],
        'thursday'  => [204, 240, 240],
        'friday'    => [204, 240, 204],
        'saturday'  => [245, 240, 204],
        'sunday'    => [248, 221, 188],
    ];

    private float $usableW;

    public function __construct(
        private array $data,
        private array $files,
        private ?string $signaturePath,
        private array $meta
    ) {
        parent::__construct('P', 'mm', 'Letter');
        $this->SetMargins(14, 30, 14);
        $this->SetAutoPageBreak(true, 16);
        $this->SetTitle('Legends Global – Employee Information');
        $this->SetCreator('Legends Global Onboarding');
        $this->AliasNbPages();
        $this->usableW = $this->GetPageWidth() - 28;
    }

    public function render(string $workDir): string
    {
        $this->AddPage();

        $this->sectionBar('PERSONAL INFORMATION');
        $this->row([
            ['label' => 'First Name (Legal)', 'value' => $this->disp('first_name')],
            ['label' => 'Middle Name (Legal)', 'value' => $this->disp('middle_name')],
            ['label' => 'Last Name (Legal)', 'value' => $this->disp('last_name')],
        ]);
        $this->row([
            ['label' => 'Preferred Name', 'value' => $this->disp('preferred_name')],
            ['label' => 'Pronouns', 'value' => $this->dispPronouns()],
            ['label' => 'Date of Birth', 'value' => $this->disp('date_of_birth')],
        ]);
        $this->row([
            ['label' => 'Home Street Address', 'value' => $this->disp('street_address'), 'w' => 2],
            ['label' => 'Unit / Apt', 'value' => $this->disp('unit'), 'w' => 1],
        ]);
        $this->row([
            ['label' => 'City', 'value' => $this->disp('city')],
            ['label' => 'Province', 'value' => $this->disp('province')],
            ['label' => 'Postal Code', 'value' => $this->disp('postal_code')],
        ]);
        $this->row([
            ['label' => 'Mobile Phone', 'value' => $this->disp('mobile_phone')],
            ['label' => 'Home Phone', 'value' => $this->disp('home_phone')],
            ['label' => 'Other Phone', 'value' => $this->disp('other_phone')],
        ]);
        $this->row([
            ['label' => 'Primary Email', 'value' => $this->disp('primary_email')],
            ['label' => 'Secondary Email', 'value' => $this->disp('secondary_email')],
        ]);

        // Emergency contacts (repeatable)
        $this->sectionBar('EMERGENCY CONTACTS');
        $contacts = is_array($this->data['contacts'] ?? null) ? $this->data['contacts'] : [];
        if ($contacts === []) {
            $this->paragraph('No emergency contact provided.');
        }
        foreach ($contacts as $i => $c) {
            $this->subLabel(($i === 0 ? 'Primary Contact' : 'Additional Contact') . ' #' . ($i + 1));
            $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            $rel = FieldMap::RELATIONSHIPS[$c['relationship'] ?? ''] ?? '';
            if (($c['relationship'] ?? '') === 'other' && ($c['relationship_other'] ?? '') !== '') {
                $rel = $c['relationship_other'];
            }
            $this->row([
                ['label' => 'Name', 'value' => $name !== '' ? $name : '—'],
                ['label' => 'Relationship', 'value' => $rel !== '' ? $rel : '—'],
            ]);
            $phone = trim(($c['phone'] ?? '')
                . (($c['phone_device'] ?? '') ? ' (' . (FieldMap::PHONE_DEVICES[$c['phone_device']] ?? '') . ')' : '')
                . (($c['phone_location'] ?? '') ? ' · ' . (FieldMap::CONTACT_LOCATIONS[$c['phone_location']] ?? '') : ''));
            $email = trim(($c['email'] ?? '') . (($c['email_location'] ?? '') ? ' · ' . (FieldMap::CONTACT_LOCATIONS[$c['email_location']] ?? '') : ''));
            $this->row([
                ['label' => 'Phone', 'value' => $phone !== '' ? $phone : '—'],
                ['label' => 'Email', 'value' => $email !== '' ? $email : '—'],
            ]);
        }

        // Availability
        $this->sectionBar('GENERAL AVAILABILITY');
        $this->availabilityTable();
        $this->row([
            ['label' => 'Desired hours per week', 'value' => $this->disp('desired_hours')],
        ]);
        if ($this->disp('availability_comments') !== '—') {
            $this->block('Comments / Additional Information', $this->disp('availability_comments'));
        }
        $this->row([['label' => 'Upcoming trips / time off', 'value' => $this->yn('trips_has')]]);
        if (($this->data['trips_has'] ?? '') === 'yes') {
            $this->block('Trip / time-off details', $this->disp('trips_details'));
        }

        // Medical (yes/no + details)
        $this->sectionBar('MEDICAL AND SAFETY INFORMATION');
        $this->row([
            ['label' => 'Has allergies', 'value' => $this->yn('allergies_has')],
            ['label' => 'Has medical conditions', 'value' => $this->yn('medical_has')],
        ]);
        if (($this->data['allergies_has'] ?? '') === 'yes') {
            $this->block('Allergies', $this->disp('allergies_details'));
        }
        if (($this->data['medical_has'] ?? '') === 'yes') {
            $this->block('Medical Conditions', $this->disp('medical_details'));
        }

        // Work authorization
        $this->sectionBar('WORK AUTHORIZATION');
        $this->row([
            ['label' => 'Social Insurance Number', 'value' => $this->dispSin()],
            ['label' => 'SIN Issued Date', 'value' => $this->disp('sin_issued')],
            ['label' => 'SIN Expiry Date', 'value' => $this->disp('sin_expiry')],
        ]);
        if ((($this->data['sin'] ?? '')[0] ?? '') === '9') {
            $this->subLabel('Work / Study Permit (SIN begins with 9)');
            $this->row([
                ['label' => 'Permit Type', 'value' => FieldMap::PERMIT_TYPES[$this->data['permit_type'] ?? ''] ?? '—'],
                ['label' => 'Permit Number', 'value' => $this->disp('permit_number')],
            ]);
            $this->row([
                ['label' => 'Issued Date', 'value' => $this->disp('permit_issued')],
                ['label' => 'Expiry Date', 'value' => $this->disp('permit_expiry')],
            ]);
            if (($this->data['ircc_letter_id'] ?? '') !== '') {
                $this->row([['label' => 'IRCC Letter ID (permit expired)', 'value' => $this->disp('ircc_letter_id')]]);
            }
        }

        // Government ID
        $this->sectionBar('GOVERNMENT ISSUED IDENTIFICATION');
        $this->row([
            ['label' => 'First Name (Legal)', 'value' => $this->disp('gov_first_name')],
            ['label' => 'Middle Name (Legal)', 'value' => $this->disp('gov_middle_name')],
            ['label' => 'Last Name (Legal)', 'value' => $this->disp('gov_last_name')],
        ]);
        $this->row([
            ['label' => 'Document Type', 'value' => FieldMap::idTypeOptions()[$this->data['gov_doc_type'] ?? ''] ?? '—'],
            ['label' => 'Document ID Number', 'value' => $this->disp('gov_doc_number')],
            ['label' => 'Expiry Date', 'value' => $this->disp('gov_expiry_date')],
        ]);

        // Direct deposit
        $this->sectionBar('DIRECT DEPOSIT DETAILS');
        $this->row([
            ['label' => 'Financial Institution', 'value' => $this->dispBank()],
            ['label' => "Account Holder's Name", 'value' => $this->disp('dd_account_holder')],
        ]);
        $this->row([
            ['label' => 'Transit (5)', 'value' => $this->disp('dd_transit')],
            ['label' => 'Institution (3)', 'value' => $this->disp('dd_institution_number')],
            ['label' => 'Account Number', 'value' => $this->disp('dd_account_number')],
        ]);

        // Certifications — Yes/No per cert; details when held.
        $this->sectionBar('CERTIFICATIONS');
        foreach (FieldMap::CERTS as $key => $cmeta) {
            $has = $this->data["{$key}_has"] ?? '';
            if ($has === 'yes') {
                $this->subLabel($cmeta['label'] . ' — Yes');
                $cells = [
                    ['label' => 'Name', 'value' => $this->certName($key)],
                    ['label' => 'Certificate ID', 'value' => $this->disp("{$key}_cert_id")],
                    ['label' => 'Issued', 'value' => $this->disp("{$key}_issued")],
                    ['label' => 'Expiry', 'value' => $this->disp("{$key}_expiry")],
                ];
                $this->row($cells);
                if ($cmeta['provider']) {
                    $this->row([['label' => 'Training Provider', 'value' => $this->disp("{$key}_provider")]]);
                }
            } else {
                $label = $has === 'no' ? 'No' : ($has === 'na' ? 'Not applicable (under 18)' : '—');
                $this->row([['label' => $cmeta['label'], 'value' => $label]]);
            }
        }

        // Attachments summary
        $this->sectionBar('ATTACHED DOCUMENTS');
        $this->attachmentsList();

        // Headshot
        if (isset($this->files['headshot'])) {
            $this->sectionBar('HEADSHOT PHOTOGRAPH');
            $this->embedImage($this->files['headshot']['path'], 40);
        }

        // Declaration
        $this->sectionBar('DECLARATION & COMMUNICATION CONSENT');
        $this->paragraph(
            'The employee certified that all information provided is true, complete, and accurate to the best '
            . 'of their knowledge, and acknowledged responsibility to notify Human Resources of any changes to '
            . 'their personal, banking, work-authorization, contact, or certification information.'
        );
        $this->row([
            ['label' => 'Privacy Notice consent', 'value' => $this->yesNo('privacy_ack')],
            ['label' => 'Declaration acknowledged', 'value' => $this->yesNo('declaration_ack')],
            ['label' => 'Electronic comms consent', 'value' => $this->yesNo('comms_consent')],
        ]);
        $this->row([
            ['label' => 'Preferred Method of Contact', 'value' => $this->dispContact()],
            ['label' => 'Employee Name (typed)', 'value' => $this->disp('employee_name')],
            ['label' => 'Date Signed', 'value' => $this->disp('signature_date')],
        ]);
        $this->signatureBlock();

        $out = rtrim($workDir, '/') . '/employee-information.pdf';
        $this->Output('F', $out);
        return $out;
    }

    // ================================================================== //
    //  Page furniture
    // ================================================================== //
    public function Header(): void
    {
        // Logo ring
        $cx = 19.5;
        $cy = 16;
        $this->SetDrawColor(...self::INK);
        $this->SetFillColor(...self::INK);
        $this->Ellipse($cx, $cy, 5.4, 5.4, 'F');
        $this->SetFillColor(255, 255, 255);
        $this->Ellipse($cx, $cy, 3.1, 3.1, 'F');
        $this->SetFillColor(...self::INK);
        $this->Ellipse($cx + 3.4, $cy - 3.4, 1.2, 1.2, 'F'); // little accent dot

        // Wordmark
        $this->SetXY(27, 11);
        $this->SetTextColor(...self::INK);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell(0, 5, 'LEGENDS', 0, 2);
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(0, 4, 'G L O B A L', 0, 0);

        // Title
        $this->SetXY(14, 10);
        $this->SetFont('Helvetica', 'B', 20);
        $this->Cell($this->usableW, 12, 'EMPLOYEE INFORMATION', 0, 0, 'R');

        // Sub line
        $this->SetXY(14, 22.5);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::GREY);
        $ref = (string) ($this->meta['reference'] ?? '');
        $ts  = (string) ($this->meta['timestamp'] ?? '');
        $this->Cell($this->usableW, 4, 'CONFIDENTIAL – New Hire Submission', 0, 0, 'L');
        $this->Cell(0, 4, trim("Ref {$ref}   {$ts}"), 0, 0, 'R');

        $this->SetDrawColor(...self::INK);
        $this->SetLineWidth(0.5);
        $this->Line(14, 27, 14 + $this->usableW, 27);
        $this->SetLineWidth(0.2);
        $this->SetTextColor(...self::INK);
        $this->SetY(31);
    }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::GREY);
        $this->Cell($this->usableW / 2, 5, 'Legends Global – Confidential employee record', 0, 0, 'L');
        $this->Cell($this->usableW / 2, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        $this->SetTextColor(...self::INK);
    }

    // ================================================================== //
    //  Layout helpers
    // ================================================================== //
    private function sectionBar(string $title): void
    {
        $this->ensure(11);
        $this->Ln(1.5);
        $y = $this->GetY();
        $this->SetFillColor(...self::BAR_BG);
        $this->Rect(14, $y, $this->usableW, 6.4, 'F');
        $this->SetXY(16, $y + 0.4);
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetTextColor(...self::INK);
        $this->Cell($this->usableW - 4, 5.6, $title, 0, 0, 'L');
        $this->SetY($y + 6.4 + 2);
    }

    private function subLabel(string $text): void
    {
        $this->ensure(7);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(...self::INK);
        $this->Cell(0, 5, $text, 0, 1, 'L');
        $this->SetY($this->GetY() + 0.5);
    }

    /** Render a row of fields, splitting usable width by each cell's weight. */
    private function row(array $cells, float $h = 10.0): void
    {
        $this->ensure($h);
        $y = $this->GetY();
        $totalWeight = 0.0;
        foreach ($cells as $c) {
            $totalWeight += (float) ($c['w'] ?? 1);
        }
        $x = 14.0;
        foreach ($cells as $c) {
            $w = $this->usableW * (((float) ($c['w'] ?? 1)) / $totalWeight);
            $this->fieldAt($x, $y, $w - 5, (string) $c['label'], (string) $c['value']);
            $x += $w;
        }
        $this->SetY($y + $h);
    }

    private function fieldAt(float $x, float $y, float $w, string $label, string $value): void
    {
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...self::GREY);
        $this->Cell($w, 3.2, strtoupper($label), 0, 0, 'L');

        $this->SetXY($x, $y + 3.4);
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(...self::INK);
        $this->Cell($w, 5, $this->fit($value, $w, 10), 0, 0, 'L');

        $this->SetDrawColor(...self::RULE);
        $this->Line($x, $y + 8.8, $x + $w, $y + 8.8);
        $this->SetDrawColor(...self::INK);
    }

    /** Full-width labelled block (for multi-line text). */
    private function block(string $label, string $value): void
    {
        if ($value === '' || $value === '—') {
            $value = '—';
        }
        $this->SetFont('Helvetica', '', 10);
        $lines = max(1, $this->countLines($value, $this->usableW - 6, 10));
        $boxH  = 6 + $lines * 4.6;
        $this->ensure($boxH + 6);

        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...self::GREY);
        $this->Cell(0, 4, strtoupper($label), 0, 1, 'L');

        $y = $this->GetY();
        $this->SetFillColor(...self::BOX_BG);
        $this->SetDrawColor(...self::RULE);
        $this->Rect(14, $y, $this->usableW, $boxH, 'DF');
        $this->SetXY(17, $y + 2.5);
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(...self::INK);
        $this->MultiCell($this->usableW - 6, 4.6, $value, 0, 'L');
        $this->SetDrawColor(...self::INK);
        $this->SetY($y + $boxH + 2.5);
    }

    private function paragraph(string $text): void
    {
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(...self::GREY);
        $this->MultiCell($this->usableW, 4.2, $text, 0, 'L');
        $this->SetTextColor(...self::INK);
        $this->Ln(1.5);
    }

    private function availabilityTable(): void
    {
        $this->ensure(8);
        $rowH = 7.5;
        foreach (FieldMap::DAYS as $day => $label) {
            $this->ensure($rowH);
            $y = $this->GetY();
            $on = ($this->data["avail_{$day}_enabled"] ?? '') === '1';
            $start = $this->data["avail_{$day}_start"] ?? '';
            $end   = $this->data["avail_{$day}_end"] ?? '';

            // Day chip
            $this->SetFillColor(...self::DAY_COLORS[$day]);
            $this->SetDrawColor(...self::RULE);
            $this->Rect(14, $y, 40, $rowH - 1.5, 'DF');
            $this->SetXY(14, $y);
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(...self::INK);
            $this->Cell(40, $rowH - 1.5, ' ' . $label, 0, 0, 'L');

            // Availability text
            $this->SetXY(58, $y);
            $this->SetFont('Helvetica', '', 9.5);
            if ($on && $start !== '' && $end !== '') {
                $this->SetTextColor(...self::INK);
                $this->Cell(0, $rowH - 1.5, "{$start}  –  {$end}", 0, 0, 'L');
            } else {
                $this->SetTextColor(...self::GREY);
                $this->Cell(0, $rowH - 1.5, 'Not available', 0, 0, 'L');
            }
            $this->SetY($y + $rowH);
        }
        $this->SetTextColor(...self::INK);
        $this->Ln(1);
    }

    private function attachmentsList(): void
    {
        if ($this->files === []) {
            $this->paragraph('No documents were attached.');
            return;
        }
        $this->SetFont('Helvetica', '', 9.5);
        foreach ($this->files as $f) {
            $this->ensure(5.5);
            $line = sprintf(
                '•  %s  —  %s (%s)',
                $f['label'],
                strtoupper($f['ext']),
                Support::bytesHuman((int) $f['size'])
            );
            $this->SetTextColor(...self::INK);
            $this->Cell(0, 5, $line, 0, 1, 'L');
        }
        $this->Ln(1);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(...self::GREY);
        $this->MultiCell($this->usableW, 4, 'The listed documents are included as separate files inside this encrypted package.', 0, 'L');
        $this->SetTextColor(...self::INK);
    }

    private function signatureBlock(): void
    {
        $this->ensure(26);
        $y = $this->GetY() + 2;
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...self::GREY);
        $this->SetXY(14, $y);
        $this->Cell(0, 3.2, 'SIGNATURE', 0, 1, 'L');
        $y += 3.6;
        if ($this->signaturePath && is_file($this->signaturePath)) {
            $info = @getimagesize($this->signaturePath);
            $w = 60;
            $h = 20;
            if ($info && $info[0] > 0) {
                $h = min(20, $w * ($info[1] / $info[0]));
            }
            $this->Image($this->signaturePath, 14, $y, $w, 0, 'PNG');
            $this->SetY($y + $h + 1);
        }
        $this->SetDrawColor(...self::INK);
        $this->Line(14, $this->GetY(), 14 + 70, $this->GetY());
        $this->SetY($this->GetY() + 1);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::GREY);
        $this->Cell(0, 4, $this->disp('employee_name') . '   ·   ' . $this->disp('signature_date'), 0, 1, 'L');
        $this->SetTextColor(...self::INK);
    }

    private function embedImage(string $path, float $w): void
    {
        if (!is_file($path)) {
            return;
        }
        $info = @getimagesize($path);
        $h = $info && $info[0] > 0 ? $w * ($info[1] / $info[0]) : $w;
        $this->ensure($h + 4);
        $type = ($info && ($info[2] ?? 0) === IMAGETYPE_PNG) ? 'PNG' : 'JPG';
        $this->Image($path, 14, $this->GetY(), $w, 0, $type);
        $this->SetY($this->GetY() + $h + 3);
    }

    // ================================================================== //
    //  UTF-8 → Windows-1252 bridge (FPDF core fonts are cp1252)
    // ================================================================== //
    private function tx(string $s): string
    {
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
            if ($out !== false) {
                return $out;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            if ($out !== false) {
                return $out;
            }
        }
        return $s;
    }

    // phpcs:disable
    function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        parent::Cell($w, $h, $this->tx((string) $txt), $border, $ln, $align, $fill, $link);
    }

    function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        parent::MultiCell($w, $h, $this->tx((string) $txt), $border, $align, $fill);
    }
    // phpcs:enable

    // ================================================================== //
    //  Value formatting
    // ================================================================== //
    private function disp(string $key): string
    {
        $v = trim((string) ($this->data[$key] ?? ''));
        return $v === '' ? '—' : $v;
    }

    private function dispSin(): string
    {
        $d = (string) ($this->data['sin'] ?? '');
        if (strlen($d) === 9) {
            return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6, 3);
        }
        return $d === '' ? '—' : $d;
    }

    private function dispContact(): string
    {
        $v = (string) ($this->data['preferred_contact'] ?? '');
        return FieldMap::CONTACT_METHODS[$v] ?? '—';
    }

    private function yesNo(string $key): string
    {
        return ($this->data[$key] ?? '') === '1' ? 'Yes' : 'No';
    }

    /** Yes/No/— for a 'yesno' field. */
    private function yn(string $key): string
    {
        $v = $this->data[$key] ?? '';
        return $v === 'yes' ? 'Yes' : ($v === 'no' ? 'No' : '—');
    }

    private function dispPronouns(): string
    {
        $v = (string) ($this->data['pronouns'] ?? '');
        if ($v === 'other') {
            $o = trim((string) ($this->data['pronouns_other'] ?? ''));
            return $o !== '' ? $o : 'Other';
        }
        return $v !== '' ? (FieldMap::PRONOUNS[$v] ?? $v) : '—';
    }

    private function dispBank(): string
    {
        $v = (string) ($this->data['dd_bank'] ?? '');
        if ($v === 'other') {
            $o = trim((string) ($this->data['dd_bank_other'] ?? ''));
            return $o !== '' ? $o : 'Other';
        }
        return $v !== '' ? (FieldMap::BANKS[$v]['name'] ?? $v) : '—';
    }

    private function certName(string $key): string
    {
        $parts = array_filter([
            $this->data["{$key}_first_name"] ?? '',
            $this->data["{$key}_middle_name"] ?? '',
            $this->data["{$key}_last_name"] ?? '',
        ]);
        $n = trim(implode(' ', $parts));
        return $n === '' ? '—' : $n;
    }

    // Truncate a value to fit a cell width at a given font size.
    private function fit(string $s, float $w, float $size): string
    {
        if ($s === '') {
            return '';
        }
        $this->SetFontSize($size);
        if ($this->GetStringWidth($this->tx($s)) <= $w) {
            return $s;
        }
        while ($s !== '' && $this->GetStringWidth($this->tx($s . '…')) > $w) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '…';
    }

    /**
     * Count how many lines FPDF's MultiCell will use for $text at width $w.
     * Mirrors FPDF's wrapping (break at last space, or force-break mid-token
     * when a single word is wider than the line) so the box is never
     * undersized by a long unbroken token.
     */
    private function countLines(string $text, float $w, float $size): int
    {
        $this->SetFontSize($size);
        $total = 0;
        foreach (explode("\n", str_replace("\r", '', $text)) as $para) {
            $s = $this->tx($para); // Windows-1252, single-byte — safe to index
            $n = strlen($s);
            if ($n === 0) {
                $total++;
                continue;
            }
            $sep = -1;
            $i = 0;
            $j = 0;
            $len = 0.0;
            $lineCount = 1;
            while ($i < $n) {
                $c = $s[$i];
                if ($c === ' ') {
                    $sep = $i;
                }
                $len += $this->GetStringWidth($c);
                if ($len > $w) {
                    if ($sep === -1) {
                        if ($i === $j) {
                            $i++;
                        }
                    } else {
                        $i = $sep + 1;
                    }
                    $sep = -1;
                    $j = $i;
                    $len = 0.0;
                    $lineCount++;
                } else {
                    $i++;
                }
            }
            $total += $lineCount;
        }
        return max(1, $total);
    }

    private function ensure(float $need): void
    {
        if ($this->GetY() + $need > $this->GetPageHeight() - 16) {
            $this->AddPage();
        }
    }

    // ================================================================== //
    //  FPDF ellipse/circle add-on (public-domain, Olivier Plathey)
    // ================================================================== //
    private function Ellipse(float $x, float $y, float $rx, float $ry, string $style = 'D'): void
    {
        $op = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx;
        $ly = 4 / 3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k;
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k, ($h - $y) * $k,
            ($x + $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x + $lx) * $k, ($h - ($y - $ry)) * $k,
            $x * $k, ($h - ($y - $ry)) * $k
        ));
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k, ($h - ($y - $ry)) * $k,
            ($x - $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x - $rx) * $k, ($h - $y) * $k
        ));
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x - $lx) * $k, ($h - ($y + $ry)) * $k,
            $x * $k, ($h - ($y + $ry)) * $k
        ));
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x + $lx) * $k, ($h - ($y + $ry)) * $k,
            ($x + $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x + $rx) * $k, ($h - $y) * $k,
            $op
        ));
    }
}
