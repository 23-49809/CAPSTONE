<?php
declare(strict_types=1);

/*
 * ID type registry and field extraction (no OCR, no database, no network; fictional people only):
 * classification by printed titles plus an identifying detail, field reading per type, OCR
 * look-alike characters, the passport machine-readable zone and its check digits, expiry, name
 * rules, the version 5 worker result (states only), the Staff findings and the Staff wording.
 *
 *   php tests/id_field_extraction_test.php
 */

require_once __DIR__ . '/../app/services/DocumentAnalysis.php';
require_once __DIR__ . '/../app/services/AiDocumentWorker.php';
require_once __DIR__ . '/../app/services/DocumentRequirements.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 400)];
};
$x = static fn (string $text, string $today = '2026-11-20'): array => IdFieldExtractor::extract($text, $today);
$states = static fn (array $read): array => array_map(static fn (array $f): string => $f['state'] === 'read' ? (string) $f['value'] : '-', $read['fields']);

// ---- Registry ----
$registryOk = true;
foreach (IdDocumentTypes::TYPES as $kind => $type) {
    $fields = array_keys($type['fields']);
    $registryOk = $registryOk && isset(DocumentAnalysis::ID_KINDS[$kind]) && isset(DocumentAnalysis::KIND_EVIDENCE[$kind])
        && array_diff($type['required'], $fields) === [] && array_diff($type['name'], $fields) === [] && array_diff($type['identifying'], $fields) === []
        && (!isset($type['expiry']) || in_array($type['expiry'], $fields, true));
}
foreach (IdDocumentTypes::NAME_ONLY_TYPES as $kind) {
    $registryOk = $registryOk && isset(DocumentAnalysis::ID_KINDS[$kind]) && isset(DocumentAnalysis::KIND_EVIDENCE[$kind]) && IdDocumentTypes::type($kind) !== null;
}
$check('registry: every type has its title patterns, and its required, name, identifying and expiry fields among its own fields', $registryOk);
$check('registry: the seven main types have full layouts; seven more are recognised with name-only reading', count(IdDocumentTypes::TYPES) === 7
    && array_keys(IdDocumentTypes::TYPES) === ['philsys_national_id', 'passport', 'drivers_license', 'umid', 'sss_id', 'philhealth_id', 'postal_id'] && count(IdDocumentTypes::NAME_ONLY_TYPES) === 7);
$check('registry: a school or company ID and an unknown ID have no layout (never treated as a government ID)', IdDocumentTypes::type('school_or_company_id') === null && IdDocumentTypes::type('other_id') === null);

// ---- Fictional texts as OCR reads them ----
$philsys = "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nPhilippine Identification Card\nApelyido/Last Name\nDELA CRUZ\nMga Pangalan/Given Names\nJUAN\n"
    . "Gitnang Apelyido/Middle Name\nSANTOS\nPetsa ng Kapanganakan/Date of Birth\nJANUARY 01, 1990\n1234-5678-9012-3456";
$mrz1 = 'P<PHLMAGBANUA<<ROSALINDA' . str_repeat('<', 20);
// TD3 line 2 with an empty personal-number field and its composite check digit (position 44).
$mrzLine2 = static function (string $number, string $birth, string $expiry): string {
    $line = $number . IdFieldExtractor::checkDigit($number) . 'PHL' . $birth . IdFieldExtractor::checkDigit($birth) . 'F' . $expiry . IdFieldExtractor::checkDigit($expiry) . str_repeat('<', 15);
    return $line . IdFieldExtractor::checkDigit(substr($line, 0, 10) . substr($line, 13, 7) . substr($line, 21, 22));
};
$passport = static fn (string $viz = 'P4821736B', ?string $line2 = null, string $given = 'ROSALINDA', string $expiry = '13 MAR 2032'): string => "REPUBLIKA NG PILIPINAS\nPASAPORTE / PASSPORT\n"
    . "Apelyido/Surname\nMAGBANUA\nMga Pangalan/Given names\n{$given}\nPanggitnang apelyido/Middle name\nIBARRA\nNasyonalidad/Nationality\nFILIPINO\n"
    . "Petsa ng kapanganakan/Date of birth\n14 MAR 1985\nPasaporte Blg./Passport No.\n{$viz}\nPetsa ng pagkawalang-bisa/Date of expiry\n{$expiry}\n{$mrz1}\n"
    . ($line2 ?? $mrzLine2('P4821736B', '850314', '320313'));
$license = "REPUBLIC OF THE PHILIPPINES\nLAND TRANSPORTATION OFFICE\nDRIVER'S LICENSE\nLast Name, First Name, Middle Name\nVILLAREAL, MARCO DIZON\n"
    . "Nationality Sex Date of Birth\nPHL M 1987/11/23\nLicense No.\nN03-21-004512\nExpiration Date\n2028/11/23";
$umid = "REPUBLIC OF THE PHILIPPINES\nUNIFIED MULTI-PURPOSE ID\nCRN-0111-2345678-9\nSURNAME\nPASCUAL\nGIVEN NAME\nLORENZO\nMIDDLE NAME\nAQUINO\nDATE OF BIRTH\n1979/06/30\nSOCIAL SECURITY SYSTEM";
$sss = "Republic of the Philippines\nSOCIAL SECURITY SYSTEM\nSS No. 34-5678901-2\nTOLENTINO, GRACE MENDOZA";
$philhealth = "PhilHealth\nPhilippine Health Insurance Corporation\n12-345678901-2\nNAVARRO, ELENA CRUZ\nDate of Birth JULY 21, 1985";
$postal = "PHILIPPINE POSTAL CORPORATION\nPOSTAL IDENTITY CARD\nPRN 100141234567P\nSurname\nSORIANO\nFirst Name\nRAFAEL\nMiddle Name\nGATCHALIAN\nDate of Birth\n05 AUG 1991\nValid Until\n05 AUG 2027";

