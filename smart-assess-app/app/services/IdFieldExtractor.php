<?php
declare(strict_types=1);

require_once __DIR__ . '/IdDocumentTypes.php';
require_once __DIR__ . '/DocumentAnalysis.php';

/**
 * Reads the printed fields of a Philippine government-issued ID from its OCR text, by the ID's type
 * (IdDocumentTypes). Pure: no I/O, nothing logged. Values exist only in the returned array, in
 * memory; what leaves the worker is each field's state ('read' / 'unverified'), never a value
 * (except the holder name, which is checked against the text like any other name).
 *
 * Rules:
 *  - The type comes from printed titles and issuers (DocumentAnalysis::kindEvidence), never a file
 *    name. Several different ID titles in one text: the type is a conflict and stays unverified.
 *  - A field is 'read' only when its value has the field's form (capital-letter name, real calendar
 *    date, the printed number format). Anything else is 'unverified': nothing is guessed or completed.
 *  - OCR letter/digit mix-ups (O/0, I/1, S/5, B/8, Z/2) are tried only inside numbers and dates, and
 *    a value read that way stays 'unverified' unless a check digit confirms it (passport machine
 *    zone). Names are never changed.
 *  - Passport: the visual zone and the machine-readable zone (MRZ) are both read; a field the two
 *    read differently is unverified. MRZ numbers and dates count only when their check digit is right.
 *  - Reading fields says nothing about whether the ID is genuine.
 */
final class IdFieldExtractor
{
    /** Other printed labels: they end a value on the same line but are not fields here. */
    private const STOP_LABELS = ['sex', 'kasarian', 'address', 'tirahan', 'nationality', 'height', 'weight', 'blood type', 'signature', 'eyes color',
        'restrictions', 'conditions', 'agency code', 'date of issue', 'issue date', 'place of birth', 'lugar ng kapanganakan', 'type', 'uri',
        'issuing authority', 'civil status', 'marital status', 'date of birth', 'valid until', 'expiration date'];
    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    private const MONTH_PATTERN = 'JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUNE?|JULY?|AUG(?:UST)?|SEPT?(?:EMBER)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?';
    /** Letters OCR commonly reads for digits. */
    private const DIGIT_LOOKALIKES = ['O' => '0', 'D' => '0', 'Q' => '0', 'I' => '1', 'L' => '1', '|' => '1', 'S' => '5', 'B' => '8', 'Z' => '2', 'G' => '6'];

    /**
     * @return array{
     *   type: ?string, type_state: string, evidence: list<string>,
     *   fields: array<string, array{state: string, value: ?string}>,
     *   missing_required: list<string>, name: ?string, expiry: string, label_fields: int
     * }
     */
    public static function extract(string $text, ?string $today = null): array
    {
        $text = DocumentAnalysis::normalizeText($text);
        [$type, $state, $evidence] = self::classify($text);
        if ($type === null) {
            return ['type' => null, 'type_state' => $state, 'evidence' => $evidence, 'fields' => [], 'missing_required' => [], 'name' => null, 'name_complete' => true,
                'expiry' => 'not_applicable', 'label_fields' => 0];
        }
        $read = self::extractAs($type, $text, $today);
        $identifying = IdDocumentTypes::type($type)['identifying'] ?? [];
        $structured = array_filter($identifying, static fn (string $field): bool => ($read['fields'][$field]['state'] ?? '') === 'read') !== [];
        if ($state === 'confirmed' && !$structured) {
            $state = 'title_only';   // a printed title alone is not an ID: none of its identifying details were read
        }
        return ['type_state' => $state, 'evidence' => $evidence] + $read;
    }

