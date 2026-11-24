<?php
declare(strict_types=1);

require_once __DIR__ . '/IdFieldExtractor.php';

/**
 * Front-side National ID review: the applicant's Full Name and Address read from the FRONT of the
 * uploaded National ID (never the back), compared with the application form, and the AI's findings
 * about that comparison, worded for Staff. Pure: no I/O, nothing logged.
 *
 *  - Extraction (worker): one Full Name and one Address from the applicant's National ID front only.
 *    No separate name parts or other ID fields are returned. Each value has a status; nothing is guessed.
 *  - Comparison (worker for the AI, application for Staff): Match, Mismatch or Needs Review. Letter case,
 *    whitespace and punctuation are ignored; a possible spelling or missing-part difference needs review.
 *  - AI (worker): the model answers only with codes from the fixed lists below (overall assessment,
 *    per field agrees / differs / cannot tell and the kind of difference, items for Staff to check).
 *    Staff see sentences written here from those codes, so the AI can never say that an ID is genuine,
 *    that a signature, photo or security feature was checked, or that a request should be approved or
 *    rejected. Everything is advisory; Staff decide.
 */
final class IdFrontReview
{
    public const STATUSES = ['read', 'incomplete', 'uncertain', 'unreadable', 'not_found'];
    public const STATUS_LABELS = [
        'read' => 'Read', 'incomplete' => 'Partly read', 'uncertain' => 'Readings disagree',
        'unreadable' => 'Could not be read', 'not_found' => 'Not found on the ID',
    ];
    /** Statuses that carry a value (partial for 'incomplete'); the others never do. */
    private const STATUSES_WITH_VALUE = ['read', 'incomplete'];
    public const RESULTS = ['match', 'mismatch', 'needs_review'];
    public const RESULT_LABELS = ['match' => 'Match', 'mismatch' => 'Mismatch', 'needs_review' => 'Needs Review'];
    public const MAX_NAME = 120;
    public const MAX_NAME_PART = 60;
    public const MAX_ADDRESS = 200;

    // ---- AI codes (the model's only vocabulary) ----
    public const AI_OVERALL = ['match', 'needs_review', 'mismatch'];
    public const AI_OVERALL_LABELS = ['match' => 'MATCH', 'needs_review' => 'NEEDS REVIEW', 'mismatch' => 'MISMATCH'];
    public const AI_ASSESSMENTS = ['agrees', 'differs', 'cannot_tell'];
    public const AI_NAME_DIFFERENCES = [
        'spelling' => 'a spelling difference',
        'possible_ocr_misread' => 'letters that may have been misread by OCR',
        'missing_middle_name_on_id' => 'the middle name on the form was not read on the ID',
        'missing_middle_name_on_form' => 'the ID shows a middle name that is not on the form',
        'missing_name_part' => 'a name part on one side is missing on the other',
        'initial_or_abbreviation' => 'an initial or abbreviation',
        'accents_or_spacing' => 'a difference in accent marks or spacing only',
        'different_name' => 'a different name',
        'not_readable' => 'the name could not be read clearly from the ID',
        'incomplete_reading' => 'only part of the name was read from the ID',
    ];
    public const AI_ADDRESS_DIFFERENCES = [
        'different_barangay' => 'a different barangay',
        'different_city_or_municipality' => 'a different city or municipality',
        'different_province' => 'a different province',
        'different_street_or_house_number' => 'a different street, house or lot number',
        'detail_missing_on_id' => 'a detail on the form that is not in the ID address',
        'extra_detail_on_id' => 'a detail in the ID address that is not on the form',
        'abbreviation_or_format' => 'abbreviations or formatting only',
        'possible_ocr_misread' => 'words that may have been misread by OCR',
        'not_readable' => 'the address could not be read clearly from the ID',
        'incomplete_reading' => 'only part of the address was read from the ID',
        'not_on_form' => 'no address on the application form to compare',
    ];
    public const AI_ATTENTION = [
        'check_full_name' => 'Check the full name on the original ID.',
        'check_middle_name' => 'Check the middle name on the original ID.',
        'check_name_spelling' => 'Check the spelling of the name on the original ID.',
        'check_address' => 'Check the address on the original ID.',
        'check_barangay' => 'Check the barangay in the address.',
        'check_city_or_province' => 'Check the city or municipality and the province in the address.',
        'check_street_or_house_number' => 'Check the street, house or lot number in the address.',
        'image_hard_to_read' => 'The ID image is hard to read; inspect it at full size.',
        'check_document_is_national_id_front' => 'Check that the upload is the front side of a National ID.',
    ];
    private const MAX_DIFFERENCES = 4;
    private const MAX_ATTENTION = 5;