$cases = [
    'PhilSys' => [$philsys, 'philsys_national_id', 'DELA CRUZ, JUAN SANTOS', ['last_name' => 'DELA CRUZ', 'given_names' => 'JUAN', 'middle_name' => 'SANTOS', 'birth_date' => '1990-01-01', 'card_number' => '1234-5678-9012-3456']],
    'Passport' => [$passport(), 'passport', 'MAGBANUA, ROSALINDA IBARRA', ['surname' => 'MAGBANUA', 'given_names' => 'ROSALINDA', 'middle_name' => 'IBARRA', 'nationality' => 'FILIPINO',
        'birth_date' => '1985-03-14', 'passport_number' => 'P4821736B', 'expiry_date' => '2032-03-13']],
    'Driver\'s License' => [$license, 'drivers_license', 'VILLAREAL, MARCO DIZON', ['full_name' => 'VILLAREAL, MARCO DIZON', 'license_number' => 'N03-21-004512', 'birth_date' => '1987-11-23', 'expiry_date' => '2028-11-23']],
    'UMID (with the SSS name printed on it)' => [$umid, 'umid', 'PASCUAL, LORENZO AQUINO', ['surname' => 'PASCUAL', 'given_name' => 'LORENZO', 'middle_name' => 'AQUINO', 'birth_date' => '1979-06-30', 'crn' => 'CRN-0111-2345678-9']],
    'SSS (name printed without a label)' => [$sss, 'sss_id', 'TOLENTINO, GRACE MENDOZA', ['full_name' => 'TOLENTINO, GRACE MENDOZA', 'ss_number' => '34-5678901-2', 'birth_date' => '-']],
    'PhilHealth (number printed without a label)' => [$philhealth, 'philhealth_id', 'NAVARRO, ELENA CRUZ', ['full_name' => 'NAVARRO, ELENA CRUZ', 'pin' => '12-345678901-2', 'birth_date' => '1985-07-21']],
    'Postal ID' => [$postal, 'postal_id', 'SORIANO, RAFAEL GATCHALIAN', ['surname' => 'SORIANO', 'first_name' => 'RAFAEL', 'middle_name' => 'GATCHALIAN', 'birth_date' => '1991-08-05',
        'prn' => '100141234567P', 'valid_until' => '2027-08-05']],
];
foreach ($cases as $label => [$text, $kind, $name, $fields]) {
    $read = $x($text);
    $check("extract: {$label} is confirmed by its title and an identifying detail, with its fields and name read", $read['type'] === $kind && $read['type_state'] === 'confirmed'
        && $read['name'] === $name && $states($read) === $fields && $read['missing_required'] === [], [$read['type'], $read['type_state'], $read['name'], $states($read)]);
}

// ---- Classification ----
$letter = "BARANGAY CERTIFICATION\nPlease bring your PhilHealth contribution record, a passport-size photo and your SSS number.\nDate of Birth: January 1, 1990";
$check('classification: a letter that names issuers is never confirmed as an ID (title only, or titles of several IDs) and gives no name',
    !in_array($x($letter)['type_state'], ['confirmed'], true) && $x($letter)['name'] === null, $x($letter)['type_state']);
$check('classification: a title with none of its identifying details is "title only"', $x("PHILHEALTH\nPhilippine Health Insurance Corporation\nNAVARRO, ELENA CRUZ")['type_state'] === 'title_only');
$check('classification: titles of two different IDs are a conflict, never confirmed', $x($philsys . "\nPASAPORTE")['type_state'] === 'conflict');
$check('classification: no government ID title is unverified (school IDs and unknown IDs are not supported)', $x("ST. MARY'S COLLEGE\nSTUDENT ID\nDELA CRUZ, JUAN")['type_state'] === 'unverified'
    && $x("ST. MARY'S COLLEGE\nSTUDENT ID\nDELA CRUZ, JUAN")['type'] === null);
$check('classification: from the text only (the extractor takes no file name)', (new ReflectionMethod(IdFieldExtractor::class, 'extract'))->getNumberOfParameters() === 2);

// ---- Labels and values ----
$check('labels: misread Filipino/English labels still find their values', $x("Philippine Identification Card\n1234-5678-9012-3456\nApelydo/Last Name\nDELA CRUZ\nMga Pahgalan/Gren Nemes\nJUAN\nGithang Apety do/Middie Narne\nSANTOS")['name'] === 'DELA CRUZ, JUAN SANTOS');
$check('labels: a value on the same line as its label is read ("License No. N03-...", "PRN 1001...")', $x(str_replace("License No.\nN03-21-004512", 'License No. N03-21-004512', $license))['fields']['license_number']['value'] === 'N03-21-004512');
$check('labels: "Gitnang Apelyido" is the middle name, not the last name (longest label wins)', $x($philsys)['fields']['last_name']['value'] === 'DELA CRUZ' && $x($philsys)['fields']['middle_name']['value'] === 'SANTOS');
$check('values: a name must be in capitals with a word of 3+ letters; "MA. CLARA" is a name, "JU", "Juan" and "J4AN" are not', IdFieldExtractor::name('MA. CLARA') === 'MA. CLARA'
    && IdFieldExtractor::name('JU') === null && IdFieldExtractor::name('Juan') === null && IdFieldExtractor::name('J4AN') === null && IdFieldExtractor::name('REPUBLIKA NG PILIPINAS') === null);
$check('values: a period after a whole word is dropped ("CRUZ."), after an abbreviation it stays ("JR.")', IdFieldExtractor::fullName('NAVARRO, ELENA CRUZ.') === 'NAVARRO, ELENA CRUZ'
    && IdFieldExtractor::fullName('SANTOS, JUAN JR.') === 'SANTOS, JUAN JR.');
$check('values: dates in month-name and year-first forms become YYYY-MM-DD; an impossible date is not read', $states($x(str_replace('JANUARY 01, 1990', '1990/02/30', $philsys)))['birth_date'] === '-'
    && $states($x(str_replace('JANUARY 01, 1990', '01 JAN 1990', $philsys)))['birth_date'] === '1990-01-01');

