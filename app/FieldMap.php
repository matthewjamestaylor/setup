<?php

declare(strict_types=1);

namespace Legends;

/**
 * Canonical definition of every field on the Employee Information form.
 *
 * Single source of truth shared by the Validator (server-side rules) and the
 * PdfBuilder / text export. The HTML form is authored by hand but its `name`
 * attributes match the keys defined here.
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

    /** Pronoun options; "other" reveals a free-text specify field. */
    public const PRONOUNS = [
        'she/her'     => 'she/her',
        'he/him'      => 'he/him',
        'they/them'   => 'they/them',
        'prefer_not'  => 'Prefer not to say',
        'other'       => 'Other (specify)',
    ];

    /** Preferred method of contact (text/SMS removed per requirements). */
    public const CONTACT_METHODS = [
        'email' => 'Email',
        'phone' => 'Phone Call',
    ];

    /** Emergency-contact relationship options (from the provided screenshot). */
    public const RELATIONSHIPS = [
        'spouse'                => 'Spouse',
        'domestic_partner'      => 'Domestic Partner',
        'parent'                => 'Parent',
        'sibling'               => 'Sibling',
        'child'                 => 'Child',
        'domestic_partner_child' => 'Domestic Partner Child',
        'ex_spouse'             => 'Ex-Spouse',
        'ex_domestic_partner'   => 'Ex-Domestic Partner',
        'other'                 => 'Other',
    ];

    /** Phone device type (from the provided screenshot). */
    public const PHONE_DEVICES = [
        'mobile'   => 'Mobile',
        'landline' => 'Landline',
        'fax'      => 'Fax',
    ];

    /** Home/Work location tag for phone + email (from the provided screenshot). */
    public const CONTACT_LOCATIONS = [
        'home' => 'Home',
        'work' => 'Work',
    ];

    /** Accepted government photo-ID types. `renews` => expiry is required. */
    public const ID_TYPES = [
        'passport'         => ['label' => 'Canadian Passport',                        'renews' => true],
        'drivers_licence'  => ['label' => "Provincial/Territorial Driver's Licence",  'renews' => true],
        'photo_id'         => ['label' => 'Provincial/Territorial Photo ID Card',     'renews' => true],
        'pr_card'          => ['label' => 'Permanent Resident (PR) Card',             'renews' => true],
        'citizenship_card' => ['label' => 'Canadian Citizenship Card',                'renews' => false],
        'indian_status'    => ['label' => 'Secure Certificate of Indian Status',      'renews' => false],
    ];

    /** Work/study permit types (shown when SIN begins with 9). */
    public const PERMIT_TYPES = [
        'work'  => 'Work Permit',
        'study' => 'Study Permit',
    ];

    /**
     * Financial institutions. `institution` = the standard 3-digit number that
     * auto-fills; null means the number is entered manually (credit unions route
     * through Central 1 and vary, and "other" is free-form).
     * Ordered by prevalence in Toronto/Ontario.
     */
    public const BANKS = [
        'rbc'         => ['name' => 'Royal Bank of Canada (RBC)',                    'institution' => '003'],
        'td'          => ['name' => 'Toronto-Dominion Bank (TD)',                    'institution' => '004'],
        'scotiabank'  => ['name' => 'Bank of Nova Scotia (Scotiabank)',             'institution' => '002'],
        'bmo'         => ['name' => 'Bank of Montreal (BMO)',                        'institution' => '001'],
        'cibc'        => ['name' => 'Canadian Imperial Bank of Commerce (CIBC)',    'institution' => '010'],
        'national'    => ['name' => 'National Bank of Canada',                       'institution' => '006'],
        'tangerine'   => ['name' => 'Tangerine Bank',                               'institution' => '614'],
        'eq'          => ['name' => 'EQ Bank (Equitable Bank)',                      'institution' => '338'],
        'simplii'     => ['name' => 'Simplii Financial',                            'institution' => '010'],
        'desjardins'  => ['name' => 'Desjardins',                                   'institution' => '815'],
        'meridian'    => ['name' => 'Meridian Credit Union',                        'institution' => null],
        'firstontario' => ['name' => 'FirstOntario Credit Union',                   'institution' => null],
        'duca'        => ['name' => 'DUCA Credit Union',                            'institution' => null],
        'alterna'     => ['name' => 'Alterna Savings',                              'institution' => null],
        'other'       => ['name' => 'Other (specify)',                              'institution' => null],
    ];

    /**
     * Certifications. Each is gated by a yes/no question ({key}_has).
     *   provider  => has a "Training Provider" field
     *   age_gated => only asked when the employee is at least min_age (Smart Serve)
     */
    public const CERTS = [
        'smartserve' => ['label' => 'Smart Serve Certification',      'provider' => false, 'age_gated' => true,  'min_age' => 18],
        'foodsafety' => ['label' => 'Food Safety Certification',      'provider' => true,  'age_gated' => false],
        'jhsc1'      => ['label' => 'Joint Health & Safety – Level 1', 'provider' => true,  'age_gated' => false],
        'jhsc2'      => ['label' => 'Joint Health & Safety – Level 2', 'provider' => true,  'age_gated' => false],
    ];

    /**
     * File-upload fields: key => [label, kind(image|doc), required].
     * Conditional requirements (permit/IRCC/cert docs) are enforced in Validator.
     */
    public const FILES = [
        'headshot'      => ['label' => 'Headshot photograph',              'kind' => 'image', 'required' => true],
        'gov_document'  => ['label' => 'Government ID (scan/photo)',        'kind' => 'doc',   'required' => true],
        'sin_document'  => ['label' => 'Proof of SIN',                     'kind' => 'doc',   'required' => true],
        'permit_document' => ['label' => 'Work/Study Permit',              'kind' => 'doc',   'required' => false],
        'ircc_document' => ['label' => 'IRCC letter',                      'kind' => 'doc',   'required' => false],
        'dd_document'   => ['label' => 'Void cheque / direct deposit form', 'kind' => 'doc',  'required' => true],
        'smartserve_document' => ['label' => 'Smart Serve certificate',    'kind' => 'doc',   'required' => false],
        'foodsafety_document' => ['label' => 'Food Safety certificate',    'kind' => 'doc',   'required' => false],
        'jhsc1_document' => ['label' => 'JHSC Level 1 certificate',         'kind' => 'doc',   'required' => false],
        'jhsc2_document' => ['label' => 'JHSC Level 2 certificate',         'kind' => 'doc',   'required' => false],
    ];

    /** Maximum number of emergency contacts a user may add. */
    public const MAX_CONTACTS = 4;

    /**
     * Per-emergency-contact field template (the contacts[] array is validated
     * against this). Requiredness for index 0 (primary) vs. added contacts is
     * handled in the Validator.
     */
    public static function contactFields(): array
    {
        return [
            'first_name'        => ['label' => 'First Name', 'rule' => 'name', 'len' => 60],
            'last_name'         => ['label' => 'Last Name',  'rule' => 'name', 'len' => 60],
            'relationship'      => ['label' => 'Relationship', 'rule' => 'select', 'options' => self::RELATIONSHIPS],
            'relationship_other' => ['label' => 'Relationship (other)', 'rule' => 'text', 'len' => 40],
            'phone'             => ['label' => 'Phone', 'rule' => 'tel'],
            'phone_device'      => ['label' => 'Phone Device', 'rule' => 'select', 'options' => self::PHONE_DEVICES],
            'phone_location'    => ['label' => 'Phone Type', 'rule' => 'select', 'options' => self::CONTACT_LOCATIONS],
            'email'             => ['label' => 'Email', 'rule' => 'email'],
            'email_location'    => ['label' => 'Email Type', 'rule' => 'select', 'options' => self::CONTACT_LOCATIONS],
        ];
    }

    /**
     * Flat map of every non-repeating scalar field: key => rule spec.
     *   rule     – validation type
     *   required – base requiredness (conditional rules live in Validator)
     *   len/digits/options – rule parameters
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
            'pronouns'       => ['label' => 'Pronouns',            'rule' => 'select', 'required' => false, 'options' => self::PRONOUNS],
            'pronouns_other' => ['label' => 'Pronouns (specified)', 'rule' => 'text', 'required' => false, 'len' => 40],
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

            // --- Availability (per-day fields added below) -----------------
            'desired_hours'         => ['label' => 'Desired hours per week', 'rule' => 'hours', 'required' => false],
            'availability_comments' => ['label' => 'Availability comments',  'rule' => 'textarea', 'required' => false, 'len' => 1000],

            // --- Time off / trips -----------------------------------------
            'trips_has'     => ['label' => 'Upcoming trips or time off', 'rule' => 'yesno', 'required' => true],
            'trips_details' => ['label' => 'Trip / time-off details', 'rule' => 'textarea', 'required' => false, 'len' => 800],

            // --- Medical & Safety (yes/no gated) --------------------------
            'allergies_has'      => ['label' => 'Has allergies', 'rule' => 'yesno', 'required' => true],
            'allergies_details'  => ['label' => 'Allergies', 'rule' => 'textarea', 'required' => false, 'len' => 800],
            'medical_has'        => ['label' => 'Has medical conditions', 'rule' => 'yesno', 'required' => true],
            'medical_details'    => ['label' => 'Medical Conditions', 'rule' => 'textarea', 'required' => false, 'len' => 800],

            // --- Work Authorization (SIN) ---------------------------------
            'sin'        => ['label' => 'Social Insurance Number', 'rule' => 'sin', 'required' => true],
            'sin_issued' => ['label' => 'SIN Issued Date',  'rule' => 'date', 'required' => false], // required if SIN starts with 9
            'sin_expiry' => ['label' => 'SIN Expiry Date',  'rule' => 'date', 'required' => false], // required if SIN starts with 9

            // --- Permit block (SIN begins with 9) -------------------------
            'permit_type'    => ['label' => 'Permit Type', 'rule' => 'select', 'required' => false, 'options' => self::PERMIT_TYPES],
            'permit_number'  => ['label' => 'Permit Number', 'rule' => 'text', 'required' => false, 'len' => 40],
            'permit_issued'  => ['label' => 'Permit Issued Date', 'rule' => 'date', 'required' => false],
            'permit_expiry'  => ['label' => 'Permit Expiry Date', 'rule' => 'date', 'required' => false],
            // IRCC block (only when the permit is expired)
            'ircc_letter_id' => ['label' => 'IRCC Letter ID', 'rule' => 'text', 'required' => false, 'len' => 40],

            // --- Government Issued Identification --------------------------
            'gov_first_name'  => ['label' => 'ID – First Name (Legal)',  'rule' => 'name', 'required' => true, 'len' => 60],
            'gov_middle_name' => ['label' => 'ID – Middle Name (Legal)', 'rule' => 'name', 'required' => false, 'len' => 60],
            'gov_last_name'   => ['label' => 'ID – Last Name (Legal)',   'rule' => 'name', 'required' => true, 'len' => 60],
            'gov_doc_type'    => ['label' => 'Document Type',      'rule' => 'select', 'required' => true, 'options' => self::idTypeOptions()],
            'gov_doc_number'  => ['label' => 'Document ID Number', 'rule' => 'text', 'required' => true, 'len' => 40],
            'gov_expiry_date' => ['label' => 'Expiry Date',        'rule' => 'date', 'required' => false], // required if the type renews

            // --- Direct Deposit (single account) --------------------------
            'dd_bank'             => ['label' => 'Financial Institution', 'rule' => 'select', 'required' => true, 'options' => self::bankOptions()],
            'dd_bank_other'       => ['label' => 'Financial Institution (other)', 'rule' => 'text', 'required' => false, 'len' => 80],
            'dd_account_holder'   => ['label' => "Account Holder's Name", 'rule' => 'name', 'required' => true, 'len' => 80],
            'dd_transit'          => ['label' => 'Transit Number', 'rule' => 'digits', 'required' => true, 'digits' => 5],
            'dd_institution_number' => ['label' => 'Institution Number', 'rule' => 'digits', 'required' => true, 'digits' => 3],
            'dd_account_number'   => ['label' => 'Account Number', 'rule' => 'digits', 'required' => true, 'digits' => [5, 12]],
            'dd_account_confirm'  => ['label' => 'Confirm Account Number', 'rule' => 'digits', 'required' => true, 'digits' => [5, 12]],

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

        // Certifications: yes/no gate + per-group fields.
        foreach (self::CERTS as $key => $meta) {
            $f["{$key}_has"] = ['label' => "{$meta['label']} held", 'rule' => 'yesno', 'required' => false];
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

    /** ID types as a value=>label option list for a <select>. */
    public static function idTypeOptions(): array
    {
        $o = [];
        foreach (self::ID_TYPES as $k => $meta) {
            $o[$k] = $meta['label'];
        }
        return $o;
    }

    /** Banks as a value=>label option list for a <select>. */
    public static function bankOptions(): array
    {
        $o = [];
        foreach (self::BANKS as $k => $meta) {
            $o[$k] = $meta['name'];
        }
        return $o;
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
