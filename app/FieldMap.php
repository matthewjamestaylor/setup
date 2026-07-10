<?php

declare(strict_types=1);

namespace Legends;

/**
 * Canonical definition of every field on the Employee Information form.
 *
 * This is the single source of truth shared by:
 *   - the Validator (server-side rules + conditional-required logic)
 *   - the PdfBuilder (ordered, section-by-section rendering)
 *
 * The public HTML form (public/index.php) is authored by hand for full design
 * control, but its `name` attributes match the keys defined here.
 */
final class FieldMap
{
    /** Days of the week for the availability grid (key => display label). */
    public const DAYS = [
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
    ];

    /** Preferred method of contact options. */
    public const CONTACT_METHODS = [
        'email' => 'Email',
        'text'  => 'Text Message',
        'phone' => 'Phone Call',
    ];

    /**
     * "If Applicable" certification groups.
     *   provider => whether the section has a "Training Provider" field.
     */
    public const CERTS = [
        'smartserve' => ['label' => 'Smart Serve Certification',          'provider' => false],
        'foodsafety' => ['label' => 'Food Safety Certification',          'provider' => true],
        'jhsc1'      => ['label' => 'Joint Health & Safety – Level 1',     'provider' => true],
        'jhsc2'      => ['label' => 'Joint Health & Safety – Level 2',     'provider' => true],
    ];

    /** File-upload fields: key => [label, kind(image|doc), required]. */
    public const FILES = [
        'gov_document' => ['label' => 'Government ID (scan/photo)',        'kind' => 'doc',   'required' => true],
        'dd_document'  => ['label' => 'Void cheque / direct deposit form', 'kind' => 'doc',   'required' => false],
        'headshot'     => ['label' => 'Headshot photograph',              'kind' => 'image', 'required' => true],
        'smartserve_document' => ['label' => 'Smart Serve certificate',   'kind' => 'doc',   'required' => false],
        'foodsafety_document' => ['label' => 'Food Safety certificate',   'kind' => 'doc',   'required' => false],
        'jhsc1_document' => ['label' => 'JHSC Level 1 certificate',        'kind' => 'doc',   'required' => false],
        'jhsc2_document' => ['label' => 'JHSC Level 2 certificate',        'kind' => 'doc',   'required' => false],
    ];