// ---- OCR look-alike characters: never corrected into a "read" value ----
$check('look-alikes: a number with a letter in a digit place (O for 0) stays unverified, not corrected', $x(str_replace('N03-21-004512', 'N03-21-OO4512', $license))['fields']['license_number']['state'] === 'unverified');
$check('look-alikes: a date with a letter for a digit ("199O") stays unverified', $states($x(str_replace('JANUARY 01, 1990', 'JANUARY 01, 199O', $philsys)))['birth_date'] === '-');
$check('look-alikes: a name with a digit is never changed into a name', $x(str_replace("JUAN\n", "J0HN\n", $philsys))['name'] === null);

// ---- Passport machine-readable zone ----
$check('MRZ: check digits match the published ICAO 9303 specimen (L898902C3 -> 6, 740812 -> 2, 120415 -> 9)',
    IdFieldExtractor::checkDigit('L898902C3') === '6' && IdFieldExtractor::checkDigit('740812') === '2' && IdFieldExtractor::checkDigit('120415') === '9');
$check('MRZ: a passport number left out of the visual zone is read from the machine zone when its check digit is right', $x($passport(''))['fields']['passport_number']['value'] === 'P4821736B');
$badCheck = substr($mrzLine2('P4821736B', '850314', '320313'), 0, 9) . '0' . substr($mrzLine2('P4821736B', '850314', '320313'), 10);
$check('MRZ: a wrong check digit is never used', !isset(IdFieldExtractor::machineZone([$mrz1, $badCheck])['passport_number'])
    && isset(IdFieldExtractor::machineZone([$mrz1, $mrzLine2('P4821736B', '850314', '320313')])['passport_number']));
$check('MRZ: when the visual and machine zones disagree, the field is unverified', $x($passport('P4821737B'))['fields']['passport_number']['state'] === 'unverified'
    && $x($passport('P4821736B', null, 'ROSALINA'))['fields']['given_names']['state'] === 'unverified');
$check('MRZ: a name read only in the machine zone (no check digit) is not used', $x(str_replace("Apelyido/Surname\nMAGBANUA\n", '', $passport()))['fields']['surname']['state'] === 'unverified');
$check('MRZ: filler misread as letters after the given names does not join the name', IdFieldExtractor::machineZone(['P<PHLMAGBANUA<<ROSALINDA<<KKC<<<<<<<<<<<<<<<', $mrzLine2('P4821736B', '850314', '320313')])['given_names'] === 'ROSALINDA');

// ---- Expiry ----
$check('expiry: valid, expired, not read, not applicable', $x($passport())['expiry'] === 'valid' && $x($passport(), '2032-03-14')['expiry'] === 'expired'
    && $x(str_replace("Expiration Date\n2028/11/23", '', $license))['expiry'] === 'not_read' && $x($philsys)['expiry'] === 'not_applicable');

// ---- Name rules ----
$check('name: a middle name the card labels but OCR could not read leaves the name unverified (no shortened name)', $x(str_replace("SANTOS\n", '', $philsys))['name'] === null);
$check('name: a card without a middle-name field gives the name without one', $x(str_replace("Gitnang Apelyido/Middle Name\nSANTOS\n", '', $philsys))['name'] === 'DELA CRUZ, JUAN');
$check('name: a missing required name field leaves the name unverified', $x(str_replace("DELA CRUZ\n", '', $philsys))['name'] === null);
$check('name: a name label misread past recognition ("Maddie Name", "MIOOLE NAME") above a name value leaves the name unverified, never shortened',
    $x(str_replace('Gitnang Apelyido/Middle Name', 'Garang Apetydo Maddie Name', $philsys))['name'] === null
    && $x(str_replace('MIDDLE NAME', 'MIOOLE NAME', $umid))['name'] === null && $x($umid)['name'] === 'PASCUAL, LORENZO AQUINO');
$check('name: a name line left without its label (the label lost entirely) right after another name part leaves the name unverified',
    $x(str_replace("MIDDLE NAME\n", '', $umid))['name'] === null && $x(str_replace("Gitnang Apelyido/Middle Name\n", '', $philsys))['name'] === null);
$check('names agree: same words in any order, or one inside the other; different people do not', DocumentAnalysis::namesAgree('DELA CRUZ, JUAN SANTOS', 'JUAN DELA CRUZ')
    && !DocumentAnalysis::namesAgree('DELA CRUZ, JUAN', 'REYES, MARIA') && !DocumentAnalysis::namesAgree('DELA CRUZ, JUAN', 'DELA CRUZ, PEDRO'));

// ---- Version 5 id_check: states only ----
$idCheck = DocumentAnalysis::idCheck($x($passport()), 'good', true, 'MAGBANUA ROSALINDA IBARRA', false);
$json = (string) json_encode($idCheck);
$check('id_check: states only - no number, date or nationality value leaves the worker', !preg_match('/P4821736B|1985|2032|FILIPINO|MAGBANUA/', $json) && $idCheck['needs_staff_review'] === false, $json);
$check('id_check: needs Staff review when the type is not confirmed, the name is not read or conflicts, a required field is missing, the ID appears expired, or OCR was partial',
    DocumentAnalysis::idCheck($x($letter), 'good', true, '', false)['needs_staff_review'] && DocumentAnalysis::idCheck($x($passport()), 'good', true, 'X', true)['needs_staff_review']
    && DocumentAnalysis::idCheck($x($passport(), '2033-01-01'), 'good', true, 'X', false)['needs_staff_review'] && DocumentAnalysis::idCheck($x($passport()), 'partial', true, 'X', false)['needs_staff_review']
    && DocumentAnalysis::idCheck($x($passport()), 'good', true, '', false)['name_state'] === 'unverified');

$doc = static fn (string $key, ?array $id): array => ['key' => $key, 'ocr_status' => 'ok', 'ocr_error' => null, 'readability' => 'good', 'pages' => 1, 'method' => 'image_ocr',
    'text_characters' => 200, 'name_match' => 'none', 'kind_evidence' => [], 'id_check' => $id];
$worker = static fn (array $documents): array => ['version' => 5, 'documents' => $documents, 'rule_findings' => [], 'ocr_engine' => 'tesseract (eng)',
    'model' => ['status' => 'skipped', 'name' => 'fake', 'digest' => null, 'output' => null]];
