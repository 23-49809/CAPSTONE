<?php
/**
 * Seeds a handful of realistic sample requests by going through the same
 * AI Rule-Based Requirement Checker call the real forms use — so the Staff
 * and Department Head dashboards have believable data on first run.
 *
 * Usage (from the project root, with MySQL + the Django checker running):
 *   php database/seed_demo_requests.php
 */

require_once __DIR__ . '/../web/includes/functions.php';

function tiny_png(): string
{
    $chunk = function (string $tag, string $data): string {
        return pack('N', strlen($data)) . $tag . $data . pack('N', crc32($tag . $data));
    };
    $png = "\x89PNG\r\n\x1a\n";
    $png .= $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0));
    $png .= $chunk('IDAT', gzcompress("\x00\xff\xff\xff"));
    $png .= $chunk('IEND', '');
    return $png;
}

function tiny_pdf(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
}

/** Writes fake bytes straight into UPLOAD_DIR (no real HTTP upload in CLI). */
function seed_save_file(string $refNo, string $key, string $bytes, string $mime, string $ext): array
{
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = $refNo . '_' . $key . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
    file_put_contents(UPLOAD_DIR . '/' . $name, $bytes);
    return ['original_name' => "$key.$ext", 'stored_path' => 'uploads/' . $name, 'mime' => $mime, 'size' => strlen($bytes)];
}

function seed_request(array $r): void
{
    $pdo = db();
    $flow = $r['flow'];

    $fileBytesByKey = [];
    foreach ($r['files'] as $key => $wantPdf) {
        $fileBytesByKey[$key] = $wantPdf ? [tiny_pdf(), 'application/pdf', 'pdf'] : [tiny_png(), 'image/png', 'png'];
    }
    $uploadedMeta = [];
    foreach ($fileBytesByKey as $key => [$bytes, $mime, $ext]) {
        $uploadedMeta[$key] = ['mime' => $mime, 'size' => strlen($bytes)];
    }

    $ai = call_ai_checker([
        'flow' => $flow,
        'document_type' => $r['document_type'] ?? null,
        'transfer_type' => $r['transfer_type'] ?? null,
        'purpose' => $r['purpose'],
        'is_owner' => $r['is_owner'],
        'uploaded_files' => $uploadedMeta,
    ]);
    if (!$ai['ok']) {
        fwrite(STDERR, "AI checker unreachable — is the Django server running? {$ai['error']}\n");
        exit(1);
    }
    $statusByKey = array_column($ai['checklist'], 'status', 'key');

    $refNo = next_reference_no($flow);
    $stmt = $pdo->prepare(
        'INSERT INTO requests
         (client_id, reference_no, flow, first_name, middle_name, last_name, contact_number, email,
          address_line, province, city, zip_code, document_type, transfer_type, purpose,
          arp_number, property_address, barangay, is_owner, status, requirement_complete, advisory, created_at)
         VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?, "Received", ?, ?, ?)'
    );
    $stmt->execute([
        $r['client_id'] ?? null, $refNo, $flow, $r['first_name'], $r['middle_name'] ?? null, $r['last_name'], $r['contact'], $r['email'],
        $r['address_line'], 'Batangas', 'Mabini', $r['zip'],
        $r['document_type'] ?? null, $r['transfer_type'] ?? null, $r['purpose'],
        $r['arp'], $r['property_address'], $r['barangay'], $r['is_owner'] ? 1 : 0,
        $ai['requirement_complete'] ? 1 : 0, $ai['advisory'], $r['created_at'],
    ]);
    $requestId = (int) $pdo->lastInsertId();

    $docStmt = $pdo->prepare(
        'INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    foreach ($r['files'] as $key => $wantPdf) {
        [$bytes, $mime, $ext] = $fileBytesByKey[$key];
        $saved = seed_save_file($refNo, $key, $bytes, $mime, $ext);
        $label = in_array($key, ['ownerId', 'requesterId', 'authLetter'], true)
            ? id_label($flow, $key)
            : (array_values(array_filter(LAND_TRANSFER_DOCS, fn($d) => $d['key'] === $key))[0]['label'] ?? $key);
        $docStmt->execute([$requestId, $key, $label, $saved['original_name'], $saved['stored_path'], $saved['mime'], $saved['size'], $statusByKey[$key] ?? 'ok']);
    }
    // Also record any required-but-not-uploaded items as explicit "missing" rows.
    $expectedKeys = $flow === 'landtransfer'
        ? array_merge(array_column(LAND_TRANSFER_DOCS, 'key'), required_id_keys($r['is_owner']))
        : required_id_keys($r['is_owner']);
    foreach ($expectedKeys as $key) {
        if (isset($r['files'][$key])) continue;
        $label = in_array($key, ['ownerId', 'requesterId', 'authLetter'], true)
            ? id_label($flow, $key)
            : (array_values(array_filter(LAND_TRANSFER_DOCS, fn($d) => $d['key'] === $key))[0]['label'] ?? $key);
        $docStmt->execute([$requestId, $key, $label, null, null, null, null, 'missing']);
    }

    $pdo->prepare('INSERT INTO request_status_log (request_id, status, sms_body, created_at) VALUES (?,?,?,?)')
        ->execute([$requestId, 'Received', sms_body_for('Received', $refNo), $r['created_at']]);
    if (($r['final_status'] ?? 'Received') !== 'Received') {
        $finalAt = date('Y-m-d H:i:s', strtotime($r['created_at']) + 3600);
        $pdo->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute([$r['final_status'], $requestId]);
        $pdo->prepare('INSERT INTO request_status_log (request_id, status, sms_body, created_at) VALUES (?,?,?,?)')
            ->execute([$requestId, $r['final_status'], sms_body_for($r['final_status'], $refNo, $ai['missing']), $finalAt]);
    }

    echo "Seeded $refNo ({$r['last_name']}) — " . ($ai['requirement_complete'] ? 'complete' : 'flagged: ' . implode(', ', $ai['missing'])) . "\n";
}