    /** Address words that only say what kind of place follows (or the country): not compared. */
    private const ADDRESS_FORMAT_WORDS = ['BARANGAY', 'PROVINCE', 'MUNICIPALITY', 'CITY', 'OF', 'NUMBER', 'PHILIPPINES', 'PILIPINAS', 'PHL'];
    /** Abbreviations written either way on forms and IDs. */
    private const ADDRESS_EQUIVALENTS = [
        'BRGY' => 'BARANGAY', 'BGY' => 'BARANGAY', 'BRG' => 'BARANGAY', 'ST' => 'STREET', 'STR' => 'STREET', 'AVE' => 'AVENUE', 'AV' => 'AVENUE',
        'RD' => 'ROAD', 'SUBD' => 'SUBDIVISION', 'SUBDV' => 'SUBDIVISION', 'VILL' => 'VILLAGE', 'PRK' => 'PUROK', 'PROV' => 'PROVINCE',
        'MUN' => 'MUNICIPALITY', 'BLK' => 'BLOCK', 'NO' => 'NUMBER', 'NUM' => 'NUMBER',
    ];
    /** Name abbreviations that stand for a whole word (shown as Needs Review, never as the same name). */
    private const NAME_ABBREVIATIONS = ['MA' => 'MARIA', 'STO' => 'SANTO', 'STA' => 'SANTA'];
    /** Digits OCR reads in place of letters (for spotting a likely misread only). */
    private const LETTER_LOOKALIKES = ['0' => 'O', '1' => 'I', '2' => 'Z', '4' => 'A', '5' => 'S', '8' => 'B'];

    // ================= Extraction (worker) =================

    /**
     * Full Name and Address read from the front side's OCR text.
     *
     * @param bool $readingsDisagree complete OCR passes read different names (no name is given)
     * @return array{side: string, full_name: array{status: string, value: ?string}, address: array{status: string, value: ?string}}
     */
    public static function extract(string $text, bool $ocrOk, bool $readingsDisagree = false): array
    {
        $text = DocumentAnalysis::normalizeText($text);
        if (!$ocrOk || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return ['side' => 'front', 'full_name' => ['status' => 'unreadable', 'value' => null],
                'address' => ['status' => 'unreadable', 'value' => null]];
        }
        return ['side' => 'front', 'full_name' => self::fullName($text, $readingsDisagree), 'address' => IdFieldExtractor::address($text)];
    }

    private static function fullName(string $text, bool $readingsDisagree): array
    {
        if ($readingsDisagree) {
            return ['status' => 'uncertain', 'value' => null];
        }
        [$type, $typeState] = IdFieldExtractor::classify($text);
        if ($typeState === 'conflict') {
            return ['status' => 'uncertain', 'value' => null];
        }
        if ($type !== null && $type !== 'philsys_national_id') {
            return ['status' => 'not_found', 'value' => null];
        }
        return IdFieldExtractor::extractNationalIdFullName($text);
    }

    /**
     * Application side: strictly validates a worker's extraction (result version 6).
     *
     * @throws UnexpectedValueException
     */
    public static function validateExtraction(mixed $value): array
    {
        $object = self::exact($value, ['side', 'full_name', 'address'], 'id_review');
        if ($object['side'] !== 'front') {
            throw new UnexpectedValueException('id_review must describe the front side');
        }
        $name = self::exact($object['full_name'], ['status', 'value'], 'id_review full_name');
        $status = self::status($name['status']);
        $text = self::value($name['value'], $status, self::MAX_NAME, 'full_name');
        $address = self::exact($object['address'], ['status', 'value'], 'id_review address');
        $addressStatus = self::status($address['status']);
        return [
            'side' => 'front',
            'full_name' => ['status' => $status, 'value' => $text],
            'address' => ['status' => $addressStatus, 'value' => self::value($address['value'], $addressStatus, self::MAX_ADDRESS, 'address')],
        ];
    }

    // ================= The application form =================