$valid = DocumentAnalysis::validateWorkerResult($worker([$doc('requesterId', $idCheck), $doc('authLetter', null)]), ['requesterId', 'authLetter']);
$check('v5 app: a version 5 result with id_check is accepted and kept per document', $valid['documents'][0]['id_check']['type'] === 'passport' && $valid['documents'][1]['id_check'] === null);
$rejects = static function (array $id, string $key = 'requesterId') use ($worker, $doc): bool {
    try {
        DocumentAnalysis::validateWorkerResult($worker([$doc($key, $id)]), [$key]);
        return false;
    } catch (UnexpectedValueException) {
        return true;
    }
};
$check('v5 app: an id_check with a field value instead of a state, an unknown field or state, a missing-field list naming other fields, or on a non-ID upload is rejected',
    $rejects(['fields' => ['passport_number' => 'P4821736B']] + $idCheck) && $rejects(['fields' => ['secret_number' => 'read']] + $idCheck) && $rejects(['type_state' => 'genuine'] + $idCheck)
    && $rejects(['missing_required' => ['crn']] + $idCheck) && $rejects($idCheck, 'authLetter') && $rejects(['type' => 'approved_id'] + $idCheck) && $rejects($idCheck + ['value' => 'x']));
$tampered = DocumentAnalysis::validateWorkerResult($worker([$doc('requesterId', ['name_state' => 'unverified', 'needs_staff_review' => false] + $idCheck)]), ['requesterId']);
$check('v5 app: needs_staff_review is recomputed, never trusted (an unverified name always needs review)', $tampered['documents'][0]['id_check']['needs_staff_review'] === true);

// ---- Findings and payload ----
$requirements = (new DocumentRequirements())->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/png', 'size' => 10]]);
$classifyWith = static function (array $id) use ($worker, $doc, $requirements): array {
    $valid = DocumentAnalysis::validateWorkerResult($worker([$doc('ownerId', $id)]), ['ownerId']);
    return DocumentAnalysis::classify($requirements, $valid, ['ownerId' => 'Valid ID']);
};
$text = static fn (array $classified): string => implode(' | ', array_column($classified['findings'], 'explanation'));
$titleOnly = $classifyWith(DocumentAnalysis::idCheck($x("PHILHEALTH\nPhilippine Health Insurance Corporation\nNAVARRO, ELENA CRUZ"), 'good', true, '', false));
$check('findings: a title-only ID says its type is not confirmed, and the result needs review (never passed)', str_contains($text($titleOnly), 'none of its ID details')
    && $titleOnly['outcome'] === DocumentAnalysis::OUTCOME_NEEDS_REVIEW && $titleOnly['documents'][0]['id_check']['type_state'] === 'title_only', $text($titleOnly));
$expired = $classifyWith(DocumentAnalysis::idCheck($x($passport(), '2033-01-01'), 'good', true, 'MAGBANUA ROSALINDA', false));
$check('findings: an ID whose printed expiry appears past is a possible issue to check on the original', str_contains($text($expired), 'expiry date') && $expired['outcome'] === DocumentAnalysis::OUTCOME_NEEDS_REVIEW);
$conflict = $classifyWith(DocumentAnalysis::idCheck($x($passport()), 'good', true, '', true));
$check('findings: disagreeing name readings are reported, no name is shown', str_contains($text($conflict), 'disagree') && $conflict['documents'][0]['holder_name'] === '');
$missing = $classifyWith(DocumentAnalysis::idCheck($x(str_replace("JUAN\n", '', $philsys)), 'good', true, '', false));
$check('findings: unread required details are named (states only, no values)', str_contains($text($missing), 'could not be read: given names'), $text($missing));
$check('findings and payload hold no field values', !preg_match('/P4821736B|1234-5678|1985-03-14|JANUARY/', (string) json_encode([$titleOnly, $expired, $conflict, $missing])));

// ---- Name checked against the model's name ----
$keep = static fn (string $model, string $text): array => DocumentAnalysis::keepQuotedDetails(['documents' => [['key' => 'requesterId', 'holder_name' => $model, 'property_number' => '']]], ['requesterId' => ['text' => $text]]);
[$out, $matches, $conflicts] = $keep('REYES, MARIA', $philsys . "\nREYES, MARIA");
$check('name conflict: when the model names a different person than the ID\'s labelled fields, neither name is kept', $out['documents'][0]['holder_name'] === '' && isset($conflicts['requesterId']));
[$out, , $conflicts] = $keep('JUAN DELA CRUZ', $philsys);
$check('name conflict: the model\'s shorter form of the same name is not a conflict', $out['documents'][0]['holder_name'] !== '' && $conflicts === []);

// ---- Worker: OCR passes that read different names ----
$state = sys_get_temp_dir() . '/sa-id-extraction-' . bin2hex(random_bytes(4));
mkdir($state, 0700);
$fakeOcr = static fn (array $alternatives, ?string $text = null): Closure => static fn (string $dir, int $seconds = 60): object => new class ($text ?? $philsys, $alternatives) {
    public function __construct(private string $text, private array $alternatives)
    {
    }
    public function extract(string $path, ?callable $completeness = null): array
    {
        return ['success' => true, 'text' => $this->text, 'error_code' => null, 'pages_processed' => 1, 'extraction_method' => 'image_ocr', 'alternative_texts' => $this->alternatives];
    }
};
$job = AiDocumentWorker::validateJob(['version' => 1, 'job_id' => 3, 'declared' => ['flow' => 'docreq', 'is_owner' => true, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
    'arp_number' => '', 'document_type' => 'Certification', 'transfer_type' => ''], 'documents' => [['key' => 'ownerId', 'label' => 'Valid ID', 'mime' => 'image/png', 'content_base64' => base64_encode('x')]]]);