    /**
     * Flat map of every text/scalar field: key => rule spec.
     *   label    – human label (used in the PDF)
     *   rule     – validation type
     *   required – base requiredness (conditional rules live in Validator)
     *   len      – max length (optional)
     *   digits   – exact digit count (optional)
     *   options  – for 'select'
     */
    public static function fields(): array
    {
        $f = [
            // --- Consent ---------------------------------------------------
            'privacy_ack' => ['label' => 'Privacy Notice consent', 'rule' => 'checkbox', 'required' => true],

            // --- Personal Information -------------------------------------
            'first_name'     => ['label' => 'First Name (Legal)',  'rule' => 'name', 'required' => true,  'len' => 60],
            'middle_name'    => ['label' => 'Middle Name (Legal)', 'rule' => 'name', 'required' => false, 'len' => 60],
            'last_name'      => ['label' => 'Last Name (Legal)',   'rule' => 'name', 'required' => true,  'len' => 60],
            'preferred_name' => ['label' => 'Preferred Name',      'rule' => 'name', 'required' => false, 'len' => 60],
            'pronouns'       => ['label' => 'Pronouns',            'rule' => 'text', 'required' => false, 'len' => 40],
            'date_of_birth'  => ['label' => 'Date of Birth',       'rule' => 'dob',  'required' => true],
            'street_address' => ['label' => 'Home Street Address', 'rule' => 'text', 'required' => true,  'len' => 120],
            'unit'           => ['label' => 'Unit / Apartment',    'rule' => 'text', 'required' => false, 'len' => 20],
            'city'           => ['label' => 'City',                'rule' => 'text', 'required' => true,  'len' => 60],
            'province'       => ['label' => 'Province',            'rule' => 'text', 'required' => true,  'len' => 40],
            'postal_code'    => ['label' => 'Postal Code',         'rule' => 'postal', 'required' => true],
            'mobile_phone'   => ['label' => 'Mobile Phone Number', 'rule' => 'tel', 'required' => true],
            'home_phone'     => ['label' => 'Home Phone Number',   'rule' => 'tel', 'required' => false],
            'other_phone'    => ['label' => 'Other Phone Number',  'rule' => 'tel', 'required' => false],
            'primary_email'  => ['label' => 'Primary Email Address',   'rule' => 'email', 'required' => true],
            'secondary_email' => ['label' => 'Secondary Email Address', 'rule' => 'email', 'required' => false],

            // --- Emergency Contacts ---------------------------------------
            'ec1_name'         => ['label' => 'Primary Contact – Name',        'rule' => 'name', 'required' => true,  'len' => 80],
            'ec1_relationship' => ['label' => 'Primary Contact – Relationship', 'rule' => 'text', 'required' => true, 'len' => 40],
            'ec1_phone'        => ['label' => 'Primary Contact – Phone',       'rule' => 'tel',  'required' => true],
            'ec1_email'        => ['label' => 'Primary Contact – Email',       'rule' => 'email', 'required' => false],
            'ec2_name'         => ['label' => 'Secondary Contact – Name',        'rule' => 'name', 'required' => false, 'len' => 80],
            'ec2_relationship' => ['label' => 'Secondary Contact – Relationship', 'rule' => 'text', 'required' => false, 'len' => 40],
            'ec2_phone'        => ['label' => 'Secondary Contact – Phone',       'rule' => 'tel',  'required' => false],
            'ec2_email'        => ['label' => 'Secondary Contact – Email',       'rule' => 'email', 'required' => false],

            // --- Availability (extras; per-day fields added below) ---------
            'desired_hours'         => ['label' => 'Desired hours per week', 'rule' => 'hours', 'required' => false],
            'availability_comments' => ['label' => 'Availability comments',  'rule' => 'textarea', 'required' => false, 'len' => 1000],

            // --- Medical & Safety -----------------------------------------
            'allergies'          => ['label' => 'Allergies',          'rule' => 'textarea', 'required' => false, 'len' => 800],
            'medical_conditions' => ['label' => 'Medical Conditions', 'rule' => 'textarea', 'required' => false, 'len' => 800],

            // --- Work Authorization ---------------------------------------
            'sin'        => ['label' => 'Social Insurance Number', 'rule' => 'sin', 'required' => true],
            'sin_issued' => ['label' => 'SIN Issued Date',  'rule' => 'date', 'required' => false],
            'sin_expiry' => ['label' => 'SIN Expiry Date',  'rule' => 'date', 'required' => false], // required if SIN starts with 9
            // Conditional permit block (SIN begins with 9)
            'permit_number'       => ['label' => 'Work/Study Permit Number', 'rule' => 'text', 'required' => false, 'len' => 40],
            'permit_issued'       => ['label' => 'Permit Issued Date', 'rule' => 'date', 'required' => false],
            'permit_expiry'       => ['label' => 'Permit Expiry Date', 'rule' => 'date', 'required' => false],
            'ircc_letter_id'      => ['label' => 'IRCC Letter ID',    'rule' => 'text', 'required' => false, 'len' => 40],
            'permit_restrictions' => ['label' => 'Restrictions / Other Information', 'rule' => 'textarea', 'required' => false, 'len' => 600],

            // --- Government Issued Identification --------------------------
            'gov_first_name'  => ['label' => 'ID – First Name (Legal)',  'rule' => 'name', 'required' => true, 'len' => 60],
            'gov_middle_name' => ['label' => 'ID – Middle Name (Legal)', 'rule' => 'name', 'required' => false, 'len' => 60],
            'gov_last_name'   => ['label' => 'ID – Last Name (Legal)',   'rule' => 'name', 'required' => true, 'len' => 60],
            'gov_doc_type'    => ['label' => 'Document Type',      'rule' => 'text', 'required' => true, 'len' => 60],
            'gov_doc_number'  => ['label' => 'Document ID Number', 'rule' => 'text', 'required' => true, 'len' => 60],
            'gov_issued_by'   => ['label' => 'Issued By',          'rule' => 'text', 'required' => true, 'len' => 60],
            'gov_issued_date' => ['label' => 'Issued Date',        'rule' => 'date', 'required' => false],
            'gov_expiry_date' => ['label' => 'Expiry Date',        'rule' => 'date', 'required' => false],

            // --- Direct Deposit -------------------------------------------
            'dd_institution_name' => ['label' => 'Name of Financial Institution', 'rule' => 'text', 'required' => true, 'len' => 80],
            'dd_account_holder'   => ['label' => "Account Holder's Name",         'rule' => 'name', 'required' => true, 'len' => 80],
            'dd_transit'          => ['label' => 'Transit Number',     'rule' => 'digits', 'required' => true, 'digits' => 5],
            'dd_institution_number' => ['label' => 'Institution Number', 'rule' => 'digits', 'required' => true, 'digits' => 3],
            'dd_account_number'   => ['label' => 'Account Number',     'rule' => 'digits', 'required' => true, 'digits' => [5, 17]],

            // --- Declaration & Communication Consent ----------------------
            'declaration_ack'  => ['label' => 'Declaration acknowledgement', 'rule' => 'checkbox', 'required' => true],
            'comms_consent'    => ['label' => 'Electronic communication consent', 'rule' => 'checkbox', 'required' => true],
            'preferred_contact' => ['label' => 'Preferred Method of Contact', 'rule' => 'select', 'required' => true, 'options' => self::CONTACT_METHODS],
            'employee_name'    => ['label' => 'Employee Name (typed)', 'rule' => 'name', 'required' => true, 'len' => 120],
            'signature_date'   => ['label' => 'Date', 'rule' => 'date', 'required' => true],
        ];

        // Availability: per-day enabled + start/end time.
        foreach (self::DAYS as $day => $_label) {
            $f["avail_{$day}_enabled"] = ['label' => "Available {$day}", 'rule' => 'checkbox', 'required' => false];
            $f["avail_{$day}_start"]   = ['label' => "{$day} start", 'rule' => 'time', 'required' => false];
            $f["avail_{$day}_end"]     = ['label' => "{$day} end",   'rule' => 'time', 'required' => false];
        }

        // Certifications: per-group fields.
        foreach (self::CERTS as $key => $meta) {
            $f["{$key}_not_applicable"] = ['label' => "{$meta['label']} – N/A", 'rule' => 'checkbox', 'required' => false];
            $f["{$key}_first_name"] = ['label' => "{$meta['label']} – First Name", 'rule' => 'name', 'required' => false, 'len' => 60];
            $f["{$key}_middle_name"] = ['label' => "{$meta['label']} – Middle Name", 'rule' => 'name', 'required' => false, 'len' => 60];
            $f["{$key}_last_name"]  = ['label' => "{$meta['label']} – Last Name", 'rule' => 'name', 'required' => false, 'len' => 60];
            $f["{$key}_cert_id"]    = ['label' => "{$meta['label']} – Certificate ID", 'rule' => 'text', 'required' => false, 'len' => 60];
            $f["{$key}_issued"]     = ['label' => "{$meta['label']} – Issued Date", 'rule' => 'date', 'required' => false];
            $f["{$key}_expiry"]     = ['label' => "{$meta['label']} – Expiry Date", 'rule' => 'date', 'required' => false];
            if ($meta['provider']) {
                $f["{$key}_provider"] = ['label' => "{$meta['label']} – Training Provider", 'rule' => 'text', 'required' => false, 'len' => 80];
            }
        }

        return $f;
    }

    /** Convenience: label for a field key (falls back to a prettified key). */
    public static function label(string $key): string
    {
        $fields = self::fields();
        if (isset($fields[$key]['label'])) {
            return $fields[$key]['label'];
        }
        if (isset(self::FILES[$key]['label'])) {
            return self::FILES[$key]['label'];
        }
        return ucwords(str_replace('_', ' ', $key));
    }
}
