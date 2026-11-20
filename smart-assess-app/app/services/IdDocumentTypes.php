<?php
declare(strict_types=1);

/**
 * What SMART ASSESS knows about each Philippine government-issued ID it reads: which printed fields
 * it has, how their labels may be printed (Filipino and English, as OCR may misread them), which
 * fields the extraction check requires, and how values are found. No I/O; data and lookups only.
 *
 * Recognition (is this file this ID?) is NOT here: it is the printed titles and issuers in
 * DocumentAnalysis::KIND_EVIDENCE, so the Staff page's ID Type check and the extraction always agree.
 * A file name is never used. Reading an ID's fields says nothing about whether the ID is genuine.
 *
 * Field rules:
 *  - 'labels': label phrases (lower case, words separated by spaces). A value follows its label on
 *    the same line or on the next line. Longer phrases win, so "Gitnang Apelyido" is the middle name.
 *  - 'type':  'name' (capital letters), 'full_name' ("LAST, FIRST MIDDLE"), 'date', 'number', 'word'.
 *  - 'pattern': for numbers, the printed format (a regular expression without delimiters); with
 *    'anywhere' => true the number is also looked for outside its label (cards that print it alone).
 *  - 'unlabelled_name' => true (full_name only): the card prints the name without a label; it is the
 *    first line written "LAST, FIRST MIDDLE" in capitals.
 * Type rules: 'required' (all must be read for the extraction to be complete), 'name' (the fields
 * that make the holder's name, last name first), 'identifying' (at least one must be read for the
 * type to be confirmed: a printed title alone, which a letter can also mention, is not enough),
 * optional 'expiry' and 'mrz' (passport machine zone).
 *
 * Adding an ID: add its title patterns to DocumentAnalysis::KIND_EVIDENCE and an entry here.
 */
final class IdDocumentTypes
{
    public const TYPES = [
        'philsys_national_id' => [
            'fields' => [
                'last_name' => ['labels' => ['apelyido', 'last name', 'surname'], 'type' => 'name'],
                // Single words too: OCR often misreads one word of a small two-word label ("Middie Narne").
                'given_names' => ['labels' => ['mga pangalan', 'given names', 'given name', 'first name', 'mga', 'given'], 'type' => 'name'],
                'middle_name' => ['labels' => ['gitnang apelyido', 'panggitnang apelyido', 'middle name', 'gitnang', 'middle'], 'type' => 'name'],
                'birth_date' => ['labels' => ['petsa ng kapanganakan', 'date of birth', 'birth date'], 'type' => 'date'],
                'card_number' => ['labels' => ['pcn', 'philsys card number'], 'type' => 'number', 'pattern' => '[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}', 'anywhere' => true],
            ],
            'required' => ['last_name', 'given_names'],
            'name' => ['last_name', 'given_names', 'middle_name'],
            'identifying' => ['last_name', 'given_names', 'card_number'],
        ],
        'passport' => [
            'fields' => [
                'surname' => ['labels' => ['apelyido', 'surname', 'last name'], 'type' => 'name'],
                'given_names' => ['labels' => ['mga pangalan', 'pangalan', 'given names', 'given name'], 'type' => 'name'],
                'middle_name' => ['labels' => ['panggitnang apelyido', 'gitnang apelyido', 'middle name'], 'type' => 'name'],
                'nationality' => ['labels' => ['nasyonalidad', 'nationality'], 'type' => 'word'],
                'birth_date' => ['labels' => ['petsa ng kapanganakan', 'date of birth', 'birth date'], 'type' => 'date'],
                'passport_number' => ['labels' => ['pasaporte blg', 'passport no', 'passport number'], 'type' => 'number', 'pattern' => '[A-Z][0-9]{7}[A-Z]'],
                'expiry_date' => ['labels' => ['petsa ng pagkawalang bisa', 'date of expiry', 'expiry date', 'valid until'], 'type' => 'date'],
            ],
            'required' => ['surname', 'given_names', 'passport_number'],
            'identifying' => ['passport_number'],
            'name' => ['surname', 'given_names', 'middle_name'],
            'expiry' => 'expiry_date',
            'mrz' => true,
        ],
        'drivers_license' => [
            'fields' => [
                'full_name' => ['labels' => ['last name first name middle name', 'name'], 'type' => 'full_name'],
                'license_number' => ['labels' => ['license no', 'licence no', 'license number', 'dl no'], 'type' => 'number', 'pattern' => '[A-Z][0-9]{2}-[0-9]{2}-[0-9]{6}', 'anywhere' => true],
                'birth_date' => ['labels' => ['date of birth', 'birth date'], 'type' => 'date'],
                'expiry_date' => ['labels' => ['expiration date', 'expiry date', 'valid until'], 'type' => 'date'],
            ],
            'required' => ['full_name', 'license_number'],
            'identifying' => ['license_number'],
            'name' => ['full_name'],
            'expiry' => 'expiry_date',
        ],
        'umid' => [
            'fields' => [
                'surname' => ['labels' => ['surname', 'last name', 'apelyido'], 'type' => 'name'],
                'given_name' => ['labels' => ['given name', 'given names', 'first name', 'pangalan'], 'type' => 'name'],
                'middle_name' => ['labels' => ['middle name', 'gitnang apelyido'], 'type' => 'name'],
                'birth_date' => ['labels' => ['date of birth', 'birth date'], 'type' => 'date'],
                'crn' => ['labels' => ['crn', 'common reference number'], 'type' => 'number', 'pattern' => 'CRN-[0-9]{4}-[0-9]{7}-[0-9]', 'anywhere' => true],
            ],
            'required' => ['surname', 'given_name', 'crn'],
            'identifying' => ['crn'],
            'name' => ['surname', 'given_name', 'middle_name'],
        ],
        'sss_id' => [
            'fields' => [
                'full_name' => ['labels' => ['name'], 'type' => 'full_name', 'unlabelled_name' => true],
                'ss_number' => ['labels' => ['ss no', 'sss no', 'ss number'], 'type' => 'number', 'pattern' => '[0-9]{2}-[0-9]{7}-[0-9]', 'anywhere' => true],
                'birth_date' => ['labels' => ['date of birth', 'birth date'], 'type' => 'date'],
            ],
            'required' => ['full_name', 'ss_number'],
            'identifying' => ['ss_number'],
            'name' => ['full_name'],
        ],
        'philhealth_id' => [
            'fields' => [
                'full_name' => ['labels' => ['name'], 'type' => 'full_name', 'unlabelled_name' => true],
                'pin' => ['labels' => ['pin', 'philhealth identification number'], 'type' => 'number', 'pattern' => '[0-9]{2}-[0-9]{9}-[0-9]', 'anywhere' => true],
                'birth_date' => ['labels' => ['date of birth', 'birth date'], 'type' => 'date'],
            ],
            'required' => ['full_name', 'pin'],
            'identifying' => ['pin'],
            'name' => ['full_name'],
        ],
        'postal_id' => [
            'fields' => [
                'surname' => ['labels' => ['surname', 'last name'], 'type' => 'name'],
                'first_name' => ['labels' => ['first name', 'given name', 'given names'], 'type' => 'name'],
                'middle_name' => ['labels' => ['middle name'], 'type' => 'name'],
                'birth_date' => ['labels' => ['date of birth', 'birth date'], 'type' => 'date'],
                'prn' => ['labels' => ['prn', 'postal reference number'], 'type' => 'number', 'pattern' => '[0-9]{12}[A-Z]', 'anywhere' => true],
                'valid_until' => ['labels' => ['valid until', 'expiry date'], 'type' => 'date'],
            ],
            'required' => ['surname', 'first_name', 'prn'],
            'identifying' => ['prn'],
            'name' => ['surname', 'first_name', 'middle_name'],
            'expiry' => 'valid_until',
        ],
    ];