$run = static fn (array $alternatives, ?string $text = null): array => (new AiDocumentWorker($state, $fakeOcr($alternatives, $text), null, 'none', null, 'tesseract (eng)'))->run($job);
$agree = $run([str_replace('SANTOS', 'SANTOS', $philsys)]);
$disagree = $run([str_replace("JUAN\n", "PEDRO\n", $philsys)]);
$check('worker: complete OCR passes that agree leave the name read; conflicting passes return no name, while only the original front OCR text is retained for Staff',
    $agree['version'] === DocumentAnalysis::WORKER_RESULT_VERSION && $agree['documents'][0]['id_check']['type_state'] === 'confirmed'
    && $disagree['documents'][0]['id_check']['name_state'] === 'conflict'
    && $agree['documents'][0]['id_front_ocr_text'] === $philsys && $disagree['documents'][0]['id_front_ocr_text'] === $philsys
    && !str_contains((string) json_encode($disagree['documents'][0]['id_review']), 'PEDRO'), [$agree['documents'][0], $disagree['documents'][0]]);
$passportFront = $passport();
$otherId = $run([$passportFront], $passportFront);
$check('worker: raw OCR text is not retained when the applicant upload is not identified as a National ID front',
    $otherId['documents'][0]['id_front_ocr_text'] === null && $otherId['documents'][0]['id_review']['full_name']['status'] === 'not_found');
$malformedTranscript = $agree;
$malformedTranscript['documents'][0]['id_front_ocr_text'] = $passportFront;
$rejectedTranscript = false;
try {
    DocumentAnalysis::validateWorkerResult($malformedTranscript, ['ownerId']);
} catch (UnexpectedValueException) {
    $rejectedTranscript = true;
}
$check('application validation rejects a non-National-ID OCR transcript even from a version 7 worker', $rejectedTranscript);
@rmdir($state);

// ---- Staff page wording (no layout, font or size change) ----
$html = (string) file_get_contents(dirname(__DIR__, 2) . '/smart-assess-internal.html');
$from = (int) strpos($html, 'function dcKindCheck(d, isId, ctx){');
$wrapper = substr($html, $from, (int) strpos($html, 'function dcKindCheckTitles', $from) - $from);
$check('Staff page: ID Type explains a title-only ID, titles of two IDs and an expired ID; Name Match explains disagreeing readings',
    str_contains($wrapper, "idc.type_state==='title_only'") && str_contains($wrapper, "idc.type_state==='conflict'") && str_contains($wrapper, "idc.expiry==='expired'")
    && str_contains($html, "d.id_check.name_state==='conflict'"));
$check('Staff page: the new wording sets no style; an expired ID is a "Possible issue" (not "Possible mismatch"), shown like a possible mismatch',
    !preg_match('/style|font|class=|<span/i', $wrapper) && str_contains($wrapper, "idc.expiry==='expired') return dcCheck(base.name, 'issue'")
    && str_contains($html, "issue:'Possible issue'") && str_contains($html, '.dc-check.v-possible, .dc-check.v-issue{ border-left-color:var(--warn); }')
    && str_contains($html, '.dc-check.v-possible .dc-verdict, .dc-check.v-issue .dc-verdict{ color:var(--warn); }') && str_contains($html, "['correct','mismatch','possible','issue','missing','unable']"));


// ================= Keep parsed ID fields out of model findings and extracted fields; retain only the requested original OCR transcript =================
$idText = $philsys;
$tdText = "TAX DECLARATION OF REAL PROPERTY\nARP No. 018-00045 Owner JUAN DELA CRUZ Location Poblacion Mabini";
$sensitive = ['1234-5678-9012-3456', 'JANUARY 01, 1990'];
$leaks = static fn (mixed $data): array => array_values(array_filter($sensitive, static fn (string $v): bool => str_contains((string) json_encode($data), $v)));
$fakeTexts = static fn (array $byKey): Closure => static fn (string $dir, int $seconds = 60): object => new class ($byKey) {
    public function __construct(private array $byKey)
    {
    }
    public function extract(string $path, ?callable $completeness = null): array
    {
        $key = str_contains($path, 'document-0') ? 'ownerId' : 'ctcTdOrTitle';
        return ['success' => true, 'text' => $this->byKey[$key], 'error_code' => null, 'pages_processed' => 1, 'extraction_method' => 'image_ocr', 'alternative_texts' => []];
    }
};
$leakModel = static fn (): array => ['documents' => [
    ['key' => 'ownerId', 'document_kind' => 'passport', 'holder_name' => 'MAGBANUA ROSALINDA IBARRA', 'holder_name_clear' => true, 'property_number' => 'P4821736B', 'expected_type_match' => 'yes', 'missing_fields' => []],
    ['key' => 'ctcTdOrTitle', 'document_kind' => 'tax_declaration', 'holder_name' => 'JUAN DELA CRUZ', 'holder_name_clear' => true, 'property_number' => '018-00045', 'expected_type_match' => 'yes', 'missing_fields' => []]],
    'findings' => [
        ['category' => 'possible_inconsistency', 'field' => 'other', 'document_keys' => ['ownerId'], 'explanation' => 'x', 'evidence' => 'Pasaporte Blg./Passport No. P4821736B'],
        ['category' => 'possible_inconsistency', 'field' => 'other', 'document_keys' => [], 'explanation' => 'x', 'evidence' => 'Petsa ng kapanganakan/Date of birth 14 MAR 1985'],
        ['category' => 'possible_inconsistency', 'field' => 'property_identifier', 'document_keys' => ['ctcTdOrTitle'], 'explanation' => 'x', 'evidence' => 'ARP No. 018-00045 Owner JUAN DELA CRUZ']],
    'manual_review_recommended' => false];
$state2 = sys_get_temp_dir() . '/sa-id-privacy-' . bin2hex(random_bytes(4));
mkdir($state2, 0700);
$landJob = AiDocumentWorker::validateJob(['version' => 1, 'job_id' => 4, 'declared' => ['flow' => 'landtransfer', 'is_owner' => true, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
    'arp_number' => '018-00045', 'document_type' => '', 'transfer_type' => 'Sale'], 'documents' => [['key' => 'ownerId', 'label' => 'Valid ID', 'mime' => 'image/png', 'content_base64' => base64_encode('a')],
    ['key' => 'ctcTdOrTitle', 'label' => 'Tax Declaration', 'mime' => 'image/png', 'content_base64' => base64_encode('b')]]]);
