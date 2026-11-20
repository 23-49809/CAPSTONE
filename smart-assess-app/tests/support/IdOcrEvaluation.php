<?php
declare(strict_types=1);

require_once __DIR__ . '/IdCardFixtures.php';

/**
 * Repeatable OCR evaluation of the ID reading path on FICTIONAL cards (IdCardFixtures) against the
 * hand-written ground truth in tests/fixtures/ocr-ground-truth. Each fixture runs in its own PHP
 * process (tests/tools/ocr-evaluate-one.php, real Tesseract and Poppler), so its time and peak
 * memory are its own. Results are reported per document type; nothing is averaged across types.
 *
 * Metrics, per type:
 *  - classification: the type state (confirmed / title only ...) and type as expected;
 *  - false classification: confirmed as a different type (or a non-ID confirmed as an ID);
 *  - name: the name reported (only for a confirmed type, as the worker does) equals the expected name;
 *    false name match: a name reported that is not the expected one; unverified name: none reported
 *    where one was expected;
 *  - fields: exact match of every ground-truth field (a field not printed must stay unverified);
 *    character error rate (CER) over printed field values (edit distance / characters);
 *  - unverified required fields; expiry verdicts; time (average, maximum); peak resident memory.
 */
final class IdOcrEvaluation
{
    /** Expiry is judged on this date, so results do not change as the calendar moves. */
    public const EVALUATION_DATE = '2026-11-20';
    public const QUICK_CONDITIONS = ['clear', 'missing_field', 'rotated_90', 'pdf_incomplete_text'];
    public const TYPE_ORDER = ['philsys_national_id', 'passport', 'drivers_license', 'umid', 'sss_id', 'philhealth_id', 'postal_id'];
    public const TYPE_NAMES = ['philsys_national_id' => 'PhilSys', 'passport' => 'Passport', 'drivers_license' => 'Driver\'s License', 'umid' => 'UMID',
        'sss_id' => 'SSS', 'philhealth_id' => 'PhilHealth', 'postal_id' => 'Postal ID'];

    private IdCardFixtures $fixtures;

    public function __construct(private readonly string $workDirectory, private readonly array $tools, private readonly string $truthDirectory)
    {
        $this->fixtures = new IdCardFixtures();
    }