    /**
     * IDs recognised by their printed title but without a field layout here: their name is read only
     * when printed "LAST, FIRST MIDDLE" or after a "Name" label. Extend by giving them a full entry.
     */
    public const NAME_ONLY_TYPES = ['gsis_id', 'prc_id', 'voters_id', 'tin_id', 'pagibig_id', 'senior_citizen_id', 'pwd_id'];

    /** When a card shows several issuers' titles, the first named here is the card (a UMID carries the SSS or GSIS name). */
    public const PRECEDENCE = ['umid', 'passport', 'philsys_national_id', 'drivers_license', 'postal_id', 'philhealth_id', 'sss_id', 'gsis_id',
        'prc_id', 'voters_id', 'tin_id', 'pagibig_id', 'senior_citizen_id', 'pwd_id'];

    /** Field states that leave the worker (never the values). */
    public const FIELD_STATES = ['read', 'unverified'];
    /**
     * confirmed: one ID's title and at least one of its identifying fields read. title_only: the title
     * but none of those fields (a letter may name an issuer). conflict: titles of different IDs.
     * unverified: no government ID title.
     */
    public const TYPE_STATES = ['confirmed', 'title_only', 'conflict', 'unverified'];
    /** Staff-facing names of fields (others: the field name with spaces). */
    public const FIELD_LABELS = ['crn' => 'CRN', 'prn' => 'PRN', 'pin' => 'PhilHealth number (PIN)', 'ss_number' => 'SS number', 'full_name' => 'name',
        'card_number' => 'card number (PCN)'];

    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? str_replace('_', ' ', $field);
    }

    /** @return array{fields: array, required: list<string>, name: list<string>, expiry?: string, mrz?: bool}|null */
    public static function type(string $kind): ?array
    {
        if (isset(self::TYPES[$kind])) {
            return self::TYPES[$kind];
        }
        if (in_array($kind, self::NAME_ONLY_TYPES, true)) {
            return [
                'fields' => ['full_name' => ['labels' => ['name', 'full name'], 'type' => 'full_name', 'unlabelled_name' => true]],
                'required' => ['full_name'],
                'name' => ['full_name'],
                'identifying' => ['full_name'],
            ];
        }
        return null;
    }

    /** @return list<string> every field name any type may report */
    public static function allFieldNames(): array
    {
        $names = ['full_name'];
        foreach (self::TYPES as $type) {
            $names = array_merge($names, array_keys($type['fields']));
        }
        return array_values(array_unique($names));
    }
}