$leakRun = (new AiDocumentWorker($state2, $fakeTexts(['ownerId' => $idText, 'ctcTdOrTitle' => $tdText]), static fn () => $leakModel(), 'fake', null, 'tesseract (eng)'))->run($landJob);
@rmdir($state2);
$out = $leakRun['model']['output'];
$withoutTranscript = $leakRun;
foreach ($withoutTranscript['documents'] as &$docCheck) {
    $docCheck['id_front_ocr_text'] = null;
}
unset($docCheck);
$check('privacy: sensitive ID text is retained only as the applicant-front OCR transcript; parsed numbers and model quotes stay removed',
    $leaks($withoutTranscript) === [] && $leakRun['documents'][0]['id_front_ocr_text'] === $idText && $leakRun['documents'][1]['id_front_ocr_text'] === null
    && $out['documents'][0]['property_number'] === '' && $out['documents'][1]['property_number'] === '018-00045'
    && $out['findings'][0]['evidence'] === '' && $out['findings'][1]['evidence'] === '' && $out['findings'][2]['evidence'] === 'ARP No. 018-00045 Owner JUAN DELA CRUZ', [$leaks($leakRun), $out]);
$landLabels = ['ownerId' => 'Valid ID', 'ctcTdOrTitle' => 'Tax Declaration'];
$landReq = ['items' => [['key' => 'ownerId', 'label' => 'Valid ID', 'status' => 'uploaded_valid_format'], ['key' => 'ctcTdOrTitle', 'label' => 'Tax Declaration', 'status' => 'uploaded_valid_format']]];
// An older or compromised worker that still sends them: the application removes them anyway (versions 3, 4 and 5).
foreach ([3, 4, 5] as $version) {
    $raw = $leakRun;
    $raw['version'] = $version;
    $raw['model']['output'] = $leakModel();
    foreach ($raw['documents'] as &$d) {
        if ($version < 5) {
            unset($d['id_check']);
        }
        if ($version < 6) {
            unset($d['id_review']);
        }
        if ($version < 7) {
            unset($d['id_front_ocr_text']);
        }
        if ($version < 4) {
            unset($d['kind_evidence']);
        }
        $d['name_match'] = 'exact';
    }
    unset($d);
    $valid = DocumentAnalysis::validateWorkerResult($raw, ['ownerId', 'ctcTdOrTitle']);
    $classified = DocumentAnalysis::classify($landReq, $valid, $landLabels);
    $byKey = array_column($classified['documents'], null, 'key');
    $check("privacy (application, version {$version} result): property_number and quotes for an ID are removed in validation, the stored result and the Staff payload; the property document keeps its number",
        $leaks($valid) === [] && $leaks($classified) === [] && $byKey['ownerId']['property_number'] === '' && $byKey['ctcTdOrTitle']['property_number'] === '018-00045'
        && in_array('ARP No. 018-00045 Owner JUAN DELA CRUZ', array_column($classified['findings'], 'evidence'), true), [$version, $leaks($valid), $leaks($classified)]);
}
[$storedDocs, $storedFindings] = DocumentAnalysis::withoutIdDetails(
    [['key' => 'ownerId', 'label' => 'Valid ID', 'property_number' => 'P4821736B', 'id_front_ocr_text' => $passport()],
        ['key' => 'requesterId', 'label' => 'Applicant National ID', 'property_number' => '', 'id_front_ocr_text' => $philsys],
        ['key' => 'ctcTdOrTitle', 'label' => 'Tax Declaration', 'property_number' => '018-00045', 'id_front_ocr_text' => $philsys]],
    [['document_labels' => ['Valid ID'], 'evidence' => 'Passport No. P4821736B'], ['document_labels' => [], 'evidence' => 'Date of birth 14 MAR 1985'],
        ['document_labels' => ['Tax Declaration'], 'evidence' => 'ARP No. 018-00045 Owner JUAN DELA CRUZ']]);
$check('privacy (stored results read for Staff): non-National-ID OCR transcripts and ID property numbers/quotes are removed; National-ID transcript and property-document values remain',
    $storedDocs[0]['id_front_ocr_text'] === null && $storedDocs[0]['property_number'] === '' && $storedDocs[1]['id_front_ocr_text'] === $philsys
    && $storedDocs[2]['id_front_ocr_text'] === null && $storedDocs[2]['property_number'] === '018-00045'
    && $storedFindings[2]['evidence'] === 'ARP No. 018-00045 Owner JUAN DELA CRUZ');
$verificationSource = (string) file_get_contents(__DIR__ . '/../app/services/AiVerification.php');
$check('privacy (Staff view): stored results pass through withoutIdDetails before reaching the page', str_contains($verificationSource, 'DocumentAnalysis::withoutIdDetails('));
foreach (['UMID CRN' => [$umid, 'CRN-0111-2345678-9'], 'SSS number' => [$sss, '34-5678901-2'], 'PhilHealth PIN' => [$philhealth, '12-345678901-2'], 'Postal PRN' => [$postal, '100141234567P'],
    'license number' => [$license, 'N03-21-004512'], 'PhilSys card number' => [$philsys, '1234-5678-9012-3456']] as $what => [$sample, $number]) {
    [$kept] = DocumentAnalysis::keepQuotedDetails(['documents' => [['key' => 'requesterId', 'holder_name' => '', 'property_number' => $number]]], ['requesterId' => ['text' => $sample]]);
    $check("privacy: a {$what} never becomes an ID upload's property_number", $kept['documents'][0]['property_number'] === '');
}

// ================= Fix 2: readable text without a recognised government ID title needs review =================
$school = $classifyWith(DocumentAnalysis::idCheck($x("ST. MARY'S COLLEGE\nSTUDENT IDENTIFICATION CARD\nNAME: DELA CRUZ, JUAN SANTOS"), 'good', true, 'DELA CRUZ, JUAN SANTOS', false));
$check('unverified type: a readable school or unknown ID gets "No recognised government ID title was found ... Please inspect the original document manually." and needs review',
    str_contains($text($school), 'No recognised government ID title was found in Valid ID. Please inspect the original document manually.')
    && $school['outcome'] === DocumentAnalysis::OUTCOME_NEEDS_REVIEW, $text($school));
