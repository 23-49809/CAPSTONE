<?php
/**
 * Dedicated handler for Land Transfer submissions. Required inline by
 * client/land-transfer.php — see api/submit-document-request.php's header
 * comment for why this is a required handler, not a separate URL. Logic
 * moved verbatim from the former inline handler; unchanged.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors['_csrf'] = 'Your session expired. Please resubmit the form.';
    }

    foreach ($values as $key => $default) {
        if ($key === 'is_owner') continue;
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $values['is_owner'] = isset($_POST['is_owner']);

    $errors = array_merge($errors, validate_personal($values));
    $errors = array_merge($errors, validate_property_common($values, false, false));
    if (!in_array($values['transfer_type'], TRANSFER_TYPES, true)) {
        $errors['transfer_type'] = 'Please select a transfer type.';
    }

    $idKeys = required_id_keys($values['is_owner']);
    $docKeys = array_column(LAND_TRANSFER_DOCS, 'key');
    $requiredKeys = array_merge($docKeys, $idKeys);

    if (empty($errors)) {
        $uploadedMeta = build_uploaded_meta_from_files($requiredKeys);

        $ai = call_ai_checker([
            'flow' => $flow,
            'document_type' => null,
            'transfer_type' => $values['transfer_type'],
            'purpose' => '',
            'is_owner' => $values['is_owner'],
            'uploaded_files' => $uploadedMeta,
        ]);

        if (!$ai['ok']) {
            $aiIssue = $ai['error'];
            $errors['_system'] = 'The AI Rule-Based Requirement Checker could not be reached, so this request was not submitted. Please try again shortly.';
        } else {
            $statusByKey = array_column($ai['checklist'], 'status', 'key');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $refNo = next_reference_no($flow);
                $stmt = $pdo->prepare(
                    'INSERT INTO requests
                     (client_id, reference_no, flow, first_name, middle_name, last_name, contact_number, email,
                      address_line, province, city, zip_code, document_type, transfer_type, purpose,
                      arp_number, property_address, barangay, is_owner, status, requirement_complete, advisory)
                     VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?, "Received", ?, ?)'
                );
                $stmt->execute([
                    $client['id'] ?? null, $refNo, $flow, $values['first_name'], $values['middle_name'] ?: null, $values['last_name'],
                    $values['contact_number'], $values['email'],
                    $values['address_line'], $values['province'], $values['city'], $values['zip_code'],
                    null, $values['transfer_type'], '',
                    $values['arp_number'], '', $values['barangay'], $values['is_owner'] ? 1 : 0,
                    $ai['requirement_complete'] ? 1 : 0, $ai['advisory'],
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $docStmt = $pdo->prepare(
                    'INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                foreach (LAND_TRANSFER_DOCS as $doc) {
                    $saved = save_uploaded_file($doc['key'], $refNo);
                    $fileStatus = $statusByKey[$doc['key']] ?? 'missing';
                    $docStmt->execute([
                        $requestId, $doc['key'], $doc['label'],
                        $saved['original_name'] ?? null, $saved['stored_path'] ?? null,
                        $saved['mime'] ?? null, $saved['size'] ?? null,
                        $fileStatus,
                    ]);
                    record_document_validation($pdo, (int) $pdo->lastInsertId(), $fileStatus);
                }
                foreach ($idKeys as $key) {
                    $saved = save_uploaded_file($key, $refNo);
                    $fileStatus = $statusByKey[$key] ?? 'missing';
                    $docStmt->execute([
                        $requestId, $key, id_label($flow, $key),
                        $saved['original_name'] ?? null, $saved['stored_path'] ?? null,
                        $saved['mime'] ?? null, $saved['size'] ?? null,
                        $fileStatus,
                    ]);
                    record_document_validation($pdo, (int) $pdo->lastInsertId(), $fileStatus);
                }

                push_status($requestId, 'Received', $refNo, $ai['missing'], 'client');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors['_system'] = 'Something went wrong saving your request. Please try again.';
            }

            if (empty($errors)) {
                header('Location: /confirmation.php?ref=' . urlencode($refNo));
                exit;
            }
        }
    }
}