    /** @return array<string, string>|null the four OCR programs, or null when one is missing */
    public static function tools(): ?array
    {
        $tools = [];
        foreach (['tesseract', 'pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'] as $directory) {
                if (is_executable("{$directory}/{$tool}")) {
                    $tools[$tool] = "{$directory}/{$tool}";
                    break;
                }
            }
        }
        return count($tools) === 4 ? $tools : null;
    }

    /** @return array{cards: array<string, array>, negatives: array<string, array>} */
    public function truth(): array
    {
        $cards = [];
        foreach (glob($this->truthDirectory . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (basename($file) !== 'negatives.json') {
                $cards[$data['card']] = $data;
            }
        }
        $negatives = json_decode((string) file_get_contents($this->truthDirectory . '/negatives.json'), true, 32, JSON_THROW_ON_ERROR)['documents'];
        return ['cards' => $cards, 'negatives' => $negatives];
    }

    /** The expected reading of a card under a condition (its overrides applied). */
    public static function expected(array $card, string $condition): array
    {
        $expected = ['type' => $card['type'], 'type_state' => $card['type_state'], 'fields' => $card['fields'], 'name' => $card['name'],
            'expiry' => $card['expiry'], 'readability' => $card['readability'][$condition] ?? null, 'required' => $card['required']];
        $override = $card['overrides'][$condition] ?? [];
        foreach ($override['fields'] ?? [] as $field => $value) {
            $expected['fields'][$field] = $value;
        }
        foreach (['type_state', 'name', 'expiry'] as $key) {
            if (array_key_exists($key, $override)) {
                $expected[$key] = $override[$key];
            }
        }
        return $expected;
    }

    /** @return list<array{kind: string, card: string, condition: string}> */
    public function plan(bool $quick): array
    {
        $truth = $this->truth();
        $plan = [];
        foreach ($truth['cards'] as $id => $card) {
            foreach ($card['conditions'] as $condition) {
                if (!$quick || in_array($condition, self::QUICK_CONDITIONS, true)) {
                    $plan[] = ['kind' => 'card', 'card' => $id, 'condition' => $condition];
                }
            }
        }
        foreach (array_keys($truth['negatives']) as $id) {
            $plan[] = ['kind' => 'negative', 'card' => $id, 'condition' => 'negative'];
        }
        return $plan;
    }

    /** Runs every planned fixture; $progress(index, total, item) is called before each. */
    public function run(bool $quick, ?callable $progress = null): array
    {
        $plan = $this->plan($quick);
        $results = [];
        foreach ($plan as $index => $item) {
            if ($progress !== null) {
                $progress($index, count($plan), $item);
            }
            $path = $item['kind'] === 'card'
                ? $this->fixtures->write($item['card'], $item['condition'], $this->workDirectory)
                : $this->fixtures->writeNegative($item['card'], $this->workDirectory);
            $results[] = $item + ['actual' => $this->evaluateOne($path)];
            @unlink($path);
        }
        return $results;
    }

    private function evaluateOne(string $path): array
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__) . '/tools/ocr-evaluate-one.php', $path, (string) json_encode($this->tools), self::EVALUATION_DATE];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['error' => 'could not start'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $decoded = json_decode(trim($out), true);
        return is_array($decoded) ? $decoded : ['error' => 'no result'];
    }

    /** Per-fixture comparison with the ground truth. */
    public static function score(array $card, string $condition, array $actual): array
    {
        $expected = self::expected($card, $condition);
        $fieldsTotal = 0;
        $fieldMatches = 0;
        $distance = 0;
        $characters = 0;
        foreach ($expected['fields'] as $field => $value) {
            $read = $actual['fields'][$field] ?? ['state' => 'unverified', 'value' => null];
            $got = $read['state'] === 'read' ? (string) $read['value'] : null;
            $fieldsTotal++;
            $fieldMatches += ($value === null ? $got === null : $got === $value) ? 1 : 0;
            if ($value !== null) {
                $distance += min(strlen($value), levenshtein($value, $got ?? ''));
                $characters += strlen($value);
            }
        }
        $required = array_values(array_filter($expected['required'], static fn (string $f): bool => $expected['fields'][$f] !== null));
        $requiredUnverified = count(array_filter($required, static fn (string $f): bool => ($actual['fields'][$f]['state'] ?? '') !== 'read'));
        $name = $actual['name'] ?? null;
        return [
            'classification' => ($actual['type_state'] ?? null) === $expected['type_state'] && ($actual['type'] ?? null) === $expected['type'],
            'false_classification' => ($actual['type_state'] ?? null) === 'confirmed' && ($actual['type'] ?? null) !== $expected['type'],
            'name' => $name === $expected['name'],
            'false_name' => $name !== null && $name !== $expected['name'],
            'unverified_name' => $name === null && $expected['name'] !== null,
            'fields_total' => $fieldsTotal,
            'field_matches' => $fieldMatches,
            'cer_distance' => $distance,
            'cer_characters' => $characters,
            'required_total' => count($required),
            'required_unverified' => $requiredUnverified,
            'expiry' => $expected['expiry'] === 'not_applicable' ? null : ($actual['expiry'] ?? null) === $expected['expiry'],
            'readable_expected' => $expected['readability'] === 'readable' ? ($actual['ocr_state'] ?? null) === 'readable' : null,
        ];
    }

    /** Aggregates per document type (never across types), plus the negative controls. */
    public function summary(array $results): array
    {
        $truth = $this->truth();
        $types = [];
        $negatives = ['fixtures' => 0, 'confirmed' => 0, 'names' => 0, 'seconds' => [], 'rss' => []];
        $conditions = [];
        foreach ($results as $result) {
            $actual = $result['actual'];
            if ($result['kind'] === 'negative') {
                $negatives['fixtures']++;
                $negatives['confirmed'] += ($actual['type_state'] ?? null) === 'confirmed' ? 1 : 0;
                $negatives['names'] += ($actual['name'] ?? null) !== null ? 1 : 0;
                $negatives['seconds'][] = (float) ($actual['seconds'] ?? 0);
                $negatives['rss'][] = (int) ($actual['peak_rss'] ?? 0);
                continue;
            }
            $card = $truth['cards'][$result['card']];
            $s = self::score($card, $result['condition'], $actual);
            $t = &$types[$card['type']];
            $t ??= ['fixtures' => 0, 'classification' => 0, 'false_classification' => 0, 'name' => 0, 'false_name' => 0, 'unverified_name' => 0, 'fields_total' => 0,
                'field_matches' => 0, 'cer_distance' => 0, 'cer_characters' => 0, 'required_total' => 0, 'required_unverified' => 0, 'expiry_total' => 0, 'expiry' => 0,
                'readable_total' => 0, 'readable' => 0, 'seconds' => [], 'rss' => [], 'errors' => 0, 'cards' => []];
            $t['fixtures']++;
            $t['cards'][$result['card']] = true;
            $t['errors'] += isset($actual['error']) ? 1 : 0;
            foreach (['classification', 'false_classification', 'name', 'false_name', 'unverified_name'] as $flag) {
                $t[$flag] += $s[$flag] ? 1 : 0;
            }
            foreach (['fields_total', 'field_matches', 'cer_distance', 'cer_characters', 'required_total', 'required_unverified'] as $count) {
                $t[$count] += $s[$count];
            }
            if ($s['expiry'] !== null) {
                $t['expiry_total']++;
                $t['expiry'] += $s['expiry'] ? 1 : 0;
            }
            if ($s['readable_expected'] !== null) {
                $t['readable_total']++;
                $t['readable'] += $s['readable_expected'] ? 1 : 0;
            }
            $t['seconds'][] = (float) ($actual['seconds'] ?? 0);
            $t['rss'][] = (int) ($actual['peak_rss'] ?? 0);
            unset($t);
            $conditions[$result['card']][$result['condition']] = $s['name'] && $s['classification'] ? 'ok' : ($s['false_name'] || $s['false_classification'] ? 'wrong' : 'unverified');
        }
        uksort($types, static fn (string $a, string $b): int => array_search($a, self::TYPE_ORDER, true) <=> array_search($b, self::TYPE_ORDER, true));
        return ['types' => $types, 'negatives' => $negatives, 'conditions' => $conditions];
    }

    private static function share(int $part, int $whole): string
    {
        return $whole === 0 ? '–' : sprintf('%d / %d (%.0f%%)', $part, $whole, 100 * $part / $whole);
    }

    /** The evaluation report (Markdown), from measured results only. */
    public static function markdown(array $summary, array $meta): string
    {
        $out = "# SMART ASSESS OCR evaluation (fictional ID fixtures)\n\n";
        $out .= "Generated by `php tests/tools/ocr-evaluation.php" . ($meta['quick'] ? ' --quick' : '') . ' --report=docs/OCR_EVALUATION.md` on ' . $meta['date'] . ".\n";
        $out .= 'Environment: ' . $meta['environment'] . ". Expiry judged on " . self::EVALUATION_DATE . ".\n\n";
        $out .= "Every fixture is a generated FICTIONAL card (generic layout, invented person and numbers; not a copy of a real design),\n";
        $out .= "compared with hand-written ground truth (`tests/fixtures/ocr-ground-truth`). These results describe synthetic cards only and\n";
        $out .= "do not predict accuracy on real IDs. Reading an ID's fields does not show that the ID is genuine.\n\n";
        $out .= "## Summary by document type\n\n| Document Type | Fixtures | Correct Classification | Correct Name Extraction | False Matches |\n|---|---:|---:|---:|---:|\n";
        foreach ($summary['types'] as $type => $t) {
            $out .= '| ' . self::TYPE_NAMES[$type] . ' | ' . $t['fixtures'] . ' | ' . self::share($t['classification'], $t['fixtures']) . ' | ' . self::share($t['name'], $t['fixtures'])
                . ' | ' . $t['false_name'] . " |\n";
        }
        $n = $summary['negatives'];
        $out .= "\nFalse Matches: a name reported that is not the card's name. Correct Name Extraction includes reporting no name where the\n";
        $out .= "card's name cannot be read (for example the \"missing field\" fixtures).\n\n";
        $out .= "## Detailed metrics by document type\n\n| Document Type | Field exact match | CER | Unverified required fields | Unverified names | False classifications | Expiry verdicts | Clear/text PDF read as readable | Avg time | Max time | Peak memory |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n";
        foreach ($summary['types'] as $type => $t) {
            $out .= '| ' . self::TYPE_NAMES[$type] . ' | ' . self::share($t['field_matches'], $t['fields_total']) . ' | '
                . ($t['cer_characters'] ? sprintf('%.1f%%', 100 * $t['cer_distance'] / $t['cer_characters']) : '–') . ' | '
                . self::share($t['required_unverified'], $t['required_total']) . ' | ' . self::share($t['unverified_name'], $t['fixtures']) . ' | '
                . $t['false_classification'] . ' | ' . self::share($t['expiry'], $t['expiry_total']) . ' | ' . self::share($t['readable'], $t['readable_total']) . ' | '
                . sprintf('%.1f s', array_sum($t['seconds']) / max(1, count($t['seconds']))) . ' | ' . sprintf('%.1f s', max($t['seconds'] ?: [0])) . ' | '
                . sprintf('%d MB', intdiv(max($t['rss'] ?: [0]), 1048576)) . " |\n";
        }
        $out .= "\nPeak memory: the largest resident memory of one fixture's PHP process (GD image work included; Tesseract runs as a separate\n";
        $out .= "process and is not included).\n\n## Negative controls (non-ID documents naming ID issuers)\n\n";
        $out .= "| Fixtures | Confirmed as an ID (false positive) | Names reported |\n|---:|---:|---:|\n| {$n['fixtures']} | {$n['confirmed']} | {$n['names']} |\n\n";
        $out .= "## Name result by card and condition\n\n`ok` = right type and right name (or correctly no name), `unverified` = left for Staff, `wrong` = a false name or type.\n\n";
        $conditions = IdCardFixtures::CONDITIONS;
        $out .= '| Card | ' . implode(' | ', $conditions) . " |\n|---|" . str_repeat('---|', count($conditions)) . "\n";
        foreach ($summary['conditions'] as $card => $row) {
            $out .= "| {$card} | " . implode(' | ', array_map(static fn (string $c): string => $row[$c] ?? '–', $conditions)) . " |\n";
        }
        $out .= "\n## Limitations\n\n";
        foreach ($meta['limitations'] as $line) {
            $out .= "- {$line}\n";
        }
        return $out;
    }
}