$check('unverified type: unsupported, title-only and unreadable keep different findings',
    !str_contains($text($titleOnly), 'No recognised government ID title') && str_contains($text($titleOnly), 'none of its ID details'));
$unreadableId = DocumentAnalysis::validateWorkerResult($worker([['readability' => 'unreadable', 'text_characters' => 0] + $doc('ownerId', DocumentAnalysis::idCheck($x(''), 'unreadable', true, '', false))]), ['ownerId']);
$unreadableText = $text(DocumentAnalysis::classify($requirements, $unreadableId, ['ownerId' => 'Valid ID']));
$check('unverified type: an unreadable ID is reported as unreadable, not as an unsupported ID', str_contains($unreadableText, 'Little or no text') && !str_contains($unreadableText, 'No recognised government ID title'), $unreadableText);

// ================= Fix 3: passport composite check digit =================
$specimen1 = 'P<PHLERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';
$specimen2 = 'L898902C36PHL7408122F1204159ZE184226B<<<<<10';   // ICAO 9303 specimen line 2 (nationality UTO -> PHL; composite 0)
$check('composite: the ICAO specimen\'s composite check digit is 0, and its birth and expiry dates are read with it', IdFieldExtractor::checkDigit(substr($specimen2, 0, 10) . substr($specimen2, 13, 7) . substr($specimen2, 21, 22)) === '0'
    && (IdFieldExtractor::machineZone([$specimen1, $specimen2])['birth_date'] ?? null) === '1974-08-12' && (IdFieldExtractor::machineZone([$specimen1, $specimen2])['expiry_date'] ?? null) === '2012-04-15');
$noNumbers = static fn (array $zone): bool => !isset($zone['passport_number']) && !isset($zone['birth_date']) && !isset($zone['expiry_date']);
$check('composite: a wrong composite digit, a missing one, or a line short of 44 characters gives no number or date', $noNumbers(IdFieldExtractor::machineZone([$specimen1, substr($specimen2, 0, 43) . '7']))
    && $noNumbers(IdFieldExtractor::machineZone([$specimen1, substr($specimen2, 0, 43) . '<'])) && $noNumbers(IdFieldExtractor::machineZone([$specimen1, substr($specimen2, 0, 43)])));
$good2 = $mrzLine2('P4821736B', '850314', '320313');
// A misread in the personal-number area: every field's own check digit still passes; only the composite catches it.
// ('B' = 11 at weight 1 changes the sum; an 'A' = 10 there would not: check digits cannot catch every misread.)
$corrupted = substr($good2, 0, 30) . 'B' . substr($good2, 31);
$check('composite: a corruption only the composite check digit catches gives no number or date (field check digits alone are not enough)',
    $noNumbers(IdFieldExtractor::machineZone([$mrz1, $corrupted])) && isset(IdFieldExtractor::machineZone([$mrz1, $good2])['passport_number']));
$badComposite = substr($good2, 0, 43) . (string) (((int) $good2[43] + 1) % 10);
$check('composite: with a bad composite, a passport number missing from the visual zone stays unverified (not taken from the machine zone)',
    $x($passport('', $badComposite))['fields']['passport_number']['state'] === 'unverified' && $x($passport(''))['fields']['passport_number']['state'] === 'read');

// ================= Fix 4: "LAST, FIRST MIDDLE" names =================
$ph = static fn (string $name): string => "PhilHealth\nPhilippine Health Insurance Corporation\n12-345678901-2\n{$name}";
$complete = $x($ph('NAVARRO, ELENA CRUZ'));
$short = $x($ph('NAVARRO, ELENA'));
$check('comma names: a complete name is read (name state read)', $complete['name'] === 'NAVARRO, ELENA CRUZ' && $complete['name_complete']
    && DocumentAnalysis::idCheck($complete, 'good', true, 'NAVARRO, ELENA CRUZ', false)['name_state'] === 'read');
$shortCheck = DocumentAnalysis::idCheck($short, 'good', true, 'NAVARRO, ELENA', false);
$check('comma names: a name without a middle name is flagged possibly incomplete, needs review, and is never "read"', $short['name'] === 'NAVARRO, ELENA' && !$short['name_complete']
    && $shortCheck['name_state'] === 'incomplete' && $shortCheck['needs_staff_review']);
$shortClassified = $classifyWith($shortCheck);
$check('comma names: the possibly incomplete name gets a finding and the result needs review', str_contains($text($shortClassified), 'may be incomplete') && $shortClassified['outcome'] === DocumentAnalysis::OUTCOME_NEEDS_REVIEW);
$check('comma names: a missing first name or a noisy name is unverified (no name)', $x($ph('NAVARRO,'))['name'] === null && $x($ph('NAVARRO, ELENA CRU2'))['name'] === null
    && $x($ph('NAVARRO, E.'))['name'] === null && $x($ph('Navarro, Elena Cruz'))['name'] === null);
$check('comma names: the evaluator, like the worker, never counts a possibly incomplete name as read', str_contains((string) file_get_contents(__DIR__ . '/tools/ocr-evaluate-one.php'), "&& \$read['name_complete'] ? \$read['name'] : null"));
$check('comma names: the Staff Name Match shows "Possible issue" for a possibly incomplete name, after the existing comparison safeguards', str_contains($html, "d.id_check.name_state==='incomplete') return dcCheck(name, 'issue'")
    && strpos($html, "if(cmp==='differs')", (int) strpos($html, 'function dcNameCheck')) < strpos($html, "d.id_check.name_state==='incomplete'"));
$check('v5 app: name_state "incomplete" is accepted and always needs review', DocumentAnalysis::validateWorkerResult($worker([$doc('requesterId', ['needs_staff_review' => false] + $shortCheck)]), ['requesterId'])['documents'][0]['id_check']['needs_staff_review'] === true);

// ---- Photo noise on real cards (National ID first; fictional people only) ----
// Real photos add stray marks: lines of a few characters between a label and its value, short
// fragments at the ends of a value line, and spaces read in place of the card number's hyphens.
$noisy = static fn (string $between = "| ae\n", string $last = 'DELA CRUZ', string $given = 'JUAN'): string => "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nPhilippine Identification Card\n"
    . "Apelyido/Last Name\n{$between}{$last}\nMga Pangalan/Given Names\n{$between}{$given}\nGitnang Apelyido/Middle Name\n{$between}SANTOS\nPetsa ng Kapanganakan/Date of Birth\n{$between}JANUARY 01, 1990\n";