    /**
     * The form details compared with the ID (the applicant's own: owner or representative).
     *
     * @param array<string, mixed> $row first_name, middle_name, last_name, address_line, city, province, zip_code
     * @return array{first_name: string, middle_name: string, last_name: string, full_name: string, address_line: string, city: string, province: string, zip_code: string, address: string}
     */
    public static function form(array $row): array
    {
        $get = static fn (string $key): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($row[$key] ?? '')));
        $form = [];
        foreach (['first_name', 'middle_name', 'last_name', 'address_line', 'city', 'province', 'zip_code'] as $key) {
            $form[$key] = $get($key);
        }
        $form['full_name'] = implode(' ', array_filter([$form['first_name'], $form['middle_name'], $form['last_name']], static fn (string $p): bool => $p !== ''));
        $place = implode(', ', array_filter([$form['address_line'], $form['city'], $form['province']], static fn (string $p): bool => $p !== ''));
        $form['address'] = trim($place . ($place !== '' && $form['zip_code'] !== '' ? ' ' . $form['zip_code'] : ''));
        return $form;
    }

    // ================= Comparison =================

    /** @return array{full_name: array{result: string, reasons: list<string>}, address: array{result: string, reasons: list<string>}, overall: string} */
    public static function compare(array $extracted, array $form): array
    {
        $name = self::compareFullName($extracted['full_name'], $form);
        $address = self::compareAddress($extracted['address'], $form);
        return ['full_name' => $name, 'address' => $address, 'overall' => self::worst([$name['result'], $address['result']])];
    }

    /** @return array{result: string, reasons: list<string>} */
    public static function compareFullName(array $extracted, array $form): array
    {
        if ($form['full_name'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
            return ['result' => 'needs_review', 'reasons' => [$form['full_name'] === '' ? 'not_on_form' : 'incomplete_on_form']];
        }
        if ($extracted['status'] !== 'read' || $extracted['value'] === null) {
            return ['result' => 'needs_review', 'reasons' => ['not_read_' . $extracted['status']]];
        }
        $idName = self::normalizeName((string) $extracted['value']);
        $formName = self::normalizeName($form['full_name']);
        if ($idName === $formName) {
            return ['result' => 'match', 'reasons' => ['same']];
        }
        $idWords = explode(' ', $idName);
        $formWords = explode(' ', $formName);
        $idSorted = $idWords;
        $formSorted = $formWords;
        sort($idSorted);
        sort($formSorted);
        if ($idSorted === $formSorted) {
            return ['result' => 'needs_review', 'reasons' => ['word_order_difference']];
        }
        $difference = self::comparePart($idName, $formName);
        return match ($difference) {
            'same' => ['result' => 'match', 'reasons' => ['same']],
            'different' => ['result' => 'mismatch', 'reasons' => ['different_full_name']],
            'accents' => ['result' => 'needs_review', 'reasons' => ['accent_difference']],
            'missing_on_form', 'missing_on_id', 'missing_part' => ['result' => 'needs_review', 'reasons' => ['possible_missing_name_part']],
            'spacing' => ['result' => 'needs_review', 'reasons' => ['spacing_difference']],
            'ocr' => ['result' => 'needs_review', 'reasons' => ['possible_ocr_misread']],
            'initial' => ['result' => 'needs_review', 'reasons' => ['initial_or_abbreviation']],
            default => ['result' => 'needs_review', 'reasons' => ['possible_spelling_difference']],
        };
    }

    /**
     * One name part: same (after letter case, spaces and punctuation), a variant that needs a person
     * (accents, spacing inside the part, an initial or abbreviation, one letter, OCR digits, a missing
     * word), missing on one side, or different.
     */
    private static function comparePart(?string $id, string $form): string
    {
        $i = self::normalizeName((string) $id);
        $f = self::normalizeName($form);
        if ($i === $f) {
            return 'same';
        }
        if ($f === '') {
            return 'missing_on_form';
        }
        if ($i === '') {
            return 'missing_on_id';
        }
        if (self::fold($i) === self::fold($f)) {
            return 'accents';
        }
        $iFold = self::fold($i);
        $fFold = self::fold($f);
        if (str_replace(' ', '', $iFold) === str_replace(' ', '', $fFold)) {
            return 'spacing';
        }
        if (preg_match('/\d/', $iFold) === 1 && strtr($iFold, self::LETTER_LOOKALIKES) === $fFold) {
            return 'ocr';
        }
        $iWords = explode(' ', $iFold);
        $fWords = explode(' ', $fFold);
        if (count($iWords) === count($fWords)) {
            $initials = true;
            foreach ($iWords as $n => $word) {
                $other = $fWords[$n];
                $short = static fn (string $a, string $b): bool => (strlen($a) === 1 && str_starts_with($b, $a)) || (self::NAME_ABBREVIATIONS[$a] ?? null) === $b;
                if ($word !== $other && !$short($word, $other) && !$short($other, $word)) {
                    $initials = false;
                    break;
                }
            }
            if ($initials) {
                return 'initial';
            }
        }
        if (self::containsAll($iWords, $fWords) || self::containsAll($fWords, $iWords)) {
            return 'missing_part';
        }
        if (min(strlen($iFold), strlen($fFold)) >= 4 && levenshtein($iFold, $fFold) === 1) {
            return 'spelling';
        }
        return 'different';
    }

    /** @return array{result: string, reasons: list<string>, missing?: list<string>, extra?: list<string>} */
    public static function compareAddress(array $extracted, array $form): array
    {
        $formWords = self::addressWords($form['address_line'] . ' ' . $form['city'] . ' ' . $form['province']);
        if ($formWords === []) {
            return ['result' => 'needs_review', 'reasons' => ['not_on_form']];
        }
        if ($extracted['status'] !== 'read' || $extracted['value'] === null) {
            return ['result' => 'needs_review', 'reasons' => ['not_read_' . $extracted['status']]];
        }
        $idWords = array_values(array_filter(self::addressWords((string) $extracted['value']),
            static fn (string $w): bool => $form['zip_code'] === '' || $w !== strtoupper($form['zip_code'])));
        // Exact words first, then near words (one letter in a word of 4+ letters, or OCR digits/letters).
        $leftForm = $formWords;
        $leftId = $idWords;
        foreach ($leftForm as $n => $word) {
            $at = array_search($word, $leftId, true);
            if ($at !== false) {
                unset($leftForm[$n], $leftId[$at]);
            }
        }
        $near = [];
        foreach ($leftForm as $n => $word) {
            foreach ($leftId as $m => $other) {
                if (self::nearWord($word, $other)) {
                    $near[] = $word;
                    unset($leftForm[$n], $leftId[$m]);
                    break;
                }
            }
        }
        $missing = array_values($leftForm);
        $extra = array_values($leftId);
        if ($missing === [] && $extra === [] && $near === []) {
            $same = self::normalizeName((string) $extracted['value']) === self::normalizeName($form['address']);
            return ['result' => 'match', 'reasons' => [$same ? 'same' : 'format']];
        }
        // A place the form names that appears nowhere in the ID address (not even nearly) is a real difference.
        $absent = static function (array $words) use ($idWords): bool {
            foreach ($words as $word) {
                foreach ($idWords as $other) {
                    if ($word === $other || self::nearWord($word, $other)) {
                        return false;
                    }
                }
            }
            return $words !== [];
        };
        $reasons = [];
        if ($absent(self::addressWords($form['city']))) {
            $reasons[] = 'different_city';
        }
        $barangay = self::barangay($form['address_line']);
        if ($absent(self::addressWords($barangay))) {
            $reasons[] = 'different_barangay';
        }
        $found = count($formWords) - count($missing);
        if ($reasons === [] && $found * 2 < count($formWords)) {
            $reasons[] = 'mostly_different';
        }
        if ($reasons !== []) {
            return ['result' => 'mismatch', 'reasons' => $reasons, 'missing' => $missing, 'extra' => $extra];
        }
        foreach (['spelling' => $near !== [], 'missing_on_id' => $missing !== [], 'extra_on_id' => $extra !== []] as $reason => $applies) {
            if ($applies) {
                $reasons[] = $reason;
            }
        }
        return ['result' => 'needs_review', 'reasons' => $reasons, 'missing' => $missing, 'extra' => $extra];
    }

    /** The barangay named in the form's address line ("..., Barangay POBLACION"), or ''. */
    private static function barangay(string $line): string
    {
        return preg_match_all('/\b(?:BARANGAY|BRGY|BGY)\.?\s+([^,]+)/iu', $line, $m) ? (string) end($m[1]) : '';
    }

    /** @return list<string> */
    private static function addressWords(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::fold(mb_strtoupper($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = self::ADDRESS_EQUIVALENTS[$word] ?? $word;
            if (!in_array($word, self::ADDRESS_FORMAT_WORDS, true)) {
                $words[] = $word;
            }
        }
        return $words;
    }

    private static function nearWord(string $a, string $b): bool
    {
        if (min(strlen($a), strlen($b)) >= 4 && levenshtein($a, $b) === 1) {
            return true;
        }
        $digits = array_flip(self::LETTER_LOOKALIKES);
        // House numbers: letters OCR reads for digits ("I23" for "123").
        return preg_match('/\d/', $a . $b) === 1 && strlen($a) === strlen($b) && strtr($a, $digits) === strtr($b, $digits);
    }

    // ================= AI (worker side) =================

    /**
     * What the model is given about the applicant's own ID (all untrusted: the extracted values come
     * from the document, the form values from the applicant).
     */
    public static function modelInput(string $key, array $extracted, array $form, array $comparison, string $ocrState): array
    {
        return [
            'document_key' => $key,
            'side' => 'front',
            'ocr_quality' => $ocrState,
            'application_form' => ['full_name' => $form['full_name'], 'address' => $form['address']],
            'extracted_from_id' => [
                'full_name' => ['status' => $extracted['full_name']['status'], 'value' => $extracted['full_name']['value']],
                'address' => ['status' => $extracted['address']['status'], 'value' => $extracted['address']['value']],
            ],
            'system_comparison' => [
                'full_name' => ['result' => $comparison['full_name']['result'], 'reasons' => $comparison['full_name']['reasons']],
                'address' => ['result' => $comparison['address']['result'], 'reasons' => $comparison['address']['reasons']],
            ],
        ];
    }

    /** JSON schema of the model's "id_review" answer (codes only). */
    public static function modelSchema(string $key): array
    {
        $field = static fn (array $codes): array => ['type' => 'object', 'additionalProperties' => false, 'required' => ['assessment', 'differences'], 'properties' => [
            'assessment' => ['type' => 'string', 'enum' => self::AI_ASSESSMENTS],
            'differences' => ['type' => 'array', 'maxItems' => self::MAX_DIFFERENCES, 'items' => ['type' => 'string', 'enum' => array_keys($codes)]],
        ]];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'overall', 'full_name', 'address', 'attention'], 'properties' => [
            'key' => ['type' => 'string', 'enum' => [$key]],
            'overall' => ['type' => 'string', 'enum' => self::AI_OVERALL],
            'full_name' => $field(self::AI_NAME_DIFFERENCES),
            'address' => $field(self::AI_ADDRESS_DIFFERENCES),
            'attention' => ['type' => 'array', 'maxItems' => self::MAX_ATTENTION, 'items' => ['type' => 'string', 'enum' => array_keys(self::AI_ATTENTION)]],
        ]];
    }

    /** Rules for the model about "id_review" (appended to the system prompt when an ID is reviewed). */
    public static function modelRules(): string
    {
        return <<<'RULES'
10. "id_review" is about the applicant's own National ID (front side only), described in the ID CHECK section. It gives the application form's full name and address, the full name and address OCR extracted from the ID with a status for each, and the system's comparison. Analyze only those two fields and answer only with codes. Ignore every other ID detail, including date of birth, sex and ID numbers:
   - overall: "match" only when both the full name and the address clearly agree; "mismatch" when either clearly refers to a different person or place; otherwise "needs_review".
   - full_name / address: "assessment" is "agrees", "differs" or "cannot_tell" (use "cannot_tell" whenever the status is not "read"); "differences" lists the kinds of difference you see, empty if none.
   - attention: what staff should check on the original ID.
   You only compare text. You cannot tell whether the ID is genuine, and you cannot check signatures, photos or security features. Never invent a value that was not extracted.
RULES;
    }

    /**
     * Strictly validates the model's "id_review" (worker and application).
     *
     * @param list<string> $allowedKeys
     * @throws UnexpectedValueException
     */
    public static function validateModelReview(mixed $value, array $allowedKeys): array
    {
        $object = self::exact($value, ['key', 'overall', 'full_name', 'address', 'attention'], 'id_review');
        if (!is_string($object['key']) || !in_array($object['key'], $allowedKeys, true)) {
            throw new UnexpectedValueException('id_review names an unexpected document');
        }
        $field = static function (mixed $value, array $codes, string $what): array {
            $f = self::exact($value, ['assessment', 'differences'], $what);
            return ['assessment' => self::enum($f['assessment'], self::AI_ASSESSMENTS, $what), 'differences' => self::codes($f['differences'], array_keys($codes), self::MAX_DIFFERENCES, $what)];
        };
        return [
            'key' => $object['key'],
            'overall' => self::enum($object['overall'], self::AI_OVERALL, 'id_review overall'),
            'full_name' => $field($object['full_name'], self::AI_NAME_DIFFERENCES, 'id_review full_name'),
            'address' => $field($object['address'], self::AI_ADDRESS_DIFFERENCES, 'id_review address'),
            'attention' => self::codes($object['attention'], array_keys(self::AI_ATTENTION), self::MAX_ATTENTION, 'id_review attention'),
        ];
    }

    // ================= Staff view (application side) =================

    /** ID slots of each request type that hold the applicant's own ID. */
    public static function ownIdKey(bool $isOwner): string
    {
        return $isOwner ? 'ownerId' : 'requesterId';
    }

    /**
     * What the Staff page shows: the applicant's own ID front, its extracted Full Name and Address,
     * the comparison with the form, and the AI's findings worded from its codes. Null when the stored
     * result predates this review (result version 5 or earlier).
     *
     * @param list<array> $documents stored document checks (DocumentAnalysis::classify()['documents'])
     */
    public static function staffView(array $documents, array $form, bool $isOwner): ?array
    {
        $byKey = [];
        foreach ($documents as $document) {
            if (is_array($document) && isset($document['key'])) {
                $byKey[(string) $document['key']] = $document;
            }
        }
        $key = self::ownIdKey($isOwner);
        $own = $byKey[$key] ?? null;
        // Never build the applicant review from a representative/owner ID in the other slot.
        if ($own === null || !is_array($own['id_review'] ?? null)) {
            return null;
        }
        $extracted = null;
        if ($own !== null && is_array($own['id_review'] ?? null)) {
            try {
                $extracted = self::validateExtraction($own['id_review']);
            } catch (UnexpectedValueException) {
                $extracted = null;   // a stored value that is not valid is never shown
            }
        }
        $uploaded = $own !== null && ($own['requirement'] ?? '') !== 'missing';
        $comparison = $extracted === null ? null : self::compare($extracted, $form);
        $rows = [];
        foreach (['full_name' => ['Full Name', $form['full_name']], 'address' => ['Address', $form['address']]] as $field => [$label, $formValue]) {
            if ($comparison === null) {
                $rows[] = ['field' => $field, 'label' => $label, 'form' => $formValue, 'extracted' => null, 'status' => null, 'statusLabel' => null,
                    'result' => 'needs_review', 'resultLabel' => self::RESULT_LABELS['needs_review'],
                    'note' => $uploaded ? 'Nothing was extracted from this ID. Check the original.' : 'The ID was not uploaded.'];
                continue;
            }
            $read = $extracted[$field];
            $rows[] = ['field' => $field, 'label' => $label, 'form' => $formValue, 'extracted' => $read['value'],
                'status' => $read['status'], 'statusLabel' => self::STATUS_LABELS[$read['status']],
                'result' => $comparison[$field]['result'], 'resultLabel' => self::RESULT_LABELS[$comparison[$field]['result']],
                'note' => self::comparisonNote($field, $comparison[$field])];
        }
        return [
            'key' => $key,
            'label' => (string) ($own['label'] ?? ''),
            'uploaded' => $uploaded,
            'side' => 'front',
            'ocrText' => self::isConfirmedNationalIdText($own['id_front_ocr_text'] ?? null) ? $own['id_front_ocr_text'] : null,
            'rows' => $rows,
            'overall' => self::worst(array_column($rows, 'result')),
            'ai' => self::aiFindings($own, $comparison, $extracted),
            'otherIds' => [],
        ];
    }

    private static function isConfirmedNationalIdText(mixed $text): bool
    {
        if (!is_string($text)) {
            return false;
        }
        [$type, $state] = IdFieldExtractor::classify($text);
        return $type === 'philsys_national_id' && $state === 'confirmed';
    }

    /** The Staff note for one comparison row (empty for a plain match). */
    public static function comparisonNote(string $field, array $row): string
    {
        if ($field === 'full_name') {
            return match ($row['reasons'][0] ?? '') {
                'accent_difference' => 'The complete name differs only in accent marks. Check the original ID.',
                'possible_missing_name_part' => 'A word may be missing from one complete name. Check the full name on the original ID.',
                'word_order_difference' => 'The same name words appear in a different order. Check the printed full name on the original ID.',
                'spacing_difference' => 'The full name differs only in spacing. Check the original ID.',
                'possible_ocr_misread' => 'A character in the full name may have been misread by OCR. Check the original ID.',
                'initial_or_abbreviation' => 'One complete name uses an initial or abbreviation. Check the full name on the original ID.',
                'possible_spelling_or_ocr_difference' => 'The complete names differ slightly; this may be a spelling difference or OCR misread. Check the original ID.',
                'different_full_name' => 'The complete name differs. Check the original ID and application form.',
                default => '',
            };
        }
        $notes = [];
        foreach ($row['reasons'] as $reason) {
            if (str_starts_with($reason, 'not_read_')) {
                $notes[] = match (substr($reason, 9)) {
                    'incomplete' => 'Only part of the ' . ($field === 'address' ? 'address' : 'name') . ' was read from the ID, so it is not compared. Check the original.',
                    'uncertain' => 'Different OCR readings of the name disagree, so no name is shown. Check the original.',
                    'not_found' => 'The ' . ($field === 'address' ? 'address' : 'name') . ' was not found on this upload. Check that it is the front of the National ID.',
                    default => 'The ' . ($field === 'address' ? 'address' : 'name') . ' could not be read from the ID. Check the original.',
                };
                continue;
            }
            if ($reason === 'not_on_form') {
                $notes[] = 'The application form has no ' . ($field === 'address' ? 'address' : 'name') . ' to compare.';
                continue;
            }
            if ($field === 'full_name') {
                [$part, $what] = array_pad(explode(':', $reason, 2), 2, '');
                if ($part === 'same') {
                    continue;
                }
                $partLabel = ['given' => 'First name', 'middle' => 'Middle name', 'last' => 'Last name'][$part] ?? 'Name';
                $notes[] = $partLabel . ': ' . match ($what) {
                    'accents' => 'differs only in accent marks (for example Ñ and N).',
                    'spacing' => 'differs only in spacing (for example DELA CRUZ and DELACRUZ).',
                    'initial' => 'one side has an initial or abbreviation (for example MA. and MARIA).',
                    'ocr' => 'digits were read where letters are expected; probably an OCR misread.',
                    'spelling' => 'one letter differs; a spelling difference or an OCR misread.',
                    'missing_part' => 'one side has a name part the other does not.',
                    'missing_on_id' => 'on the form, but not read on the ID.',
                    'missing_on_form' => 'on the ID, but not on the form.',
                    default => 'different.',
                };
                continue;
            }
            $notes[] = match ($reason) {
                'format' => 'Differs only in abbreviations, punctuation or the ZIP code.',
                'spelling' => 'Some words differ by one letter; possibly an OCR misread.',
                'missing_on_id' => 'Not in the ID address: ' . implode(', ', array_slice($row['missing'] ?? [], 0, 4)) . '.',
                'extra_on_id' => 'Only in the ID address: ' . implode(', ', array_slice($row['extra'] ?? [], 0, 4)) . '.',
                'different_city' => 'The city or municipality on the form is not in the ID address.',
                'different_barangay' => 'The barangay on the form is not in the ID address.',
                'mostly_different' => 'Most of the address differs from the form.',
                default => '',
            };
        }
        return implode(' ', array_filter($notes));
    }

    /**
     * The AI's findings for Staff, worded here from its validated codes. When the AI gave no valid
     * answer, an unavailable state and why, never an invented assessment.
     */
    public static function aiFindings(?array $own, ?array $comparison, ?array $extracted): array
    {
        $disclaimer = 'AI-generated and advisory only. It compares text; it does not verify that the ID is genuine, does not check signatures, photos or security features, and does not approve or reject the application. Staff make the final decision.';
        $state = (string) ($own['ai_id_review_state'] ?? 'not_requested');
        $review = null;
        if ($state === 'ok' && is_array($own['ai_id_review'] ?? null)) {
            try {
                $review = self::validateModelReview($own['ai_id_review'], [(string) $own['key']]);
            } catch (UnexpectedValueException) {
                $review = null;
            }
        }
        if ($review === null) {
            $message = match ($state) {
                'invalid_output' => 'AI review unavailable: the AI model did not return a valid answer, so no AI findings are shown. Use the comparison and the original ID.',
                'skipped' => 'AI review not run: no AI model was available or no readable text was found. Use the comparison and the original ID.',
                default => 'AI review unavailable for this ID. Use the comparison and the original ID.',
            };
            return ['available' => false, 'message' => $message, 'disclaimer' => $disclaimer];
        }
        $field = static function (string $what, array $answer, array $codes): string {
            $kinds = implode('; ', array_map(static fn (string $c): string => $codes[$c], $answer['differences']));
            $start = match ($answer['assessment']) {
                'agrees' => 'The AI found that the ' . $what . ' read from the ID agrees with the application form.',
                'differs' => 'The AI found that the ' . $what . ' read from the ID differs from the application form.',
                default => 'The AI could not tell whether the ' . $what . ' read from the ID agrees with the application form.',
            };
            return $start . ($kinds !== '' ? ' It noted: ' . $kinds . '.' : '');
        };
        $notes = [];
        $system = $comparison['overall'] ?? 'needs_review';
        $rank = ['match' => 0, 'needs_review' => 1, 'mismatch' => 2];
        if ($rank[$review['overall']] < $rank[$system]) {
            $notes[] = 'The automatic comparison above found more to check than the AI did. Follow the comparison and the original ID.';
        }
        foreach (['full_name' => 'full name', 'address' => 'address'] as $key => $what) {
            if ($review[$key]['assessment'] === 'agrees' && ($extracted[$key]['status'] ?? 'unreadable') !== 'read') {
                $notes[] = 'The ' . $what . ' was not read completely from the ID, so the AI\'s "agrees" cannot be relied on.';
            }
        }
        $attention = array_map(static fn (string $c): string => self::AI_ATTENTION[$c], $review['attention']);
        $recommendation = match ($review['overall']) {
            'match' => 'Confirm on the original ID that the full name and address match the application form before continuing.',
            'mismatch' => 'Compare the original ID with the application form carefully before continuing; the details may refer to a different person or address.',
            default => 'Check the items above on the original ID before continuing.',
        };
        return [
            'available' => true,
            'overall' => $review['overall'],
            'overallLabel' => self::AI_OVERALL_LABELS[$review['overall']],
            'fullName' => $field('full name', $review['full_name'], self::AI_NAME_DIFFERENCES),
            'address' => $field('address', $review['address'], self::AI_ADDRESS_DIFFERENCES),
            'attention' => $attention,
            'recommendation' => $recommendation,
            'notes' => $notes,
            'disclaimer' => $disclaimer,
        ];
    }

    // ================= Helpers =================

    /** The most serious result: mismatch > needs_review > match. */
    public static function worst(array $results): string
    {
        return in_array('mismatch', $results, true) ? 'mismatch' : (in_array('needs_review', $results, true) || $results === [] ? 'needs_review' : 'match');
    }

    /** Letter case, spaces and punctuation only (". , ' ’"; a hyphen counts as a space). Accents and letters stay. */
    private static function normalizeName(string $text): string
    {
        $text = mb_strtoupper(str_replace(['-', '‐', '–'], ' ', $text));
        $text = (string) preg_replace("/[.,'’`]+/u", '', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Without accent marks (for spotting an accent-only difference). */
    private static function fold(string $text): string
    {
        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            $text = is_string($decomposed) ? $decomposed : $text;
        }
        return (string) preg_replace('/\p{M}+/u', '', $text);
    }

    /** True when every word of $small is in $big (each used once). */
    private static function containsAll(array $big, array $small): bool
    {
        foreach ($small as $word) {
            $at = array_search($word, $big, true);
            if ($at === false) {
                return false;
            }
            unset($big[$at]);
        }
        return true;
    }

    private static function exact(mixed $value, array $keys, string $what): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new UnexpectedValueException($what . ' must be an object');
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new UnexpectedValueException($what . ' has missing or unexpected fields');
        }
        return $value;
    }

    private static function status(mixed $value): string
    {
        return self::enum($value, self::STATUSES, 'id_review status');
    }

    private static function enum(mixed $value, array $allowed, string $what): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new UnexpectedValueException($what . ' has an unexpected value');
        }
        return $value;
    }

    /** @return list<string> distinct codes from $allowed, at most $max */
    private static function codes(mixed $value, array $allowed, int $max, string $what): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw new UnexpectedValueException($what . ' must be a short list');
        }
        $codes = [];
        foreach ($value as $code) {
            $code = self::enum($code, $allowed, $what);
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /** A value that matches its status: text for read/incomplete, null otherwise. */
    private static function value(mixed $value, string $status, int $max, string $what): ?string
    {
        if (!in_array($status, self::STATUSES_WITH_VALUE, true)) {
            if ($value !== null) {
                throw new UnexpectedValueException($what . ' has a value its status does not allow');
            }
            return null;
        }
        return self::clean($value, $max, $what);
    }

    /** One line of printable text with a letter or digit, at most $max characters. */
    private static function clean(mixed $value, int $max, string $what): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1
            || mb_strlen($value) > $max || trim($value) !== $value || preg_match('/[\p{L}\p{N}]/u', $value) !== 1) {
            throw new UnexpectedValueException($what . ' is not valid text');
        }
        return $value;
    }
}
