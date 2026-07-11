<?php

declare(strict_types=1);

namespace Legends;

/**
 * Plain-text summary of a submission (one "Field: value" per line), for easy
 * copy-paste into Workday. Included in the encrypted package alongside the PDF.
 */
final class TextBuilder
{
    public function __construct(private array $data, private array $files, private array $meta)
    {
    }

    public function build(): string
    {
        $L = [];
        $L[] = 'LEGENDS GLOBAL — EMPLOYEE INFORMATION';
        $L[] = 'Reference: ' . ($this->meta['reference'] ?? '') . '   Submitted: ' . ($this->meta['timestamp'] ?? '');
        $L[] = str_repeat('=', 60);

        $this->section($L, 'PERSONAL INFORMATION');
        $this->kv($L, 'First Name (Legal)', $this->v('first_name'));
        $this->kv($L, 'Middle Name (Legal)', $this->v('middle_name'));
        $this->kv($L, 'Last Name (Legal)', $this->v('last_name'));
        $this->kv($L, 'Preferred Name', $this->v('preferred_name'));
        $this->kv($L, 'Pronouns', $this->pronouns());
        $this->kv($L, 'Date of Birth', $this->v('date_of_birth'));
        $this->kv($L, 'Street Address', $this->v('street_address'));
        $this->kv($L, 'Unit / Apartment', $this->v('unit'));
        $this->kv($L, 'City', $this->v('city'));
        $this->kv($L, 'Province', $this->v('province'));
        $this->kv($L, 'Postal Code', $this->v('postal_code'));
        $this->kv($L, 'Mobile Phone', $this->v('mobile_phone'));
        $this->kv($L, 'Home Phone', $this->v('home_phone'));
        $this->kv($L, 'Other Phone', $this->v('other_phone'));
        $this->kv($L, 'Primary Email', $this->v('primary_email'));
        $this->kv($L, 'Secondary Email', $this->v('secondary_email'));

        $this->section($L, 'EMERGENCY CONTACTS');
        $contacts = is_array($this->data['contacts'] ?? null) ? $this->data['contacts'] : [];
        foreach ($contacts as $i => $c) {
            $L[] = '-- Contact #' . ($i + 1) . ($i === 0 ? ' (Primary)' : '');
            $this->kv($L, 'Name', trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            $rel = FieldMap::RELATIONSHIPS[$c['relationship'] ?? ''] ?? '';
            if (($c['relationship'] ?? '') === 'other') {
                $rel = $c['relationship_other'] ?? $rel;
            }
            $this->kv($L, 'Relationship', $rel);
            $this->kv($L, 'Phone', ($c['phone'] ?? '') . $this->tag($c, 'phone_device', 'phone_location'));
            $this->kv($L, 'Email', ($c['email'] ?? '') . $this->tag($c, null, 'email_location'));
        }

        $this->section($L, 'AVAILABILITY');
        foreach (FieldMap::DAYS as $day => $label) {
            if (($this->data["avail_{$day}_enabled"] ?? '') === '1') {
                $this->kv($L, $label, ($this->data["avail_{$day}_start"] ?? '') . ' - ' . ($this->data["avail_{$day}_end"] ?? ''));
            }
        }
        $this->kv($L, 'Desired hours/week', $this->v('desired_hours'));
        $this->kv($L, 'Comments', $this->v('availability_comments'));
        $this->kv($L, 'Upcoming trips / time off', $this->yn('trips_has'));
        if (($this->data['trips_has'] ?? '') === 'yes') {
            $this->kv($L, 'Trip details', $this->v('trips_details'));
        }

        $this->section($L, 'MEDICAL & SAFETY');
        $this->kv($L, 'Has allergies', $this->yn('allergies_has'));
        if (($this->data['allergies_has'] ?? '') === 'yes') {
            $this->kv($L, 'Allergies', $this->v('allergies_details'));
        }
        $this->kv($L, 'Has medical conditions', $this->yn('medical_has'));
        if (($this->data['medical_has'] ?? '') === 'yes') {
            $this->kv($L, 'Medical conditions', $this->v('medical_details'));
        }

        $this->section($L, 'WORK AUTHORIZATION');
        $sin = (string) ($this->data['sin'] ?? '');
        $this->kv($L, 'SIN', strlen($sin) === 9 ? substr($sin, 0, 3) . '-' . substr($sin, 3, 3) . '-' . substr($sin, 6, 3) : $sin);
        if (($sin[0] ?? '') === '9') {
            $this->kv($L, 'SIN Issued', $this->v('sin_issued'));
            $this->kv($L, 'SIN Expiry', $this->v('sin_expiry'));
            $this->kv($L, 'Permit Type', FieldMap::PERMIT_TYPES[$this->data['permit_type'] ?? ''] ?? '');
            $this->kv($L, 'Permit Number', $this->v('permit_number'));
            $this->kv($L, 'Permit Issued', $this->v('permit_issued'));
            $this->kv($L, 'Permit Expiry', $this->v('permit_expiry'));
            if (($this->data['ircc_letter_id'] ?? '') !== '') {
                $this->kv($L, 'IRCC Letter ID', $this->v('ircc_letter_id'));
            }
        }

        $this->section($L, 'GOVERNMENT ID');
        $this->kv($L, 'Name on ID', trim(($this->data['gov_first_name'] ?? '') . ' ' . ($this->data['gov_middle_name'] ?? '') . ' ' . ($this->data['gov_last_name'] ?? '')));
        $this->kv($L, 'Document Type', FieldMap::idTypeOptions()[$this->data['gov_doc_type'] ?? ''] ?? '');
        $this->kv($L, 'Document ID Number', $this->v('gov_doc_number'));
        $this->kv($L, 'Expiry Date', $this->v('gov_expiry_date'));

        $this->section($L, 'DIRECT DEPOSIT');
        $bank = (string) ($this->data['dd_bank'] ?? '');
        $this->kv($L, 'Financial Institution', $bank === 'other' ? ($this->data['dd_bank_other'] ?? '') : (FieldMap::BANKS[$bank]['name'] ?? ''));
        $this->kv($L, "Account Holder's Name", $this->v('dd_account_holder'));
        $this->kv($L, 'Transit Number', $this->v('dd_transit'));
        $this->kv($L, 'Institution Number', $this->v('dd_institution_number'));
        $this->kv($L, 'Account Number', $this->v('dd_account_number'));

        $this->section($L, 'CERTIFICATIONS');
        foreach (FieldMap::CERTS as $key => $meta) {
            $has = $this->data["{$key}_has"] ?? '';
            if ($has === 'yes') {
                $this->kv($L, $meta['label'], 'Yes');
                $this->kv($L, '  Name', trim(($this->data["{$key}_first_name"] ?? '') . ' ' . ($this->data["{$key}_last_name"] ?? '')));
                $this->kv($L, '  Certificate ID', $this->v("{$key}_cert_id"));
                $this->kv($L, '  Issued', $this->v("{$key}_issued"));
                $this->kv($L, '  Expiry', $this->v("{$key}_expiry"));
                if (!empty($meta['provider'])) {
                    $this->kv($L, '  Training Provider', $this->v("{$key}_provider"));
                }
            } else {
                $this->kv($L, $meta['label'], $has === 'na' ? 'N/A (under 18)' : ($has === 'no' ? 'No' : '—'));
            }
        }

        $this->section($L, 'DECLARATION');
        $this->kv($L, 'Preferred Method of Contact', FieldMap::CONTACT_METHODS[$this->data['preferred_contact'] ?? ''] ?? '');
        $this->kv($L, 'Privacy consent', ($this->data['privacy_ack'] ?? '') === '1' ? 'Yes' : 'No');
        $this->kv($L, 'Declaration acknowledged', ($this->data['declaration_ack'] ?? '') === '1' ? 'Yes' : 'No');
        $this->kv($L, 'Electronic comms consent', ($this->data['comms_consent'] ?? '') === '1' ? 'Yes' : 'No');
        $this->kv($L, 'Employee Name (typed)', $this->v('employee_name'));
        $this->kv($L, 'Date Signed', $this->v('signature_date'));

        $this->section($L, 'ATTACHED DOCUMENTS');
        foreach ($this->files as $f) {
            $L[] = '  - ' . $f['label'] . ' (' . strtoupper($f['ext']) . ')';
        }

        return implode("\r\n", $L) . "\r\n";
    }

    private function section(array &$L, string $title): void
    {
        $L[] = '';
        $L[] = $title;
        $L[] = str_repeat('-', 60);
    }

    private function kv(array &$L, string $k, string $v): void
    {
        $L[] = $k . ': ' . ($v === '' ? '—' : $v);
    }

    private function v(string $key): string
    {
        return trim((string) ($this->data[$key] ?? ''));
    }

    private function yn(string $key): string
    {
        $v = $this->data[$key] ?? '';
        return $v === 'yes' ? 'Yes' : ($v === 'no' ? 'No' : '—');
    }

    private function pronouns(): string
    {
        $v = (string) ($this->data['pronouns'] ?? '');
        if ($v === 'other') {
            return (string) ($this->data['pronouns_other'] ?? 'Other');
        }
        return $v !== '' ? (FieldMap::PRONOUNS[$v] ?? $v) : '';
    }

    private function tag(array $c, ?string $deviceKey, string $locKey): string
    {
        $bits = [];
        if ($deviceKey && ($c[$deviceKey] ?? '')) {
            $bits[] = FieldMap::PHONE_DEVICES[$c[$deviceKey]] ?? '';
        }
        if ($c[$locKey] ?? '') {
            $bits[] = FieldMap::CONTACT_LOCATIONS[$c[$locKey]] ?? '';
        }
        return $bits ? ' (' . implode(', ', array_filter($bits)) . ')' : '';
    }
}