$now = time();
seed_request([
    'client_id' => 1, // links to the demo client account (r.villareal@example.com) seeded in schema.sql
    'flow' => 'docreq', 'document_type' => 'Certified True Copy of Tax Declaration (CTC-TD)', 'purpose' => 'For Titling',
    'first_name' => 'Ramon', 'last_name' => 'Villareal', 'contact' => '0917-224-5510', 'email' => 'r.villareal@example.com',
    'address_line' => '12 Rizal St.', 'zip' => '4202', 'arp' => '024-01-0032', 'property_address' => 'Brgy. Poblacion, Mabini', 'barangay' => 'Poblacion',
    'is_owner' => true, 'files' => ['ownerId' => false], 'final_status' => 'Approved',
    'created_at' => date('Y-m-d H:i:s', $now - 6 * 86400),
]);
seed_request([
    'flow' => 'docreq', 'document_type' => 'Certification of No/With Existing Improvement', 'purpose' => 'For Building Permit',
    'first_name' => 'Josefina', 'last_name' => 'Dimaano', 'contact' => '0928-771-2093', 'email' => 'j.dimaano@example.com',
    'address_line' => 'Purok 3', 'zip' => '4202', 'arp' => '024-03-1187', 'property_address' => 'Brgy. Bagalangit, Mabini', 'barangay' => 'Bagalangit',
    'is_owner' => false, 'files' => ['ownerId' => false], 'final_status' => 'Rejected',
    'created_at' => date('Y-m-d H:i:s', $now - 2 * 86400),
]);
seed_request([
    'flow' => 'landtransfer', 'transfer_type' => 'Sale', 'purpose' => 'For Transfer',
    'first_name' => 'Antonio', 'last_name' => 'Reyes', 'contact' => '0939-402-8815', 'email' => 'a.reyes@example.com',
    'address_line' => '45 Mabini St.', 'zip' => '4202', 'arp' => '024-02-0765', 'property_address' => 'Brgy. Solo, Mabini', 'barangay' => 'Solo',
    'is_owner' => true,
    'files' => ['ownerId' => false, 'ctcTdOrTitle' => true, 'notarialDeed' => true, 'vicinityMap' => false, 'certNoImprovement' => true, 'taxClearance' => true],
    'final_status' => 'Processing',
    'created_at' => date('Y-m-d H:i:s', $now - 1 * 86400),
]);
seed_request([
    'flow' => 'docreq', 'document_type' => 'Certification of No Liens and Encumbrances', 'purpose' => 'Personal Copy',
    'first_name' => 'Corazon', 'last_name' => 'Manalo', 'contact' => '0905-118-4471', 'email' => 'c.manalo@example.com',
    'address_line' => 'Sitio Ilaya', 'zip' => '4202', 'arp' => '024-05-2290', 'property_address' => 'Brgy. Sto. Tomas, Mabini', 'barangay' => 'Sto. Tomas',
    'is_owner' => true, 'files' => [], 'final_status' => 'Received',
    'created_at' => date('Y-m-d H:i:s', $now - 12 * 3600),
]);
seed_request([
    'flow' => 'landtransfer', 'transfer_type' => 'Estate', 'purpose' => 'For Titling',
    'first_name' => 'Bienvenido', 'last_name' => 'Cruz', 'contact' => '0918-330-2244', 'email' => 'b.cruz@example.com',
    'address_line' => 'Zone 2', 'zip' => '4202', 'arp' => '024-04-0518', 'property_address' => 'Brgy. Poblacion, Mabini', 'barangay' => 'Poblacion',
    'is_owner' => true, 'files' => ['ownerId' => false, 'ctcTdOrTitle' => true], 'final_status' => 'Received',
    'created_at' => date('Y-m-d H:i:s', $now - 3 * 3600),
]);

echo "Done.\n";