$read = $x($noisy());
$check('noise: one noise line between each label and its value: every field read, the type confirmed and the full name given', $read['type_state'] === 'confirmed'
    && $read['name'] === 'DELA CRUZ, JUAN SANTOS' && $states($read)['birth_date'] === '1990-01-01', [$read['type_state'], $read['name'], $states($read)]);
$read = $x($noisy("= .\n|\n"));
$check('noise: two noise lines are skipped too', $read['type_state'] === 'confirmed' && $read['name'] === 'DELA CRUZ, JUAN SANTOS', [$read['type_state'], $read['name']]);
$read = $x($noisy("|\n.\n=\n"));
$check('noise: three noise lines end the search: no value is taken from further down the card', $states($read)['last_name'] === '-' && $read['name'] === null && $read['type_state'] === 'title_only',
    [$read['type_state'], $states($read)]);
$read = $x($noisy("ee ae oo\n"));
$check('noise: a line with more than two letters is not noise: it is the candidate, fails, and nothing below it is used', $states($read)['last_name'] === '-' && $read['name'] === null, $states($read));
$read = $x("REPUBLIKA NG PILIPINAS\nPhilippine Identification Card\nApelyido/Last Name\n|\nMga Pangalan/Given Names\nJUAN\nGitnang Apelyido/Middle Name\nSANTOS\n");
$check('noise: a label line ends the search: a missing last name never takes the given name below its label', $states($read)['last_name'] === '-' && $states($read)['given_names'] === 'JUAN'
    && $read['name'] === null, $states($read));
$read = $x($noisy('', 'DELA CRUZ ee', '| JUAN'));
$check('stray marks: short fragments at the ends of a value line are dropped ("DELA CRUZ ee", "| JUAN")', $read['name'] === 'DELA CRUZ, JUAN SANTOS' && $read['type_state'] === 'confirmed', [$read['name'], $states($read)]);
$read = $x($noisy('', 'DELA CRUZ', 'JUAN A'));
$check('stray marks: a capital initial is kept, never dropped', $read['name'] === 'DELA CRUZ, JUAN A SANTOS', $read['name']);
$check('stray marks: nothing inside a name is changed: a digit or a lower-case word inside it leaves the field unread', $states($x($noisy('', 'DELA CRU2 ee')))['last_name'] === '-'
    && $states($x($noisy('', 'DELA cruz')))['last_name'] === '-' && $states($x($noisy('', 'de la CRUZ')))['last_name'] === '-');
$check('mixed case is still not read as a name (no "unclear" state exists, so it is not guessed)', $x($noisy('', 'Dela Cruz', 'Juan'))['name'] === null
    && $states($x($noisy('', 'Dela Cruz')))['last_name'] === '-');
$check('stray marks: a value line that is only stray marks gives no value', $states($x($noisy('', 'ee |')))['last_name'] === '-');
$pcn = static fn (string $number): array => $states($x("REPUBLIKA NG PILIPINAS\nPhilippine Identification Card\n{$number}\n"));
$check('card number: printed with hyphens or read with spaces, it is read (and confirms the type)', $pcn('1234-5678-9012-3456')['card_number'] === '1234-5678-9012-3456'
    && $pcn('1234 5678 9012 3456')['card_number'] === '1234 5678 9012 3456' && $x("REPUBLIKA NG PILIPINAS\nPhilippine Identification Card\n1234 5678 9012 3456\n")['type_state'] === 'confirmed');
$check('card number: mixed separators, a longer run of digit groups, or a short group are not a card number', $pcn('1234-5678 9012-3456')['card_number'] === '-'
    && $pcn('1234 5678 9012 3456 7890')['card_number'] === '-' && $pcn('9999 1234 5678 9012 3456')['card_number'] === '-' && $pcn('1234 5678 9012 345')['card_number'] === '-'
    && $pcn('12345678 9012 3456')['card_number'] === '-');
$check('card number: on separate lines the groups are not joined', $pcn("1234 5678\n9012 3456")['card_number'] === '-');
$check('card number: other text OCR joined after it on the same line does not hide it (the address\'s house number too)', $pcn('1234-5678-9012-3456 1 RIZAL ST, POBLACION')['card_number'] === '1234-5678-9012-3456'
    && $pcn('1234 5678 9012 3456 12 MABINI ST')['card_number'] === '1234 5678 9012 3456');
$check('noise: the other labelled IDs gain the same tolerance (Postal ID, UMID)', $x(str_replace(["Surname\nSORIANO", "First Name\nRAFAEL"], ["Surname\n|\nSORIANO ee", "First Name\n= .\nRAFAEL"], $postal))['name'] === 'SORIANO, RAFAEL GATCHALIAN'
    && $x(str_replace("SURNAME\nPASCUAL", "SURNAME\n| a\nPASCUAL", $umid))['name'] === 'PASCUAL, LORENZO AQUINO');
$check('noise: noise lines never turn a letter that only mentions the National ID into a confirmed ID', $x("Please bring your Philippine Identification Card.\n|\nThank you.\n= .\n")['type_state'] !== 'confirmed'
    && $x("Please bring your Philippine Identification Card.\n|\nThank you.\n= .\n")['name'] === null);
$check('stray marks: letters at the start of a value are never dropped ("de la CRUZ" stays unread, never "CRUZ")', $states($x($noisy('', 'de la CRUZ')))['last_name'] === '-'
    && $states($x($noisy('', 'a DELA CRUZ')))['last_name'] === '-');
$noisyCheck = DocumentAnalysis::idCheck($x($noisy()), 'good', true, '', false);
$check('worker v5 states for a noisy National ID: type confirmed and the name grounded, states only', ($noisyCheck['type_state'] ?? '') === 'confirmed'
    && !preg_match('/1990-01-01|JANUARY|1234/', (string) json_encode($noisyCheck)), $noisyCheck);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