    /**
     * The fields of $text read as an ID of type $kind (the type already decided).
     *
     * @return array{type: string, fields: array<string, array{state: string, value: ?string}>, missing_required: list<string>, name: ?string, expiry: string, label_fields: int}
     */
    public static function extractAs(string $kind, string $text, ?string $today = null): array
    {
        $definition = IdDocumentTypes::type($kind);
        $text = DocumentAnalysis::normalizeText($text);
        if ($definition === null) {
            return ['type' => $kind, 'fields' => [], 'missing_required' => [], 'name' => null, 'name_complete' => true, 'expiry' => 'not_applicable', 'label_fields' => 0];
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn (string $line): bool => $line !== ''));
        $spans = array_map(static fn (string $line): array => self::labelSpans($line, $definition['fields']), $lines);
        $fields = [];
        $labelled = [];
        foreach ($definition['fields'] as $field => $rule) {
            $fields[$field] = self::readField($field, $rule, $lines, $spans, $labelled);
        }
        if (!empty($definition['mrz'])) {
            $fields = self::withMachineZone($fields, self::machineZone($lines));
        }
        $missing = array_values(array_filter($definition['required'], static fn (string $field): bool => $fields[$field]['state'] !== 'read'));
        $expiry = 'not_applicable';
        if (isset($definition['expiry'])) {
            $value = $fields[$definition['expiry']]['state'] === 'read' ? $fields[$definition['expiry']]['value'] : null;
            $expiry = $value === null ? 'not_read' : ($value < ($today ?? date('Y-m-d')) ? 'expired' : 'valid');
        }
        // A label with the word "Name" that matched no field, above a name-like value: a name field OCR
        // misread past recognition ("Maddie Name"). The name would be missing that part, so it is not given.
        $unknownNameField = false;
        foreach ($lines as $index => $line) {
            $named = preg_match('/\bnames?\b/i', $line) === 1;
            $claimed = array_filter($spans[$index], static fn (array $span): bool => $span[0] !== '' && in_array($span[0], $definition['name'], true)) !== [];
            if ($named && !$claimed && isset($lines[$index + 1]) && $spans[$index + 1] === [] && self::name($lines[$index + 1]) !== null) {
                $unknownNameField = true;
            }
        }
        // A name-like line no field claimed, right after a name field's label or value: a name part whose
        // label OCR lost entirely ("LORENZO" then "AQUINO" with no "Middle Name" between).
        $nameValues = [];
        foreach ($definition['name'] as $field) {
            if ($fields[$field]['state'] === 'read') {
                $nameValues[] = (string) $fields[$field]['value'];
            }
        }
        $fieldValues = array_map(static fn (array $f): string => (string) $f['value'], array_filter($fields, static fn (array $f): bool => $f['state'] === 'read'));
        foreach ($lines as $index => $line) {
            if ($index === 0 || $spans[$index] !== [] || self::name($line) === null || in_array($line, $fieldValues, true)) {
                continue;
            }
            $previous = $lines[$index - 1];
            $afterNameLabel = array_filter($spans[$index - 1], static fn (array $span): bool => in_array($span[0], $definition['name'], true)) !== [];
            if (in_array($previous, $nameValues, true) || $afterNameLabel) {
                $unknownNameField = true;
            }
        }
        $name = $unknownNameField ? null : self::holderName($definition, $fields, $labelled);
        return ['type' => $kind, 'fields' => $fields, 'missing_required' => $missing, 'name' => $name, 'name_complete' => $name === null || self::nameComplete($definition, $name),
            'expiry' => $expiry, 'label_fields' => count($labelled)];
    }

    /**
     * [type, state, evidence] from printed titles only: state 'confirmed' (one ID's title; extract()
     * then also requires an identifying field), 'conflict' (titles of different IDs; the type
     * returned is only the one tried for reading), 'unverified' (no ID title).
     *
     * @return array{0: ?string, 1: string, 2: list<string>}
     */
    public static function classify(string $text): array
    {
        $evidence = array_values(array_intersect(IdDocumentTypes::PRECEDENCE, DocumentAnalysis::kindEvidence($text)));
        if (in_array('umid', $evidence, true)) {
            $evidence = array_values(array_diff($evidence, ['sss_id', 'gsis_id']));   // a UMID carries its issuer's name
        }
        if ($evidence === []) {
            return [null, 'unverified', []];
        }
        return [$evidence[0], count($evidence) === 1 ? 'confirmed' : 'conflict', $evidence];
    }

    // ---- labels ----

    private static function fold(string $word): string
    {
        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($word, Normalizer::FORM_D);
            $word = is_string($decomposed) ? $decomposed : $word;
        }
        return mb_strtolower((string) preg_replace('/\p{M}+/u', '', $word));
    }

    private static function wordMatches(string $fold, string $label): bool
    {
        return $fold === $label || (strlen($label) >= 5 && preg_match('/^[a-z]+$/', $fold) === 1 && abs(strlen($fold) - strlen($label)) <= 1 && levenshtein($fold, $label) <= 1);
    }

    /**
     * Labels found on one line, longest phrases first and never overlapping: list of
     * [field or '' for a stop label, start byte, end byte], in line order.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function labelSpans(string $line, array $fieldRules): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $line, $m, PREG_OFFSET_CAPTURE);
        $words = array_map(static fn (array $w): array => [self::fold($w[0]), $w[1], $w[1] + strlen($w[0]), $w[0]], $m[0]);
        $phrases = [];
        foreach ($fieldRules as $field => $rule) {
            foreach ($rule['labels'] as $label) {
                $phrases[] = [$field, explode(' ', $label)];
            }
        }
        foreach (self::STOP_LABELS as $label) {
            $phrases[] = ['', explode(' ', $label)];
        }
        $found = [];
        foreach ($phrases as [$field, $parts]) {
            for ($i = 0; $i + count($parts) <= count($words); $i++) {
                $all = true;
                foreach ($parts as $k => $part) {
                    if (!self::wordMatches($words[$i + $k][0], $part)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $found[] = [$field, $i, $i + count($parts) - 1, count($parts)];
                }
            }
        }
        // Field labels win over stop labels of the same length (e.g. "date of birth" is a field where the type has it).
        usort($found, static fn (array $a, array $b): int => [$b[3], $b[0] !== '' ? 1 : 0, $a[1]] <=> [$a[3], $a[0] !== '' ? 1 : 0, $b[1]]);
        $taken = [];
        $spans = [];
        foreach ($found as [$field, $from, $to]) {
            for ($k = $from; $k <= $to; $k++) {
                if (isset($taken[$k])) {
                    continue 2;
                }
            }
            for ($k = $from; $k <= $to; $k++) {
                $taken[$k] = true;
            }
            $spans[] = [$field, $words[$from][1], $words[$to][2]];
        }
        usort($spans, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        return $spans;
    }

    /** @param array<int, true> $labelled fields whose label was seen (out) */
    private static function readField(string $field, array $rule, array $lines, array $spans, array &$labelled): array
    {
        $candidates = [];
        foreach ($lines as $index => $line) {
            $last = null;
            foreach ($spans[$index] as $position => $span) {
                if ($span[0] === $field) {
                    $last = $position;
                }
            }
            if ($last === null) {
                continue;
            }
            $labelled[$field] = true;
            $end = $spans[$index][$last][2];
            $next = $spans[$index][$last + 1][1] ?? strlen($line);
            $candidates[] = trim(substr($line, $end, $next - $end), " \t:;/|.-_");
            if (isset($lines[$index + 1]) && $spans[$index + 1] === []) {
                $candidates[] = $lines[$index + 1];
            }
        }
        $uncertain = false;
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $value = self::value($rule, $candidate);
            if ($value['value'] !== null && !$value['uncertain']) {
                return ['state' => 'read', 'value' => $value['value']];
            }
            $uncertain = $uncertain || $value['value'] !== null;
        }
        // Printed without its label: a number in its exact format, or a "LAST, FIRST MIDDLE" line.
        if (($rule['anywhere'] ?? false) && isset($rule['pattern'])) {
            if (preg_match('/(?<![A-Z0-9])' . $rule['pattern'] . '(?![A-Z0-9])/u', implode("\n", $lines), $match)) {
                return ['state' => 'read', 'value' => $match[0]];
            }
        }
        if (($rule['unlabelled_name'] ?? false)) {
            foreach ($lines as $index => $line) {
                if ($spans[$index] === [] && ($value = self::fullName($line)) !== null) {
                    return ['state' => 'read', 'value' => $value];
                }
            }
        }
        return ['state' => 'unverified', 'value' => null];
    }

    // ---- values ----

    /** @return array{value: ?string, uncertain: bool} */
    private static function value(array $rule, string $text): array
    {
        return match ($rule['type']) {
            'name' => ['value' => self::name($text), 'uncertain' => false],
            'full_name' => ['value' => self::fullName($text), 'uncertain' => false],
            'word' => ['value' => preg_match('/^\p{Lu}{3,20}$/u', $text) === 1 ? $text : null, 'uncertain' => false],
            'date' => self::date($text),
            'number' => self::number($rule['pattern'], $text),
            default => ['value' => null, 'uncertain' => false],
        };
    }

    /** A name value as printed: capital letters (". ' -" and spaces allowed), a word of 3+ letters, no document words. */
    public static function name(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " ,;:");
        // A period after a whole word is OCR noise ("CRUZ."); after an initial or "MA." it is part of the name.
        $text = (string) preg_replace("/(\\p{Lu}{3,})\\.$/u", '$1', $text);
        if ($text === '' || mb_strlen($text) > 40 || preg_match("/^\\p{Lu}[\\p{Lu}\\p{M} .'’-]*[\\p{Lu}.]$/u", $text) !== 1) {
            return null;
        }
        $words = array_values(array_filter(preg_split("/[\\s.'’-]+/u", $text) ?: [], static fn (string $w): bool => $w !== ''));
        if ($words === [] || max(array_map('mb_strlen', $words)) < 3) {
            return null;
        }
        foreach ($words as $word) {
            if (in_array(self::fold($word), DocumentAnalysis::NOT_NAME_WORDS, true)) {
                return null;
            }
        }
        return $text;
    }

    /** "LAST, FIRST MIDDLE" in capitals: both sides names. */
    public static function fullName(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (substr_count($text, ',') !== 1 || mb_strlen($text) > 80) {
            return null;
        }
        [$last, $rest] = array_map(static fn (string $part): ?string => self::name(trim($part)), explode(',', $text));
        return $last !== null && $rest !== null ? $last . ', ' . $rest : null;
    }

    /** @return array{value: ?string, uncertain: bool} Y-m-d; uncertain when digits had to be read from look-alike letters */
    private static function date(string $text): array
    {
        $upper = mb_strtoupper($text);
        $parsed = self::parseDate($upper);
        if ($parsed !== null) {
            return ['value' => $parsed, 'uncertain' => false];
        }
        $fixed = (string) preg_replace_callback('/(?<![A-Z])[0-9OQDILSBZG|]{1,4}(?![A-Z])/', static fn (array $m): string => strtr($m[0], self::DIGIT_LOOKALIKES), $upper);
        $parsed = self::parseDate($fixed);
        return ['value' => $parsed, 'uncertain' => $parsed !== null];
    }

    private static function parseDate(string $upper): ?string
    {
        $month = static fn (string $name): int => self::MONTHS[substr($name, 0, 3)] ?? 0;
        if (preg_match('/\b(' . self::MONTH_PATTERN . ')\.?\s+([0-9]{1,2}),?\s+([0-9]{4})\b/', $upper, $m)) {
            [$y, $mo, $d] = [(int) $m[3], $month($m[1]), (int) $m[2]];
        } elseif (preg_match('/\b([0-9]{1,2})\s+(' . self::MONTH_PATTERN . ')\.?,?\s+([0-9]{4})\b/', $upper, $m)) {
            [$y, $mo, $d] = [(int) $m[3], $month($m[2]), (int) $m[1]];
        } elseif (preg_match('/\b([0-9]{4})[\/.-]([0-9]{1,2})[\/.-]([0-9]{1,2})\b/', $upper, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return $y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** @return array{value: ?string, uncertain: bool} */
    private static function number(string $pattern, string $text): array
    {
        $upper = mb_strtoupper($text);
        if (preg_match('/(?<![A-Z0-9])' . $pattern . '(?![A-Z0-9])/u', $upper, $m)) {
            return ['value' => $m[0], 'uncertain' => false];
        }
        // Digit places may hold look-alike letters: found that way, the number stays unverified.
        $tolerant = str_replace('[0-9]', '[0-9OQDILSBZG|]', $pattern);
        if (preg_match('/(?<![A-Z0-9])' . $tolerant . '(?![A-Z0-9])/u', $upper, $m)) {
            return ['value' => $m[0], 'uncertain' => true];
        }
        return ['value' => null, 'uncertain' => false];
    }

    /**
     * False when a name printed "LAST, FIRST MIDDLE" on one line has a single word after the comma:
     * OCR may have dropped the middle name (or a second given name), and nothing on the card shows
     * it. Labelled names are complete by construction (a labelled part not read gives no name).
     */
    private static function nameComplete(array $definition, string $name): bool
    {
        if ($definition['name'] !== ['full_name']) {
            return true;
        }
        $rest = trim(explode(',', $name, 2)[1] ?? '');
        return count(array_filter(preg_split('/\s+/u', $rest) ?: [], static fn (string $w): bool => $w !== '')) >= 2;
    }

    /**
     * The holder's name from the type's name fields ("LAST, GIVEN MIDDLE"), or null unless every
     * required name field was read AND every optional name field whose label is printed was read
     * too: a middle name the card prints but OCR could not read would otherwise give a shortened
     * name that looks complete.
     *
     * @param array<string, true> $labelled
     */
    private static function holderName(array $definition, array $fields, array $labelled): ?string
    {
        $parts = $definition['name'];
        foreach ($parts as $field) {
            $required = in_array($field, $definition['required'], true);
            if ($fields[$field]['state'] !== 'read' && ($required || isset($labelled[$field]))) {
                return null;
            }
        }
        if ($parts === ['full_name']) {
            return $fields['full_name']['value'];
        }
        $given = array_values(array_filter(array_map(static fn (string $f): ?string => $fields[$f]['state'] === 'read' ? $fields[$f]['value'] : null, array_slice($parts, 1))));
        return $fields[$parts[0]]['value'] . ', ' . implode(' ', $given);
    }

    // ---- passport machine-readable zone ----

    /**
     * The two MRZ lines of a passport, read with their check digits: surname and given names (no
     * check digit: used only to confirm the visual zone), and the number, birth date and expiry
     * (each only when its own check digit AND line 2's composite check digit are right, on a complete
     * 44-character line). Check digits catch misreads; they say nothing about whether a passport is genuine.
     *
     * @return array<string, string> field => value
     */
    public static function machineZone(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $row = strtoupper(str_replace([' ', '«', '‹', '﹤'], ['', '<', '<', '<'], $line));
            if ((strlen($row) >= 30 && strlen($row) <= 50 && str_contains($row, '<<')) || preg_match('/^P.PHL/', $row) === 1) {
                $rows[] = $row;
            }
        }
        $out = [];
        foreach ($rows as $index => $row) {
            if (!preg_match('/^P.PHL([A-Z<]+)$/', $row, $m)) {
                continue;
            }
            // SURNAME<<GIVEN<NAMES<<<...: the given names end at the next "<<" (the filler after them
            // is often misread as letters, which must not become part of a name).
            $names = explode('<<', $m[1]);
            $surname = trim(str_replace('<', ' ', $names[0]));
            $given = trim((string) preg_replace('/ +/', ' ', str_replace('<', ' ', $names[1] ?? '')));
            if (preg_match('/^[A-Z ]{2,}$/', $surname) && preg_match('/^[A-Z ]{2,}$/', $given)) {
                $out['surname'] = $surname;
                $out['given_names'] = $given;
            }
            // Line 2 (TD3, 44 characters): its numbers and dates count only when the line is complete and
            // its composite check digit (position 44, over positions 1-10, 14-20 and 22-43) is right,
            // as well as each field's own check digit. A short or corrupted line gives none of them.
            $raw = $rows[$index + 1] ?? '';
            $digits = static fn (string $s): string => strtr($s, self::DIGIT_LOOKALIKES);
            if (strlen($raw) !== 44) {
                break;
            }
            $second = $raw;
            foreach ([9, 19, 27, 42, 43] as $at) {
                $second[$at] = $second[$at] === '<' ? '<' : $digits($second[$at]);
            }
            $second = substr($second, 0, 13) . $digits(substr($second, 13, 6)) . substr($second, 19, 2) . $digits(substr($second, 21, 6)) . substr($second, 27);
            if (self::checkDigit(substr($second, 0, 10) . substr($second, 13, 7) . substr($second, 21, 22)) !== $second[43]) {
                break;
            }
            $number = substr($second, 0, 9);
            if (preg_match('/^[A-Z][0-9]{7}[A-Z]$/', $number) && self::checkDigit($number) === $second[9]) {
                $out['passport_number'] = $number;
            }
            foreach (['birth_date' => [13, 19, false], 'expiry_date' => [21, 27, true]] as $field => [$at, $check, $future]) {
                $value = substr($second, $at, 6);
                if (preg_match('/^[0-9]{6}$/', $value) && self::checkDigit($value) === $second[$check]) {
                    $yy = (int) substr($value, 0, 2);
                    $year = $future ? 2000 + $yy : ($yy > (int) date('y') ? 1900 + $yy : 2000 + $yy);
                    $date = sprintf('%04d-%s-%s', $year, substr($value, 2, 2), substr($value, 4, 2));
                    if (checkdate((int) substr($value, 2, 2), (int) substr($value, 4, 2), $year)) {
                        $out[$field] = $date;
                    }
                }
            }
            break;
        }
        return $out;
    }

    /** ICAO 9303 check digit (weights 7, 3, 1; '<' = 0, A = 10 ... Z = 35). */
    public static function checkDigit(string $data): string
    {
        $sum = 0;
        foreach (str_split($data) as $i => $char) {
            $value = ctype_digit($char) ? (int) $char : ($char === '<' ? 0 : (ctype_upper($char) ? ord($char) - 55 : 0));
            $sum += $value * [7, 3, 1][$i % 3];
        }
        return (string) ($sum % 10);
    }

    /**
     * Visual zone and machine zone together: a field both read must agree (names compared by their
     * letters only); a number or date read only in the machine zone counts when its check digit is
     * right; a name read only in the machine zone is not used (it has no check digit).
     */
    private static function withMachineZone(array $fields, array $mrz): array
    {
        $letters = static fn (string $s): string => (string) preg_replace('/[^A-Z]/', '', strtoupper($s));
        foreach (['surname', 'given_names', 'passport_number', 'birth_date', 'expiry_date'] as $field) {
            if (!isset($fields[$field]) || !isset($mrz[$field])) {
                continue;
            }
            $isName = in_array($field, ['surname', 'given_names'], true);
            if ($fields[$field]['state'] === 'read') {
                $same = $isName ? $letters((string) $fields[$field]['value']) === $letters($mrz[$field]) : $fields[$field]['value'] === $mrz[$field];
                if (!$same) {
                    $fields[$field] = ['state' => 'unverified', 'value' => null];   // the two zones disagree
                }
            } elseif (!$isName) {
                $fields[$field] = ['state' => 'read', 'value' => $mrz[$field]];
            }
        }
        return $fields;
    }
}
